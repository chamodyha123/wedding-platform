<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceProvider extends Model
{
    use HasFactory;

    /**
     * Mass assignable attributes.
     */
    protected $fillable = [
        'user_id',

        'business_name',
        'business_slug',

        'description',

        'phone',
        'whatsapp',
        'email',
        'website',

        'address',
        'city',
        'district',

        'latitude',
        'longitude',

        'logo',
        'cover_image',

        'verification_status',
        'verification_notes',

        'verified_at',
        'verified_by',

        'is_active',
    ];

    /**
     * Attribute casts.
     */
    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',

            'longitude' => 'decimal:7',

            'verified_at' => 'datetime',

            'is_active' => 'boolean',
        ];
    }

    /**
     * The user who owns this provider profile.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(
            User::class
        );
    }

    /**
     * Provider verification history.
     */
    public function verificationHistory(): HasMany
    {
        return $this->hasMany(
            ProviderVerificationHistory::class,
            'service_provider_id'
        )->latest();
    }

    /**
     * Admin who verified the provider.
     */
    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'verified_by'
        );
    }

    /**
     * Categories belonging to this provider.
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(
            ServiceCategory::class,
            'provider_categories'
        );
    }

    /**
     * Services created by this provider.
     */
    public function services(): HasMany
    {
        return $this->hasMany(
            Service::class,
            'service_provider_id'
        );
    }
}