<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BookingController extends Controller
{
    /**
     * Get all bookings belonging to the authenticated customer.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user->hasRole('customer')) {
            return response()->json([
                'message' =>
                    'Only customer accounts can access customer bookings.',
            ], 403);
        }

        $bookings = Booking::query()
            ->where(
                'customer_id',
                $user->id
            )
            ->with([
                'provider:id,business_name,business_slug',
                'service:id,name,slug',
                'package:id,name,slug,price,duration_minutes',
            ])
            ->latest()
            ->get();

        return response()->json([
            'message' =>
                'Customer bookings loaded successfully.',

            'bookings' =>
                $bookings,
        ]);
    }

    /**
     * Get one booking belonging to the authenticated customer.
     */
    public function show(
        Request $request,
        int $id
    ): JsonResponse {
        $user = $request->user();

        if (!$user->hasRole('customer')) {
            return response()->json([
                'message' =>
                    'Only customer accounts can access customer bookings.',
            ], 403);
        }

        $booking = Booking::query()
            ->where(
                'customer_id',
                $user->id
            )
            ->where(
                'id',
                $id
            )
            ->with([
                'provider:id,business_name,business_slug,phone,whatsapp,email,city,district',
                'service:id,name,slug,description',
                'package:id,name,slug,description,price,duration_minutes',
            ])
            ->first();

        if (!$booking) {
            return response()->json([
                'message' =>
                    'Booking not found.',
            ], 404);
        }

        return response()->json([
            'message' =>
                'Booking loaded successfully.',

            'booking' =>
                $booking,
        ]);
    }

    /**
     * Cancel a booking belonging to the authenticated customer.
     */
    public function cancel(
        Request $request,
        int $id
    ): JsonResponse {
        $user = $request->user();

        if (!$user->hasRole('customer')) {
            return response()->json([
                'message' =>
                    'Only customer accounts can cancel bookings.',
            ], 403);
        }

        $validated = $request->validate([
            'cancellation_reason' => [
                'required',
                'string',
                'max:2000',
            ],
        ]);

        /*
         * Ownership protection:
         *
         * A customer can only find and cancel
         * their own bookings.
         */
        $booking = Booking::query()
            ->where(
                'id',
                $id
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
         * Before payment support is implemented,
         * customers may cancel pending or accepted
         * bookings.
         *
         * Confirmed bookings will later use payment
         * and refund rules.
         */
        if (!in_array(
            $booking->booking_status,
            [
                'pending',
                'accepted',
            ],
            true
        )) {
            return response()->json([
                'message' =>
                    'Only pending or accepted bookings can be cancelled.',
            ], 422);
        }

        $booking->booking_status =
            'cancelled';

        $booking->cancellation_reason =
            $validated['cancellation_reason'];

        $booking->cancelled_at =
            now();

        $booking->save();

        return response()->json([
            'message' =>
                'Booking cancelled successfully.',

            'booking' =>
                $booking->load([
                    'provider:id,business_name,business_slug',
                    'service:id,name,slug',
                    'package:id,name,slug,price,duration_minutes',
                ]),
        ]);
    }

    /**
     * Create a new booking for the authenticated customer.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user->hasRole('customer')) {
            return response()->json([
                'message' =>
                    'Only customer accounts can create bookings.',
            ], 403);
        }

        $validated = $request->validate([
            'service_id' => [
                'required',
                'integer',
                'exists:services,id',
            ],

            'service_package_id' => [
                'required',
                'integer',
                'exists:service_packages,id',
            ],

            'event_date' => [
                'required',
                'date',
                'after_or_equal:today',
            ],

            'start_time' => [
                'required',
                'date_format:H:i',
            ],

            'end_time' => [
                'required',
                'date_format:H:i',
                'after:start_time',
            ],

            'event_location' => [
                'nullable',
                'string',
                'max:500',
            ],

            'customer_notes' => [
                'nullable',
                'string',
                'max:2000',
            ],
        ]);

        /*
         * Only published, non-soft-deleted services
         * can be booked.
         */
        $service = Service::with([
            'provider',
        ])
            ->where(
                'id',
                $validated['service_id']
            )
            ->where(
                'status',
                'published'
            )
            ->first();

        if (!$service) {
            return response()->json([
                'message' =>
                    'The selected service is not available for booking.',
            ], 422);
        }

        /*
         * Provider must be active and verified.
         */
        $provider = $service->provider;

        if (
            !$provider ||
            !$provider->is_active ||
            $provider->verification_status !== 'verified'
        ) {
            return response()->json([
                'message' =>
                    'The selected service provider is not currently available for bookings.',
            ], 422);
        }

        /*
         * Package must belong to the selected service
         * and must be published.
         */
        $package = $service->packages()
            ->where(
                'id',
                $validated['service_package_id']
            )
            ->where(
                'status',
                'published'
            )
            ->first();

        if (!$package) {
            return response()->json([
                'message' =>
                    'The selected package is not available for this service.',
            ], 422);
        }

        /*
         * Check whether the whole date is unavailable.
         */
        $fullDayUnavailable = $service->availabilities()
            ->whereDate(
                'date',
                $validated['event_date']
            )
            ->whereNull('start_time')
            ->whereNull('end_time')
            ->whereIn(
                'status',
                [
                    'unavailable',
                    'blocked',
                    'booked',
                ]
            )
            ->exists();

        if ($fullDayUnavailable) {
            return response()->json([
                'message' =>
                    'The selected date is not available for booking.',
            ], 422);
        }

        /*
         * Requested time must fit completely inside
         * at least one available time slot.
         */
        $availableSlotExists = $service->availabilities()
            ->whereDate(
                'date',
                $validated['event_date']
            )
            ->where(
                'status',
                'available'
            )
            ->whereNotNull('start_time')
            ->whereNotNull('end_time')
            ->where(
                'start_time',
                '<=',
                $validated['start_time']
            )
            ->where(
                'end_time',
                '>=',
                $validated['end_time']
            )
            ->exists();

        if (!$availableSlotExists) {
            return response()->json([
                'message' =>
                    'The selected time is outside the service availability.',
            ], 422);
        }

        /*
         * Prevent overlapping bookings for this provider.
         *
         * Rejected, cancelled, and completed bookings
         * do not block the provider's schedule.
         */
        $bookingConflictExists = Booking::where(
            'service_provider_id',
            $provider->id
        )
            ->whereDate(
                'event_date',
                $validated['event_date']
            )
            ->whereIn(
                'booking_status',
                [
                    'pending',
                    'accepted',
                    'confirmed',
                ]
            )
            ->where(
                'start_time',
                '<',
                $validated['end_time']
            )
            ->where(
                'end_time',
                '>',
                $validated['start_time']
            )
            ->exists();

        if ($bookingConflictExists) {
            return response()->json([
                'message' =>
                    'The selected provider already has a booking that overlaps this time.',
            ], 409);
        }

        /*
         * Create the booking atomically.
         */
        $booking = DB::transaction(
            function () use (
                $user,
                $provider,
                $service,
                $package,
                $validated
            ) {
                return Booking::create([
                    'booking_reference' =>
                        $this->generateBookingReference(),

                    'customer_id' =>
                        $user->id,

                    'service_provider_id' =>
                        $provider->id,

                    'service_id' =>
                        $service->id,

                    'service_package_id' =>
                        $package->id,

                    'event_date' =>
                        $validated['event_date'],

                    'start_time' =>
                        $validated['start_time'],

                    'end_time' =>
                        $validated['end_time'],

                    'event_location' =>
                        $validated['event_location'] ?? null,

                    'customer_notes' =>
                        $validated['customer_notes'] ?? null,

                    /*
                     * Price comes from PostgreSQL,
                     * never from customer input.
                     */
                    'total_amount' =>
                        $package->price,

                    'booking_status' =>
                        'pending',

                    'payment_status' =>
                        'unpaid',
                ]);
            }
        );

        return response()->json([
            'message' =>
                'Booking created successfully.',

            'booking' =>
                $booking->load([
                    'customer:id,name,email',
                    'provider:id,business_name,business_slug',
                    'service:id,name,slug',
                    'package:id,name,slug,price,duration_minutes',
                ]),
        ], 201);
    }

    /**
     * Get all bookings received by the
     * authenticated service provider.
     */
    public function providerIndex(
        Request $request
    ): JsonResponse {
        $user = $request->user();

        if (!$user->hasRole('service_provider')) {
            return response()->json([
                'message' =>
                    'Only service provider accounts can access provider bookings.',
            ], 403);
        }

        $provider =
            $user->serviceProvider()->first();

        if (!$provider) {
            return response()->json([
                'message' =>
                    'Business profile not found.',
            ], 404);
        }

        /*
         * Ownership protection:
         *
         * Only bookings belonging to this provider
         * are returned.
         */
        $bookings = $provider->bookings()
            ->with([
                'customer:id,name,email',
                'service:id,name,slug',
                'package:id,name,slug,price,duration_minutes',
            ])
            ->orderBy('event_date')
            ->orderBy('start_time')
            ->get();

        return response()->json([
            'message' =>
                'Provider bookings loaded successfully.',

            'bookings' =>
                $bookings,
        ]);
    }

    /**
     * Get one booking belonging to the
     * authenticated service provider.
     */
    public function providerShow(
        Request $request,
        int $id
    ): JsonResponse {
        $user = $request->user();

        if (!$user->hasRole('service_provider')) {
            return response()->json([
                'message' =>
                    'Only service provider accounts can access provider bookings.',
            ], 403);
        }

        $provider =
            $user->serviceProvider()->first();

        if (!$provider) {
            return response()->json([
                'message' =>
                    'Business profile not found.',
            ], 404);
        }

        $booking = $provider->bookings()
            ->where(
                'id',
                $id
            )
            ->with([
                'customer:id,name,email',
                'service:id,name,slug,description',
                'package:id,name,slug,description,price,duration_minutes',
            ])
            ->first();

        if (!$booking) {
            return response()->json([
                'message' =>
                    'Booking not found.',
            ], 404);
        }

        return response()->json([
            'message' =>
                'Provider booking loaded successfully.',

            'booking' =>
                $booking,
        ]);
    }

    /**
     * Accept a pending booking.
     */
    public function accept(
        Request $request,
        int $id
    ): JsonResponse {
        $user = $request->user();

        if (!$user->hasRole('service_provider')) {
            return response()->json([
                'message' =>
                    'Only service provider accounts can accept bookings.',
            ], 403);
        }

        $provider =
            $user->serviceProvider()->first();

        if (!$provider) {
            return response()->json([
                'message' =>
                    'Business profile not found.',
            ], 404);
        }

        if (!$provider->is_active) {
            return response()->json([
                'message' =>
                    'Your provider account is inactive and cannot manage bookings.',
            ], 403);
        }

        if (
            $provider->verification_status !==
            'verified'
        ) {
            return response()->json([
                'message' =>
                    'Your business must be verified before managing bookings.',
            ], 403);
        }

        /*
         * Provider can only find their own booking.
         */
        $booking = $provider->bookings()
            ->where(
                'id',
                $id
            )
            ->first();

        if (!$booking) {
            return response()->json([
                'message' =>
                    'Booking not found.',
            ], 404);
        }

        /*
         * Only a pending booking can be accepted.
         */
        if (
            $booking->booking_status !==
            'pending'
        ) {
            return response()->json([
                'message' =>
                    'Only pending bookings can be accepted.',
            ], 422);
        }

        $validated = $request->validate([
            'provider_notes' => [
                'nullable',
                'string',
                'max:2000',
            ],
        ]);

        $booking->booking_status =
            'accepted';

        $booking->accepted_at =
            now();

        if (
            array_key_exists(
                'provider_notes',
                $validated
            )
        ) {
            $booking->provider_notes =
                $validated['provider_notes'];
        }

        $booking->save();

        return response()->json([
            'message' =>
                'Booking accepted successfully.',

            'booking' =>
                $booking->load([
                    'customer:id,name,email',
                    'service:id,name,slug',
                    'package:id,name,slug,price,duration_minutes',
                ]),
        ]);
    }

    /**
     * Reject a pending booking.
     */
    public function reject(
        Request $request,
        int $id
    ): JsonResponse {
        $user = $request->user();

        if (!$user->hasRole('service_provider')) {
            return response()->json([
                'message' =>
                    'Only service provider accounts can reject bookings.',
            ], 403);
        }

        $provider =
            $user->serviceProvider()->first();

        if (!$provider) {
            return response()->json([
                'message' =>
                    'Business profile not found.',
            ], 404);
        }

        if (!$provider->is_active) {
            return response()->json([
                'message' =>
                    'Your provider account is inactive and cannot manage bookings.',
            ], 403);
        }

        if (
            $provider->verification_status !==
            'verified'
        ) {
            return response()->json([
                'message' =>
                    'Your business must be verified before managing bookings.',
            ], 403);
        }

        $booking = $provider->bookings()
            ->where(
                'id',
                $id
            )
            ->first();

        if (!$booking) {
            return response()->json([
                'message' =>
                    'Booking not found.',
            ], 404);
        }

        /*
         * Only pending bookings may be rejected.
         */
        if (
            $booking->booking_status !==
            'pending'
        ) {
            return response()->json([
                'message' =>
                    'Only pending bookings can be rejected.',
            ], 422);
        }

        $validated = $request->validate([
            'provider_notes' => [
                'nullable',
                'string',
                'max:2000',
            ],
        ]);

        $booking->booking_status =
            'rejected';

        if (
            array_key_exists(
                'provider_notes',
                $validated
            )
        ) {
            $booking->provider_notes =
                $validated['provider_notes'];
        }

        $booking->save();

        return response()->json([
            'message' =>
                'Booking rejected successfully.',

            'booking' =>
                $booking->load([
                    'customer:id,name,email',
                    'service:id,name,slug',
                    'package:id,name,slug,price,duration_minutes',
                ]),
        ]);
    }

    /**
     * Complete a confirmed booking.
     */
    public function complete(
        Request $request,
        int $id
    ): JsonResponse {
        $user = $request->user();

        if (!$user->hasRole('service_provider')) {
            return response()->json([
                'message' =>
                    'Only service provider accounts can complete bookings.',
            ], 403);
        }

        $provider =
            $user->serviceProvider()->first();

        if (!$provider) {
            return response()->json([
                'message' =>
                    'Business profile not found.',
            ], 404);
        }

        if (!$provider->is_active) {
            return response()->json([
                'message' =>
                    'Your provider account is inactive and cannot manage bookings.',
            ], 403);
        }

        if (
            $provider->verification_status !==
            'verified'
        ) {
            return response()->json([
                'message' =>
                    'Your business must be verified before managing bookings.',
            ], 403);
        }

        /*
         * Ownership protection:
         *
         * Provider can only complete bookings
         * belonging to their own business.
         */
        $booking = $provider->bookings()
            ->where(
                'id',
                $id
            )
            ->first();

        if (!$booking) {
            return response()->json([
                'message' =>
                    'Booking not found.',
            ], 404);
        }

        /*
         * Payment processing will move an accepted
         * booking to confirmed.
         *
         * Only confirmed bookings may be completed.
         */
        if (
            $booking->booking_status !==
            'confirmed'
        ) {
            return response()->json([
                'message' =>
                    'Only confirmed bookings can be completed.',
            ], 422);
        }

        /*
         * Prevent completing a booking before
         * the scheduled event date.
         */
        if ($booking->event_date->isFuture()) {
            return response()->json([
                'message' =>
                    'A booking cannot be completed before its event date.',
            ], 422);
        }

        $booking->booking_status =
            'completed';

        $booking->completed_at =
            now();

        $booking->save();

        return response()->json([
            'message' =>
                'Booking completed successfully.',

            'booking' =>
                $booking->load([
                    'customer:id,name,email',
                    'service:id,name,slug',
                    'package:id,name,slug,price,duration_minutes',
                ]),
        ]);
    }

    /**
     * Generate a unique booking reference.
     *
     * Example:
     *
     * BK-20260918-A1B2C3
     */
    private function generateBookingReference(): string
    {
        do {
            $reference =
                'BK-' .
                now()->format('Ymd') .
                '-' .
                Str::upper(
                    Str::random(6)
                );
        } while (
            Booking::where(
                'booking_reference',
                $reference
            )->exists()
        );

        return $reference;
    }
}
