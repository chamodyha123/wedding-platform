<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use HasFactory;

    /**
     * Mass assignable attributes.
     */
    protected $fillable = [
        'booking_id',
        'customer_id',

        'payment_reference',

        'amount',
        'currency',

        'payment_method',

        'status',

        'gateway',
        'gateway_transaction_id',

        'metadata',
        'failure_reason',

        'paid_at',
        'failed_at',
        'cancelled_at',
        'refunded_at',
    ];

    /**
     * Attribute casts.
     */
    protected function casts(): array
    {
        return [
            'amount' =>
                'decimal:2',

            'metadata' =>
                'array',

            'paid_at' =>
                'datetime',

            'failed_at' =>
                'datetime',

            'cancelled_at' =>
                'datetime',

            'refunded_at' =>
                'datetime',
        ];
    }

    /**
     * Booking this payment belongs to.
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(
            Booking::class,
            'booking_id'
        );
    }

    /**
     * Customer who made this payment.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'customer_id'
        );
    }
}