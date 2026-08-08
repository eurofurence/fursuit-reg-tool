<?php

namespace App\Services;

use App\Domain\Checkout\Models\Checkout\Checkout;
use App\Domain\Checkout\Models\Checkout\States\Finished;
use App\Domain\Printing\Models\PrintBatch;
use App\Http\Controllers\POS\StatisticsController;
use App\Models\Badge\Badge;
use App\Models\Event;
use App\Models\Staff;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * What one desk clerk actually did, for one event or across all of them.
 *
 * Three tables already record who did what, each for its own reason, and this is
 * the only place that reads all three together:
 *
 *  - `badges.picked_up_by_staff_id` + `picked_up_at` - handovers, the main job
 *  - `checkouts.cashier_id` - money taken, only for the badges that cost anything
 *  - `print_batches.created_by_staff_id` - runs sent to a printer
 *
 * ## Why hours are derived rather than clocked
 *
 * There is no shift log. The POS records `staff.last_login_at` and nothing else:
 * no logout, no lock, no timeout, so wall-clock presence is simply not stored and
 * adding a session table would answer nothing about any event that already
 * happened. What *is* stored, on every one of those three tables, is a timestamp
 * per action. Sorting a member's actions into one timeline and cutting it wherever
 * the gap exceeds {@see self::IDLE_GAP_MINUTES} reconstructs the shifts they
 * worked, over historical data, with no new writes anywhere.
 *
 * The cut is what makes the numbers honest. Without it a member who worked an hour
 * on Thursday and an hour on Sunday reads as three days on duty. With it, a break
 * longer than the threshold ends a shift and the time in it is not counted as
 * worked.
 *
 * ## What a "transaction" is
 *
 * The interval between two consecutive actions inside one shift. Not the checkout
 * row: at EF30 nearly every badge is free or prepaid, so most handovers never
 * produce a checkout at all and timing those alone would measure a small and
 * unrepresentative slice of the desk's work.
 *
 * Two consequences worth knowing before reading the numbers:
 *
 *  - Every interval is bounded by the idle threshold by construction, so
 *    `longestTransactionSeconds` is a slow attendee, never a lunch break.
 *  - The *first* action of a shift has no preceding action to measure from, so it
 *    contributes {@see self::LEAD_IN_SECONDS} rather than nothing. Otherwise a
 *    shift of one handover would report zero hours worked.
 *
 * ## Event scoping
 *
 * Badges scope through `fursuit.event_id` and print batches through their own
 * `event_id`, because both carry the answer. Checkouts do not: a checkout's items
 * are polymorphic and one can mix badges from several years, which is the same
 * reason {@see StatisticsController} refuses to split
 * till money per event. They are scoped by timestamp against the event's days
 * instead, so a checkout rung up outside them appears only in the all-time view.
 */
class StaffStatisticsService
{
    /**
     * A gap longer than this ends a shift.
     *
     * Twenty minutes is longer than any queue at the counter and shorter than any
     * real break, so it separates "the next attendee was slow to arrive" from "this
     * person went away".
     */
    public const IDLE_GAP_MINUTES = 20;

    /**
     * Time credited to the first action of a shift, which has nothing before it to
     * be measured against.
     */
    public const LEAD_IN_SECONDS = 60;

