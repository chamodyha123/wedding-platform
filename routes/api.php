<?php

use App\Http\Controllers\Api\Admin\AdminCustomerController;
use App\Http\Controllers\Api\Admin\AdminDashboardController;
use App\Http\Controllers\Api\Admin\ProviderVerificationController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\MarketplaceController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\ServiceAvailabilityController;
use App\Http\Controllers\Api\ServiceController;
use App\Http\Controllers\Api\ServicePackageController;
use App\Http\Controllers\Api\ServiceProviderController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Authentication Routes
|--------------------------------------------------------------------------
*/

Route::prefix('auth')->group(function () {

    Route::post('/register', [
        AuthController::class,
        'register',
    ])->middleware('throttle:register');

    Route::post('/login', [
        AuthController::class,
        'login',
    ])->middleware('throttle:login');

    Route::middleware('auth:sanctum')->group(function () {

        Route::get('/me', [
            AuthController::class,
            'me',
        ]);

        Route::post('/logout', [
            AuthController::class,
            'logout',
        ]);
    });
});

/*
|--------------------------------------------------------------------------
| Public Marketplace Routes
|--------------------------------------------------------------------------
|
| These routes are public and read-only.
| They do not require authentication or provider/admin roles.
|
*/

Route::prefix('marketplace')->group(function () {

    Route::get('/categories', [
        MarketplaceController::class,
        'categories',
    ]);

    Route::get('/providers', [
        MarketplaceController::class,
        'providers',
    ]);

    Route::get('/providers/{slug}', [
        MarketplaceController::class,
        'provider',
    ]);

    Route::get('/services', [
        MarketplaceController::class,
        'services',
    ]);

    Route::get('/services/{slug}/reviews', [
        MarketplaceController::class,
        'serviceReviews',
    ]);

    Route::get('/services/{slug}', [
        MarketplaceController::class,
        'service',
    ]);
});

/*
|--------------------------------------------------------------------------
| Customer Routes
|--------------------------------------------------------------------------
*/

Route::middleware([
    'auth:sanctum',
    'role:customer',
])
    ->prefix('customer')
    ->group(function () {

        /*
        |--------------------------------------------------------------------------
        | Customer Bookings
        |--------------------------------------------------------------------------
        */

        Route::get('/bookings', [
            BookingController::class,
            'index',
        ]);

        Route::post('/bookings', [
            BookingController::class,
            'store',
        ]);

        Route::get('/bookings/{id}', [
            BookingController::class,
            'show',
        ]);

        Route::post('/bookings/{id}/cancel', [
            BookingController::class,
            'cancel',
        ]);

        /*
        |--------------------------------------------------------------------------
        | Customer Reviews
        |--------------------------------------------------------------------------
        */

        Route::post('/bookings/{bookingId}/reviews', [
            ReviewController::class,
            'store',
        ]);

        Route::get('/reviews', [
            ReviewController::class,
            'index',
        ]);

        Route::get('/reviews/{id}', [
            ReviewController::class,
            'show',
        ]);

        Route::put('/reviews/{id}', [
            ReviewController::class,
            'update',
        ]);

        Route::delete('/reviews/{id}', [
            ReviewController::class,
            'destroy',
        ]);

        /*
        |--------------------------------------------------------------------------
        | Customer Payments
        |--------------------------------------------------------------------------
        */

        Route::get('/payments', [
            PaymentController::class,
            'index',
        ]);

        Route::get('/payments/{id}', [
            PaymentController::class,
            'show',
        ]);

        Route::post('/bookings/{bookingId}/payments', [
            PaymentController::class,
            'store',
        ]);

        Route::post(
            '/bookings/{bookingId}/payments/{paymentId}/success',
            [
                PaymentController::class,
                'success',
            ]
        );

        Route::post(
            '/bookings/{bookingId}/payments/{paymentId}/fail',
            [
                PaymentController::class,
                'fail',
            ]
        );

        Route::post(
            '/bookings/{bookingId}/payments/{paymentId}/cancel',
            [
                PaymentController::class,
                'cancel',
            ]
        );
    });

/*
|--------------------------------------------------------------------------
| Service Provider Routes
|--------------------------------------------------------------------------
*/

