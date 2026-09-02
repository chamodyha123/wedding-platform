<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ServiceProvider extends Model
{
    use HasFactory;

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

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'verified_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
    public function categories(): BelongsToMany
{
    return $this->belongsToMany(
        ServiceCategory::class,
        'provider_categories'
    );
}
}