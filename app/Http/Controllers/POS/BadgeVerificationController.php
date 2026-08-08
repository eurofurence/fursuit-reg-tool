<?php

namespace App\Http\Controllers\POS;

use App\Domain\Printing\Models\PrintJob;
use App\Enum\PrintJobStatusEnum;
use App\Enum\PrintVerificationSourceEnum;
use App\Http\Controllers\Controller;
use App\Models\Badge\Badge;
use App\Models\Event;
use App\Models\EventUser;
use App\Models\Machine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * Checking a printed card off against the stack in front of you.
 *
 * The desk holds a crate of finished cards. Somebody reads the number off every
 * card and types it, the card is verified, and whatever is still unverified at
 * the end of the crate is what never came out of the printer and has to be
 * reprinted. That is the whole feature: a numpad, a running list, and an undo.
 *
 * It writes the same `verified_print_at` the print agent writes, with
 * `verification_source = operator`, because "a human looked at the card and
 * confirmed it" is exactly what happened. A card verified here is never
 * un-verified by a later reprint - the stamp says the card was seen once, and
 * the admin list is filtered on it to find the ones that never were.
 */
class BadgeVerificationController extends Controller
{
    /**
     * The screen: counters for the crate, and the tail of the check-off list.
     *
     * The list is read back from the database rather than kept in the browser,
     * so it survives a lock, a reload and a second clerk working the same crate.
     */
    public function index(Request $request)
    {
        $event = Event::getActiveEvent();
        $machine = auth('machine')->user();

        if (! $event) {
            return redirect()->route('pos.dashboard')->with('error', 'No current event found');
        }

        $printed = $this->printedBadges($event, $machine);

        return Inertia::render('POS/Verification/Index', [
            'stats' => [
                'printed' => (clone $printed)->count(),
                'verified' => (clone $printed)->whereNotNull('badges.verified_print_at')->count(),
                'missing' => (clone $printed)->whereNull('badges.verified_print_at')->count(),
            ],
            'badgeRange' => [
                'min' => $machine?->badge_range_min,
                'max' => $machine?->badge_range_max,
            ],
            'recent' => $this->recent($event, $machine),
            // The verdict on the number just typed. It rides the session rather
            // than the shared flash bag, which only carries success/error strings
            // and is read by the toast layer; this one is a small structure the
            // page renders itself.
            'result' => fn () => $request->session()->get('verification'),
        ]);
    }

    /**
     * Check one card off.
     *
     * A bare attendee number means their first badge, because that is what all
     * but a handful of them have: `1234` is `1234-1`. A second copy has to be
     * typed in full as `1234-2`, since guessing which copy is in your hand is
     * exactly the mistake this screen exists to catch.
     */
    public function store(Request $request)
    {
        $event = Event::getActiveEvent();

        if (! $event) {
            return back()->with('error', 'No current event found');
        }

        $customId = $this->normalizeBadgeId((string) $request->input('badge_id', ''));

        if ($customId === null) {
            return back()->with('verification', [
                'status' => 'error',
                'message' => 'Not a badge number. Type 1234 or 1234-2.',
                'input' => trim((string) $request->input('badge_id', '')),
            ]);
        }

        $badge = Badge::whereHas('fursuit', fn (Builder $q) => $q->where('event_id', $event->id))
            ->with(['fursuit.user'])
            ->where('custom_id', $customId)
            ->first();

        if (! $badge) {
            return back()->with('verification', [
                'status' => 'error',
                'message' => $this->missingBadgeReason($event, $customId),
                'input' => $customId,
            ]);
        }

        if ($badge->verified_print_at !== null) {
            return back()->with('verification', [
                'status' => 'duplicate',
                'message' => $customId.' was already checked off '.$badge->verified_print_at->diffForHumans().'.',
                'input' => $customId,
                'badge' => $this->badgeRow($badge),
            ]);
        }

        $this->verify($badge);

        return back()->with('verification', [
            'status' => 'ok',
            'message' => $customId.' checked off.',
            'input' => $customId,
            'badge' => $this->badgeRow($badge->fresh(['fursuit.user'])),
        ]);
    }

    /**
     * Undo one check-off, for the card typed as `1234-1` that was `1234-2`.
     *
     * Clears the stamp on the badge and on its print jobs, so the card falls
     * back into the unverified list and the batch counters agree with it again.
     */
    public function revert(Badge $badge)
    {
        if ($badge->verified_print_at === null) {
            return back()->with('verification', [
                'status' => 'error',
                'message' => ($badge->custom_id ?? 'That badge').' was not checked off.',
                'input' => $badge->custom_id,
            ]);
        }

        DB::transaction(function () use ($badge) {
            $badge->printJobs()
                ->whereNotNull('verified_print_at')
                ->get()
                ->each(function (PrintJob $job) {
                    $job->update([
                        'verified_print_at' => null,
                        'verification_source' => null,
                        'verified_by_id' => null,
                    ]);

                    $job->batch?->recalculateCounters();
                });

            $badge->forceFill(['verified_print_at' => null])->saveQuietly();

            activity()
                ->performedOn($badge)
                ->withProperties(['staff' => auth('machine-user')->user()?->name])
                ->log('Badge check-off reverted at the desk');
        });

        return back()->with('verification', [
            'status' => 'reverted',
            'message' => ($badge->custom_id ?? 'Badge').' put back on the missing list.',
            'input' => $badge->custom_id,
        ]);
    }

