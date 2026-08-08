<?php

namespace App\Support;

use App\Models\Badge\Badge;
use App\Models\Event;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The badge desk's booth split.
 *
 * On the busy first convention day the desk runs several booths in parallel, each with
 * its own queue and its own slice of attendee ids - the same id that prefixes every
 * badge's custom_id ("1234-2" belongs to attendee 1234). An attendee in the wrong queue
 * has to be sent to another booth, so the ranges are printed on the booth signs and
 * shown to attendees in the badge pages.
 *
 * The split lives on the event (`events.pickup_booths`) because the right cut points
 * follow that event's id distribution: EF30's ids run to ~9800 and are dense at the low
 * end, so equal-width buckets would put twice the queue on the first booths. The
 * defaults below are the balanced split for that distribution (~460-630 attendees per
 * booth against an ideal of 541), rounded to 500s so the signs read cleanly.
 *
 * An event with no configured split falls back to DEFAULTS, so the feature has a sane
 * shape before anyone opens the admin page.
 */
final class PickupBooths
{
    /**
     * @var list<array{label: string, from: int, to: int|null}>
     */
    public const DEFAULTS = [
        ['label' => '0 – 999', 'from' => 0, 'to' => 999],
        ['label' => '1000 – 1999', 'from' => 1000, 'to' => 1999],
        ['label' => '2000 – 3499', 'from' => 2000, 'to' => 3499],
        ['label' => '3500 – 5499', 'from' => 3500, 'to' => 5499],
        ['label' => '5500 – 7499', 'from' => 5500, 'to' => 7499],
        ['label' => '7500 and up', 'from' => 7500, 'to' => null],
    ];

    /**
     * The booths for an event, always normalized and never empty.
     *
     * @return list<array{label: string, from: int, to: int|null}>
     */
    public static function forEvent(?Event $event): array
    {
        $configured = $event?->pickup_booths;

        if (! is_array($configured) || $configured === []) {
            return self::DEFAULTS;
        }

        $normalized = self::normalize($configured);

        return $normalized === [] ? self::DEFAULTS : $normalized;
    }

    /**
     * The one day the booth split applies to, `Y-m-d`, or null when it cannot be placed.
     *
     * The split only exists to break up the day-one rush, so it is tied to the first day
     * the desk is open rather than to the whole convention. That day comes from the
     * published opening hours, because they are what the desk team actually maintains -
     * `starts_at` is the convention's own start and can sit a day either side of when
     * the badge desk first opens. It is the fallback, not the source.
     */
    public static function splitDay(?Event $event): ?string
    {
        return DeskOpeningHours::firstDate($event) ?? $event?->starts_at?->format('Y-m-d');
    }

    /**
     * Whether the booth split is still worth showing.
     *
     * True up to and including the split day: before it, an attendee planning their
     * arrival wants to know which queue is theirs; after it, the desk runs one counter
     * and a booth grid on the page only sends people looking for a booth that is not
     * there. Decided on the server so every viewer gets the same answer regardless of
     * their device clock.
     */
    public static function splitActive(?Event $event): bool
    {
        $day = self::splitDay($event);

        return $day !== null && CarbonImmutable::now()->format('Y-m-d') <= $day;
    }

    /**
     * Coerce a decoded JSON list into the shape the frontend renders.
     *
     * Entries without a usable `from` are dropped rather than rendered as a booth with
     * no range: a booth sign that says nothing is worse than one booth fewer. `to` is
     * null for the open-ended last booth. A missing label is derived from the range, so
     * the admin only has to type the numbers.
     *
     * @param  array<int, mixed>  $booths
     * @return list<array{label: string, from: int, to: int|null}>
     */
    public static function normalize(array $booths): array
    {
        $normalized = [];

        foreach ($booths as $booth) {
            if (! is_array($booth) || ! isset($booth['from']) || ! is_numeric($booth['from'])) {
                continue;
            }

            $from = (int) $booth['from'];
            $to = isset($booth['to']) && is_numeric($booth['to']) ? (int) $booth['to'] : null;

            if ($to !== null && $to < $from) {
                continue;
            }

            $label = isset($booth['label']) && is_string($booth['label']) && trim($booth['label']) !== ''
                ? trim($booth['label'])
                : self::label($from, $to);

            $normalized[] = ['label' => $label, 'from' => $from, 'to' => $to];
        }

        usort($normalized, fn (array $a, array $b) => $a['from'] <=> $b['from']);

        return $normalized;
    }

    public static function label(int $from, ?int $to): string
    {
        return $to === null ? $from.' and up' : $from.' – '.$to;
    }

    /**
     * How many attendees and badges each booth would serve for an event.
     *
     * Counted from the badges of that event joined to the attendee id the owner holds
     * *for that event*, which is the number the desk reads off the badge. Badges whose
     * owner has no attendee id (an incomplete registration) land in `unassigned`, so a
     * split can never look complete while some badges have nowhere to queue.
     *
     * @param  list<array{label: string, from: int, to: int|null}>  $booths
     * @return array{booths: list<array{label: string, from: int, to: int|null, attendees: int, badges: int}>, totals: array{attendees: int, badges: int, unassigned: int}}
     */
    public static function counts(?Event $event, array $booths): array
    {
        $empty = array_map(
            fn (array $booth) => $booth + ['attendees' => 0, 'badges' => 0],
            $booths
        );

        if (! $event) {
            return ['booths' => $empty, 'totals' => ['attendees' => 0, 'badges' => 0, 'unassigned' => 0]];
        }

        $rows = Badge::query()
            ->join('fursuits', 'fursuits.id', '=', 'badges.fursuit_id')
            ->leftJoin('event_users', function ($join) use ($event) {
                $join->on('event_users.user_id', '=', 'fursuits.user_id')
                    ->where('event_users.event_id', '=', $event->id);
            })
            ->where('fursuits.event_id', $event->id)
            ->whereNull('badges.deleted_at')
            ->whereNull('fursuits.deleted_at')
            ->get([
                'event_users.attendee_id as attendee_id',
                DB::raw('fursuits.user_id as user_id'),
            ]);

        $counted = $empty;
        $attendeesPerBooth = array_fill(0, count($booths), []);
        $unassigned = 0;

        foreach ($rows as $row) {
            $attendeeId = is_numeric($row->attendee_id) ? (int) $row->attendee_id : null;
            $index = $attendeeId === null ? null : self::boothIndex($booths, $attendeeId);

            if ($index === null) {
                $unassigned++;

                continue;
            }

            $counted[$index]['badges']++;
            $attendeesPerBooth[$index][$row->user_id] = true;
        }

        foreach ($counted as $index => $booth) {
            $counted[$index]['attendees'] = count($attendeesPerBooth[$index]);
        }

        return [
            'booths' => array_values($counted),
            'totals' => [
                'attendees' => array_sum(array_column($counted, 'attendees')),
                'badges' => array_sum(array_column($counted, 'badges')),
                'unassigned' => $unassigned,
            ],
        ];
    }

    /**
     * Index of the booth serving an attendee id, or null when no booth covers it.
     *
     * @param  list<array{label: string, from: int, to: int|null}>  $booths
     */
    public static function boothIndex(array $booths, int $attendeeId): ?int
    {
        foreach ($booths as $index => $booth) {
            $withinStart = $attendeeId >= $booth['from'];
            $withinEnd = $booth['to'] === null || $attendeeId <= $booth['to'];

            if ($withinStart && $withinEnd) {
                return $index;
            }
        }

        return null;
    }
}
