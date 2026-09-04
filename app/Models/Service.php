<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Service extends Model
{
    use HasFactory;

    /**
     * Mass assignable attributes.
     */
    protected $fillable = [
        'service_provider_id',
        'service_category_id',
        'name',
        'slug',
        'description',
        'status',
        'is_featured',
    ];

    /**
     * Attribute casts.
     */
    protected function casts(): array
    {
        return [
            'is_featured' => 'boolean',
        ];
    }

    /**
     * Provider that owns this service.
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(
            ServiceProvider::class,
            'service_provider_id'
        );
    }

    /**
     * Category this service belongs to.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(
            ServiceCategory::class,
            'service_category_id'
        );
    }
}