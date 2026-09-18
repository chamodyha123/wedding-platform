<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaymentController extends Controller
{
    /**
     * Start a payment attempt for one of the
     * authenticated customer's bookings.
     */
    public function store(
        Request $request,
        int $bookingId
    ): JsonResponse {
        $user = $request->user();

        if (!$user->hasRole('customer')) {
            return response()->json([
                'message' =>
                    'Only customer accounts can initiate payments.',
            ], 403);
        }

        /*
         * Only allow supported payment methods.
         *
         * This does not process a real card yet.
         * Gateway integration will be added later.
         */
        $validated = $request->validate([
            'payment_method' => [
                'required',
                'string',
                'in:card,bank_transfer',
            ],
        ]);

        /*
         * Ownership protection.
         *
         * A customer must never be able to initiate
         * payment for another customer's booking.
         */
        $booking = Booking::query()
            ->where(
                'id',
                $bookingId
            )
            ->where(
                'customer_id',
                $user->id
            )
            ->first();

        if (!$booking) {
            return response()->json([
                'message' =>
                    'Booking not found.',
            ], 404);
        }

        /*
         * Payment may only begin after the provider
         * has accepted the booking.
         */
        if (
            $booking->booking_status !==
            'accepted'
        ) {
            return response()->json([
                'message' =>
                    'Only accepted bookings can be paid.',
            ], 422);
        }

        /*
         * Prevent a booking that is already paid
         * from being charged again.
         */
        if (
            $booking->payment_status === 'paid'
        ) {
            return response()->json([
                'message' =>
                    'This booking has already been paid.',
            ], 422);
        }

        /*
         * Prevent duplicate active payment attempts.
         *
         * Failed or cancelled attempts do not block
         * the customer from trying again.
         */
        $activePayment = $booking->payments()
            ->whereIn(
                'status',
                [
                    'pending',
                    'processing',
                ]
            )
            ->latest()
            ->first();

        if ($activePayment) {
            return response()->json([
                'message' =>
                    'An active payment attempt already exists for this booking.',

                'payment' =>
                    $activePayment,
            ], 409);
        }

        /*
         * Create the payment and update the booking
         * inside one database transaction.
         *
         * The amount is copied from the booking.
         * Customer input can never control the amount.
         */
        $payment = DB::transaction(
            function () use (
                $booking,
                $user,
                $validated
            ) {
                $payment = Payment::create([
                    'booking_id' =>
                        $booking->id,

                    'customer_id' =>
                        $user->id,

                    'payment_reference' =>
                        $this->generatePaymentReference(),

                    'amount' =>
                        $booking->total_amount,

                    'currency' =>
                        'LKR',

                    'payment_method' =>
                        $validated['payment_method'],

                    'status' =>
                        'pending',

                    /*
                     * No real gateway has been
                     * connected yet.
                     */
                    'gateway' =>
                        null,

                    'gateway_transaction_id' =>
                        null,
                ]);

                $booking->payment_status =
                    'pending';

                $booking->save();

                return $payment;
            }
        );

        return response()->json([
            'message' =>
                'Payment initiated successfully.',

            'payment' =>
                $payment->load([
                    'booking:id,booking_reference,customer_id,total_amount,booking_status,payment_status',
                ]),
        ], 201);
    }

    /**
     * Generate a unique internal payment reference.
     *
     * Example:
     *
     * PAY-20260918-A1B2C3
     */
    private function generatePaymentReference(): string
    {
        do {
            $reference =
                'PAY-' .
                now()->format('Ymd') .
                '-' .
                Str::upper(
                    Str::random(6)
                );
        } while (
            Payment::where(
                'payment_reference',
                $reference
            )->exists()
        );

        return $reference;
    }
}