<?php

use App\Http\Controllers\Api\Admin\ProviderVerificationController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BookingController;
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
        'register'
    ]);

    Route::post('/login', [
        AuthController::class,
        'login'
    ]);

    Route::middleware('auth:sanctum')->group(function () {

        Route::get('/me', [
            AuthController::class,
            'me'
        ]);

        Route::post('/logout', [
            AuthController::class,
            'logout'
        ]);
    });
});


/*
|--------------------------------------------------------------------------
| Customer Routes
|--------------------------------------------------------------------------
*/

Route::middleware([
    'auth:sanctum',
    'role:customer'
])
    ->prefix('customer')
    ->group(function () {

        Route::get('/bookings', [
            BookingController::class,
            'index'
        ]);

        Route::post('/bookings', [
            BookingController::class,
            'store'
        ]);

        Route::get('/bookings/{id}', [
            BookingController::class,
            'show'
        ]);
    });


/*
|--------------------------------------------------------------------------
| Service Provider Routes
|--------------------------------------------------------------------------
*/

Route::middleware([
    'auth:sanctum',
    'role:service_provider'
])
    ->prefix('provider')
    ->group(function () {

        /*
        |--------------------------------------------------------------------------
        | Provider Business Profile
        |--------------------------------------------------------------------------
        */

        Route::post('/profile', [
            ServiceProviderController::class,
            'store'
        ]);

        Route::get('/profile', [
            ServiceProviderController::class,
            'show'
        ]);

        Route::put('/profile', [
            ServiceProviderController::class,
            'update'
        ]);

        /*
        |--------------------------------------------------------------------------
        | Provider Categories
        |--------------------------------------------------------------------------
        */

        Route::get('/categories', [
            ServiceProviderController::class,
            'categories'
        ]);

        Route::put('/categories', [
            ServiceProviderController::class,
            'updateCategories'
        ]);

        /*
        |--------------------------------------------------------------------------
        | Provider Dashboard
        |--------------------------------------------------------------------------
        */

        Route::get('/dashboard', [
            ServiceProviderController::class,
            'dashboard'
        ]);

        /*
        |--------------------------------------------------------------------------
        | Provider Bookings
        |--------------------------------------------------------------------------
        */

        Route::get('/bookings', [
            BookingController::class,
            'providerIndex'
        ]);

        Route::get('/bookings/{id}', [
            BookingController::class,
            'providerShow'
        ]);

        Route::post('/bookings/{id}/accept', [
            BookingController::class,
            'accept'
        ]);

        Route::post('/bookings/{id}/reject', [
            BookingController::class,
            'reject'
        ]);

        /*
        |--------------------------------------------------------------------------
        | Provider Services
        |--------------------------------------------------------------------------
        */

        Route::get('/services', [
            ServiceController::class,
            'index'
        ]);

        Route::get('/services/{id}', [
            ServiceController::class,
            'show'
        ]);

        Route::post('/services', [
            ServiceController::class,
            'store'
        ]);

        Route::put('/services/{id}', [
            ServiceController::class,
            'update'
        ]);

        Route::delete('/services/{id}', [
            ServiceController::class,
            'destroy'
        ]);

        /*
        |--------------------------------------------------------------------------
        | Service Packages
        |--------------------------------------------------------------------------
        */

        Route::get('/services/{serviceId}/packages', [
            ServicePackageController::class,
            'index'
        ]);

        Route::post('/services/{serviceId}/packages', [
            ServicePackageController::class,
            'store'
        ]);

        Route::get(
            '/services/{serviceId}/packages/{packageId}',
            [
                ServicePackageController::class,
                'show'
            ]
        );

        Route::put(
            '/services/{serviceId}/packages/{packageId}',
            [
                ServicePackageController::class,
                'update'
            ]
        );

        Route::delete(
            '/services/{serviceId}/packages/{packageId}',
            [
                ServicePackageController::class,
                'destroy'
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
                'index'
            ]
        );

        Route::post(
            '/services/{serviceId}/availability',
            [
                ServiceAvailabilityController::class,
                'store'
            ]
        );

        Route::put(
            '/services/{serviceId}/availability/{availabilityId}',
            [
                ServiceAvailabilityController::class,
                'update'
            ]
        );

        Route::delete(
            '/services/{serviceId}/availability/{availabilityId}',
            [
                ServiceAvailabilityController::class,
                'destroy'
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
    'role:admin'
])
    ->prefix('admin/providers')
    ->group(function () {

        Route::get('/pending', [
            ProviderVerificationController::class,
            'pending'
        ]);

        Route::get('/{id}', [
            ProviderVerificationController::class,
            'show'
        ]);

        Route::post('/{id}/approve', [
            ProviderVerificationController::class,
            'approve'
        ]);

        Route::post('/{id}/reject', [
            ProviderVerificationController::class,
            'reject'
        ]);

        Route::post('/{id}/request-changes', [
            ProviderVerificationController::class,
            'requestChanges'
        ]);

        Route::post('/{id}/suspend', [
            ProviderVerificationController::class,
            'suspend'
        ]);
    });