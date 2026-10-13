<?php

use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\RepDayController;
use App\Http\Middleware\AuthenticateDevice;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Device (rep's phone) write path.
    Route::post('locations', [LocationController::class, 'store'])
        ->middleware([AuthenticateDevice::class, 'throttle:device']);

    // Management read path.
    Route::get('reps', [RepDayController::class, 'reps']);
    Route::prefix('reps/{rep}/days')->group(function () {
        Route::get('/', [RepDayController::class, 'index']);
        Route::get('{date}', [RepDayController::class, 'show']);
        Route::get('{date}/track', [RepDayController::class, 'track']);
        Route::get('{date}/points', [RepDayController::class, 'points']);
        Route::post('{date}/simulate', [RepDayController::class, 'simulate']);
    });
});