    /**
     * @return array<string, mixed>
     */
    public function for(Staff $staff, ?Event $event = null): array
    {
        $badges = $this->badgeHandovers($staff, $event);
        $checkouts = $this->checkouts($staff, $event);
        $batches = $this->printBatches($staff, $event);

        $timeline = $this->timeline($badges, $checkouts, $batches);
        $shifts = $this->shifts($timeline);
        $intervals = $this->intervals($shifts);

        $transactionSeconds = (int) $intervals->sum();
        $activeSeconds = $transactionSeconds + $shifts->count() * self::LEAD_IN_SECONDS;
        $activeHours = $activeSeconds / 3600;

        return [
            'event' => $event ? ['id' => $event->id, 'name' => $event->name] : null,

            'handovers' => [
                'badges' => $badges->count(),
                'perHour' => $this->rate($badges->count(), $activeHours),
                'firstAt' => $timeline->first()?->toIso8601String(),
                'lastAt' => $timeline->last()?->toIso8601String(),
            ],

            'checkouts' => [
                'count' => $checkouts->count(),
                // Cents the whole way to the browser, which formats it. Mixing
                // euros and cents is what broke the old POS statistics page.
                'revenueCents' => (int) $checkouts->sum('total'),
            ],

            'printing' => [
                'runs' => $batches->count(),
                'cards' => (int) $batches->sum('total_jobs'),
                'printedCards' => (int) $batches->sum('printed_count'),
            ],

            'time' => [
                'activeSeconds' => $activeSeconds,
                'activeHours' => round($activeHours, 2),
                'shifts' => $shifts->count(),
                'transactionSeconds' => $transactionSeconds,
                'actions' => $timeline->count(),
                'actionsPerHour' => $this->rate($timeline->count(), $activeHours),
                'avgTransactionSeconds' => $intervals->isEmpty() ? null : (int) round($intervals->avg()),
                'medianTransactionSeconds' => $intervals->isEmpty() ? null : (int) round($intervals->median()),
                'longestTransactionSeconds' => $intervals->isEmpty() ? null : (int) $intervals->max(),
                'idleGapMinutes' => self::IDLE_GAP_MINUTES,
            ],

            'shifts' => $shifts->map(fn (Collection $shift) => [
                'startedAt' => $shift->first()->toIso8601String(),
                'endedAt' => $shift->last()->toIso8601String(),
                'actions' => $shift->count(),
                'seconds' => $this->shiftSeconds($shift),
            ])->values()->all(),

            'perDay' => $this->perDay($shifts, $badges),

            'busiestHour' => $this->busiestHour($timeline),
        ];
    }

    /**
     * Handovers, newest first is irrelevant here - only the instants are used.
     *
     * `status_fulfillment` is checked as well as the staff id because a badge that
     * was picked up and then reverted at the desk (an error correction) keeps its
     * `picked_up_at`, and that handover no longer happened.
     *
     * @return Collection<int, Badge>
     */
    private function badgeHandovers(Staff $staff, ?Event $event): Collection
    {
        return Badge::query()
            ->where('picked_up_by_staff_id', $staff->id)
            ->where('status_fulfillment', 'picked_up')
            ->whereNotNull('picked_up_at')
            ->when($event, fn ($q) => $q->whereHas(
                'fursuit',
                fn ($f) => $f->where('event_id', $event->id)
            ))
            ->get(['id', 'picked_up_at']);
    }

    /**
     * Finished tills only: ACTIVE ones are baskets nobody paid and CANCELLED ones
     * never took money.
     *
     * @return Collection<int, Checkout>
     */
    private function checkouts(Staff $staff, ?Event $event): Collection
    {
        return Checkout::query()
            ->where('cashier_id', $staff->id)
            ->where('status', Finished::$name)
            ->when($event, fn ($q) => $q->whereBetween('created_at', $this->eventWindow($event)))
            ->get(['id', 'total', 'created_at']);
    }

    /**
     * @return Collection<int, PrintBatch>
     */
    private function printBatches(Staff $staff, ?Event $event): Collection
    {
        return PrintBatch::query()
            ->where('created_by_staff_id', $staff->id)
            ->when($event, fn ($q) => $q->where('event_id', $event->id))
            ->get(['id', 'total_jobs', 'printed_count', 'created_at']);
    }

    /**
     * The event's days, widened to whole days at both ends.
     *
     * `starts_at` and `ends_at` are cast to dates, so an unwidened comparison would
     * drop everything the desk did on the last day after midnight-as-time-zero.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function eventWindow(Event $event): array
    {
        return [
            CarbonImmutable::parse($event->starts_at)->startOfDay(),
            CarbonImmutable::parse($event->ends_at)->endOfDay(),
        ];
    }

    /**
     * Every attributed action as one ascending list of instants.
     *
     * @param  Collection<int, Badge>  $badges
     * @param  Collection<int, Checkout>  $checkouts
     * @param  Collection<int, PrintBatch>  $batches
     * @return Collection<int, CarbonImmutable>
     */
    private function timeline(Collection $badges, Collection $checkouts, Collection $batches): Collection
    {
        return $badges->map(fn (Badge $badge) => CarbonImmutable::parse($badge->picked_up_at))
            ->concat($checkouts->map(fn (Checkout $checkout) => CarbonImmutable::parse($checkout->created_at)))
            ->concat($batches->map(fn (PrintBatch $batch) => CarbonImmutable::parse($batch->created_at)))
            ->sort()
            ->values();
    }

