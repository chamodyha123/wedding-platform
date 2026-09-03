<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ServiceProviderController;
use Illuminate\Support\Facades\Route;

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