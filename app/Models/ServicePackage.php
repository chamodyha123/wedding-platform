<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ServicePackage extends Model
{
    use HasFactory;
    use SoftDeletes;

    /**
     * Mass assignable attributes.
     */
    protected $fillable = [
        'service_id',
        'name',
        'slug',
        'description',
        'price',
        'duration_minutes',
        'status',
        'is_featured',
    ];

    /**
     * Attribute casts.
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'duration_minutes' => 'integer',
            'is_featured' => 'boolean',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * Service that owns this package.
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(
            Service::class,
            'service_id'
        );
    }
}