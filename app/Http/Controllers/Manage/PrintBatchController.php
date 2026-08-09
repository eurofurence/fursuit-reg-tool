<?php

namespace App\Http\Controllers\Manage;

use App\Domain\Printing\Models\PrintBatch;
use App\Domain\Printing\Models\Printer;
use App\Domain\Printing\Models\PrintJob;
use App\Enum\PrintBatchStatusEnum;
use App\Enum\PrintJobStatusEnum;
use App\Http\Controllers\Controller;
use App\Models\Badge\Badge;
use App\Support\Manage\Action;
use App\Support\Manage\Column;
use App\Support\Manage\Filter;
use App\Support\Manage\Status;
use App\Support\Manage\Table;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Response;

/**
 * Print batches, the successor to the old batch list, ListPrintBatches, ViewPrintBatch and
 * PrintJobsRelationManager.
 *
 * A batch is a live convention print run, so this controller reads and nothing else. Every
 * mutation - pause, resume, cancel, verify - is an explicit POST on PrintBatchRunController.
 * Rendering the list, opening a batch and the ten-second poll behind both must never move a
 * batch, a card, a printer or a badge.
 *
 * Batches are immutable once built. There is no create, no edit and no delete here, exactly
 * as `canCreate(): false` and the missing edit page say in the old panel: the only thing that can
 * populate a batch is `PrintBatch::build()`, which freezes the print order and locks every
 * badge in it at once.
 *
 * Three differences from the old panel, all of them decisions the plan already made.
 *
 *  - The three run controls require `is_admin` through the new PrintBatchPolicy. There is
 *    no policy on the model today, so a reviewer can halt or cancel a run in progress while
 *    needing `is_admin` merely to look at a printer. Reading the
 *    list stays open to every `access-manage` holder.
 *  - The controls are reachable from the batch detail page as well as from the row.
 *    `ViewPrintBatch::getHeaderActions()` returns `[]` deliberately, so an operator who
 *    opened a batch to see which card jammed had to navigate back to stop it.
 *  - A control that cannot fire is offered disabled with the reason rather than hidden,
 *    which is what PrinterController's `clear-error` already does. the old panel hid
 *    actions, which leaves an operator staring at a paused run wondering where Resume went.
 *
 * The card list on the detail page polls at 10s. It is the screen staff watch during a run
 * and it never refreshed itself.
 */
class PrintBatchController extends Controller
{
    /**
     * `->dateTime('M j, H:i')` on the two table timestamps.
     */
    private const LIST_DATETIME_FORMAT = 'M j, H:i';

    /**
     * the old panel's app-default `->dateTime()`, which is what the infolist's Timing entries
     * render with.
     */
    private const DATETIME_FORMAT = 'M j, Y H:i:s';

    /**
     * `->limit(40)` on the pause reason, with the full text as the tooltip.
     */
    private const REASON_LIMIT = 40;

    /**
     * The tooltip on an unverified card, verbatim.
     */
    private const UNVERIFIED_TOOLTIP = 'Nobody has confirmed this card came out';

