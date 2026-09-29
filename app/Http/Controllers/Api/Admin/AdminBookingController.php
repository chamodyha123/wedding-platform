<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminBookingResource;
use App\Models\Booking;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminBookingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'booking_status' => ['nullable', 'string', 'in:pending,accepted,rejected,confirmed,completed,cancelled'],
            'customer_id' => ['nullable', 'integer', 'exists:users,id'],
            'provider_id' => ['nullable', 'integer', 'exists:service_providers,id'],
            'service_id' => ['nullable', 'integer', 'exists:services,id'],
            'event_date' => ['nullable', 'date'],
            'event_date_from' => ['nullable', 'date'],
            'event_date_to' => ['nullable', 'date', 'after_or_equal:event_date_from'],
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $bookings = $this->query()
            ->when($validated['booking_status'] ?? null, fn ($q, $value) => $q->where('booking_status', $value))
            ->when($validated['customer_id'] ?? null, fn ($q, $value) => $q->where('customer_id', $value))
            ->when($validated['provider_id'] ?? null, fn ($q, $value) => $q->where('service_provider_id', $value))
            ->when($validated['service_id'] ?? null, fn ($q, $value) => $q->where('service_id', $value))
            ->when($validated['event_date'] ?? null, fn ($q, $value) => $q->whereDate('event_date', $value))
            ->when($validated['event_date_from'] ?? null, fn ($q, $value) => $q->whereDate('event_date', '>=', $value))
            ->when($validated['event_date_to'] ?? null, fn ($q, $value) => $q->whereDate('event_date', '<=', $value))
            ->when($validated['search'] ?? null, fn ($q, $value) => $q->where('booking_reference', 'like', '%'.$value.'%'))
            ->latest('created_at')->latest('id')->paginate($validated['per_page'] ?? 15);

        return response()->json(['bookings' => AdminBookingResource::collection($bookings), 'pagination' => $this->pagination($bookings)]);
    }

    public function show(int $id): JsonResponse
    {
        $booking = $this->query()->with(['payments', 'review'])->find($id);
        if (! $booking) {
            return response()->json(['message' => 'Booking not found.'], 404);
        }

        return response()->json(['booking' => new AdminBookingResource($booking)]);
    }

    private function query()
    {
        return Booking::query()->with(['customer:id,name,email', 'provider:id,business_name,business_slug', 'service:id,name,slug', 'package:id,name,slug,price,duration_minutes']);
    }

    private function pagination($bookings): array
    {
        return ['current_page' => $bookings->currentPage(), 'per_page' => $bookings->perPage(), 'total' => $bookings->total(), 'last_page' => $bookings->lastPage()];
    }
}
