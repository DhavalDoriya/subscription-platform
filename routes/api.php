<?php

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationController;
use App\Http\Controllers\Api\V1\Auth\PasswordController;
use App\Http\Controllers\Api\V1\HealthController;
use Illuminate\Support\Facades\Route;

// Every route here is served under /api (framework prefix). Groups per audience are added
// phase by phase: public, customer, provider, admin.
Route::prefix('v1')->group(function () {
    Route::get('health', HealthController::class);

    Route::prefix('auth')->group(function () {
        Route::middleware('throttle:20,1')->group(function () {
            Route::post('register', [AuthController::class, 'register']);
            Route::post('login', [AuthController::class, 'login']);
            Route::post('refresh', [AuthController::class, 'refresh']);
            Route::post('password/forgot', [PasswordController::class, 'forgot']);
            Route::post('password/reset', [PasswordController::class, 'reset']);
        });

        Route::get('email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
            ->middleware('signed')->name('verification.verify');

        Route::middleware('auth:api')->group(function () {
            Route::get('me', [AuthController::class, 'me']);
            Route::post('logout', [AuthController::class, 'logout']);
            Route::post('logout-all', [AuthController::class, 'logoutAll']);
            Route::post('email/resend', [EmailVerificationController::class, 'resend'])->middleware('throttle:6,1');
        });
    });
});
