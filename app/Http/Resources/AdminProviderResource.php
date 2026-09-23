<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminProviderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'business_name' => $this->business_name, 'business_slug' => $this->business_slug,
            'email' => $this->email, 'phone' => $this->phone, 'city' => $this->city, 'district' => $this->district,
            'verification_status' => $this->verification_status, 'verification_notes' => $this->verification_notes,
            'verified_at' => $this->verified_at, 'is_active' => $this->is_active,
            'created_at' => $this->created_at, 'updated_at' => $this->updated_at,
            'services_count' => (int) ($this->services_count ?? 0), 'bookings_count' => (int) ($this->bookings_count ?? 0),
            'reviews_count' => (int) ($this->reviews_count ?? 0),
            'average_rating' => $this->reviews_avg_rating === null ? null : round((float) $this->reviews_avg_rating, 2),
            'user' => $this->whenLoaded('user', fn (): array => ['id' => $this->user->id, 'name' => $this->user->name, 'email' => $this->user->email]),
            'categories' => $this->whenLoaded('categories', fn (): array => $this->categories->map(fn ($category): array => ['id' => $category->id, 'name' => $category->name, 'slug' => $category->slug])->all()),
        ];
    }
}
