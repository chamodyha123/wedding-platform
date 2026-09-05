# Wedding Marketplace Backend - Package API verification bundle

The Laravel source was inspected. The package route, Sanctum middleware, login token response, and package ownership checks are structurally correct.

## Improved PowerShell diagnostic

`scripts/test-package-api.ps1` now:

- stops immediately on HTTP failures;
- prints the actual API error response when available;
- prints the last 60 lines of `storage/logs/laravel.log` on a server-side error;
- no longer reports the misleading message "Login succeeded but no token was returned" after a failed login request;
- logs in, stores the Sanctum token in memory, verifies `/api/auth/me`, then creates the package with the same token.

Run Laravel in terminal 1:

    php artisan serve

Run the test in terminal 2:

    powershell -ExecutionPolicy Bypass -File .\scripts\test-package-api.ps1 -ServiceId 2

If a 500 error occurs, the script now exposes the Laravel exception details needed to fix the actual backend cause.
