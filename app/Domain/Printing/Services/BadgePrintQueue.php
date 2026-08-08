<?php

namespace App\Domain\Printing\Services;

use App\Domain\Printing\Models\PrintBatch;
use App\Domain\Printing\Models\Printer;
use App\Domain\Printing\Models\PrintJob;
use App\Enum\PrintBatchStatusEnum;
use App\Enum\PrintJobStatusEnum;
use App\Enum\PrintJobTypeEnum;
use App\Jobs\Printing\GenerateBadgePrintFileJob;
use App\Models\Badge\Badge;
use App\Models\Badge\State_Fulfillment\Processing;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * The single way badges get sent to a printer.
 *
 * Every print action -- the admin row action, the admin bulk action and both
 * POS endpoints -- comes through here, so there is exactly one description of
 * what "print this badge" means. Before this existed each of those four built
 * its own PrintJob directly and none of them set print_batch_id, which meant
 * the agent's batch lane never saw a single job and the batch page was always
 * empty however much you printed.
 *
 * A batch is made even for one badge. A one-job batch looks like overkill, but
 * the batch is what carries the things that make printing recoverable: frozen
 * order, a pause that stops the rest of the run when a card fails, the badge
 * lock, and a counter the operator can watch. A lone job outside a batch has
 * none of that, and it is exactly the card that goes missing.
 */
class BadgePrintQueue
{
    /**
     * Queue badges for printing and return the batch the agent will claim from.
     *
     * Returns null when nothing was printable, so callers can tell the
     * operator rather than reporting success over an empty run.
     */
    public static function queue(
        Collection $badges,
        ?Printer $printer = null,
        ?string $name = null,
        ?int $createdById = null,
        ?int $createdByStaffId = null,
    ): ?PrintBatch {
        $badges = self::withoutCardsAlreadyOnTheirWay(
            self::withoutUnapprovedFursuits(
                $badges->filter(fn (Badge $badge) => $badge->exists)
            )
        );

        if ($badges->isEmpty()) {
            return null;
        }

        $printer = $printer ?? self::defaultBadgePrinter();

        // Move to Processing first: that is what allocates custom_id, and the
        // print file is rendered from it. Rendering before the transition
        // would put a badge with no id on the card.
        $badges->each(function (Badge $badge) {
            if ($badge->status_fulfillment->canTransitionTo(Processing::class)) {
                $badge->status_fulfillment->transitionTo(Processing::class);
            }
        });

        $badges = $badges->map(fn (Badge $badge) => $badge->fresh());

        self::ensurePrintFiles($badges);

        $batch = PrintBatch::build(
            name: $name ?? self::nameFor($badges),
            badges: $badges,
            printer: $printer,
            eventId: $badges->first()?->fursuit?->event_id,
            createdById: $createdById,
            createdByStaffId: $createdByStaffId,
        );

        // Built batches are drafts, and a draft is not selectable by the
        // agent. Nothing here is waiting on an operator to review it, so it
        // goes straight to ready or it would sit unclaimable forever.
        $batch->transitionTo(PrintBatchStatusEnum::Ready);

        Log::info('badge print batch queued', [
            'batch_id' => $batch->id,
            'badges' => $badges->count(),
            'printer_id' => $printer?->id,
            'created_by_id' => $createdById,
            'created_by_staff_id' => $createdByStaffId,
        ]);

        return $batch->fresh();
    }

    /**
     * Drop badges whose fursuit has not been cleared for printing.
     *
     * This is where a Code of Conduct rejection actually stops a card. Nothing used to
     * enforce it: printing looked only at the badge, so a rejected fursuit - a submission
     * a reviewer had refused, with the attendee already told to change it - was printed
     * and handed out by any of the four print entry points, and the rejection only ever
     * meant an email. A badge that never reaches Processing also never reaches PickedUp,
     * so this one filter closes both the printer and the desk.
     *
     * A publication block deliberately does not appear here. It bars the gallery and the
     * game, and the whole reason it exists is that the card is fine: an attendee does not
     * lose their badge over a gallery rule.
     *
     * @param  Collection<int, Badge>  $badges
     * @return Collection<int, Badge>
     */
    private static function withoutUnapprovedFursuits(Collection $badges): Collection
    {
        if ($badges->isEmpty()) {
            return $badges;
        }

        [$printable, $blocked] = $badges->partition(
            // A badge with no fursuit row left is not printable either: the artwork is
            // rendered from it.
            fn (Badge $badge) => $badge->fursuit?->isPrintable() === true
        );

        if ($blocked->isNotEmpty()) {
            Log::info('badges skipped: their fursuit is not approved', [
                'badge_ids' => $blocked->map(fn (Badge $badge) => $badge->getKey())->values()->all(),
            ]);
        }

        return $printable->values();
    }

