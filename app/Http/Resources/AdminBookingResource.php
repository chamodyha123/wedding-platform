<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminBookingResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'booking_reference' => $this->booking_reference, 'booking_status' => $this->booking_status,
            'payment_status' => $this->payment_status, 'event_date' => $this->event_date, 'start_time' => $this->start_time,
            'end_time' => $this->end_time, 'event_location' => $this->event_location, 'total_amount' => $this->total_amount,
            'accepted_at' => $this->accepted_at, 'confirmed_at' => $this->confirmed_at, 'completed_at' => $this->completed_at, 'cancelled_at' => $this->cancelled_at,
            'created_at' => $this->created_at, 'updated_at' => $this->updated_at,
            'customer' => $this->whenLoaded('customer', fn (): array => ['id' => $this->customer->id, 'name' => $this->customer->name, 'email' => $this->customer->email]),
            'provider' => $this->whenLoaded('provider', fn (): array => ['id' => $this->provider->id, 'business_name' => $this->provider->business_name, 'business_slug' => $this->provider->business_slug]),
            'service' => $this->whenLoaded('service', fn (): array => ['id' => $this->service->id, 'name' => $this->service->name, 'slug' => $this->service->slug]),
            'package' => $this->whenLoaded('package', fn (): ?array => $this->package ? ['id' => $this->package->id, 'name' => $this->package->name, 'slug' => $this->package->slug, 'price' => $this->package->price, 'duration_minutes' => $this->package->duration_minutes] : null),
            'payments' => $this->whenLoaded('payments', fn (): array => $this->payments->map(fn ($payment): array => ['id' => $payment->id, 'amount' => $payment->amount, 'currency' => $payment->currency, 'status' => $payment->status, 'payment_method' => $payment->payment_method, 'paid_at' => $payment->paid_at])->all()),
            'review' => $this->whenLoaded('review', fn (): ?array => $this->review ? ['id' => $this->review->id, 'rating' => $this->review->rating, 'comment' => $this->review->comment, 'created_at' => $this->review->created_at] : null),
        ];
    }
}
