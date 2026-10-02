<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('login', function (Request $request): Limit {
            return Limit::perMinute((int) env('LOGIN_RATE_LIMIT', 10))
                ->by(Str::lower((string) $request->input('email')).'|'.$request->ip());
        });

        RateLimiter::for('register', function (Request $request): Limit {
            return Limit::perMinute((int) env('REGISTER_RATE_LIMIT', 5))
                ->by($request->ip());
        });

        RateLimiter::for('verification', function (Request $request): Limit {
            return Limit::perMinute((int) env('EMAIL_VERIFICATION_RATE_LIMIT', 6))
                ->by((string) $request->user()?->getAuthIdentifier());
        });

        RateLimiter::for('password-reset', function (Request $request): Limit {
            return Limit::perMinute((int) env('PASSWORD_RESET_RATE_LIMIT', 5))
                ->by(Str::lower((string) $request->input('email')).'|'.$request->ip());
        });

        RateLimiter::for('password-reset-submission', function (Request $request): Limit {
            return Limit::perMinute((int) env('PASSWORD_RESET_SUBMISSION_RATE_LIMIT', 5))
                ->by(Str::lower((string) $request->input('email')).'|'.$request->ip());
        });
    }
}
