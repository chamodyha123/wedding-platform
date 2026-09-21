<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens;
    use HasFactory;
    use Notifiable;
    use HasRoles;

    /**
     * Mass assignable attributes.
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * Hidden attributes.
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Attribute casts.
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Service provider profile belonging to this user.
     */
    public function serviceProvider(): HasOne
    {
        return $this->hasOne(
            ServiceProvider::class
        );
    }

    /**
     * Bookings created by this user as a customer.
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(
            Booking::class,
            'customer_id'
        );
    }

    /**
     * Reviews submitted by this user as a customer.
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(
            Review::class,
            'customer_id'
        );
    }
}