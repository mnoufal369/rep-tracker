<?php

namespace App\Http\Middleware;

use App\Models\Rep;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a rep's phone by its Bearer device token and binds the rep to the request,
 * so a device can only ever write its own track.
 */
class AuthenticateDevice
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        $rep = $token ? Rep::findByDeviceToken($token) : null;

        if (! $rep) {
            return response()->json(['message' => 'Invalid or missing device token.'], 401);
        }

        $request->attributes->set('rep', $rep);

        return $next($request);
    }
}
