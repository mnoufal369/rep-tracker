<?php

use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\RepDayController;
use App\Http\Middleware\AuthenticateDevice;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Upload from a rep's phone (authenticated with the device token).
    Route::post('locations', [LocationController::class, 'store'])->middleware(AuthenticateDevice::class);

    // Inspect the daily logs.
    Route::get('reps', [RepDayController::class, 'reps']);
    Route::get('reps/{rep}/days', [RepDayController::class, 'index']);
    Route::get('reps/{rep}/days/{date}', [RepDayController::class, 'show']);
    Route::get('reps/{rep}/days/{date}/points', [RepDayController::class, 'points']);

    // Generate test data.
    Route::post('reps/{rep}/days/{date}/generate', [RepDayController::class, 'generate']);
});
