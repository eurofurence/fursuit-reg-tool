<?php

namespace App\Http\Middleware;

use App\Models\Event;
use App\Support\LoginRedirect;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class CatchEmAllIntroductionMiddleware
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

        // Skip if this is the introduction route itself
        if ($request->routeIs('catch-em-all.introduction') || $request->routeIs('catch-em-all.introduction.complete')) {
            return $next($request);
        }

        $user = Auth::user();
        $currentEvent = Event::latest('starts_at')->first();

        // Skip if no current event
        if (! $currentEvent) {
            return $next($request);
        }

        // Check if user has been introduced to catch-em-all for this event
        $eventUser = $user->eventUsers()->where('event_id', $currentEvent->id)->first();

        // Log for debugging
        \Log::info('Introduction middleware check', [
            'user_id' => $user->id,
            'event_id' => $currentEvent->id,
            'event_user_exists' => (bool) $eventUser,
            'introduced' => $eventUser ? $eventUser->catch_em_all_introduced : false,
            'route' => $request->route()->getName(),
        ]);

        if (! $eventUser || ! $eventUser->catch_em_all_introduced) {
            // Hold on to where they were headed so finishing the introduction can hand
            // them on to it, rather than dropping everyone on the game's front page.
            if ($request->isMethod('GET')) {
                LoginRedirect::keep($request);
            }

            return redirect()->route('catch-em-all.introduction');
        }

        return $next($request);
    }
}
