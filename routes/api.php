<?php

use App\Http\Controllers\Api\HealthCheckController;
use App\Http\Controllers\Api\LicenseActivationController;
use App\Http\Controllers\Api\LicenseVerificationController;
use App\Http\Middleware\AuthenticateApplicationApiKey;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

Route::get('/health', [HealthCheckController::class, 'check'])->name('api.health');

Route::prefix('v1')->group(function () {
    Route::post('/license/activate', [LicenseActivationController::class, 'activate'])
        ->middleware([
            'throttle:api-activation',
            AuthenticateApplicationApiKey::class,
        ])
        ->name('api.v1.license.activate');

    Route::post('/license/verify', [LicenseVerificationController::class, 'verify'])
        ->middleware([
            'throttle:api-verification',
            AuthenticateApplicationApiKey::class,
        ])
        ->name('api.v1.license.verify');
});
