<?php

namespace App\Http\Controllers\Api;

use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;

class FacebookAuthController extends GoogleAuthController
{
    protected const Provider = 'facebook';

    /**
     * Configure Facebook with only the email permission required for registration.
     */
    protected function socialDriver(): Provider
    {
        return Socialite::driver(static::Provider)->setScopes(['email']);
    }

    /**
     * Meta does not provide a Google-equivalent verified-email claim for this flow.
     *
     * @param  array<string, mixed>  $identity
     */
    protected function emailIsVerified(array $identity): bool
    {
        return false;
    }

    /**
     * Return the provider name for controlled user-facing responses.
     */
    protected function providerLabel(): string
    {
        return 'Facebook';
    }
}
