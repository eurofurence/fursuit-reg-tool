<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class WelcomeController extends Controller
{
    public function __invoke()
    {
        // States => closed, coutdown, preorder, late => closed
        // Get next event by ends_at
        $event = \App\Models\Event::getActiveEvent();


        $prepaidBadgesLeft = 0;
        $currentEventBadgeCount = 0;
        if ($event && Auth::check()) {
            $user = Auth::user();
            $prepaidBadgesLeft = $user->getPrepaidBadgesLeft($event->id);
            $currentEventBadgeCount = $user->badges()->where('event_id', $event->id)->count();
        }

        return Inertia::render('Welcome', [
            'showState' => $event?->state->value ?? \App\Enum\EventStateEnum::CLOSED->value,
            'event' => $event ? [
                'id' => $event->id,
                'name' => $event->name,
                'state' => $event->state->value,
                'allowsOrders' => $event->allowsOrders(),
                'orderStartsAt' => $event->order_starts_at?->toISOString(),
                'orderEndsAt' => $event->order_ends_at?->toISOString(),
            ] : null,
            'prepaidBadgesLeft' => $prepaidBadgesLeft,
            'currentEventBadgeCount' => $currentEventBadgeCount,
        ]);
    }
}
