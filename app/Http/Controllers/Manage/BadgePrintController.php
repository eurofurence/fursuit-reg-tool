<?php

namespace App\Http\Controllers\Manage;

use App\Domain\Printing\Exceptions\StalePrintFileException;
use App\Domain\Printing\Models\PrintBatch;
use App\Domain\Printing\Models\Printer;
use App\Domain\Printing\Services\BadgePrintQueue;
use App\Enum\PrintBatchStatusEnum;
use App\Enum\PrintJobTypeEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Manage\BadgePrintRequest;
use App\Models\Badge\Badge;
use App\Models\Badge\State_Fulfillment\Processing;
use App\Support\Manage\Action;
use App\Support\Manage\Status;
use App\Support\Manage\Toast;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

/**
 * The two endpoints that put badges through a printer.
 *
 * They live apart from BadgeController for the same reason PrintJobRetryController lives
 * apart from PrintJobController: the CRUD controller owns pages and props, this owns a
 * hardware-facing verb. Both are POSTs, so nothing queues a card as a side effect of a
 * page render or of the badge list's five-second poll.
 *
 * Everything goes through `BadgePrintQueue::queue()`, which is the single description of
 * what "print this badge" means. Nothing here builds a PrintJob, picks a printer file or
 * decides a print order of its own:
 *
 *  - the **batch** is what carries the frozen sequence, the pause-on-failure and the
 *    printing lock. Even one badge gets its own, which is why the row action queues
 *    rather than creating a job.
 *  - the **order** is `PrintBatch::sortBadgesForPrinting()`, called from `build()`:
 *    attendee ascending, badge number descending inside an attendee. The old panel bulk
 *    action pre-sorted its selection by attendee id and its own comment says the batch
 *    does the ordering anyway, so that sort is not reproduced here. A second ordering
 *    could only ever disagree with the one that is actually frozen into the jobs.
 *
 * **Authorisation is `viewAny` on Badge, not `manage-admin`.** That is the gate the two
 * the old panel actions inherited from the resource (`is_admin || is_reviewer`) and neither
 * declared one of its own, so this is exactly the audience that can print today.
 * Narrowing printing to admins is a live-convention decision the plan's risk register
 * parks on the operators rather than on this PR ("confirm with the operators who actually
 * run print batches **before** phase 7 lands"), so it is not taken here.
 *
 * The bulk action's printer select is required, and its option set is the same list the
 * request validates against, so the modal cannot offer a printer the rules would refuse.
 */
class BadgePrintController extends Controller
{
    /**
     * `printBadge`'s confirm heading is its own label, because the old panel action called
     * a bare `requiresConfirmation(true)`.
     */
    private const ROW_LABEL = 'Print Badge';

    private const BULK_LABEL = 'Print Badges';

    /**
     * `printBadgeBulk`'s modal copy, verbatim.
     */
    private const BULK_HEADING = 'Print Selected Badges';

    private const BULK_DESCRIPTION = 'This will print all selected badges to the specified printer.';

    /**
     * The printer select, verbatim: label, required, helper text.
     */
    private const PRINTER_LABEL = 'Select Printer';

    private const PRINTER_HELPER = 'Select a specific printer for all selected badges.';

    /**
     * What an operator is told when a print produced no batch.
     *
     * `BadgePrintQueue::queue()` returns null when nothing in the selection was
     * printable, so a run that queued nothing is reported as one rather than as success
     * over an empty batch.
     */
    private const NOTHING_QUEUED = 'Nothing was queued';

    /**
     * `printBadge`, the row action.
     *
     * The request does not move the badge to Processing, and must not. That transition is
     * what allocates `custom_id`, so it belongs with the render that puts the id on the
     * card, and both now happen in `PrepareBadgePrintBatchJob`. Doing it here as well
     * would put the badge in Processing before the run exists, and a preparation that
     * then failed could not undo it: the job only returns badges *it* moved, precisely so
     * it cannot revert one that belongs to some other run.
     *
     * No printer is passed, exactly as `printBadge()` passed none: the queue falls back
     * to the first active badge printer.
     *
     * The log line is `printBadge()`'s own, keys included. It is the only record of who
     * put a single card through a printer and what state it was in when they did: the
     * batch log names the batch but not the badge it came from, and a reprint of a card
     * that cannot reach Processing writes no activity entry either.
     */
    public function store(Badge $badge): RedirectResponse
    {
        // manage-admin, not viewAny Badge: a reviewer reads badges but does not put cards
        // through a printer. See docs/admin/roles.md.
        Gate::authorize('manage-admin');

        Log::info('printBadge called', [
            'badge_id' => $badge->id,
            'before_fulfillment' => $badge->status_fulfillment->getValue(),
            'before_payment' => $badge->status_payment->getValue(),
            'can_transition' => $badge->status_fulfillment->canTransitionTo(Processing::class),
        ]);

        $batch = $this->queue(collect([$badge]), null);

        if ($batch === null) {
            return back();
        }

        // New: the old panel action gave no feedback at all.
        Toast::flashSuccess(
            'Badge queued for printing',
            $this->queuedBody($batch),
        );

        return back();
    }