Route::middleware([
    'auth:sanctum',
    'role:service_provider',
])
    ->prefix('provider')
    ->group(function () {

        /*
        |--------------------------------------------------------------------------
        | Provider Profile
        |--------------------------------------------------------------------------
        */

        Route::post('/profile', [
            ServiceProviderController::class,
            'store',
        ]);

        Route::get('/profile', [
            ServiceProviderController::class,
            'show',
        ]);

        Route::put('/profile', [
            ServiceProviderController::class,
            'update',
        ]);

        /*
        |--------------------------------------------------------------------------
        | Provider Categories
        |--------------------------------------------------------------------------
        */

        Route::get('/categories', [
            ServiceProviderController::class,
            'categories',
        ]);

        Route::put('/categories', [
            ServiceProviderController::class,
            'updateCategories',
        ]);

        /*
        |--------------------------------------------------------------------------
        | Provider Dashboard
        |--------------------------------------------------------------------------
        */

        Route::get('/dashboard', [
            ServiceProviderController::class,
            'dashboard',
        ]);

        /*
        |--------------------------------------------------------------------------
        | Provider Reviews
        |--------------------------------------------------------------------------
        */

        Route::get('/reviews', [
            ReviewController::class,
            'providerIndex',
        ]);

        Route::get('/reviews/{id}', [
            ReviewController::class,
            'providerShow',
        ]);

        /*
        |--------------------------------------------------------------------------
        | Provider Payments
        |--------------------------------------------------------------------------
        */

        Route::get('/payments', [
            PaymentController::class,
            'providerIndex',
        ]);

        Route::get('/payments/{id}', [
            PaymentController::class,
            'providerShow',
        ]);

        /*
        |--------------------------------------------------------------------------
        | Provider Bookings
        |--------------------------------------------------------------------------
        */

        Route::get('/bookings', [
            BookingController::class,
            'providerIndex',
        ]);

        Route::get('/bookings/{id}', [
            BookingController::class,
            'providerShow',
        ]);

        Route::post('/bookings/{id}/accept', [
            BookingController::class,
            'accept',
        ]);

        Route::post('/bookings/{id}/reject', [
            BookingController::class,
            'reject',
        ]);

        Route::post('/bookings/{id}/complete', [
            BookingController::class,
            'complete',
        ]);

        /*
        |--------------------------------------------------------------------------
        | Provider Services
        |--------------------------------------------------------------------------
        */

        Route::get('/services', [
            ServiceController::class,
            'index',
        ]);

        Route::get('/services/{id}', [
            ServiceController::class,
            'show',
        ]);

        Route::post('/services', [
            ServiceController::class,
            'store',
        ]);

        Route::put('/services/{id}', [
            ServiceController::class,
            'update',
        ]);

        Route::delete('/services/{id}', [
            ServiceController::class,
            'destroy',
        ]);

        /*
        |--------------------------------------------------------------------------
        | Service Packages
        |--------------------------------------------------------------------------
        */

        Route::get('/services/{serviceId}/packages', [
            ServicePackageController::class,
            'index',
        ]);

        Route::post('/services/{serviceId}/packages', [
            ServicePackageController::class,
            'store',
        ]);

        Route::get(
            '/services/{serviceId}/packages/{packageId}',
            [
                ServicePackageController::class,
                'show',
            ]
        );

        Route::put(
            '/services/{serviceId}/packages/{packageId}',
            [
                ServicePackageController::class,
                'update',
            ]
        );

        Route::delete(
            '/services/{serviceId}/packages/{packageId}',
            [
                ServicePackageController::class,
                'destroy',
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | Service Availability
        |--------------------------------------------------------------------------
        */

        Route::get(
            '/services/{serviceId}/availability',
            [
                ServiceAvailabilityController::class,
                'index',
            ]
        );

        Route::post(
            '/services/{serviceId}/availability',
            [
                ServiceAvailabilityController::class,
                'store',
            ]
        );

        Route::put(
            '/services/{serviceId}/availability/{availabilityId}',
            [
                ServiceAvailabilityController::class,
                'update',
            ]
        );

        Route::delete(
            '/services/{serviceId}/availability/{availabilityId}',
            [
                ServiceAvailabilityController::class,
                'destroy',
            ]
        );
    });

/*
|--------------------------------------------------------------------------
| Admin Provider Verification Routes
|--------------------------------------------------------------------------
*/

Route::middleware([
    'auth:sanctum',
    'role:admin',
])
    ->prefix('admin/providers')
    ->group(function () {

        Route::get('/pending', [
            ProviderVerificationController::class,
            'pending',
        ]);

        Route::get('/{id}', [
            ProviderVerificationController::class,
            'show',
        ]);

        Route::post('/{id}/approve', [
            ProviderVerificationController::class,
            'approve',
        ]);

        Route::post('/{id}/reject', [
            ProviderVerificationController::class,
            'reject',
        ]);

        Route::post('/{id}/request-changes', [
            ProviderVerificationController::class,
            'requestChanges',
        ]);

        Route::post('/{id}/suspend', [
            ProviderVerificationController::class,
            'suspend',
        ]);
    });

/*
|--------------------------------------------------------------------------
| Admin Payment Routes
|--------------------------------------------------------------------------
*/

Route::middleware([
    'auth:sanctum',
    'role:admin',
])
    ->prefix('admin')
    ->group(function () {

        Route::get('/dashboard', [
            AdminDashboardController::class,
            'dashboard',
        ]);

        Route::get('/customers', [AdminCustomerController::class, 'index']);
        Route::get('/customers/{id}', [AdminCustomerController::class, 'show']);

        Route::get('/payments', [
            PaymentController::class,
            'adminIndex',
        ]);

        Route::get('/payments/{id}', [
            PaymentController::class,
            'adminShow',
        ]);
    });
