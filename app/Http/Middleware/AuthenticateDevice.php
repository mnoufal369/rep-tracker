<?php

namespace App\Http\Middleware;

use App\Models\Rep;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Finds the rep by the Bearer token their phone sends. A phone can only upload its own points.
 */
class AuthenticateDevice
{
    public function handle(Request $request, Closure $next): Response
    {
        $rep = $request->bearerToken() ? Rep::findByDeviceToken($request->bearerToken()) : null;

        if (! $rep) {
            return response()->json(['message' => 'Invalid or missing device token.'], 401);
        }

        $request->attributes->set('rep', $rep);

        return $next($request);
    }
}
