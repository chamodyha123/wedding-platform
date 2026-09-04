<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProviderVerificationHistory extends Model
{
    use HasFactory;

    protected $fillable = [
        'service_provider_id',
        'admin_id',
        'previous_status',
        'new_status',
        'notes',
    ];

    public function provider(): BelongsTo
    {
        return $this->belongsTo(
            ServiceProvider::class,
            'service_provider_id'
        );
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'admin_id'
        );
    }
}