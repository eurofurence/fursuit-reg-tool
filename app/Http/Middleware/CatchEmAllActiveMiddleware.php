<?php

namespace App\Http\Middleware;

use App\Models\Event;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class CatchEmAllActiveMiddleware

{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Skip if user is not authenticated
        if (! Auth::check()) {
            return $next($request);
        }

        if ($request->user()->is_admin) {
            return $next($request);
        }

        $currentEvent = Event::latest('starts_at')->first();
        $isActive = $currentEvent?->isCatchEmAllActive();

        if (! $isActive) {
            return redirect()->route('catch-em-all.catch')->with('error', 'The game is closed right now');
        }

        return $next($request);
    }
}
