<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminServiceResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'name' => $this->name, 'slug' => $this->slug, 'description' => $this->description,
            'status' => $this->status, 'is_featured' => $this->is_featured,
            'created_at' => $this->created_at, 'updated_at' => $this->updated_at,
            'packages_count' => (int) ($this->packages_count ?? 0), 'reviews_count' => (int) ($this->reviews_count ?? 0),
            'average_rating' => $this->reviews_avg_rating === null ? null : round((float) $this->reviews_avg_rating, 2),
            'provider' => $this->whenLoaded('provider', fn (): array => ['id' => $this->provider->id, 'business_name' => $this->provider->business_name, 'business_slug' => $this->provider->business_slug, 'verification_status' => $this->provider->verification_status, 'is_active' => $this->provider->is_active]),
            'category' => $this->whenLoaded('category', fn (): array => ['id' => $this->category->id, 'name' => $this->category->name, 'slug' => $this->category->slug, 'is_active' => $this->category->is_active]),
        ];
    }
}
