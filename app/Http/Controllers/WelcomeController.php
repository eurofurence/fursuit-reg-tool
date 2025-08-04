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
        if ($event && Auth::check()) {
            $user = Auth::user();
            $prepaidBadgesLeft = $user->getPrepaidBadgesLeft($event->id);
        }

        return Inertia::render('Welcome', [
            'showState' => $event?->state ?? \App\Enum\EventStateEnum::CLOSED->value,
            'event' => $event ? [
                'id' => $event->id,
                'name' => $event->name,
                'state' => $event->state,
                'allowsOrders' => $event->allowsOrders(),
                'orderStartsAt' => $event->order_starts_at,
                'orderEndsAt' => $event->order_ends_at,
            ] : null,
            'prepaidBadgesLeft' => $prepaidBadgesLeft,
        ]);
    }

    // TODO remove this
    public function test()
    {
        return Inertia::render('POS/Dashboard');
    }
}
