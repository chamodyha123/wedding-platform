<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminCategoryResource extends JsonResource
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
            'is_active' => $this->is_active, 'created_at' => $this->created_at, 'updated_at' => $this->updated_at,
            'services_count' => (int) ($this->services_count ?? 0),
        ];
    }
}
