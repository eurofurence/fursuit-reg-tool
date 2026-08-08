<?php

namespace App\Http\Controllers;

use App\Enum\EventStateEnum;
use App\Models\Badge\Badge;
use App\Models\Badge\State_Fulfillment\PickedUp;
use App\Models\Badge\State_Fulfillment\ReadyForPickup;
use App\Models\Event;
use App\Models\User;
use App\Services\BadgeCalculationService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class WelcomeController extends Controller
{
    public function __invoke()
    {
        // States => closed, coutdown, preorder, late => closed
        // Get next event by ends_at
        $event = Event::getActiveEvent();

        $prepaidBadgesLeft = 0;
        $currentEventBadgeCount = 0;
        $canCreate = false;
        $badgeSummary = null;
        if ($event && Auth::check()) {
            $user = Auth::user();
            $prepaidBadgesLeft = $user->getPrepaidBadgesLeft($event->id);
            $currentEventBadgeCount = $user->badges()->where('event_id', $event->id)->count();
            // Authoritative permission flag - the Welcome page shows the create/customize
            // button off this rather than inferring it from the order window, so the button
            // can never disagree with whether the user may actually create a badge.
            $canCreate = Gate::allows('create', Badge::class);
            $badgeSummary = $this->badgeSummary($user, $event);
        }

        return Inertia::render('Welcome', [
            'showState' => $event?->state->value ?? EventStateEnum::CLOSED->value,
            'event' => $event ? [
                'id' => $event->id,
                'name' => $event->name,
                'state' => $event->state->value,
                'allowsOrders' => $event->allowsOrders(),
                'orderStartsAt' => $event->order_starts_at?->toISOString(),
                'orderEndsAt' => $event->order_ends_at?->toISOString(),
                // Quoted in the badge card here and in the FAQ, off one event field so
                // the two pages cannot disagree about the date.
                'freeBadgeDeadline' => $event->free_badge_deadline?->toISOString(),
            ] : null,
            'prepaidBadgesLeft' => $prepaidBadgesLeft,
            'currentEventBadgeCount' => $currentEventBadgeCount,
            'canCreate' => $canCreate,
            'badgeSummary' => $badgeSummary,
            // Same source as the FAQ, so the landing page cannot quote a stale price.
            'badgePrice' => BadgeCalculationService::calculate(),
        ]);
    }

    /**
     * How far along this user's badges are, rolled up for the landing page.
     *
     * "Is my badge ready yet" is the question people open this site to answer while
     * standing in the hall, and the page could not answer it: it knew only how many
     * badges had been ordered. One grouped count rather than loading the badges, because
     * the page renders a sentence, not a list - the list is what /badges is for.
     *
     * @return array{total: int, ready: int, pickedUp: int, inProgress: int}
     */
    private function badgeSummary(User $user, Event $event): array
    {
        $counts = $user->badges()
            ->where('event_id', $event->id)
            ->selectRaw('status_fulfillment, COUNT(*) as aggregate')
            ->groupBy('status_fulfillment')
            ->pluck('aggregate', 'status_fulfillment');

        $ready = (int) $counts->get(ReadyForPickup::$name, 0);
        $pickedUp = (int) $counts->get(PickedUp::$name, 0);
        $total = (int) $counts->sum();

        return [
            'total' => $total,
            'ready' => $ready,
            'pickedUp' => $pickedUp,
            // Everything not yet collectable: pending, processing and printed alike. The
            // distinction matters to the print room, not to somebody deciding whether it
            // is worth walking over to the desk.
            'inProgress' => max(0, $total - $ready - $pickedUp),
        ];
    }
}
