<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Review extends Model
{
    use HasFactory;

    /**
     * Mass assignable attributes.
     */
    protected $fillable = [
        'booking_id',
        'customer_id',
        'service_provider_id',
        'service_id',
        'rating',
        'comment',
    ];

    /**
     * Attribute casts.
     */
    protected function casts(): array
    {
        return [
            'rating' => 'integer',
        ];
    }

    /**
     * Booking this review belongs to.
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(
            Booking::class,
            'booking_id'
        );
    }

    /**
     * Customer who submitted the review.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'customer_id'
        );
    }

    /**
     * Service provider being reviewed.
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(
            ServiceProvider::class,
            'service_provider_id'
        );
    }

    /**
     * Service being reviewed.
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(
            Service::class,
            'service_id'
        );
    }
}