    /**
     * Cut the timeline wherever the member went away.
     *
     * @param  Collection<int, CarbonImmutable>  $timeline
     * @return Collection<int, Collection<int, CarbonImmutable>>
     */
    private function shifts(Collection $timeline): Collection
    {
        $shifts = collect();
        $current = collect();
        $idle = self::IDLE_GAP_MINUTES * 60;

        foreach ($timeline as $at) {
            $previous = $current->last();

            if ($previous !== null && $previous->diffInSeconds($at) > $idle) {
                $shifts->push($current);
                $current = collect();
            }

            $current->push($at);
        }

        if ($current->isNotEmpty()) {
            $shifts->push($current);
        }

        return $shifts;
    }

    /**
     * The gaps between consecutive actions inside each shift - one number per
     * transaction. Every one of them is below the idle threshold by construction.
     *
     * @param  Collection<int, Collection<int, CarbonImmutable>>  $shifts
     * @return Collection<int, int>
     */
    private function intervals(Collection $shifts): Collection
    {
        return $shifts->flatMap(function (Collection $shift) {
            $instants = $shift->values();

            return $instants->slice(1)->values()->map(
                fn (CarbonImmutable $at, int $i) => (int) $instants[$i]->diffInSeconds($at)
            );
        })->values();
    }

    /**
     * @param  Collection<int, CarbonImmutable>  $shift
     */
    private function shiftSeconds(Collection $shift): int
    {
        return (int) $shift->first()->diffInSeconds($shift->last()) + self::LEAD_IN_SECONDS;
    }

    /**
     * Hours worked and badges handed out, per calendar day.
     *
     * A shift is attributed to the day it started on, so a run past midnight stays
     * in one row rather than splitting into two half-shifts.
     *
     * @param  Collection<int, Collection<int, CarbonImmutable>>  $shifts
     * @param  Collection<int, Badge>  $badges
     * @return array<int, array<string, mixed>>
     */
    private function perDay(Collection $shifts, Collection $badges): array
    {
        $handoversByDay = $badges
            ->groupBy(fn (Badge $badge) => CarbonImmutable::parse($badge->picked_up_at)->toDateString())
            ->map->count();

        return $shifts
            ->groupBy(fn (Collection $shift) => $shift->first()->toDateString())
            ->map(function (Collection $daysShifts, string $date) use ($handoversByDay) {
                $seconds = (int) $daysShifts->sum(fn (Collection $shift) => $this->shiftSeconds($shift));
                $handovers = (int) ($handoversByDay[$date] ?? 0);

                return [
                    'date' => $date,
                    'seconds' => $seconds,
                    'hours' => round($seconds / 3600, 2),
                    'shifts' => $daysShifts->count(),
                    'badges' => $handovers,
                    'perHour' => $this->rate($handovers, $seconds / 3600),
                ];
            })
            ->sortKeys()
            ->values()
            ->all();
    }

    /**
     * The clock hour this member was busiest in, across the whole range.
     *
     * @param  Collection<int, CarbonImmutable>  $timeline
     * @return array{hour: int, actions: int}|null
     */
    private function busiestHour(Collection $timeline): ?array
    {
        if ($timeline->isEmpty()) {
            return null;
        }

        $counts = $timeline->groupBy(fn (CarbonImmutable $at) => $at->hour)->map->count();

        return [
            'hour' => (int) $counts->keys()->get($counts->values()->search($counts->max())),
            'actions' => (int) $counts->max(),
        ];
    }

    /**
     * A per-hour rate, or null when there is not enough time on the clock for one
     * to mean anything. A single action in a one-minute shift is not 60 an hour.
     */
    private function rate(int $count, float $hours): ?float
    {
        if ($hours < (self::LEAD_IN_SECONDS * 2 / 3600)) {
            return null;
        }

        return round($count / $hours, 1);
    }
}
