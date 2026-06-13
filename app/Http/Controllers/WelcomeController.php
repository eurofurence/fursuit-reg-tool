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
        $currentEventBadgeCount = 0;
        $canCreate = false;
        if ($event && Auth::check()) {
            $user = Auth::user();
            $prepaidBadgesLeft = $user->getPrepaidBadgesLeft($event->id);
            $currentEventBadgeCount = $user->badges()->where('event_id', $event->id)->count();
            // Authoritative permission flag - the Welcome page shows the create/customize
            // button off this rather than inferring it from the order window, so the button
            // can never disagree with whether the user may actually create a badge.
            $canCreate = Gate::allows('create', Badge::class);
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
            'canCreate' => $canCreate,
        ]);
    }
}
