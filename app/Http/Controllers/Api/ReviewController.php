<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Review;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReviewController extends Controller
{
    /**
     * List reviews submitted by the authenticated customer.
     */
    public function index(Request $request): JsonResponse
    {
        $reviews = Review::query()
            ->where('customer_id', $request->user()->id)
            ->with([
                'customer:id,name',
                'service:id,name,slug',
                'provider:id,business_name,business_slug',
            ])
            ->latest()
            ->get();

        return response()->json([
            'reviews' => $reviews->map(
                fn (Review $review): array => $this->reviewData($review)
            ),
        ]);
    }

    /**
     * Show one review belonging to the authenticated customer.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $review = $this->findCustomerReview(
            $request,
            $id
        );

        $review->load([
            'customer:id,name',
            'service:id,name,slug',
            'provider:id,business_name,business_slug',
        ]);

        return response()->json([
            'review' => $this->reviewData($review),
        ]);
    }

    /**
     * Create a review for a completed booking.
     */
    public function store(Request $request, int $bookingId): JsonResponse
    {
        $validated = $request->validate([
            'rating' => [
                'required',
                'integer',
                'between:1,5',
            ],
            'comment' => [
                'nullable',
                'string',
                'max:2000',
            ],
        ]);

        try {
            $review = DB::transaction(function () use (
                $request,
                $bookingId,
                $validated
            ): Review {
                $booking = Booking::query()
                    ->whereKey($bookingId)
                    ->lockForUpdate()
                    ->first();

                if (! $booking) {
                    abort(404, 'Booking not found.');
                }

                if ((int) $booking->customer_id !== (int) $request->user()->id) {
                    abort(404, 'Booking not found.');
                }

                if ($booking->booking_status !== 'completed') {
                    throw ValidationException::withMessages([
                        'booking' => [
                            'Only completed bookings can be reviewed.',
                        ],
                    ]);
                }

                if (
                    Review::query()
                        ->where('booking_id', $booking->id)
                        ->exists()
                ) {
                    abort(409, 'This booking has already been reviewed.');
                }

                return Review::query()->create([
                    'booking_id' => $booking->id,
                    'customer_id' => $booking->customer_id,
                    'service_provider_id' => $booking->service_provider_id,
                    'service_id' => $booking->service_id,
                    'rating' => $validated['rating'],
                    'comment' => $validated['comment'] ?? null,
                ]);
            });
        } catch (QueryException $exception) {
            /*
             * The database also has a unique constraint on booking_id.
             * This handles concurrent requests attempting to review
             * the same booking.
             */
            if ($this->isUniqueConstraintViolation($exception)) {
                return response()->json([
                    'message' => 'This booking has already been reviewed.',
                ], 409);
            }

            throw $exception;
        }

        $review->load([
            'customer:id,name',
            'service:id,name,slug',
            'provider:id,business_name,business_slug',
        ]);

        return response()->json([
            'message' => 'Review submitted successfully.',
            'review' => $this->reviewData($review),
        ], 201);
    }

    /**
     * Update a review belonging to the authenticated customer.
     */
    public function update(
        Request $request,
        int $id
    ): JsonResponse {
        $validated = $request->validate([
            'rating' => [
                'sometimes',
                'required',
                'integer',
                'between:1,5',
            ],
            'comment' => [
                'sometimes',
                'nullable',
                'string',
                'max:2000',
            ],
        ]);

        if (empty($validated)) {
            throw ValidationException::withMessages([
                'review' => [
                    'At least one of rating or comment must be provided.',
                ],
            ]);
        }

        $review = DB::transaction(function () use (
            $request,
            $id,
            $validated
        ): Review {
            $review = Review::query()
                ->whereKey($id)
                ->where('customer_id', $request->user()->id)
                ->lockForUpdate()
                ->first();

            if (! $review) {
                abort(404, 'Review not found.');
            }

            $review->update($validated);

            return $review;
        });

        $review->load([
            'customer:id,name',
            'service:id,name,slug',
            'provider:id,business_name,business_slug',
        ]);

        return response()->json([
            'message' => 'Review updated successfully.',
            'review' => $this->reviewData($review),
        ]);
    }

    /**
     * Delete a review belonging to the authenticated customer.
     */
    public function destroy(
        Request $request,
        int $id
    ): JsonResponse {
        DB::transaction(function () use (
            $request,
            $id
        ): void {
            $review = Review::query()
                ->whereKey($id)
                ->where('customer_id', $request->user()->id)
                ->lockForUpdate()
                ->first();

            if (! $review) {
                abort(404, 'Review not found.');
            }

            $review->delete();
        });

        return response()->json([
            'message' => 'Review deleted successfully.',
        ]);
    }

    /**
     * Find a review belonging to the authenticated customer.
     */
    private function findCustomerReview(
        Request $request,
        int $id
    ): Review {
        $review = Review::query()
            ->whereKey($id)
            ->where('customer_id', $request->user()->id)
            ->first();

        if (! $review) {
            abort(404, 'Review not found.');
        }

        return $review;
    }

    /**
     * Build the safe API representation of a review.
     */
    private function reviewData(Review $review): array
    {
        return [
            'id' => $review->id,
            'booking_id' => $review->booking_id,
            'customer_id' => $review->customer_id,
            'service_provider_id' => $review->service_provider_id,
            'service_id' => $review->service_id,
            'rating' => $review->rating,
            'comment' => $review->comment,
            'created_at' => $review->created_at,
            'updated_at' => $review->updated_at,

            'customer' => $review->relationLoaded('customer')
                && $review->customer
                ? [
                    'id' => $review->customer->id,
                    'name' => $review->customer->name,
                ]
                : null,

            'service' => $review->relationLoaded('service')
                && $review->service
                ? [
                    'id' => $review->service->id,
                    'name' => $review->service->name,
                    'slug' => $review->service->slug,
                ]
                : null,

            'provider' => $review->relationLoaded('provider')
                && $review->provider
                ? [
                    'id' => $review->provider->id,
                    'business_name' => $review->provider->business_name,
                    'business_slug' => $review->provider->business_slug,
                ]
                : null,
        ];
    }

    /**
     * Determine whether the database exception was caused by
     * a unique constraint violation.
     */
    private function isUniqueConstraintViolation(
        QueryException $exception
    ): bool {
        $sqlState = $exception->errorInfo[0] ?? null;

        return in_array(
            $sqlState,
            [
                '23000', // SQLite / MySQL integrity constraint violation
                '23505', // PostgreSQL unique violation
            ],
            true
        );
    }
}