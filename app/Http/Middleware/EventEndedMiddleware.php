<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EventEndedMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        // Check if there is an event that did not end yet
        $event = \App\Models\Event::where('ends_at', '>', now())->orderBy('starts_at')->first();
        if (!$event) {
            return redirect()->route('event_ended');
        }
        return $next($request);
    }
}
