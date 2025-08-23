<?php

namespace App\Http\Middleware;

use App\Models\Event;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class FilamentEventSelector
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->has('selected_event_id')) {
            $eventId = $request->get('selected_event_id');
            if ($eventId === 'all') {
                session()->forget('filament_selected_event_id');
            } else {
                session(['filament_selected_event_id' => $eventId]);
            }
        }

        if (! session()->has('filament_selected_event_id')) {
            $latestEvent = Event::orderBy('starts_at', 'desc')->first();
            if ($latestEvent) {
                session(['filament_selected_event_id' => $latestEvent->id]);
            }
        }

        return $next($request);
    }
}
