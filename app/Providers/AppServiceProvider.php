<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Generous: a phone flushing a long offline buffer sends many batches back to back.
        RateLimiter::for('device', fn (Request $request) => Limit::perMinute(120)->by($request->bearerToken() ?: $request->ip()));
    }
}
