<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Booking extends Model
{
    use HasFactory;

    /**
     * Mass assignable attributes.
     */
    protected $fillable = [
        'booking_reference',

        'customer_id',
        'service_provider_id',
        'service_id',
        'service_package_id',

        'event_date',
        'start_time',
        'end_time',

        'event_location',
        'customer_notes',

        'total_amount',

        'booking_status',
        'payment_status',

        'provider_notes',
        'cancellation_reason',

        'accepted_at',
        'confirmed_at',
        'completed_at',
        'cancelled_at',
    ];

    /**
     * Attribute casts.
     */
    protected function casts(): array
    {
        return [
            'event_date' => 'date',

            'total_amount' => 'decimal:2',

            'accepted_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * Customer who created the booking.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'customer_id'
        );
    }

    /**
     * Service provider receiving the booking.
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(
            ServiceProvider::class,
            'service_provider_id'
        );
    }

    /**
     * Service being booked.
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(
            Service::class,
            'service_id'
        );
    }

    /**
     * Package selected for this booking.
     */
    public function package(): BelongsTo
    {
        return $this->belongsTo(
            ServicePackage::class,
            'service_package_id'
        );
    }
}