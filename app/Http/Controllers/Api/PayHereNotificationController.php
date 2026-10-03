<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Payment;
use App\Notifications\PaymentConfirmedNotification;
use App\Services\Payments\PayHerePaymentGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PayHereNotificationController extends Controller
{
    public function store(Request $request, PayHerePaymentGateway $payHere): JsonResponse
    {
        $validated = $request->validate([
            'merchant_id' => ['required', 'string', 'max:100'],
            'order_id' => ['required', 'string', 'max:100'],
            'payment_id' => ['required', 'string', 'max:100'],
            'payhere_amount' => ['required', 'string', 'regex:/^\d+\.\d{2}$/'],
            'payhere_currency' => ['required', 'string', 'size:3'],
            'status_code' => ['required', 'string', 'in:2,0,-1,-2,-3'],
            'md5sig' => ['required', 'string', 'size:32'],
            'status_message' => ['nullable', 'string', 'max:2000'],
        ]);

        if (! $payHere->verifyNotification($validated)) {
            return response()->json(['message' => 'Notification rejected.'], 403);
        }

        $paymentId = Payment::query()
            ->where('payment_reference', $validated['order_id'])
            ->where('gateway', 'payhere')
            ->value('id');

        if (! $paymentId) {
            return response()->json(['message' => 'Notification rejected.'], 404);
        }

        DB::transaction(function () use ($paymentId, $validated): void {
            $payment = Payment::query()
                ->whereKey($paymentId)
                ->where('payment_reference', $validated['order_id'])
                ->where('gateway', 'payhere')
                ->first();

            if (! $payment) {
                return;
            }

            /*
             * Payment lifecycle operations consistently lock the booking
             * first, then the associated payment row.
             */
            $booking = Booking::query()
                ->whereKey($payment->booking_id)
                ->lockForUpdate()
                ->first();

            if (! $booking) {
                return;
            }

            $payment = Payment::query()
                ->whereKey($payment->id)
                ->where('booking_id', $booking->id)
                ->where('payment_reference', $validated['order_id'])
                ->where('gateway', 'payhere')
                ->lockForUpdate()
                ->first();

            if (! $payment || ! $this->matchesTrustedPayment($payment, $validated)) {
                return;
            }

            if (! $this->usesExpectedGatewayTransaction($payment, $validated['payment_id'])
                || $this->gatewayTransactionIdIsInUse($payment, $validated['payment_id'])) {
                return;
            }

            $this->processStatus($payment, $booking, $validated);
        });

        return response()->json(['message' => 'Notification acknowledged.']);
    }

    /**
     * @param array<string, string> $notification
     */
    private function matchesTrustedPayment(Payment $payment, array $notification): bool
    {
        return bccomp((string) $payment->amount, $notification['payhere_amount'], 2) === 0
            && hash_equals($payment->currency, $notification['payhere_currency']);
    }

    private function usesExpectedGatewayTransaction(Payment $payment, string $paymentId): bool
    {
        return $payment->gateway_transaction_id === null
            || hash_equals($payment->gateway_transaction_id, $paymentId);
    }

    private function gatewayTransactionIdIsInUse(Payment $payment, string $paymentId): bool
    {
        return $payment->gateway_transaction_id === null
            && Payment::query()
                ->where('gateway_transaction_id', $paymentId)
                ->where('id', '!=', $payment->id)
                ->lockForUpdate()
                ->exists();
    }

    /**
     * @param array<string, string> $notification
     */
    private function processStatus(Payment $payment, Booking $booking, array $notification): void
    {
        if ($notification['status_code'] === '2') {
            $this->processSuccess($payment, $booking, $notification['payment_id']);

            return;
        }

        if ($notification['status_code'] === '-3') {
            $this->processChargeback($payment, $notification['payment_id']);

            return;
        }

        if (! $this->isActivePayment($payment)
            || $booking->booking_status !== 'accepted'
            || $booking->payment_status === 'paid') {
            return;
        }

        if ($payment->gateway_transaction_id === null) {
            $payment->gateway_transaction_id = $notification['payment_id'];
        }

        if ($notification['status_code'] === '0') {
            $payment->save();

            return;
        }

        $now = now();

        if ($notification['status_code'] === '-1') {
            $payment->status = 'cancelled';
            $payment->cancelled_at = $now;
        }

        if ($notification['status_code'] === '-2') {
            $payment->status = 'failed';
            $payment->failed_at = $now;
            $payment->failure_reason = isset($notification['status_message'])
                ? Str::limit(strip_tags($notification['status_message']), 2000, '')
                : null;
        }

        $payment->save();
        $booking->payment_status = 'unpaid';
        $booking->save();
    }

    private function processSuccess(Payment $payment, Booking $booking, string $paymentId): void
    {
        if ($payment->status === 'paid' || $payment->status === 'chargedback') {
            return;
        }

        if (! $this->isActivePayment($payment)
            || $booking->booking_status !== 'accepted'
            || $booking->payment_status === 'paid') {
            return;
        }

        $now = now();
        $payment->status = 'paid';
        $payment->paid_at = $now;
        $payment->failure_reason = null;
        $payment->gateway_transaction_id = $paymentId;
        $payment->save();

        $booking->payment_status = 'paid';
        $booking->booking_status = 'confirmed';
        $booking->confirmed_at = $now;
        $booking->save();

        /*
         * This is a database notification written inside the same transaction.
         * A rollback therefore rolls back both the payment state and the
         * notification. The paid/status guards above make webhook retries
         * idempotent and prevent duplicate payment-confirmed notifications.
         */
        $customer = $payment->customer()->first();

        if ($customer) {
            $customer->notify(
                new PaymentConfirmedNotification(
                    paymentId: $payment->id,
                    paymentReference: $payment->payment_reference,
                    bookingId: $booking->id,
                    bookingReference: $booking->booking_reference,
                    amount: (string) $payment->amount,
                    currency: $payment->currency,
                )
            );
        }
    }

    private function processChargeback(Payment $payment, string $paymentId): void
    {
        if ($payment->status !== 'paid'
            || ! hash_equals((string) $payment->gateway_transaction_id, $paymentId)) {
            return;
        }

        $payment->status = 'chargedback';
        $payment->charged_back_at = now();
        $payment->save();
    }

    private function isActivePayment(Payment $payment): bool
    {
        return in_array($payment->status, ['pending', 'processing'], true);
    }
}
