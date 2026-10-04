<?php

namespace App\Services\Payments;

use App\Models\Booking;
use App\Models\Payment;
use App\Notifications\PaymentConfirmedNotification;
use Illuminate\Support\Facades\DB;

class PayHerePaymentReconciler
{
    public function __construct(private PayHereRetrievalClient $client) {}

    /** @return array{outcome: string, previous_status: string, current_status: string, gateway_status: ?string} */
    public function reconcile(Payment $payment): array
    {
        $previousStatus = $payment->status;

        if ($payment->gateway !== 'payhere') {
            return $this->outcome('ineligible', $previousStatus, $previousStatus, null);
        }

        $records = $this->client->search($payment->payment_reference);
        $candidate = $this->candidate($payment, $records);

        if ($candidate === null) {
            return $this->outcome('no_matching_payment', $previousStatus, $previousStatus, null);
        }

        if ($candidate === false) {
            return $this->outcome('ambiguous', $previousStatus, $previousStatus, null);
        }

        $gatewayStatus = $candidate['status'];

        if (in_array($gatewayStatus, ['REFUND REQUESTED', 'REFUND PROCESSING', 'REFUNDED'], true)) {
            return $this->outcome('refund_state_detected', $previousStatus, $previousStatus, $gatewayStatus);
        }

        if (! in_array($gatewayStatus, ['RECEIVED', 'CHARGEBACKED'], true)) {
            return $this->outcome('unsupported_status', $previousStatus, $previousStatus, $gatewayStatus);
        }

        $currentStatus = DB::transaction(function () use ($payment, $candidate): string {
            $lockedPayment = Payment::query()
                ->whereKey($payment->id)
                ->firstOrFail();

            $booking = Booking::query()
                ->whereKey($lockedPayment->booking_id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedPayment = Payment::query()
                ->whereKey($lockedPayment->id)
                ->where('booking_id', $booking->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $this->matches($lockedPayment, $candidate)) {
                return $lockedPayment->status;
            }

            if (
                $candidate['status'] === 'RECEIVED'
                && in_array($lockedPayment->status, ['pending', 'processing'], true)
                && $booking->booking_status === 'accepted'
                && $booking->payment_status !== 'paid'
            ) {
                $now = now();

                $lockedPayment->update([
                    'status' => 'paid',
                    'paid_at' => $now,
                    'gateway_transaction_id' => $candidate['payment_id'],
                    'failure_reason' => null,
                ]);

                $booking->update([
                    'payment_status' => 'paid',
                    'booking_status' => 'confirmed',
                    'confirmed_at' => $now,
                ]);

                /*
                 * Keep the notification in the same database transaction.
                 * Reconciliation retries that find an already-paid payment
                 * skip this block, preventing duplicate notifications.
                 */
                $customer = $lockedPayment->customer()->first();

                if ($customer) {
                    $customer->notify(
                        new PaymentConfirmedNotification(
                            paymentId: $lockedPayment->id,
                            paymentReference: $lockedPayment->payment_reference,
                            bookingId: $booking->id,
                            bookingReference: $booking->booking_reference,
                            amount: (string) $lockedPayment->amount,
                            currency: $lockedPayment->currency,
                        )
                    );
                }
            }

            if (
                $candidate['status'] === 'CHARGEBACKED'
                && $lockedPayment->status === 'paid'
                && $lockedPayment->gateway_transaction_id === $candidate['payment_id']
            ) {
                $lockedPayment->update([
                    'status' => 'chargedback',
                    'charged_back_at' => now(),
                ]);
            }

            return $lockedPayment->fresh()->status;
        });

        return $this->outcome(
            $currentStatus === $previousStatus ? 'already_current' : 'reconciled',
            $previousStatus,
            $currentStatus,
            $gatewayStatus
        );
    }

    /** @param list<array{order_id: string, payment_id: string, status: string, amount: string, currency: string}> $records */
    private function candidate(Payment $payment, array $records): array|bool|null
    {
        $matches = array_values(array_filter(
            $records,
            fn (array $record): bool => $this->matches($payment, $record)
        ));

        if (count($matches) !== 1) {
            return count($matches) > 1 ? false : null;
        }

        return $matches[0];
    }

    /** @param array{order_id: string, payment_id: string, status: string, amount: string, currency: string} $record */
    private function matches(Payment $payment, array $record): bool
    {
        return $record['order_id'] === $payment->payment_reference
            && bccomp((string) $payment->amount, $record['amount'], 2) === 0
            && hash_equals($payment->currency, $record['currency'])
            && (
                $payment->gateway_transaction_id === null
                || hash_equals($payment->gateway_transaction_id, $record['payment_id'])
            );
    }

    /** @return array{outcome: string, previous_status: string, current_status: string, gateway_status: ?string} */
    private function outcome(
        string $outcome,
        string $previousStatus,
        string $currentStatus,
        ?string $gatewayStatus
    ): array {
        return [
            'outcome' => $outcome,
            'previous_status' => $previousStatus,
            'current_status' => $currentStatus,
            'gateway_status' => $gatewayStatus,
        ];
    }
}
