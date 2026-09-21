<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminPaymentResource;
use App\Http\Resources\PaymentResource;
use App\Http\Resources\ProviderPaymentResource;
use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaymentController extends Controller
{
    /**
     * List all marketplace payments for the authenticated administrator.
     */
    public function adminIndex(Request $request): JsonResponse
    {
        $payments = Payment::query()
            ->with([
                'booking.customer:id,name,email',
                'booking.provider:id,business_name',
                'booking.service:id,name,slug',
                'booking.package:id,name,slug,price,duration_minutes',
            ])
            ->latest('id')
            ->get();

        return response()->json([
            'message' => 'Admin payments loaded successfully.',
            'payments' => $payments->map(
                fn (Payment $payment): array => (new AdminPaymentResource($payment))->toArray($request)
            )->values(),
        ]);
    }

    /**
     * Show one marketplace payment for the authenticated administrator.
     */
    public function adminShow(Request $request, int $id): JsonResponse
    {
        $payment = Payment::query()
            ->whereKey($id)
            ->with([
                'booking.customer:id,name,email',
                'booking.provider:id,business_name',
                'booking.service:id,name,slug',
                'booking.package:id,name,slug,price,duration_minutes',
            ])
            ->first();

        if (! $payment) {
            return response()->json([
                'message' => 'Payment not found.',
            ], 404);
        }

        return response()->json([
            'payment' => (new AdminPaymentResource($payment))->toArray($request),
        ]);
    }

    /**
     * List payments for bookings owned by the authenticated provider.
     */
    public function providerIndex(Request $request): JsonResponse
    {
        $provider = $request->user()->serviceProvider()->first();

        if (! $provider) {
            return response()->json([
                'message' => 'Business profile not found.',
            ], 404);
        }

        $payments = Payment::query()
            ->whereHas('booking', function ($query) use ($provider): void {
                $query->where('service_provider_id', $provider->id);
            })
            ->with([
                'booking.customer:id,name,email',
                'booking.service:id,name,slug',
                'booking.package:id,name,slug,price,duration_minutes',
            ])
            ->latest('id')
            ->get();

        return response()->json([
            'message' => 'Provider payments loaded successfully.',
            'payments' => $payments->map(
                fn (Payment $payment): array => (new ProviderPaymentResource($payment))->toArray($request)
            )->values(),
        ]);
    }

    /**
     * Show a payment for a booking owned by the authenticated provider.
     */
    public function providerShow(Request $request, int $id): JsonResponse
    {
        $provider = $request->user()->serviceProvider()->first();

        if (! $provider) {
            return response()->json([
                'message' => 'Business profile not found.',
            ], 404);
        }

        $payment = Payment::query()
            ->whereKey($id)
            ->whereHas('booking', function ($query) use ($provider): void {
                $query->where('service_provider_id', $provider->id);
            })
            ->with([
                'booking.customer:id,name,email',
                'booking.service:id,name,slug',
                'booking.package:id,name,slug,price,duration_minutes',
            ])
            ->first();

        if (! $payment) {
            return response()->json([
                'message' => 'Payment not found.',
            ], 404);
        }

        return response()->json([
            'payment' => (new ProviderPaymentResource($payment))->toArray($request),
        ]);
    }

    /**
     * List payments belonging to the authenticated customer.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $payments = Payment::query()
            ->where('customer_id', $user->id)
            ->with([
                'booking.service',
                'booking.package',
            ])
            ->latest('id')
            ->get();

        return response()->json([
            'payments' => $payments->map(
                fn (Payment $payment): array => (new PaymentResource($payment))->toArray($request)
            )->values(),
        ]);
    }

    /**
     * Show a payment belonging to the authenticated customer.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $payment = Payment::query()
            ->where('id', $id)
            ->where('customer_id', $user->id)
            ->with([
                'booking.service',
                'booking.package',
            ])
            ->first();

        if (! $payment) {
            return response()->json([
                'message' => 'Payment not found.',
            ], 404);
        }

        return response()->json([
            'payment' => (new PaymentResource($payment))->toArray($request),
        ]);
    }

    /**
     * Start a payment attempt.
     */
    public function store(Request $request, int $bookingId): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasRole('customer')) {
            return response()->json([
                'message' => 'Only customer accounts can initiate payments.',
            ], 403);
        }

        $validated = $request->validate([
            'payment_method' => [
                'required',
                'string',
                'in:card,bank_transfer',
            ],
        ]);

        $result = DB::transaction(
            function () use ($user, $bookingId, $validated) {
                $booking = Booking::query()
                    ->where('id', $bookingId)
                    ->where('customer_id', $user->id)
                    ->lockForUpdate()
                    ->first();

                if (! $booking) {
                    return [
                        'error' => true,
                        'status' => 404,
                        'message' => 'Booking not found.',
                    ];
                }

                if ($booking->booking_status !== 'accepted') {
                    return [
                        'error' => true,
                        'status' => 422,
                        'message' => 'Only accepted bookings can be paid.',
                    ];
                }

                if ($booking->payment_status === 'paid') {
                    return [
                        'error' => true,
                        'status' => 422,
                        'message' => 'This booking has already been paid.',
                    ];
                }

                $activePayment = $booking->payments()
                    ->whereIn('status', [
                        'pending',
                        'processing',
                    ])
                    ->latest()
                    ->first();

                if ($activePayment) {
                    return [
                        'error' => true,
                        'status' => 409,
                        'message' => 'An active payment attempt already exists for this booking.',
                        'payment' => $activePayment,
                    ];
                }

                $payment = Payment::create([
                    'booking_id' => $booking->id,
                    'customer_id' => $user->id,
                    'payment_reference' => $this->generatePaymentReference(),
                    'amount' => $booking->total_amount,
                    'currency' => 'LKR',
                    'payment_method' => $validated['payment_method'],
                    'status' => 'pending',
                    'gateway' => null,
                    'gateway_transaction_id' => null,
                ]);

                $booking->payment_status = 'pending';
                $booking->save();

                return [
                    'error' => false,
                    'payment' => $payment,
                ];
            }
        );

        if ($result['error']) {
            return response()->json([
                'message' => $result['message'],
                ...isset($result['payment'])
                    ? ['payment' => $result['payment']]
                    : [],
            ], $result['status']);
        }

        return response()->json([
            'message' => 'Payment initiated successfully.',
            'payment' => $result['payment']->load([
                'booking:id,booking_reference,customer_id,total_amount,booking_status,payment_status',
            ]),
        ], 201);
    }

    /**
     * Mark a payment as successful.
     *
     * Development/mock endpoint.
     *
     * A production payment gateway must replace
     * this customer-triggered success confirmation
     * with a verified server-side callback/webhook.
     */
    public function success(
        Request $request,
        int $bookingId,
        int $paymentId
    ): JsonResponse {
        $user = $request->user();

        if (! app()->environment(['local', 'testing'])) {
            return response()->json([
                'message' => 'Mock payment confirmation is only available in local and testing environments.',
            ], 404);
        }

        if (! $user->hasRole('customer')) {
            return response()->json([
                'message' => 'Only customer accounts can complete this payment flow.',
            ], 403);
        }

        $result = DB::transaction(
            function () use ($user, $bookingId, $paymentId) {
                $booking = Booking::query()
                    ->where('id', $bookingId)
                    ->where('customer_id', $user->id)
                    ->lockForUpdate()
                    ->first();

                if (! $booking) {
                    return [
                        'error' => true,
                        'status' => 404,
                        'message' => 'Booking not found.',
                    ];
                }

                $payment = Payment::query()
                    ->where('id', $paymentId)
                    ->where('booking_id', $booking->id)
                    ->where('customer_id', $user->id)
                    ->lockForUpdate()
                    ->first();

                if (! $payment) {
                    return [
                        'error' => true,
                        'status' => 404,
                        'message' => 'Payment not found.',
                    ];
                }

                if ($payment->status === 'paid') {
                    return [
                        'error' => true,
                        'status' => 409,
                        'message' => 'This payment has already been processed successfully.',
                    ];
                }

                if (! in_array(
                    $payment->status,
                    [
                        'pending',
                        'processing',
                    ],
                    true
                )) {
                    return [
                        'error' => true,
                        'status' => 422,
                        'message' => 'This payment cannot be completed from its current status.',
                    ];
                }

                if ($booking->booking_status !== 'accepted') {
                    return [
                        'error' => true,
                        'status' => 422,
                        'message' => 'The booking is no longer eligible for payment confirmation.',
                    ];
                }

                if ($booking->payment_status === 'paid') {
                    return [
                        'error' => true,
                        'status' => 409,
                        'message' => 'This booking has already been paid.',
                    ];
                }

                if (
                    bccomp(
                        (string) $payment->amount,
                        (string) $booking->total_amount,
                        2
                    ) !== 0
                ) {
                    return [
                        'error' => true,
                        'status' => 422,
                        'message' => 'Payment amount does not match the booking total.',
                    ];
                }

                $now = now();

                $payment->status = 'paid';
                $payment->paid_at = $now;
                $payment->failure_reason = null;
                $payment->save();

                $booking->payment_status = 'paid';
                $booking->booking_status = 'confirmed';
                $booking->confirmed_at = $now;
                $booking->save();

                return [
                    'error' => false,
                    'payment' => $payment->fresh()->load([
                        'booking:id,booking_reference,customer_id,total_amount,booking_status,payment_status,confirmed_at',
                    ]),
                ];
            }
        );

        if ($result['error']) {
            return response()->json([
                'message' => $result['message'],
            ], $result['status']);
        }

        return response()->json([
            'message' => 'Payment completed successfully. Booking confirmed.',
            'payment' => $result['payment'],
        ]);
    }

    /**
     * Mark an active payment attempt as failed.
     *
     * Development/mock endpoint.
     */
    public function fail(
        Request $request,
        int $bookingId,
        int $paymentId
    ): JsonResponse {
        $user = $request->user();

        if (! app()->environment(['local', 'testing'])) {
            return response()->json([
                'message' => 'Mock payment failure is only available in local and testing environments.',
            ], 404);
        }

        $validated = $request->validate([
            'failure_reason' => [
                'nullable',
                'string',
                'max:2000',
            ],
        ]);

        $result = DB::transaction(
            function () use ($user, $bookingId, $paymentId, $validated) {
                $booking = Booking::query()
                    ->where('id', $bookingId)
                    ->where('customer_id', $user->id)
                    ->lockForUpdate()
                    ->first();

                if (! $booking) {
                    return [
                        'error' => true,
                        'status' => 404,
                        'message' => 'Booking not found.',
                    ];
                }

                $payment = Payment::query()
                    ->where('id', $paymentId)
                    ->where('booking_id', $booking->id)
                    ->where('customer_id', $user->id)
                    ->lockForUpdate()
                    ->first();

                if (! $payment) {
                    return [
                        'error' => true,
                        'status' => 404,
                        'message' => 'Payment not found.',
                    ];
                }

                if ($payment->status === 'paid') {
                    return [
                        'error' => true,
                        'status' => 409,
                        'message' => 'A successful payment cannot be marked as failed.',
                    ];
                }

                if (! in_array(
                    $payment->status,
                    [
                        'pending',
                        'processing',
                    ],
                    true
                )) {
                    return [
                        'error' => true,
                        'status' => 422,
                        'message' => 'This payment cannot be marked as failed from its current status.',
                    ];
                }

                if ($booking->booking_status !== 'accepted') {
                    return [
                        'error' => true,
                        'status' => 422,
                        'message' => 'The booking is no longer eligible for payment processing.',
                    ];
                }

                $payment->status = 'failed';
                $payment->failure_reason =
                    $validated['failure_reason'] ?? 'Payment failed.';
                $payment->failed_at = now();
                $payment->save();

                /*
                 * The booking remains accepted so
                 * another payment attempt can be made.
                 */
                $booking->payment_status = 'unpaid';
                $booking->save();

                return [
                    'error' => false,
                    'payment' => $payment->fresh()->load([
                        'booking:id,booking_reference,customer_id,total_amount,booking_status,payment_status',
                    ]),
                ];
            }
        );

        if ($result['error']) {
            return response()->json([
                'message' => $result['message'],
            ], $result['status']);
        }

        return response()->json([
            'message' => 'Payment marked as failed. The booking can be paid again.',
            'payment' => $result['payment'],
        ]);
    }

    /**
     * Cancel an active payment attempt.
     */
    public function cancel(
        Request $request,
        int $bookingId,
        int $paymentId
    ): JsonResponse {
        $user = $request->user();

        if (! app()->environment(['local', 'testing'])) {
            return response()->json([
                'message' => 'Mock payment cancellation is only available in local and testing environments.',
            ], 404);
        }

        $result = DB::transaction(
            function () use ($user, $bookingId, $paymentId) {
                $booking = Booking::query()
                    ->where('id', $bookingId)
                    ->where('customer_id', $user->id)
                    ->lockForUpdate()
                    ->first();

                if (! $booking) {
                    return [
                        'error' => true,
                        'status' => 404,
                        'message' => 'Booking not found.',
                    ];
                }

                $payment = Payment::query()
                    ->where('id', $paymentId)
                    ->where('booking_id', $booking->id)
                    ->where('customer_id', $user->id)
                    ->lockForUpdate()
                    ->first();

                if (! $payment) {
                    return [
                        'error' => true,
                        'status' => 404,
                        'message' => 'Payment not found.',
                    ];
                }

                if ($payment->status === 'paid') {
                    return [
                        'error' => true,
                        'status' => 409,
                        'message' => 'A successful payment cannot be cancelled.',
                    ];
                }

                if (! in_array(
                    $payment->status,
                    [
                        'pending',
                        'processing',
                    ],
                    true
                )) {
                    return [
                        'error' => true,
                        'status' => 422,
                        'message' => 'This payment cannot be cancelled from its current status.',
                    ];
                }

                if ($booking->booking_status !== 'accepted') {
                    return [
                        'error' => true,
                        'status' => 422,
                        'message' => 'The booking is no longer eligible for payment processing.',
                    ];
                }

                $payment->status = 'cancelled';
                $payment->cancelled_at = now();
                $payment->save();

                /*
                 * Cancelling the payment attempt does
                 * not cancel the booking itself.
                 */
                $booking->payment_status = 'unpaid';
                $booking->save();

                return [
                    'error' => false,
                    'payment' => $payment->fresh()->load([
                        'booking:id,booking_reference,customer_id,total_amount,booking_status,payment_status',
                    ]),
                ];
            }
        );

        if ($result['error']) {
            return response()->json([
                'message' => $result['message'],
            ], $result['status']);
        }

        return response()->json([
            'message' => 'Payment attempt cancelled. The booking can be paid again.',
            'payment' => $result['payment'],
        ]);
    }

    /**
     * Generate a unique internal payment reference.
     */
    private function generatePaymentReference(): string
    {
        do {
            $reference =
                'PAY-'.
                now()->format('Ymd').
                '-'.
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