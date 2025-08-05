<?php

namespace App\Http\Controllers;

use App\Models\Badge\Badge;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class WelcomeController extends Controller
{
    public function __invoke()
    {
        // States => closed, coutdown, preorder, late => closed
        // Get next event by ends_at
        $event = \App\Models\Event::getActiveEvent();

        $prepaidBadgesLeft = 0;
        if ($event && Auth::check() && Gate::allows('create', Badge::class)) {
            $user = Auth::user();
            $prepaidBadgesLeft = $user->getPrepaidBadgesLeft($event->id);
        }

        return Inertia::render('Welcome', [
            'showState' => $event?->state->value ?? \App\Enum\EventStateEnum::CLOSED->value,
            'event' => $event ? [
                'id' => $event->id,
                'name' => $event->name,
                'state' => $event->state->value,
                'allowsOrders' => $event->allowsOrders(),
                'orderStartsAt' => $event->order_starts_at,
                'orderEndsAt' => $event->order_ends_at,
            ] : null,
            'prepaidBadgesLeft' => $prepaidBadgesLeft,
        ]);
    }
}