    /**
     * The list envelope is spread across top-level props rather than nested under one,
     * because useTableQuery reloads `rows`, `meta`, `filters`, `sort` and `search` as an
     * Inertia partial visit and partials are filtered by top-level key. Nested under a
     * single prop those five resolve to null and every sort, filter and page click is a
     * silent no-op.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', PrintBatch::class);

        return inertia('Manage/PrintBatches/Index', $this->table($request));
    }

    /**
     * The batch detail page: the three infolist sections, the run controls and the card
     * list, which carries its own list envelope at the top level for the same reason the
     * index does.
     */
    public function show(Request $request, PrintBatch $printBatch): Response
    {
        Gate::authorize('view', $printBatch);

        $printBatch->load(['printer', 'event', 'createdBy', 'retries'])->loadCount('printJobs');

        return inertia('Manage/PrintBatches/Show', [
            'batch' => $this->batchData($printBatch),
            'actions' => array_map(
                fn (Action $action) => $action->toArray(),
                $this->runActions($printBatch),
            ),
            ...$this->cardTable($request, $printBatch),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function table(Request $request): array
    {
        return Table::make($this->query())
            ->name('print-batches')
            ->columns($this->columns())
            // the old batch list: ->defaultSort('id', 'desc').
            ->defaultSort('id', 'desc')
            ->filters($this->filters())
            ->rows(fn (PrintBatch $batch) => $this->row($batch))
            ->recordUrl(fn (PrintBatch $batch) => Gate::allows('view', $batch)
                ? route('admin.print-batches.show', $batch)
                : null)
            ->rowActions(fn (PrintBatch $batch) => $this->rowActions($batch))
            // `->bulkActions()` is never called on the resource, so there is no select
            // column and no bulk anything on a print run.
            ->bulkActions([])
            // ListPrintBatches::getHeaderActions() returns [] with the docblock "No create
            // action: a batch can only come from PrintBatch::build(), which needs the
            // badges it will contain."
            ->pageActions([])
            ->toArray($request);
    }

    /**
     * The three relations the columns name, joined once rather than lazily per row on a
     * table that polls every ten seconds.
     */
    private function query(): Builder
    {
        // The card count comes along because Retry has to know whether a batch ever held
        // any: `isSealed()` reads it off the row rather than asking per row on every poll.
        return PrintBatch::query()
            ->with(['printer', 'event', 'createdBy'])
            ->withCount('printJobs');
    }

    /**
     * The audit's eleven columns, in order, with the old panel's own labels.
     *
     * `printer.name`, `event.name` and `createdBy.name` are keyed without the dot: a dot in
     * a cell key is read as a path by every data_get consumer, including Inertia's own prop
     * assertions. The labels are unchanged.
     *
     * @return array<int, Column>
     */
    private function columns(): array
    {
        return [
            Column::text('id', 'ID')->sortable(),
            Column::text('name', 'Name')->sortable()->searchable(),
            Column::badge('status', 'Status'),

            // Sorted through a correlated subquery rather than a join, so the list keeps
            // one shape whether or not the sort is on.
            Column::text('printer_name', 'Printer')
                ->sortUsing(fn (Builder $query, string $dir) => $query->orderBy(
                    Printer::select('name')->whereColumn('printers.id', 'print_batches.printer_id'),
                    $dir,
                ))
                ->searchable('printer.name')
                ->fallback('Unassigned'),

            Column::text('event_name', 'Event')->toggleable(hiddenByDefault: true)->fallback('None'),
            Column::badge('progress', 'Progress'),
            Column::badge('unverified', 'Needs check')->align('center'),
            Column::text('pause_reason', 'Reason')->fallback('None'),
            Column::text('created_by_name', 'Built by')->toggleable(hiddenByDefault: true)->fallback('System'),
            Column::datetime('started_at', 'Started')->sortable()->fallback('Not started'),
            Column::datetime('completed_at', 'Completed')
                ->toggleable(hiddenByDefault: true)
                ->fallback('Not finished'),
        ];
    }

    /**
     * One row, already formatted.
     *
     * @return array<string, mixed>
     */
    private function row(PrintBatch $batch): array
    {
        return [
            'id' => $batch->id,
            'name' => $batch->name,
            'status' => Status::printBatch($batch->status),
            'printer_name' => $batch->printer?->name,
            'event_name' => $batch->event?->name,
            'progress' => $this->progress($batch),
            'unverified' => $this->unverified($batch),
            'pause_reason' => $this->reason($batch->pause_reason),
            'created_by_name' => $batch->createdBy?->name,
            'started_at' => $this->datetime($batch->started_at, self::LIST_DATETIME_FORMAT),
            'completed_at' => $this->datetime($batch->completed_at, self::LIST_DATETIME_FORMAT),
        ];
    }

    /**
     * `{printed} / {total}` with `{verified} verified, {failed} failed` underneath, and the
     * resource's own colour ladder: any failure is danger, a finished run is success, and
     * anything else is in flight.
     *
     * Read off the denormalised counters, as the column does. They are what every progress
     * badge in the printing slice reads, which is why phase 6 made the print-job deletes
     * recalculate them.
     *
     * @return array{label: string, tone: string, icon: string|null, description: string}
     */
    private function progress(PrintBatch $batch): array
    {
        $printed = (int) $batch->printed_count;
        $total = (int) $batch->total_jobs;

        $tone = match (true) {
            (int) $batch->failed_count > 0 => Status::DANGER,
            $printed >= $total && $total > 0 => Status::OK,
            default => Status::INFO,
        };

        return Status::make("{$printed} / {$total}", $tone) + [
            'description' => "{$batch->verified_count} verified, {$batch->failed_count} failed",
        ];
    }

    /**
     * Cards that printed and that nobody has vouched for, as the counters see it.
     *
     * `printed_count - verified_count` verbatim, including that it can read negative:
     * `verified_count` counts any job carrying a `verified_print_at`, cancelled and failed
     * ones included, so the subtraction is not bounded below. A negative reads
     * idle rather than warning, which is the ladder the column already declares, and the
     * number is left as it is rather than quietly clamped - a batch showing -1 is a counter
     * problem somebody should see.
     *
     * @return array{label: string, tone: string, icon: string|null}
     */
    private function unverified(PrintBatch $batch): array
    {
        $outstanding = (int) $batch->printed_count - (int) $batch->verified_count;

        return Status::make((string) $outstanding, $outstanding > 0 ? Status::WARN : Status::IDLE);
    }

    /**
     * `->limit(40)` with the full reason as the tooltip, as today.
     *
     * @return array{display: string, title: string}|null
     */
    private function reason(?string $reason): ?array
    {
        if ($reason === null || $reason === '') {
            return null;
        }

        return [
            'display' => Str::limit($reason, self::REASON_LIMIT),
            'title' => $reason,
        ];
    }

    /**
     * The audit's three filters.
     *
     * @return array<int, Filter>
     */
    private function filters(): array
    {
        return [
            Filter::select('status', 'Status')
                ->multiple()
                ->options(collect(PrintBatchStatusEnum::cases())
                    ->mapWithKeys(fn (PrintBatchStatusEnum $case) => [$case->value => $case->label()])
                    ->all()),

            Filter::select('printer', 'Printer')
                ->placeholder('All printers')
                ->options(Printer::orderBy('name')
                    ->get(['id', 'name'])
                    ->mapWithKeys(fn (Printer $printer) => [(string) $printer->id => $printer->name])
                    ->all())
                ->apply(fn (Builder $query, string $value) => $query->where('printer_id', $value)),

            /*
             * The resource's own comment: the queue moved on but nothing has vouched for
             * the card. These are the batches somebody has to walk over and check.
             */
            Filter::boolean('needs_verification', 'Has unverified cards')
                ->apply(fn (Builder $query) => $query->whereHas(
                    'printJobs',
                    fn (Builder $jobs) => $jobs->where('status', PrintJobStatusEnum::Printed)
                        ->whereNull('verified_print_at')
                )),
        ];
    }

    /**
     * View plus the three run controls, which are the same objects the detail page shows.
     *
     * @return array<int, Action>
     */
    private function rowActions(PrintBatch $batch): array
    {
        return array_values(array_filter([
            Gate::allows('view', $batch)
                ? Action::link('view', 'View', route('admin.print-batches.show', $batch))->icon('eye')
                : null,

            ...$this->runActions($batch),
        ]));
    }

    /**
     * Pause, Resume and Cancel, with the resource's copy word for word.
     *
     * Built once and rendered in two places: the row, where the old panel put them, and the
     * detail page, where audit 84 says an operator could not reach them at all.
     *
     * An operator without the ability sees nothing; an operator with it always sees all
     * three, greyed out with the reason when the batch is in no state to take them. That is
     * the `disabledReason` of plan 2.5, and it is the same shape PrinterController uses for
     * `clear-error`. The visibility predicates the resource used become the disabled test,
     * so the same batch states allow the same three actions.
     *
     * @return array<int, Action>
     */
    private function runActions(PrintBatch $batch): array
    {
        $status = $batch->status;
        $label = $status?->label() ?? 'in an unknown state';

        $actions = [];

        if (Gate::allows('pause', $batch)) {
            $pause = Action::post('pause', 'Pause', route('admin.print-batches.pause', $batch))
                // heroicon-o-pause.
                ->icon('pause')
                ->tone(Status::WARN)
                /*
                 * The resource sets no requiresConfirmation() here, but the form itself
                 * opens a modal whose heading is the action label and whose submit is the
                 * framework default.
                 */
                ->confirm('Pause', null, 'Confirm')
                ->fields([
                    [
                        'key' => 'reason',
                        'label' => 'Why is it being paused?',
                        'type' => 'text',
                        'required' => true,
                        'maxLength' => PrintBatchRunController::REASON_MAX_LENGTH,
                        'helper' => 'Shown to whoever is standing at the printer.',
                    ],
                ]);

            if ($status !== PrintBatchStatusEnum::Printing) {
                $pause->disabled("This batch is {$label}. Only a batch that is printing can be paused.");
            }

            $actions[] = $pause;
        }

        if (Gate::allows('resume', $batch)) {
            $resume = Action::post('resume', 'Resume', route('admin.print-batches.resume', $batch))
                // heroicon-o-play.
                ->icon('play')
                ->tone(Status::OK)
                // No modalHeading override, so the heading is the action label and the
                // submit is Confirm.
                ->confirm('Resume', 'Only resume once the fault at the printer has actually been dealt with.', 'Confirm');

            if ($status !== PrintBatchStatusEnum::Paused) {
                $resume->disabled("This batch is {$label}. Only a paused batch can be resumed.");
            }

            $actions[] = $resume;
        }

        if (Gate::allows('cancel', $batch)) {
            $cancel = Action::post('cancel', 'Cancel', route('admin.print-batches.cancel', $batch))
                // heroicon-o-x-circle.
                ->icon('circle-x')
                ->tone(Status::DANGER)
                ->confirm(
                    'Cancel this batch',
                    'Cards already printed stay printed. Everything still queued is cancelled, and attendees whose card never printed get their badge back to edit.',
                    'Confirm',
                )
                ->fields([
                    [
                        'key' => 'reason',
                        'label' => 'Reason',
                        'type' => 'text',
                        'required' => false,
                        'maxLength' => PrintBatchRunController::REASON_MAX_LENGTH,
                        'default' => PrintBatchRunController::DEFAULT_CANCEL_REASON,
                    ],
                ]);

            if ($status?->isTerminal() ?? true) {
                $cancel->disabled("This batch is {$label}. There is nothing left to cancel.");
            }

            $actions[] = $cancel;
        }

        if (Gate::allows('retry', $batch)) {
            $actions[] = $this->retryAction($batch);
        }

        return $actions;
    }

    /**
     * Retry: send the same badges to a printer again after a preparation failed.
     *
     * A run that dies while it is being prepared cancels itself and hands every badge back,
     * so what an operator is left looking at is a cancelled batch with no cards in it and
     * the reason on the row. Without this the only way on is to find the same attendees in
     * the badge list and select them again by hand.
     *
     * Disabled rather than hidden, with the reason, like the other three: a control that
     * disappears leaves somebody hunting for it. The predicates are the endpoint's own,
     * re-asked there before anything is queued.
     */
    private function retryAction(PrintBatch $batch): Action
    {
        $retry = Action::post('retry', 'Retry', route('admin.print-batches.retry', $batch))
            // heroicon-o-arrow-path, as the card-level retry uses.
            ->icon('refresh-cw')
            ->tone(Status::WARN)
            ->confirm(
                'Print this run again',
                'The badges of this run are sent to a printer again as a new batch. Anything since rejected or already queued elsewhere is left out.',
                'Confirm',
            );

        if (! $batch->preparationFailed()) {
            $label = $batch->status?->label() ?? 'in an unknown state';

            $retry->disabled("This batch is {$label}. Only a run that failed while it was being prepared can be retried.");

            return $retry;
        }

        if ($live = $batch->liveRetry()) {
            $retry->disabled("These badges are already being printed again by batch #{$live->id}.");
        }

        return $retry;
    }

    /**
     * The infolist's three sections: Batch, Progress and Timing.
     *
     * @return array<string, mixed>
     */
    private function batchData(PrintBatch $batch): array
    {
        return [
            'id' => $batch->id,
            'name' => $batch->name,
            'status' => Status::printBatch($batch->status),
            'printer' => $batch->printer?->name,
            'event' => $batch->event?->name,
            'createdBy' => $batch->createdBy?->name,
            'pauseReason' => $batch->pause_reason,
            // A failed preparation and the run that replaced it are two rows. Naming each
            // other on the page is what makes "these cards were printed after all" readable
            // months later, when the cancelled batch is the one somebody opens.
            'retryOf' => $batch->retry_of_batch_id === null ? null : [
                'id' => (int) $batch->retry_of_batch_id,
                'url' => route('admin.print-batches.show', $batch->retry_of_batch_id),
            ],
            'retries' => $batch->retries
                ->sortBy('id')
                ->map(fn (PrintBatch $retry) => [
                    'id' => $retry->id,
                    'url' => route('admin.print-batches.show', $retry),
                ])
                ->values()
                ->all(),
            'progress' => [
                'total' => (int) $batch->total_jobs,
                'printed' => (int) $batch->printed_count,
                'verified' => (int) $batch->verified_count,
                'failed' => (int) $batch->failed_count,
            ],
            'timing' => [
                'created' => $this->datetime($batch->created_at, self::DATETIME_FORMAT)['display'] ?? null,
                'started' => $this->datetime($batch->started_at, self::DATETIME_FORMAT)['display'] ?? null,
                'completed' => $this->datetime($batch->completed_at, self::DATETIME_FORMAT)['display'] ?? null,
            ],
        ];
    }

    /**
     * The card list, successor to PrintJobsRelationManager. Title `Cards`.
     *
     * @return array<string, mixed>
     */
    private function cardTable(Request $request, PrintBatch $batch): array
    {
        return Table::make($this->cardQuery($request, $batch))
            ->name('print-batch-cards')
            ->columns($this->cardColumns())
            // The relation manager: ->defaultSort('sequence') ascending, which is the print
            // order PrintBatch::build() froze.
            ->defaultSort('sequence')
            ->filters($this->cardFilters())
            ->rows(fn (PrintJob $job) => $this->cardRow($job))
            ->rowActions(fn (PrintJob $job) => $this->cardActions($batch, $job))
            // ->bulkActions([]) explicitly: no bulk verify. Verifying a card
            // means holding it, so there is nothing to do in bulk.
            ->bulkActions([])
            ->pageActions([])
            ->toArray($request);
    }

    /**
     * The cards of one batch, with the badge and its fursuit joined for the two name
     * columns.
     *
     * Search is applied here rather than declared on the columns. Both searchable columns
     * of the relation manager sit behind `printable`, which is a morphTo, and
     * Table::applySearch resolves a dotted search key with `whereHas` - which cannot
     * traverse a polymorphic relation. `whereHasMorph` can, so the term is applied against
     * the badge and its fursuit here, and the columns declare no searchable key for
     * Table to trip over.
     */
    private function cardQuery(Request $request, PrintBatch $batch): Builder
    {
        $query = PrintJob::query()
            ->where('print_batch_id', $batch->getKey())
            ->with([
                'printable' => fn (MorphTo $printable) => $printable->morphWith([Badge::class => ['fursuit']]),
            ]);

        $search = trim((string) $request->input('search', ''));

        if ($search === '') {
            return $query;
        }

        $operator = $query->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
        $term = '%'.$search.'%';

        return $query->whereHasMorph(
            'printable',
            [Badge::class],
            fn (Builder $badge) => $badge
                ->where('custom_id', $operator, $term)
                ->orWhereHas('fursuit', fn (Builder $fursuit) => $fursuit->where('name', $operator, $term)),
        );
    }

    /**
     * The relation manager's eight columns, in order.
     *
     * `printable.custom_id` and `printable.fursuit.name` are keyed `badge` and `fursuit`:
     * a dot in a cell key is read as a path, and neither is a real column on print_jobs.
     *
     * @return array<int, Column>
     */
    private function cardColumns(): array
    {
        return [
            Column::text('sequence', '#')->sortable(),
            Column::text('badge', 'Badge')->fallback('Deleted'),
            Column::text('fursuit', 'Fursuit')->fallback('Deleted'),
            Column::badge('status', 'Status'),
            Column::text('completion_source', 'Finished by')->fallback('Not finished'),
            Column::icon('verified_print_at', 'Verified'),
            Column::text('attempt_count', 'Tries')->toggleable(hiddenByDefault: true),
            Column::text('error_message', 'Error')->toggleable(hiddenByDefault: true)->fallback('None'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function cardRow(PrintJob $job): array
    {
        $badge = $job->printable instanceof Badge ? $job->printable : null;

        return [
            'sequence' => $job->sequence,
            'badge' => $badge?->custom_id,
            'fursuit' => $badge?->fursuit?->name,
            // Status::printJob, the same mapping the print-job list uses, so `queued` reads
            // `Claimed` on both screens rather than one of each.
            'status' => Status::printJob($job->status),
            'completion_source' => $job->completion_source?->label(),
            'verified_print_at' => $this->verifiedCell($job),
            'attempt_count' => $job->attempt_count,
            'error_message' => $job->error_message,
        ];
    }

    /**
     * The relation manager's IconColumn->boolean(): a check for a card somebody has
     * vouched for, a question mark for one nobody has.
     *
     * The tooltip is the verification source once it is answered, and the audit's own
     * sentence while it is not.
     *
     * @return array{icon: string, tone: string, title: string|null}
     */
    private function verifiedCell(PrintJob $job): array
    {
        $verified = $job->verified_print_at !== null;

        return [
            // heroicon-o-check-badge / heroicon-o-question-mark-circle.
            'icon' => $verified ? 'circle-check' : 'circle-help',
            'tone' => $verified ? Status::OK : Status::WARN,
            'title' => $verified ? $job->verification_source?->label() : self::UNVERIFIED_TOOLTIP,
        ];
    }

    /**
     * The relation manager's two filters.
     *
     * @return array<int, Filter>
     */
    private function cardFilters(): array
    {
        return [
            Filter::boolean('unverified', 'Printed but unverified')
                ->apply(fn (Builder $query) => $query
                    ->where('status', PrintJobStatusEnum::Printed)
                    ->whereNull('verified_print_at')),

            // All seven cases, `cancelled` included, which is what this filter already
            // offered and the print-job resource's own did not.
            Filter::select('status', 'Status')
                ->placeholder('All statuses')
                ->options(collect(PrintJobStatusEnum::cases())
                    ->mapWithKeys(fn (PrintJobStatusEnum $case) => [$case->value => $case->label()])
                    ->all()),
        ];
    }

    /**
     * What a card offers: View, Verify and Retry.
     *
     * Verify was the only one while Print Jobs was a rail module of its own. It is not:
     * the queue is reached through the run that owns it now, so this table is where an
     * operator opens a card and where a failed one is put through the printer again. Both
     * are the print-job module's own endpoints, asked of the print-job policy, exactly as
     * PrintJobController::rowActions asks them; nothing is re-implemented here.
     *
     * Verify is offered only for a card that printed and that nobody has vouched for yet,
     * which is the relation manager's own predicate, and only to an operator the batch
     * policy allows. Retry is offered only where the model says it is possible - Failed,
     * with fewer than three retries behind it. Both are hidden rather than disabled where
     * they do not apply: a run is hundreds of rows long, and a greyed-out button on every
     * card is noise on the screen whose whole job is to make the exceptions obvious.
     *
     * @return array<int, Action>
     */
    private function cardActions(PrintBatch $batch, PrintJob $job): array
    {
        $verifiable = $job->status === PrintJobStatusEnum::Printed && $job->verified_print_at === null;

        return array_values(array_filter([
            Gate::allows('view', $job)
                ? Action::link('view', 'View', route('admin.print-jobs.show', $job))->icon('eye')
                : null,

            $verifiable && Gate::allows('verify', $batch)
                ? Action::post('verify', 'Mark verified', route('admin.print-batches.jobs.verify', [$batch, $job]))
                    // heroicon-o-check-badge.
                    ->icon('circle-check')
                    ->tone(Status::OK)
                    ->confirm(
                        'Confirm this card',
                        'Only do this with the printed card in front of you. This records that a human checked it.',
                        'Confirm',
                    )
                : null,

            $job->canRetry() && Gate::allows('retry', $job)
                ? Action::post('retry', 'Retry', route('admin.print-jobs.retry', $job))
                    // heroicon-o-arrow-path.
                    ->icon('refresh-cw')
                    ->tone(Status::WARN)
                    ->confirmDefault()
                : null,
        ]));
    }

    /**
     * @return array{display: string, title: string}|null
     */
    private function datetime(?CarbonInterface $value, string $format): ?array
    {
        if ($value === null) {
            return null;
        }

        return [
            'display' => $value->format($format),
            'title' => $value->toIso8601String(),
        ];
    }
}
