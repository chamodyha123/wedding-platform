<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Models\ServiceAvailability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ServiceAvailabilityController extends Controller
{
    /**
     * Get all availability records for one of the
     * authenticated provider's services.
     */
    public function index(
        Request $request,
        int $serviceId
    ): JsonResponse {
        $user = $request->user();

        if (! $user->hasRole('service_provider')) {
            return response()->json([
                'message' => 'Only service provider accounts can access service availability.',
            ], 403);
        }

        $provider = $user->serviceProvider()->first();

        if (! $provider) {
            return response()->json([
                'message' => 'Business profile not found. Please create your business profile first.',
            ], 404);
        }

        /*
         * Ownership protection:
         * only search inside this provider's services.
         */
        $service = $provider->services()
            ->where('id', $serviceId)
            ->first();

        if (! $service) {
            return response()->json([
                'message' => 'Service not found.',
            ], 404);
        }

        $availability = $service->availabilities()
            ->orderBy('date')
            ->orderBy('start_time')
            ->get();

        return response()->json([
            'service' => [
                'id' => $service->id,
                'name' => $service->name,
                'slug' => $service->slug,
            ],

            'availability' => $availability,
        ]);
    }

    /**
     * Create a new availability record.
     */
    public function store(
        Request $request,
        int $serviceId
    ): JsonResponse {
        $user = $request->user();

        if (! $user->hasRole('service_provider')) {
            return response()->json([
                'message' => 'Only service provider accounts can create service availability.',
            ], 403);
        }

        $provider = $user->serviceProvider()->first();

        if (! $provider) {
            return response()->json([
                'message' => 'Business profile not found. Please create your business profile first.',
            ], 404);
        }

        if (! $provider->is_active) {
            return response()->json([
                'message' => 'Your provider account is inactive and cannot manage availability.',
            ], 403);
        }

        if ($provider->verification_status !== 'verified') {
            return response()->json([
                'message' => 'Your business must be verified before you can manage availability.',
            ], 403);
        }

        /*
         * Ownership protection.
         */
        $service = $provider->services()
            ->where('id', $serviceId)
            ->first();

        if (! $service) {
            return response()->json([
                'message' => 'Service not found.',
            ], 404);
        }

        $validated = $request->validate([
            'date' => [
                'required',
                'date',
                'after_or_equal:today',
            ],

            'start_time' => [
                'nullable',
                'date_format:H:i',
                'required_with:end_time',
            ],

            'end_time' => [
                'nullable',
                'date_format:H:i',
                'required_with:start_time',
                'after:start_time',
            ],

            'status' => [
                'required',
                'string',
                'in:available,unavailable,blocked,booked',
            ],

            'notes' => [
                'nullable',
                'string',
                'max:1000',
            ],
        ]);

        $availability = DB::transaction(function () use (
            $provider,
            $service,
            $validated
        ) {
            ServiceProvider::query()
                ->where('id', $provider->id)
                ->lockForUpdate()
                ->first();

            Service::query()
                ->where('id', $service->id)
                ->lockForUpdate()
                ->first();

            /*
             * Prevent duplicate availability slots.
             *
             * Same service + date + start + end
             * should not exist twice.
             */
            $duplicateExists = $service->availabilities()
                ->whereDate(
                    'date',
                    $validated['date']
                )
                ->where(
                    'start_time',
                    $validated['start_time'] ?? null
                )
                ->where(
                    'end_time',
                    $validated['end_time'] ?? null
                )
                ->exists();

            if ($duplicateExists) {
                return response()->json([
                    'message' => 'An availability record already exists for this service, date and time range.',
                ], 409);
            }
            ServiceProvider::query()
                ->where('id', $provider->id)
                ->lockForUpdate()
                ->first();

            /*
             * Check for overlapping time slots.
             *
             * We only perform this check when both
             * start_time and end_time are supplied.
             */
            if (
                ! empty($validated['start_time']) &&
                ! empty($validated['end_time'])
            ) {
                $overlapExists = $service->availabilities()
                    ->whereDate(
                        'date',
                        $validated['date']
                    )
                    ->whereNotNull('start_time')
                    ->whereNotNull('end_time')
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

                if ($overlapExists) {
                    return response()->json([
                        'message' => 'This availability time overlaps with an existing availability record.',
                    ], 422);
                }
            }

            /*
             * Full-day records use NULL start/end times.
             *
             * Do not allow a full-day record when any
             * other availability already exists for that date.
             */
            if (
                empty($validated['start_time']) &&
                empty($validated['end_time'])
            ) {
                $dateAlreadyHasAvailability =
                    $service->availabilities()
                        ->whereDate(
                            'date',
                            $validated['date']
                        )
                        ->exists();

                if ($dateAlreadyHasAvailability) {
                    return response()->json([
                        'message' => 'This date already contains availability records. Remove them before creating a full-day availability record.',
                    ], 422);
                }
            }

            /*
             * Do not allow a time slot when the same date
             * already contains a full-day record.
             */
            if (
                ! empty($validated['start_time']) &&
                ! empty($validated['end_time'])
            ) {
                $fullDayRecordExists =
                    $service->availabilities()
                        ->whereDate(
                            'date',
                            $validated['date']
                        )
                        ->whereNull('start_time')
                        ->whereNull('end_time')
                        ->exists();

                if ($fullDayRecordExists) {
                    return response()->json([
                        'message' => 'This date already has a full-day availability record.',
                    ], 422);
                }
            }

            return ServiceAvailability::create([
                'service_id' => $service->id,

                'date' => $validated['date'],

                'start_time' => $validated['start_time'] ?? null,

                'end_time' => $validated['end_time'] ?? null,

                'status' => $validated['status'],

                'notes' => $validated['notes'] ?? null,
            ]);

        });

        if ($availability instanceof JsonResponse) {
            return $availability;
        }

        return response()->json([
            'message' => 'Service availability created successfully.',

            'availability' => $availability,
        ], 201);
    }

    /**
     * Update an availability record.
     */
    public function update(
        Request $request,
        int $serviceId,
        int $availabilityId
    ): JsonResponse {
        $user = $request->user();

        if (! $user->hasRole('service_provider')) {
            return response()->json([
                'message' => 'Only service provider accounts can update service availability.',
            ], 403);
        }

        $provider = $user->serviceProvider()->first();

        if (! $provider) {
            return response()->json([
                'message' => 'Business profile not found. Please create your business profile first.',
            ], 404);
        }

        if (! $provider->is_active) {
            return response()->json([
                'message' => 'Your provider account is inactive and cannot manage availability.',
            ], 403);
        }

        if ($provider->verification_status !== 'verified') {
            return response()->json([
                'message' => 'Your business must be verified before you can manage availability.',
            ], 403);
        }

        $service = $provider->services()
            ->where('id', $serviceId)
            ->first();

        if (! $service) {
            return response()->json([
                'message' => 'Service not found.',
            ], 404);
        }

        $availability = $service->availabilities()
            ->where(
                'id',
                $availabilityId
            )
            ->first();

        if (! $availability) {
            return response()->json([
                'message' => 'Service availability record not found.',
            ], 404);
        }

        $validated = $request->validate([
            'date' => [
                'sometimes',
                'required',
                'date',
                'after_or_equal:today',
            ],

            'start_time' => [
                'sometimes',
                'nullable',
                'date_format:H:i',
            ],

            'end_time' => [
                'sometimes',
                'nullable',
                'date_format:H:i',
            ],

            'status' => [
                'sometimes',
                'required',
                'string',
                'in:available,unavailable,blocked,booked',
            ],

            'notes' => [
                'sometimes',
                'nullable',
                'string',
                'max:1000',
            ],
        ]);

        /*
         * Build the final values after update so
         * validation can consider existing values too.
         */
        $newDate =
            $validated['date'] ??
            $availability->date->format('Y-m-d');

        $newStartTime =
            array_key_exists('start_time', $validated)
                ? $validated['start_time']
                : $availability->start_time;

        $newEndTime =
            array_key_exists('end_time', $validated)
                ? $validated['end_time']
                : $availability->end_time;

        /*
         * start_time and end_time must either
         * both exist or both be NULL.
         */
        if (
            ($newStartTime === null &&
                $newEndTime !== null) ||
            ($newStartTime !== null &&
                $newEndTime === null)
        ) {
            return response()->json([
                'message' => 'Start time and end time must be provided together.',
            ], 422);
        }

        if (
            $newStartTime !== null &&
            $newEndTime !== null &&
            $newEndTime <= $newStartTime
        ) {
            return response()->json([
                'message' => 'End time must be later than start time.',
            ], 422);
        }

        /*
         * Prevent duplicate records while ignoring
         * the availability record currently being updated.
         */
        $availability = DB::transaction(function () use (
            $provider,
            $service,
            $availability,
            $validated
        ) {
            ServiceProvider::query()
                ->where('id', $provider->id)
                ->lockForUpdate()
                ->first();

            Service::query()
                ->where('id', $service->id)
                ->lockForUpdate()
                ->first();

            $availability = ServiceAvailability::query()
                ->where('id', $availability->id)
                ->lockForUpdate()
                ->first();

            $newDate =
                $validated['date'] ??
                $availability->date->format('Y-m-d');

            $newStartTime =
                array_key_exists('start_time', $validated)
                    ? $validated['start_time']
                    : $availability->start_time;

            $newEndTime =
                array_key_exists('end_time', $validated)
                    ? $validated['end_time']
                    : $availability->end_time;

            $duplicateExists = $service->availabilities()
                ->where(
                    'id',
                    '!=',
                    $availability->id
                )
                ->whereDate(
                    'date',
                    $newDate
                )
                ->where(
                    'start_time',
                    $newStartTime
                )
                ->where(
                    'end_time',
                    $newEndTime
                )
                ->exists();

            if ($duplicateExists) {
                return response()->json([
                    'message' => 'An availability record already exists for this service, date and time range.',
                ], 409);
            }

            /*
             * Check overlapping time slots.
             */
            if (
                $newStartTime !== null &&
                $newEndTime !== null
            ) {
                $overlapExists = $service->availabilities()
                    ->where(
                        'id',
                        '!=',
                        $availability->id
                    )
                    ->whereDate(
                        'date',
                        $newDate
                    )
                    ->whereNotNull('start_time')
                    ->whereNotNull('end_time')
                    ->where(
                        'start_time',
                        '<',
                        $newEndTime
                    )
                    ->where(
                        'end_time',
                        '>',
                        $newStartTime
                    )
                    ->exists();

                if ($overlapExists) {
                    return response()->json([
                        'message' => 'This availability time overlaps with an existing availability record.',
                    ], 422);
                }

                $fullDayRecordExists =
                    $service->availabilities()
                        ->where(
                            'id',
                            '!=',
                            $availability->id
                        )
                        ->whereDate(
                            'date',
                            $newDate
                        )
                        ->whereNull('start_time')
                        ->whereNull('end_time')
                        ->exists();

                if ($fullDayRecordExists) {
                    return response()->json([
                        'message' => 'This date already has a full-day availability record.',
                    ], 422);
                }
            }

            /*
             * Full-day record validation.
             */
            if (
                $newStartTime === null &&
                $newEndTime === null
            ) {
                $dateAlreadyHasAvailability =
                    $service->availabilities()
                        ->where(
                            'id',
                            '!=',
                            $availability->id
                        )
                        ->whereDate(
                            'date',
                            $newDate
                        )
                        ->exists();

                if ($dateAlreadyHasAvailability) {
                    return response()->json([
                        'message' => 'This date already contains other availability records.',
                    ], 422);
                }
            }

            $availability->fill($validated);

            $availability->save();

            return $availability;
        });

        if ($availability instanceof JsonResponse) {
            return $availability;
        }

        return response()->json([
            'message' => 'Service availability updated successfully.',

            'availability' => $availability,
        ]);
    }

    /**
     * Delete an availability record.
     */
    public function destroy(
        Request $request,
        int $serviceId,
        int $availabilityId
    ): JsonResponse {
        $user = $request->user();

        if (! $user->hasRole('service_provider')) {
            return response()->json([
                'message' => 'Only service provider accounts can delete service availability.',
            ], 403);
        }

        $provider = $user->serviceProvider()->first();

        if (! $provider) {
            return response()->json([
                'message' => 'Business profile not found. Please create your business profile first.',
            ], 404);
        }

        if (! $provider->is_active) {
            return response()->json([
                'message' => 'Your provider account is inactive and cannot manage availability.',
            ], 403);
        }

        if ($provider->verification_status !== 'verified') {
            return response()->json([
                'message' => 'Your business must be verified before you can delete service availability.',
            ], 403);
        }

        $service = $provider->services()
            ->where('id', $serviceId)
            ->first();

        if (! $service) {
            return response()->json([
                'message' => 'Service not found.',
            ], 404);
        }

        $availability = $service->availabilities()
            ->where(
                'id',
                $availabilityId
            )
            ->first();

        if (! $availability) {
            return response()->json([
                'message' => 'Service availability record not found.',
            ], 404);
        }

        $availability->delete();

        return response()->json([
            'message' => 'Service availability deleted successfully.',
        ]);
    }
}
