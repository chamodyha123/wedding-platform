<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    /**
     * Transform the payment for customer-facing API responses.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'payment_reference' => $this->payment_reference,
            'booking_id' => $this->booking_id,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'payment_method' => $this->payment_method,
            'status' => $this->status,
            'failure_reason' => $this->failure_reason,
            'paid_at' => $this->paid_at,
            'failed_at' => $this->failed_at,
            'cancelled_at' => $this->cancelled_at,
            'refunded_at' => $this->refunded_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'booking' => $this->whenLoaded('booking', function (): array {
                return [
                    'id' => $this->booking->id,
                    'booking_reference' => $this->booking->booking_reference,
                    'customer_id' => $this->booking->customer_id,
                    'total_amount' => $this->booking->total_amount,
                    'booking_status' => $this->booking->booking_status,
                    'payment_status' => $this->booking->payment_status,
                    'service' => $this->booking->relationLoaded('service') && $this->booking->service ? [
                        'id' => $this->booking->service->id,
                        'name' => $this->booking->service->name,
                        'slug' => $this->booking->service->slug,
                    ] : null,
                    'package' => $this->booking->relationLoaded('package') && $this->booking->package ? [
                        'id' => $this->booking->package->id,
                        'name' => $this->booking->package->name,
                        'slug' => $this->booking->package->slug,
                        'price' => $this->booking->package->price,
                        'duration_minutes' => $this->booking->package->duration_minutes,
                    ] : null,
                ];
            }),
        ];
    }
}