    /**
     * Drop badges that already have a card on its way out of a printer.
     *
     * Queueing is a POST an operator makes, and the same POST is made twice
     * more often than anyone would like: a browser back and resubmit, two
     * operators on the same row, a bulk selection that includes a badge a row
     * action queued a minute ago, or the POS bulk print clicked again because
     * the first click looked like it did nothing. Nothing downstream refuses
     * that. build() stamps the lock and creates a second job whatever the badge
     * already has, so two live batches each hold a card for one order and two
     * physical cards land in the pickup bin for it.
     *
     * Only outstanding jobs count. A printed card is a reprint, a failed one is
     * a card that never came out, and a cancelled one is a run somebody
     * stopped; all three are legitimate reasons to queue the badge again.
     *
     * @param  Collection<int, Badge>  $badges
     * @return Collection<int, Badge>
     */
    private static function withoutCardsAlreadyOnTheirWay(Collection $badges): Collection
    {
        if ($badges->isEmpty()) {
            return $badges;
        }

        // Cast both sides: printable_id is a morph column and comes back as a
        // string on some drivers, which would make a strict comparison miss and
        // a loose one the kind of thing nobody wants to reason about again.
        $queued = PrintJob::query()
            ->where('printable_type', (new Badge)->getMorphClass())
            ->whereIn('printable_id', $badges->map(fn (Badge $badge) => $badge->getKey())->all())
            ->whereIn('status', PrintJobStatusEnum::outstanding())
            ->pluck('printable_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($queued === []) {
            return $badges;
        }

        Log::info('badges skipped: a card is already queued for them', [
            'badge_ids' => $queued,
        ]);

        return $badges->reject(fn (Badge $badge) => in_array((int) $badge->getKey(), $queued, true))->values();
    }

    /**
     * Render any badge whose print file is missing or no longer matches.
     *
     * Synchronously, because PrintBatch::build() refuses a stale file and the
     * operator is standing at the printer waiting. Queueing the render would
     * only move the failure to a place nobody is looking at.
     *
     * A badge that printed in an earlier run is still locked, and the lock
     * makes GenerateBadgePrintFileJob skip it. Left at that, a reprint of a
     * card whose artwork inputs have since moved -- a fursuit re-approved, a
     * catch code regenerated -- could never be rendered and never be batched:
     * build() refuses the stale file, the CLI pass skips it for the same lock,
     * and the badge is unprintable everywhere until somebody clears the column
     * by hand. So a reprint says so explicitly. It is safe precisely because
     * withoutCardsAlreadyOnTheirWay() has already dropped every badge that
     * still has a card queued, which is what the lock protects.
     */
    private static function ensurePrintFiles(Collection $badges): void
    {
        foreach ($badges as $badge) {
            $current = $badge->print_file_path
                && $badge->print_file_hash === GenerateBadgePrintFileJob::inputHash($badge);

            if (! $current) {
                GenerateBadgePrintFileJob::dispatchSync($badge, ignorePrintingLock: true);
                $badge->refresh();
            }
        }
    }

    /**
     * What the batch is called on the picker and in the queue.
     *
     * A count alone is no use when several runs are waiting: "24 badges" three
     * times over tells an operator nothing about which one is in front of them
     * or which pile of cards it belongs to. The attendee range does, and it is
     * what the cards are filed by.
     */
    private static function nameFor(Collection $badges): string
    {
        if ($badges->count() === 1) {
            return 'Badge '.($badges->first()->custom_id ?? $badges->first()->id);
        }

        $label = $badges->count().' badges';
        $range = self::attendeeRange($badges);

        return $range === null ? $label : $label.' '.$range;
    }

    /**
     * "1069-1093", or "1086" when every card belongs to one attendee.
     *
     * Null when nothing in the batch carries a usable id, which happens before
     * badges reach Processing and get one allocated.
     */
    private static function attendeeRange(Collection $badges): ?string
    {
        $attendees = $badges
            ->map(fn (Badge $badge) => PrintBatch::parseCustomId($badge->custom_id)[0])
            ->filter(fn (?int $attendee) => $attendee !== null)
            ->sort()
            ->values();

        if ($attendees->isEmpty()) {
            return null;
        }

        $first = $attendees->first();
        $last = $attendees->last();

        return $first === $last ? (string) $first : $first.'-'.$last;
    }

    private static function defaultBadgePrinter(): ?Printer
    {
        return Printer::where('type', PrintJobTypeEnum::Badge)
            ->where('is_active', true)
            ->orderBy('id')
            ->first();
    }
}
