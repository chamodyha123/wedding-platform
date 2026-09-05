<?php

use App\Http\Controllers\Api\Admin\ProviderVerificationController;
use App\Http\Controllers\Api\AuthController;
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

        Route::post('/services/{serviceId}/packages', [
            ServicePackageController::class,
            'store'
        ]);
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