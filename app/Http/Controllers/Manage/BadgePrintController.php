<?php

namespace App\Http\Controllers\Manage;

use App\Domain\Printing\Exceptions\StalePrintFileException;
use App\Domain\Printing\Models\PrintBatch;
use App\Domain\Printing\Models\Printer;
use App\Domain\Printing\Services\BadgePrintQueue;
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
 * The two endpoints that put badges through a printer (audit 4.2, `printBadge` and
 * `printBadgeBulk`).
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
 *    attendee ascending, badge number descending inside an attendee. The Filament bulk
 *    action pre-sorted its selection by attendee id and its own comment says the batch
 *    does the ordering anyway, so that sort is not reproduced here. A second ordering
 *    could only ever disagree with the one that is actually frozen into the jobs.
 *
 * **Authorisation is `viewAny` on Badge, not `manage-admin`.** That is the gate the two
 * Filament actions inherited from the resource (`is_admin || is_reviewer`) and neither
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
     * `printBadge`'s confirm heading is its own label, because the Filament action called
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
     * `printBadge`, the row action (audit 4.2, row action 2).
     *
     * The transition to Processing happens first and through the state machine, never as
     * a write: it is what allocates `custom_id`, and the artwork is rendered from it.
     * `BadgePrintQueue::queue()` makes the same move for every badge it is handed, so
     * this is the same edge asked for twice and the second ask is a no-op; it is spelled
     * out because the Filament helper spelled it out, and because a badge that cannot
     * reach Processing must still be printable (a reprint of a picked-up card).
     *
     * No printer is passed, exactly as `printBadge()` passed none: the queue falls back
     * to the first active badge printer.
     *
     * The two log lines are `printBadge()`'s own, keys included. They are the only record
     * of who put a single card through a printer and what state it was in when they did:
     * the batch log below them names the batch but not the badge it came from, and a
     * reprint of a card that cannot reach Processing writes no activity entry either.
     */
    public function store(Badge $badge): RedirectResponse
    {
        Gate::authorize('viewAny', Badge::class);

        Log::info('printBadge called', [
            'badge_id' => $badge->id,
            'before_fulfillment' => $badge->status_fulfillment->getValue(),
            'before_payment' => $badge->status_payment->getValue(),
            'can_transition' => $badge->status_fulfillment->canTransitionTo(Processing::class),
        ]);

        if ($badge->status_fulfillment->canTransitionTo(Processing::class)) {
            $badge->status_fulfillment->transitionTo(Processing::class);
        }

        $badge->refresh();

        Log::info('printBadge after transition', [
            'badge_id' => $badge->id,
            'after_fulfillment' => $badge->status_fulfillment->getValue(),
            'after_payment' => $badge->status_payment->getValue(),
        ]);

        $batch = $this->queue(collect([$badge]), null);

        if ($batch === null) {
            return back();
        }

        // New: the Filament action gave no feedback at all (plan 2.10 #45, audit 7.2).
        Toast::flashSuccess(
            'Badge queued for printing',
            $this->queuedBody($batch),
        );

        return back();
    }

    /**
     * `printBadgeBulk`, the bulk action (audit 4.2, bulk action 1).
     *
     * The selection can never cross a page, which is `->selectCurrentPageOnly()` and a
     * deliberate operational cap the panel keeps (plan 2.3).
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

        return back();
    }

    /**
     * The one call into the print pipeline, plus the two refusals it can produce.
     *
     * `PrintBatch::build()` throws `StalePrintFileException` when a badge's artwork is
     * missing or no longer matches the order, and nothing in the printing UI has ever
     * surfaced that (audit 93). The queue renders anything stale synchronously first, so
     * reaching this catch means the render itself did not produce a usable file - which
     * is a refusal the operator standing at the printer has to be told about, not a 500.
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
     * and how many cards are in it. A batch name carries the attendee range, which is how
     * the cards are filed, so it is the one string that tells an operator which pile they
     * are about to be handed.
     */
    private function queuedBody(PrintBatch $batch): string
    {
        $name = $batch->name ?? 'Batch #'.$batch->id;

        return $batch->total_jobs === 1
            ? $name.' is ready to print.'
            : $name.' is ready to print ('.$batch->total_jobs.' cards).';
    }

    /**
     * The row action, or null when this operator may not print.
     *
     * Visibility is `always` in Filament, meaning "whoever can see the table", which is
     * the same question `viewAny` answers at the endpoint.
     */
    public static function rowAction(Badge $badge): ?Action
    {
        if (! Gate::allows('viewAny', Badge::class)) {
            return null;
        }

        return Action::post('printBadge', self::ROW_LABEL, route('manage.badges.print', $badge))
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
        if (! Gate::allows('viewAny', Badge::class)) {
            return null;
        }

        return Action::post('printBadgeBulk', self::BULK_LABEL, route('manage.badges.bulk.print'))
            ->icon('printer')
            ->tone(Status::WARN)
            ->confirm(self::BULK_HEADING, self::BULK_DESCRIPTION)
            ->fields([
                [
                    'key' => 'printer_id',
                    'label' => self::PRINTER_LABEL,
                    'type' => 'select',
                    'options' => self::printerOptions(),
                    'required' => true,
                    'helper' => self::PRINTER_HELPER,
                ],
            ]);
    }

    /**
     * Active badge printers, exactly the Filament select's query.
     *
     * Resolved on every request that builds the badge list rather than once per table
     * build (audit 100). The list re-reads its bulk actions on the same five-second poll
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