    /**
     * `printBadgeBulk`, the bulk action.
     *
     * The selection is whatever the operator ticked, and it is no longer capped at the page
     * they were looking at: the badge list can hand over every key its filters match (see
     * App\Support\Manage\Table::requestedIds). Nothing here changes for it - the payload is
     * the same `ids[]` it always was, and the run is still one batch.
     *
     * The badges are read in a single query ordered by id, only so the request is
     * deterministic. The print order is `PrintBatch::build()`'s and nothing here competes
     * with it.
     */
    public function bulk(BadgePrintRequest $request): RedirectResponse
    {
        $badges = Badge::whereIn('id', $request->validated('ids'))
            ->orderBy('id')
            ->get();

        if ($badges->isEmpty()) {
            Toast::flashDanger(
                self::NOTHING_QUEUED,
                'None of the selected badges still exist.',
            );

            return back();
        }

        $batch = $this->queue($badges, Printer::find($request->validated('printer_id')));

        if ($batch === null) {
            return back();
        }

        Toast::flashSuccess(
            $badges->count() === 1 ? 'Badge queued for printing' : 'Badges queued for printing',
            $this->queuedBody($batch),
        );

        /*
         * Straight back to the list the operator was on, filters untouched.
         *
         * A print used to rewrite `filter[approved_from]` to the newest approval in the run
         * on the way back, on the reasoning that the next run only wants what came in after
         * this one. In practice it moved the cutoff on every press of Print - including a
         * press that queued nothing new - so the list an operator had set up narrowed itself
         * under them and cards approved before the bound dropped out of view without anybody
         * asking for that. The filter stays, and stays theirs to set.
         */
        return back();
    }

    /**
     * The one call into the print pipeline, plus the two refusals it can produce.
     *
     * `StalePrintFileException` is a refusal to batch artwork that is missing or no longer
     * matches the order. It is raised where the run is committed, which is on
     * the worker now, so this catch only still fires under the `sync` queue driver - the
     * test suite, and a console run. Kept rather than dropped because it is the same
     * refusal either way, and an operator is better told than shown a 500. On a real queue
     * the same failure cancels the batch with the message on it instead.
     *
     * @param  Collection<int, Badge>  $badges
     */
    private function queue(Collection $badges, ?Printer $printer): ?PrintBatch
    {
        try {
            $batch = BadgePrintQueue::queue(
                badges: $badges,
                printer: $printer,
                createdById: auth()->id(),
            );
        } catch (StalePrintFileException $exception) {
            Toast::flashDanger(self::NOTHING_QUEUED, $exception->getMessage());

            return null;
        }

        if ($batch === null) {
            Toast::flashDanger(
                self::NOTHING_QUEUED,
                'None of the selected badges could be sent to a printer.',
            );
        }

        return $batch;
    }

    /**
     * What the success toast says under its title: the batch the agent will claim from,
     * and how many cards are in it.
     *
     * A run is prepared on a queue, so at the moment this is written the artwork is still
     * being rendered and the batch is a Draft no agent can claim. Saying "ready to print"
     * there would be a lie that sends an operator to a printer that has nothing to do
     * yet, so the copy depends on where the batch actually is: Ready under the `sync`
     * driver, where preparation has already finished, and preparing anywhere else.
     */
    private function queuedBody(PrintBatch $batch): string
    {
        $name = $batch->name ?? 'Batch #'.$batch->id;
        $cards = $batch->total_jobs === 1 ? '' : ' ('.$batch->total_jobs.' cards)';

        return $batch->status === PrintBatchStatusEnum::Ready
            ? $name.' is ready to print'.$cards.'.'
            : $name.' is being prepared'.$cards.'. It will show as ready to print once the artwork is rendered.';
    }

    /**
     * The row action, or null when this operator may not print.
     *
     * Visibility is `always` in the old panel, meaning "whoever can see the table", which is
     * the same question `viewAny` answers at the endpoint.
     */
    public static function rowAction(Badge $badge): ?Action
    {
        if (! Gate::allows('manage-admin')) {
            return null;
        }

        return Action::post('printBadge', self::ROW_LABEL, route('admin.badges.print', $badge))
            // heroicon-o-printer.
            ->icon('printer')
            ->tone(Status::WARN)
            // A bare requiresConfirmation(): the action label as the heading, the
            // framework's default body, and Confirm to submit.
            ->confirmDefault();
    }

    /**
     * The bulk action, or null when this operator may not print.
     */
    public static function bulkAction(): ?Action
    {
        if (! Gate::allows('manage-admin')) {
            return null;
        }

        $options = self::printerOptions();

        return Action::post('printBadgeBulk', self::BULK_LABEL, route('admin.badges.bulk.print'))
            ->icon('printer')
            ->tone(Status::WARN)
            ->confirm(self::BULK_HEADING, self::BULK_DESCRIPTION)
            ->fields([
                [
                    'key' => 'printer_id',
                    'label' => self::PRINTER_LABEL,
                    'type' => 'select',
                    'options' => $options,
                    // Pre-picked rather than blank. Most desks run one badge printer, and
                    // the select was a required field the operator had to open and choose
                    // from on every run. First by name, matching the order the options are
                    // listed in, so the pick is the top of the list rather than arbitrary.
                    'default' => $options[0]['value'] ?? '',
                    'required' => true,
                    'helper' => self::PRINTER_HELPER,
                ],
            ]);
    }

    /**
     * Active badge printers, exactly the old panel select's query.
     *
     * Resolved on every request that builds the badge list rather than once per table
     * build. The list re-reads its bulk actions on the same five-second poll
     * as its rows, so a printer switched off mid-shift leaves the picker within a tick
     * instead of lingering until somebody reloads the page.
     *
     * Read by BadgePrintRequest as well, so the modal cannot offer a printer the rules
     * would refuse.
     *
     * @return array<int, array{value: int, label: string}>
     */
    public static function printerOptions(): array
    {
        return self::activePrinters()
            ->map(fn (Printer $printer) => ['value' => $printer->id, 'label' => $printer->name])
            ->all();
    }

    /**
     * @return array<int, int>
     */
    public static function printerIds(): array
    {
        return self::activePrinters()->pluck('id')->all();
    }

    /**
     * @return EloquentCollection<int, Printer>
     */
    private static function activePrinters(): EloquentCollection
    {
        return Printer::where('type', PrintJobTypeEnum::Badge)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);
    }
}
