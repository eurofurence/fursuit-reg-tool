<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\EventUser;
use App\Services\BadgeCalculationService;
use App\Support\DeskOpeningHours;
use App\Support\PickupBooths;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The public information pages the site navigation points at.
 *
 * These exist because the header used to be two unlabelled icons: every question about
 * pickup times, prepaid badges or the Catch-Em-All game arrived as a support message
 * instead of a page view. Each destination in the bottom tab bar has to land somewhere,
 * so each one gets a route here.
 */
class InfoController extends Controller
{
    /**
     * Ordering, paying and picking up, answered from live data.
     *
     * The price comes from BadgeCalculationService and the cutoff from the event's own
     * `free_badge_deadline`, so neither is a number typed into the page. The deadline has
     * its own column on purpose: it used to be read off `mass_printed_at`, which records
     * when the print run happened rather than when attendees must submit by, and rendered
     * "28 July" where the site had always said 1 August.
     */
    public function faq(): Response
    {
        $event = Event::getActiveEvent();

        return Inertia::render('Info/Faq', [
            'event' => $this->eventSummary($event),
            'badgePrice' => BadgeCalculationService::calculate(event: $event),
            'freeBadgeDeadline' => $event?->free_badge_deadline?->toISOString(),
        ]);
    }

    /**
     * Where and when to collect a badge, and which booth queue to stand in.
     *
     * The booth split is the same `PickupBooths` data the desk prints on its signs, so
     * an attendee reads the identical ranges here and at the counter. When we know the
     * viewer's attendee id for this event we mark their booth, which is the one thing
     * a person in a queue actually wants.
     *
     * The opening hours come from the same place, Settings > On-Site Desk, and are empty
     * until the desk team publishes them. That is why the page renders nothing at all
     * rather than a placeholder when the list is empty: this used to be a hardcoded
     * paragraph that no one could correct without a deploy.
     *
     * The booth split is only relevant on the desk's first day, so `boothsActive` decides
     * server-side whether the page shows it at all. Past that day the desk is one counter
     * and the grid would send people hunting for a booth that has been packed away.
     */
    public function pickup(): Response
    {
        $event = Event::getActiveEvent();
        $booths = PickupBooths::forEvent($event);
        $attendeeId = $this->attendeeId($event);

        return Inertia::render('Info/Pickup', [
            'event' => $this->eventSummary($event),
            'booths' => $booths,
            'openingHours' => DeskOpeningHours::forEvent($event),
            'attendeeId' => $attendeeId,
            'myBoothIndex' => $attendeeId === null ? null : PickupBooths::boothIndex($booths, $attendeeId),
            'boothsActive' => PickupBooths::splitActive($event),
            'boothDay' => PickupBooths::splitDay($event),
        ]);
    }

    /**
     * The game explained on the main site, with the only button that leaves for it.
     *
     * The game lives on its own subdomain with its own layout and its own login, so
     * linking straight into it from the nav dropped people into an app they had no
     * context for. This page is the context; `gameUrl` is the door.
     */
    public function catchEmAll(): Response
    {
        $event = Event::getActiveEvent();

        return Inertia::render('Info/CatchEmAll', [
            'event' => $this->eventSummary($event),
            'gameUrl' => self::gameUrl(),
            'isActive' => (bool) $event?->isCatchEmAllActive(),
            'startsAt' => $event?->catch_em_all_start?->toISOString(),
            'endsAt' => $event?->catch_em_all_end?->toISOString(),
        ]);
    }

    public static function gameUrl(): string
    {
        $domain = config('fcea.domain');
        $protocol = str_contains($domain, 'localhost') ? 'http' : 'https';

        return $protocol.'://'.$domain;
    }

    /**
     * @return array{name: string, startsAt: ?string, endsAt: ?string}|null
     */
    private function eventSummary(?Event $event = null): ?array
    {
        $event ??= Event::getActiveEvent();

        if (! $event) {
            return null;
        }

        return [
            'name' => $event->name,
            'startsAt' => $event->starts_at?->toISOString(),
            'endsAt' => $event->ends_at?->toISOString(),
        ];
    }

    private function attendeeId(?Event $event): ?int
    {
        if (! $event || ! Auth::check()) {
            return null;
        }

        $attendeeId = EventUser::query()
            ->where('event_id', $event->id)
            ->where('user_id', Auth::id())
            ->value('attendee_id');

        return is_numeric($attendeeId) ? (int) $attendeeId : null;
    }
}