    /**
     * Stamp the card, through the print job where there is one.
     *
     * `PrintJob::markVerified()` is what the agent calls, and it mirrors the
     * stamp onto the badge and recounts the batch. A badge printed before print
     * jobs existed, or whose job was pruned, still has to be checkable off, so
     * the badge is stamped directly in that case.
     */
    private function verify(Badge $badge): void
    {
        DB::transaction(function () use ($badge) {
            $job = $badge->printJobs()
                ->where('status', PrintJobStatusEnum::Printed)
                ->orderByDesc('printed_at')
                ->first()
                ?? $badge->printJobs()->orderByDesc('id')->first();

            if ($job) {
                // No `verified_by`: that column points at `users`, and the person
                // at the desk is a `staff` row. The activity log below carries who.
                $job->markVerified(PrintVerificationSourceEnum::Operator);
            } else {
                $badge->forceFill(['verified_print_at' => now()])->saveQuietly();
            }

            activity()
                ->performedOn($badge)
                ->withProperties(['staff' => auth('machine-user')->user()?->name])
                ->log('Badge checked off at the desk');
        });
    }

    /**
     * `1234` -> `1234-1`, `1234-2` -> `1234-2`, anything else -> null.
     *
     * Numpad and scanner both emit stray characters, and the numpad's minus is
     * the only separator on the keypad, so a dash is the one non-digit allowed.
     */
    private function normalizeBadgeId(string $raw): ?string
    {
        $value = trim(str_replace(['–', '—', ' '], ['-', '-', ''], $raw));

        if (preg_match('/^\d+$/', $value)) {
            return $value.'-1';
        }

        if (preg_match('/^(\d+)-(\d+)$/', $value, $matches)) {
            return $matches[1].'-'.$matches[2];
        }

        return null;
    }

    /**
     * Why a typed number found nothing, phrased for somebody holding a card.
     *
     * "No such badge" is useless at the desk: the interesting split is between a
     * number nobody at this event has and an attendee whose card has not been
     * printed, because only the second one is a card that should be in the crate.
     */
    private function missingBadgeReason(Event $event, string $customId): string
    {
        [$attendeeId, $copy] = explode('-', $customId, 2);

        $attendeeExists = EventUser::where('event_id', $event->id)
            ->where('attendee_id', $attendeeId)
            ->exists();

        if (! $attendeeExists) {
            return 'No attendee '.$attendeeId.' at this event.';
        }

        $printedCopies = Badge::whereHas('fursuit', fn (Builder $q) => $q->where('event_id', $event->id))
            ->where('custom_id', 'like', $attendeeId.'-%')
            ->pluck('custom_id')
            ->sort()
            ->values();

        if ($printedCopies->isEmpty()) {
            return 'Attendee '.$attendeeId.' has no printed badge. Card should not be in the box.';
        }

        return 'Attendee '.$attendeeId.' has no copy '.$copy.'. Printed: '.$printedCopies->implode(', ').'.';
    }

    /**
     * Printed cards of this event, narrowed to the crate this desk holds.
     *
     * Printed means the card exists: `printed_at` is stamped by the fulfillment
     * transition that also allocates `custom_id`, so it is the same population
     * the crate was filled from.
     */
    private function printedBadges(Event $event, ?Machine $machine): Builder
    {
        $query = Badge::whereHas('fursuit', fn (Builder $q) => $q->where('event_id', $event->id))
            ->whereNotNull('badges.printed_at')
            ->whereNotNull('badges.custom_id');

        $this->applyBadgeRange($query, $event, $machine);

        return $query;
    }

    /**
     * The crate this desk holds, same rule as the dashboard counter.
     */
    private function applyBadgeRange(Builder $query, Event $event, ?Machine $machine): void
    {
        if (! $machine || ! $machine->hasBadgeRange()) {
            return;
        }

        $query->whereHas('fursuit.user.eventUsers', function ($q) use ($event, $machine) {
            $q->where('event_id', $event->id);

            if ($machine->badge_range_min !== null) {
                $q->whereRaw('CAST(attendee_id AS SIGNED) >= ?', [$machine->badge_range_min]);
            }

            if ($machine->badge_range_max !== null) {
                $q->whereRaw('CAST(attendee_id AS SIGNED) <= ?', [$machine->badge_range_max]);
            }
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function recent(Event $event, ?Machine $machine): array
    {
        return $this->printedBadges($event, $machine)
            ->whereNotNull('badges.verified_print_at')
            ->with(['fursuit.user'])
            ->orderByDesc('badges.verified_print_at')
            ->orderByDesc('badges.id')
            ->limit(30)
            ->get()
            ->map(fn (Badge $badge) => $this->badgeRow($badge))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function badgeRow(Badge $badge): array
    {
        return [
            'id' => $badge->id,
            'custom_id' => $badge->custom_id,
            'fursuit_name' => $badge->fursuit?->name,
            'owner_name' => $badge->fursuit?->user?->name,
            'verified_at' => $badge->verified_print_at?->toIso8601String(),
        ];
    }
}
