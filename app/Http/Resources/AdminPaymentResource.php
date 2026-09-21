<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminPaymentResource extends JsonResource
{
    /**
     * Transform the payment for admin-facing API responses.
     *
     * Sensitive gateway internals such as metadata and
     * gateway_transaction_id are intentionally excluded.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'payment_reference' => $this->payment_reference,
            'booking_id' => $this->booking_id,
            'customer_id' => $this->customer_id,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'payment_method' => $this->payment_method,
            'status' => $this->status,
            'gateway' => $this->gateway,
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
                    'service_provider_id' => $this->booking->service_provider_id,
                    'service_id' => $this->booking->service_id,
                    'service_package_id' => $this->booking->service_package_id,
                    'event_date' => $this->booking->event_date,
                    'start_time' => $this->booking->start_time,
                    'end_time' => $this->booking->end_time,
                    'event_location' => $this->booking->event_location,
                    'total_amount' => $this->booking->total_amount,
                    'booking_status' => $this->booking->booking_status,
                    'payment_status' => $this->booking->payment_status,
                    'confirmed_at' => $this->booking->confirmed_at,
                    'completed_at' => $this->booking->completed_at,
                    'cancelled_at' => $this->booking->cancelled_at,

                    'customer' => $this->booking->relationLoaded('customer') && $this->booking->customer
                        ? [
                            'id' => $this->booking->customer->id,
                            'name' => $this->booking->customer->name,
                            'email' => $this->booking->customer->email,
                        ]
                        : null,

                    'provider' => $this->booking->relationLoaded('provider') && $this->booking->provider
                        ? [
                            'id' => $this->booking->provider->id,
                            'business_name' => $this->booking->provider->business_name,
                        ]
                        : null,

                    'service' => $this->booking->relationLoaded('service') && $this->booking->service
                        ? [
                            'id' => $this->booking->service->id,
                            'name' => $this->booking->service->name,
                            'slug' => $this->booking->service->slug,
                        ]
                        : null,

                    'package' => $this->booking->relationLoaded('package') && $this->booking->package
                        ? [
                            'id' => $this->booking->package->id,
                            'name' => $this->booking->package->name,
                            'slug' => $this->booking->package->slug,
                            'price' => $this->booking->package->price,
                            'duration_minutes' => $this->booking->package->duration_minutes,
                        ]
                        : null,
                ];
            }),
        ];
    }
}