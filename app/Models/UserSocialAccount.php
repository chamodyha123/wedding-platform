<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserSocialAccount extends Model
{
    /**
     * Mass assignable attributes.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'provider',
        'provider_user_id',
    ];

    /**
     * Local user that owns this external identity.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
