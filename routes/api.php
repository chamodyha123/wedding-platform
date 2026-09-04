<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ServiceProviderController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\Admin\ProviderVerificationController;

Route::prefix('auth')->group(function () {

    Route::post('/register', [AuthController::class, 'register']);

    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});

Route::middleware('auth:sanctum')->prefix('provider')->group(function () {
    Route::post('/profile', [ServiceProviderController::class, 'store']);
    Route::get('/profile', [ServiceProviderController::class, 'show']);
});
Route::middleware(['auth:sanctum', 'role:admin'])
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