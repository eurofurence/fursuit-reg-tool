<?php

namespace App\Http\Middleware\API;

use Closure;
use Illuminate\Http\Request;

/**
 * API calls expect bearer token to be set.
 * If invalid the return will be 403 forbidden
 * If valid the actual API request will be called
 * api_middleware_bearer_token might be null if configuration is incomplete
 */
class AuthenticationMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->bearerToken() !== config('api.api_middleware_bearer_token') || config('api.api_middleware_bearer_token') === null) {
            return response()->json([
                'message' => 'Forbidden.',
            ], 403);
        }

        return $next($request);
    }
}
