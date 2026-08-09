<?php

namespace App\Domain\Printing\Services;

use App\Domain\Printing\Models\PrintBatch;
use App\Domain\Printing\Models\Printer;
use App\Domain\Printing\Models\PrintJob;
use App\Enum\PrintBatchStatusEnum;
use App\Enum\PrintJobStatusEnum;
use App\Enum\PrintJobTypeEnum;
use App\Jobs\Printing\GenerateBadgePrintFileJob;
use App\Jobs\Printing\PrepareBadgePrintBatchJob;
use App\Models\Badge\Badge;
use App\Models\Badge\State_Fulfillment\Pending;
use App\Models\Badge\State_Fulfillment\Processing;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

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
     *
     * **Nothing here renders anything.** This opens an empty Draft batch and hands the
     * badge ids to `PrepareBadgePrintBatchJob`; the transitions, the artwork and the jobs
     * all happen on a worker. It used to render inline, one badge at a time, inside the
     * request the operator pressed the button in: a bulk run of unrendered badges spent
     * seconds per card downloading an upload from S3 and driving mpdf, and a selection of
     * any size ran the request past PHP's 30 second limit and died in the middle
     * (Sentry c5c679fb). What it left behind is the reason preparation is now one job
     * that either finishes or undoes itself - see `prepare()` and `abandon()`.
     *
     * The batch comes back as a Draft, which is inert: `scopeSelectable` and
     * `isClaimable()` both ignore it, so no agent can claim a card out of a run that is
     * still being prepared. Under the `sync` queue driver - the test suite - the job has
     * already run by the time this returns, so the batch comes back Ready.
     */
    public static function queue(
        Collection $badges,
        ?Printer $printer = null,
        ?string $name = null,
        ?int $createdById = null,
        ?int $createdByStaffId = null,
        ?int $retryOfBatchId = null,
    ): ?PrintBatch {
        $badges = self::printable($badges->filter(fn (Badge $badge) => $badge->exists));

        if ($badges->isEmpty()) {
            return null;
        }

        $printer = $printer ?? self::defaultBadgePrinter();

        $batch = PrintBatch::open(
            // Before the badges reach Processing none of them has a custom_id, so the
            // attendee range is not knowable yet. prepare() renames the batch once it is.
            name: $name ?? self::nameFor($badges),
            printer: $printer,
            eventId: $badges->first()?->fursuit?->event_id,
            createdById: $createdById,
            createdByStaffId: $createdByStaffId,
            expectedJobs: $badges->count(),
            // Written on the batch, not only handed to the job: a preparation that fails
            // leaves a cancelled batch with no jobs and no other record of what it was
            // asked to print, and `retry()` reads this back.
            requestedBadgeIds: $badges->map(fn (Badge $badge) => (int) $badge->getKey())->all(),
            retryOfBatchId: $retryOfBatchId,
        );

        Log::info('badge print batch opened', [
            'batch_id' => $batch->id,
            'badges' => $badges->count(),
            'printer_id' => $printer?->id,
            'created_by_id' => $createdById,
            'created_by_staff_id' => $createdByStaffId,
            'retry_of_batch_id' => $retryOfBatchId,
        ]);

        PrepareBadgePrintBatchJob::dispatch(
            batch: $batch,
            badgeIds: $badges->map(fn (Badge $badge) => (int) $badge->getKey())->values()->all(),
            autoName: $name === null,
        )->afterCommit();

        return $batch->fresh();
    }

    /**
     * Send the badges of a run whose preparation failed to the printer again.
     *
     * A preparation that dies - a render that threw, a worker that was killed - cancels its
     * batch and hands every badge back to Pending, so what is left on screen is a cancelled
     * run holding nothing, and the operator's only recourse was to find the same badges in
     * the badge list and select them by hand. That is the failure this closes: the selection
     * is on the batch, so the run can be asked for again in one press.
     *
     * A new batch rather than a revived one. Batches are immutable and Cancelled is
     * terminal; the failed run stays as the record that it failed, and the retry points back
     * at it through `retry_of_batch_id`.
     *
     * The selection is re-filtered on the way through `queue()`, which is the point of going
     * back through it: a fursuit rejected since, or a badge some other run has already taken,
     * is dropped rather than printed twice. Null comes back when nothing in it is printable
     * any more, exactly as it does for a first attempt.
     *
     * The desk clerk who queued the original keeps the attribution, so the run that replaces
     * theirs still reaches their own print list and their dashboard - they are the one
     * standing at the counter waiting for the card. `createdById` is whoever pressed Retry.
     */
    public static function retry(
        PrintBatch $batch,
        ?int $createdById = null,
    ): ?PrintBatch {
        $badges = Badge::whereIn('id', $batch->requested_badge_ids ?? [])->get();

        return self::queue(
            badges: $badges,
            printer: $batch->printer,
            createdById: $createdById,
            createdByStaffId: $batch->created_by_staff_id,
            retryOfBatchId: (int) $batch->getKey(),
        );
    }

    /**
     * Turn an opened batch into a run that can print. Called only from
     * `PrepareBadgePrintBatchJob`, on a worker.
     *
     * Three steps, in this order and for these reasons:
     *
     *  1. **Transition to Processing**, in one transaction. That is what allocates
     *     `custom_id`, and the card carries it, so it has to happen before anything is
     *     rendered. All of them or none of them: a half-numbered selection is not
     *     something the next attempt can reason about.
     *  2. **Render**, outside any transaction. Each render is an S3 read, an image
     *     decode and a PDF write, and holding row locks across minutes of that would put
     *     the POS behind the print button.
     *  3. **Commit and mark Ready**, in one transaction. The jobs, the badge locks, the
     *     count, the name and the status move together or not at all.
     *
     * If step 2 or 3 throws, `abandon()` puts everything back: the badges this call moved
     * return to Pending and the batch is cancelled carrying the reason. That is the whole
     * point of preparing on a worker rather than in the request. The old inline path had
     * no such recovery - a timeout mid-render left every selected badge in Processing with
     * a `custom_id`, no batch, no jobs and nothing to tell an operator that the run they
     * had just started did not exist.
     *
     * @param  array<int, int>  $badgeIds
     */
    public static function prepare(PrintBatch $batch, array $badgeIds, bool $autoName = true): void
    {
        $batch->refresh();

        // Anything but a Draft means somebody else already dealt with this run: it was
        // cancelled while it sat in the queue, or a previous attempt of this same job got
        // further than this one knows about.
        if ($batch->status !== PrintBatchStatusEnum::Draft || $batch->isSealed()) {
            Log::info('badge print batch preparation skipped', [
                'batch_id' => $batch->id,
                'status' => $batch->status->value,
                'sealed' => $batch->isSealed(),
            ]);

            return;
        }

        // Asked again here, not just at the button. Between the press and the worker
        // picking this up, another run may have taken these badges, or a reviewer may
        // have rejected the fursuit.
        $badges = self::printable(Badge::whereIn('id', $badgeIds)->get());

        if ($badges->isEmpty()) {
            self::abandon($batch, [], 'Nothing was left to print by the time the run was prepared.');

            return;
        }

        $moved = DB::transaction(function () use ($badges) {
            $moved = [];

            foreach ($badges as $badge) {
                if ($badge->status_fulfillment->canTransitionTo(Processing::class)) {
                    $badge->status_fulfillment->transitionTo(Processing::class);
                    $moved[] = (int) $badge->getKey();
                }
            }

            return $moved;
        });

        // Written down before the slow part starts, because the caller that has to undo
        // this may be the job's failed() hook after the worker was killed mid-render, and
        // that hook has no way of knowing which badges were already in Processing before
        // the run began. Reverting one of those would take a badge somebody else is
        // holding and put it back in the queue.
        Cache::put(self::movedCacheKey($batch), $moved, now()->addDay());

        try {
            $badges = $badges->map(fn (Badge $badge) => $badge->fresh());

            self::ensurePrintFiles($badges);

            DB::transaction(function () use ($batch, $badges, $autoName) {
                $batch->commitBadges($badges);

                if ($autoName) {
                    $batch->update(['name' => self::nameFor($badges)]);
                }

                $batch->transitionTo(PrintBatchStatusEnum::Ready);
            });
        } catch (Throwable $exception) {
            self::abandon($batch, $moved, $exception->getMessage());

            throw $exception;
        }

        Cache::forget(self::movedCacheKey($batch));

        Log::info('badge print batch ready', [
            'batch_id' => $batch->id,
            'badges' => $badges->count(),
            'printer_id' => $batch->printer_id,
        ]);
    }

    /**
     * Undo a preparation that did not finish: cancel the batch, put the badges back.
     *
     * Called from `prepare()` when a render or the commit throws, and again from the
     * job's `failed()` hook so a worker that was killed outright - timeout, memory, a
     * pod moving - cannot leave the run half made either. It is safe to run twice: a
     * cancelled batch is terminal and a badge that is no longer in Processing is left
     * alone.
     *
     * A batch that already holds jobs is left exactly as it is. The commit and the move
     * to Ready are one transaction, so jobs exist only if the run really was made, and
     * cancelling it here would throw away cards that are legitimately queued.
     *
     * @param  array<int, int>|null  $badgeIds  the badges this preparation moved to
     *                                          Processing, or null to read back what
     *                                          `prepare()` recorded before it started
     */
    public static function abandon(PrintBatch $batch, ?array $badgeIds, string $reason): void
    {
        $badgeIds ??= Cache::get(self::movedCacheKey($batch), []);

        DB::transaction(function () use ($batch, $badgeIds, $reason) {
            $batch->refresh();

            if ($batch->isSealed()) {
                return;
            }

            if (! $batch->status->isTerminal()) {
                $batch->update(['pause_reason' => $reason]);
                $batch->transitionTo(PrintBatchStatusEnum::Cancelled);
            }

            if ($badgeIds !== []) {
                self::returnToPending(Badge::whereIn('id', $badgeIds)->get());
            }
        });

        Cache::forget(self::movedCacheKey($batch));

        Log::warning('badge print batch abandoned', [
            'batch_id' => $batch->id,
            'badges_returned' => count($badgeIds),
            'reason' => $reason,
        ]);
    }

    /**
     * Where `prepare()` leaves the list of badges it moved, for its own failure hook.
     */
    private static function movedCacheKey(PrintBatch $batch): string
    {
        return "print-batch:{$batch->getKey()}:moved-to-processing";
    }

    /**
     * Put badges back to Pending after a preparation that produced no run.
     *
     * A compensating write rather than a state transition, deliberately. Processing to
     * Pending is not a step in the badge's life - it is the undoing of one that never
     * completed - and adding the edge to the state machine would offer "back to Pending"
     * as a manual option on every Processing badge in the admin panel, which is a
     * different decision from this one.
     *
     * `custom_id` stays. It is allocated once and re-used by the next attempt, and
     * clearing it would hand the same number to somebody else.
     *
     * Three guards, each of which means this badge is not ours to move: it has to still be
     * in Processing, it must not be locked into a run, and no card may be on its way to a
     * printer for it - which is another run having claimed it in the seconds since this
     * one started.
     *
     * "On its way", not "has ever had a job". An earlier printed or cancelled job says
     * nothing about where this badge should sit now: it was Pending a moment ago and this
     * preparation is what moved it, so it goes back. Testing for any job at all left every
     * reprint - every badge with history - stranded in Processing with no run, which is
     * the exact failure this compensation exists to undo.
     *
     * @param  Collection<int, Badge>  $badges
     */
    private static function returnToPending(Collection $badges): void
    {
        foreach ($badges as $badge) {
            if (! $badge->status_fulfillment instanceof Processing) {
                continue;
            }

            $cardOnItsWay = $badge->printJobs()
                ->whereIn('status', PrintJobStatusEnum::outstanding())
                ->exists();

            if ($badge->printing_locked_at !== null || $cardOnItsWay) {
                continue;
            }

            $badge->forceFill(['status_fulfillment' => Pending::$name])->saveQuietly();

            activity()
                ->performedOn($badge)
                ->withProperties([
                    'old_status' => Processing::$name,
                    'new_status' => Pending::$name,
                ])
                ->log('Badge returned to pending: the print run it was in was never created');
        }
    }

    /**
     * The badges in a selection that may actually be sent to a printer.
     *
     * @param  Collection<int, Badge>  $badges
     * @return Collection<int, Badge>
     */
    private static function printable(Collection $badges): Collection
    {
        return self::withoutCardsAlreadyOnTheirWay(self::withoutRejectedFursuits($badges));
    }

    /**
     * Drop badges a reviewer has refused.
     *
     * This is where a Code of Conduct rejection actually stops a card. Nothing used to
     * enforce it: printing looked only at the badge, so a rejected fursuit - a submission
     * a reviewer had refused, with the attendee already told to change it - was printed
     * and handed out by any of the four print entry points, and the rejection only ever
     * meant an email. A badge that never reaches Processing also never reaches PickedUp,
     * so this one filter closes both the printer and the desk.
     *
     * A rejection, and nothing else. It used to drop anything not Approved, which swept up
     * every fursuit still waiting for review - a queue that runs days behind while the
     * attendee is at the desk now - and refused a perfectly good card because nobody had
     * looked at it yet. Deciding whether an unreviewed badge goes out belongs to the person
     * handing it over, not to this filter; see Fursuit::isPrintable().
     *
     * A publication block deliberately does not appear here either. It bars the gallery and
     * the game, and the whole reason it exists is that the card is fine: an attendee does
     * not lose their badge over a gallery rule.
     *
     * @param  Collection<int, Badge>  $badges
     * @return Collection<int, Badge>
     */
    private static function withoutRejectedFursuits(Collection $badges): Collection
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
            Log::info('badges skipped: their fursuit was rejected', [
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
     * Inline within the preparation job, not dispatched onward: `commitBadges()` refuses
     * a stale file, so the run cannot be built until every card in it is rendered, and a
     * second fan-out of jobs would only make "is it ready yet" something this job has to
     * poll for. It is already off the request thread, which is the part that mattered.
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
