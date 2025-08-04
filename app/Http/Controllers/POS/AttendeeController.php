<?php

namespace App\Http\Controllers\POS;

use App\Domain\Checkout\Models\Checkout\Checkout;
use App\Http\Controllers\Controller;
use App\Models\EventUser;
use App\Models\Event;
use App\Models\Fursuit\States\Rejected;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AttendeeController extends Controller
{
    public function lookupForm(): Response
    {
        return Inertia::render('POS/Attendee/Lookup', [
            'backToRoute' => 'pos.dashboard',
        ]);
    }

    public function lookupSubmit(Request $request): RedirectResponse
    {
        $activeEvent = Event::getActiveEvent();
        if (!$activeEvent) {
            return redirect()->back()->withErrors(['attendeeId' => 'No active event found']);
        }

        $eventUser = EventUser::where('attendee_id', $request->get('attendeeId'))
            ->where('event_id', $activeEvent->id)
            ->first();
            
        if (!$eventUser) {
            return redirect()->back()->withErrors(['attendeeId' => 'Could not find attendee']);
        } else {
            return redirect()->route('pos.attendee.show', ['attendeeId' => $request->get('attendeeId')]);
        }
    }

    public function show(string $attendeeId, Request $request): Response
    {
        $activeEvent = Event::getActiveEvent();
        if (!$activeEvent) {
            abort(404, 'No active event found');
        }

        $eventUser = EventUser::where('attendee_id', $attendeeId)
            ->where('event_id', $activeEvent->id)
            ->with('user')
            ->first();
            
        if (!$eventUser) {
            abort(404, 'Attendee not found');
        }

        $user = $eventUser->user;
        
        // Get all badges for the user
        $allBadges = $user->badges()
            ->whereHas('fursuit', function ($query) {
                $query->where('status', '!=', Rejected::$name);
            })
            ->with(['fursuit.species', 'fursuit.event'])
            ->get()
            ->load('wallet');

        // Group badges by event
        $badgesByEvent = $allBadges->groupBy('fursuit.event.id');
        
        // Current event badges
        $currentEventBadges = $badgesByEvent->get($activeEvent->id, collect());
        
        // Past event badges (only events with unclaimed badges)
        $pastEventBadges = [];
        foreach ($badgesByEvent as $eventId => $badges) {
            if ($eventId != $activeEvent->id) {
                $unclaimedBadges = $badges->filter(function ($badge) {
                    return $badge->status_fulfillment !== 'picked_up';
                });
                
                if ($unclaimedBadges->isNotEmpty()) {
                    $event = $badges->first()->fursuit->event;
                    $pastEventBadges[] = [
                        'event' => $event,
                        'badges' => $unclaimedBadges->values(),
                    ];
                }
            }
        }

        return Inertia::render('POS/Attendee/Show', [
            'attendee' => $user->load('wallet'),
            'eventUser' => $eventUser, // Include event-specific data
            'badges' => $currentEventBadges, // Current event badges only
            'pastEventBadges' => $pastEventBadges, // Past events with unclaimed badges
            'currentEvent' => $activeEvent,
            'transactions' => $user->wallet->transactions()->where('amount', '<', 0)->orWhere('amount', '>', 0)->limit(50)->get(),
            'fursuits' => $user->fursuits()->with('species')->get(),
            'checkouts' => Checkout::whereBelongsTo($user)->with('items')->get()->all(),
        ]);
    }
}
