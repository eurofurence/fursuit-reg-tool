<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EventEndedMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        // Check if there is an active event
        $event = \App\Models\Event::getActiveEvent();
        if (!$event) {
            // Allow all badge routes to proceed - let the controllers handle authorization
            if (str_starts_with($request->route()->getName(), 'badges.')) {
                return $next($request);
            }
            // For auth routes, allow them to proceed
            if (str_starts_with($request->route()->getName(), 'auth.')) {
                return $next($request);
            }
            // For other routes, redirect to welcome
            return redirect()->route('welcome');
        }
        return $next($request);
    }
}
