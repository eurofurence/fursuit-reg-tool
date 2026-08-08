<?php

namespace App\Http\Controllers\Manage;

use App\Domain\Checkout\Models\Checkout\Checkout;
use App\Domain\Printing\Models\PrintBatch;
use App\Domain\Printing\Models\Printer;
use App\Domain\Printing\Models\PrintJob;
use App\Enum\PrintBatchStatusEnum;
use App\Enum\PrintCompletionSourceEnum;
use App\Enum\PrintJobStatusEnum;
use App\Enum\PrintJobTypeEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Manage\PrintJobRequest;
use App\Models\Badge\Badge;
use App\Support\Manage\Action;
use App\Support\Manage\Column;
use App\Support\Manage\Filter;
use App\Support\Manage\Status;
use App\Support\Manage\Table;
use App\Support\Manage\Toast;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Inertia\Response;

/**
 * Print jobs, the successor to PrintJobResource and its four pages (audit 4.9).
 *
 * This module drives real hardware, so two rules shape everything below. Nothing here
 * queues, re-queues or completes a card as a side effect of rendering a page: `retry`
 * lives on its own POST endpoint in PrintJobRetryController and is the only thing that
 * makes a new job. And the status field no longer writes the column.
 *
 * Five differences from Filament, all of them decisions the plan already made.
 *
 *  - `?printer=` is an ordinary filter. It was a resource-wide `getEloquentQuery()` scope
 *    that fired on the view and edit pages too, with no chip to clear it from the UI
 *    (plan 2.3, audit 88). The page title still names the printer, as ListPrintJobs did.
 *  - `status` is a transition picker and the write goes through the model's own state
 *    handling, so marking a job Printed releases the printer, promotes the badge to
 *    ReadyForPickup and recalculates the batch counters instead of leaving the badge
 *    stuck in Processing (plan 2.10 #10, audit 22). The picker offers operator edges
 *    only: Queued, Printing and Retrying belong to the agent and its lease, and a card
 *    put there from a form is never claimed and never reaped (plan 2.10 #64).
 *  - deleting jobs settles the parent batch, single and bulk. The Filament bulk delete
 *    desynced `total_jobs` / `printed_count` / `verified_count` / `failed_count`
 *    permanently, and every progress badge in the printing slice reads them (plan 2.10
 *    #11, audit 23). Deleting the last outstanding job also finishes the run and hands
 *    the badge's print lock back, and a job a machine is holding is not deletable at all
 *    (plan 2.10 #65).
 *  - `cancelled` is in the status vocabulary: the colour map, the filter and the picker.
 *    It was missing from all three, so a cancelled job rendered unstyled and could not be
 *    found (plan 1.3, audit 87).
 *  - creating a badge job requires a batch. A batch-less badge job lands in the
 *    receipt-only unbatched lane, which `PrintJob::claimNextUnbatched()` filters to
 *    `type = Receipt`, so it sat Pending forever (audit 89).
 *
 * The type is always `App\Enum\PrintJobTypeEnum`, never the raw string `'receipt'` that
 * CheckoutResource writes (audit landmine 4). The Filament side is left alone.
 *
 * The list is deliberately not event-scoped: plan 2.9 lists print jobs among the surfaces
 * that stay unscoped, matching today. A job belongs to the hall, not to an event.
 */
class PrintJobController extends Controller
{
    /**
     * Filament's default table date-time format, kept so the two timestamp columns read
     * the same after the move. The ISO string rides along as the cell title.
     */
    private const DATETIME_FORMAT = 'M j, Y H:i:s';

    /**
     * Filament's model label for this resource, as its delete modals render it.
     */
    private const MODEL_LABEL = 'print job';

    /**
     * `->limit(50)` on the error column, with the full text as the tooltip.
     */
    private const ERROR_LIMIT = 50;

    /**
     * What `markFailed()` records when an operator moves a job to Failed from here and
     * has typed no message of their own. The model requires a reason; a blank one would
     * leave the batch paused with nothing saying why.
     */
    private const DEFAULT_FAILURE_REASON = 'Marked failed from the admin panel';

    /**
     * What `releaseLease()` logs when an operator hands a claimed job back to the queue.
     */
    private const RELEASE_REASON = 'Released from the admin panel';

    /**
     * Why a delete was refused. One sentence for the single and the bulk path, so the two
     * cannot drift.
     */
    private const HELD_MESSAGE = 'A machine is holding one or more of these print jobs. Move the job back to Pending or cancel it before deleting it.';

    /**
     * The states only an agent may put a job into.
     *
     * Reaching Queued or Printing *is* the act of a machine taking the card: it needs a
     * `processing_machine_id` and a lease, which `PrintJob::claim()` writes and a bare
     * `transitionTo()` does not. A job moved there from a form is invisible to every
     * claimer (they filter `status = Pending`) and to `scopeLeaseExpired()` (it requires a
     * lease), so it never prints and its batch never completes. Retrying is the same trap
     * one step further along: it is unclaimable, and `requeueFailedJobs()` only picks up
     * Failed, so resuming the batch does not rescue it either.
     *
     * @var array<int, PrintJobStatusEnum>
     */
    private const AGENT_ONLY = [
        PrintJobStatusEnum::Queued,
        PrintJobStatusEnum::Printing,
        PrintJobStatusEnum::Retrying,
    ];

    /**
     * The morphable targets the create form will accept, keyed by class name.
     *
     * The `printable_*` columns are NOT NULL, and the Filament create form collected
     * neither, so creating a print job from admin has always thrown an integrity error.
     * The pair is asked for here instead. Read by PrintJobRequest as well, so the form and
     * the rules cannot disagree.
     *
     * @var array<class-string, array{label: string, table: string}>
     */
    public const PRINTABLES = [
        Badge::class => ['label' => 'Badge', 'table' => 'badges'],
        Checkout::class => ['label' => 'Checkout', 'table' => 'checkouts'],
    ];

    /**
     * The list envelope is spread across top-level props rather than nested under one,
     * because useTableQuery reloads `rows`, `meta`, `filters`, `sort` and `search` as a
     * partial visit and Inertia filters partials by top-level key. Nested under a single
     * prop those five resolve to null and every sort, filter and page click is a silent
     * no-op.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', PrintJob::class);

        return inertia('Manage/PrintJobs/Index', [
            // ListPrintJobs::getTitle() renamed the page whenever the printer scope was
            // on. The scope is a filter now, and the title still follows it.
            'title' => $this->title($request),
            ...$this->table($request),
        ]);
    }

    /**
     * The read-only page. ViewPrintJob defined no infolist, so Filament fell back to
     * rendering the form schema disabled; this renders the same fields as text.
     */
    public function show(PrintJob $printJob): Response
    {
        Gate::authorize('view', $printJob);

        $printJob->load(['printer', 'processingMachine', 'printable', 'batch']);

        return inertia('Manage/PrintJobs/Show', [
            'job' => $this->viewData($printJob),
            // ViewPrintJob's own header: EditAction, and nothing else. Plus the way back:
            // the queue has no rail entry any more, so a card is opened from the run that
            // owns it and the run is where an operator returns to. An unbatched card has
            // nowhere to go back to, so it gets no button rather than a dead one.
            'actions' => array_map(fn (Action $action) => $action->toArray(), array_values(array_filter([
                $printJob->batch !== null && Gate::allows('view', $printJob->batch)
                    ? Action::link('batch', 'Back to batch', route('admin.print-batches.show', $printJob->batch))
                        ->icon('layers')
                    : null,

                Gate::allows('update', $printJob)
                    ? Action::link('edit', 'Edit', route('admin.print-jobs.edit', $printJob))->icon('pencil')
                    : null,
            ]))),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', PrintJob::class);

        return inertia('Manage/PrintJobs/Form', $this->formProps(null));
    }

    /**
     * A hand-made job always starts Pending, whatever the form offers on an existing
     * record. There is no transition into a fresh row, and a create page that could
     * fabricate a Printed card would say a card exists that nobody ever printed.
     */
    public function store(PrintJobRequest $request): RedirectResponse
    {
        $attributes = $request->payload();

        $batch = $request->batch();

        if ($batch !== null) {
            // On the end of the run, not in the middle of a sequence somebody is already
            // filing. Same placement reprintCard() uses.
            $attributes['print_batch_id'] = $batch->id;
            $attributes['sequence'] = (int) $batch->printJobs()->max('sequence') + 1;
        }

        $printJob = PrintJob::create($attributes + ['status' => PrintJobStatusEnum::Pending]);

        $batch?->recalculateCounters();

        // Filament's built-in Created toast; PrintJobResource declares none of its own
        // beyond the retry notification (audit 7.2).
        Toast::flashSuccess('Created');

        return redirect()->route('admin.print-jobs.show', $printJob);
    }

    public function edit(PrintJob $printJob): Response
    {
        Gate::authorize('update', $printJob);

        return inertia('Manage/PrintJobs/Form', $this->formProps($printJob));
    }

    /**
     * Writes the plain attributes, then runs the status change as a transition.
     *
     * Never `$job->status = ...`. That is the whole of plan 2.10 #10: the Filament form
     * wrote the column through the default save, so no `printed_at` / `failed_at` stamp
     * happened, no `completion_source` was recorded, the printer was never released, the
     * badge was never promoted to ReadyForPickup and the batch counters were never
     * recalculated.
     *
     * Every edge the picker offers goes through the domain method that owns that outcome
     * rather than through a bare `transitionTo()`, and `transitionTo()` is left with the
     * one edge that owns nothing, Cancelled. Reaching Printed without naming a completion
     * source is deliberately impossible; a failure has to pause its batch the same way a
     * failure reported by the agent does; and going back to Pending has to clear the
     * machine, the lease and the attempt count, or the card is queued in name only.
     */
    public function update(PrintJobRequest $request, PrintJob $printJob): RedirectResponse
    {
        $printJob->update($request->payload());

        $target = $request->transitionTarget();

        if ($target !== null) {
            $from = $printJob->status;

            $moved = match ($target) {
                PrintJobStatusEnum::Printed => $printJob->markPrinted(PrintCompletionSourceEnum::Operator),
                PrintJobStatusEnum::Failed => $printJob->markFailed(
                    $printJob->error_message ?: self::DEFAULT_FAILURE_REASON
                ),
                // Back to the queue. From Failed that is the three-step walk
                // `requeue()` makes; from a claimed state it is the lease release, which
                // also drops the printer's hold on the job.
                PrintJobStatusEnum::Pending => $from === PrintJobStatusEnum::Failed
                    ? $printJob->requeue()
                    : $printJob->releaseLease(self::RELEASE_REASON),
                default => $printJob->transitionTo($target),
            };

            if (! $moved) {
                // The record moved under the operator between rendering the form and
                // submitting it. Filament wrote the column regardless and said nothing.
                Toast::flashDanger(
                    'The status was not changed',
                    'This print job can no longer move to that status.',
                );

                return back();
            }

            // markPrinted() and markFailed() recalculate on their own; the other edges do
            // not, and a job that leaves Pending still changes what the counters see.
            // Settling rather than only recalculating, because cancelling the last
            // outstanding job of a run finishes it.
            $this->settleBatch($printJob->batch);
        }

        // Filament's stock EditRecord toast; this resource declares none of its own.
        Toast::flashSuccess('Saved');

        return redirect()->route('admin.print-jobs.show', $printJob);
    }

    /**
     * Hard delete: `print_jobs` carries no SoftDeletes, and audit 7.7 lists print jobs
     * among the tables that stay hard deletes.
     *
     * The batch is read before the row goes and settled after, which is plan 2.10 #11 plus
     * the completion the counters alone do not reach.
     *
     * Refused while a machine holds the job, for the same reason PrinterController refuses
     * to delete a printer that still has jobs: the agent is mid-card and will report back
     * against an id that no longer resolves.
     */
    public function destroy(PrintJob $printJob): RedirectResponse
    {
        Gate::authorize('delete', $printJob);

        if ($this->heldByAgent($printJob)) {
            Toast::flashDanger(
                'Nothing was deleted',
                self::HELD_MESSAGE,
            );

            return back();
        }

        $batch = $printJob->batch;
        $badge = $printJob->printable;

        $printJob->delete();

        $this->releasePrintLock($badge);
        $this->settleBatch($batch);

        Toast::flashSuccess('Deleted');

        return redirect()->route('admin.print-jobs.index');
    }

    /**
     * All-or-nothing (plan 2.5): if any selected record fails the policy nothing is
     * deleted and a danger toast says why, rather than half a selection disappearing.
     *
     * Every batch touched by the selection is recalculated once, after the rows are gone.
     */
    public function bulkDestroy(Request $request): RedirectResponse
    {
        Gate::authorize('delete', new PrintJob);

        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);

        $jobs = PrintJob::whereIn('id', $validated['ids'])->get();

        foreach ($jobs as $job) {
            if (Gate::denies('delete', $job)) {
                Toast::flashDanger(
                    'Nothing was deleted',
                    'You are not allowed to delete one or more of the selected print jobs.'
                );

                return back();
            }

            if ($this->heldByAgent($job)) {
                Toast::flashDanger(
                    'Nothing was deleted',
                    self::HELD_MESSAGE,
                );

                return back();
            }
        }

        $batchIds = $jobs->pluck('print_batch_id')->filter()->unique();
        $badges = $jobs->map->printable;

        // Per record rather than a mass delete, so model events still fire, which is what
        // Filament's DeleteBulkAction did.
        $jobs->each->delete();

        $badges->each(fn ($badge) => $this->releasePrintLock($badge));

        PrintBatch::whereIn('id', $batchIds)->get()->each(fn (PrintBatch $batch) => $this->settleBatch($batch));

        Toast::flashSuccess('Deleted');

        return back();
    }

    /**
     * Whether a machine currently holds this job.
     *
     * `holdsLease()` is Queued or Printing: the agent has been handed the card and may be
     * feeding it right now. Deleting it 404s the agent's own `printed` callback, so the
     * badge is never promoted and the printer never releases the job.
     */
    private function heldByAgent(PrintJob $printJob): bool
    {
        return $printJob->status?->holdsLease() === true;
    }

    /**
     * Bring the batch's counters back in line and finish it if nothing is left to print.
     *
     * `recalculateCounters()` alone is not enough. Deleting or cancelling the last
     * outstanding job of a run leaves every progress badge reading 100% while the batch
     * itself never reaches Completed, because the only caller of `completeIfFinished()` is
     * `markPrinted()` and no job is left to print.
     */
    private function settleBatch(?PrintBatch $batch): void
    {
        if ($batch === null) {
            return;
        }

        $batch->recalculateCounters();
        $batch->completeIfFinished();
    }

    /**
     * Hand editing back to the attendee when the last print job of their badge goes.
     *
     * `printing_locked_at` is set when the batch is built and cleared in exactly one other
     * place, `PrintBatch::cancel()`. A badge whose only job is deleted has no card and
     * nothing queued, so leaving the lock set would make `BadgePolicy::updateAsOwner()`
     * refuse the owner's own edit forever. A badge that still has a card, or still has a
     * job that is not cancelled, stays locked.
     */
    private function releasePrintLock(mixed $badge): void
    {
        if (! $badge instanceof Badge || ! $badge->isPrintingLocked()) {
            return;
        }

        $outstanding = $badge->printJobs()
            ->where('status', '!=', PrintJobStatusEnum::Cancelled->value)
            ->exists();

        if ($outstanding) {
            return;
        }

        $badge->forceFill(['printing_locked_at' => null])->saveQuietly();
    }

    /**
     * The status edges an operator may take from where this record stands.
     *
     * The enum's own edges, minus the three states only an agent may write (see
     * AGENT_ONLY), plus the one edge the enum does not have a single hop for: a failed
     * card back into the queue. `PrintJob::requeue()` walks Retrying, Queued, Pending and
     * clears the lease, the machine and the attempt count, which is what "put this card
     * back in the run" actually means; the enum's `Failed -> Retrying` stops one short of
     * it and lands the job somewhere nothing will ever claim it from.
     *
     * The picker's resting position, the state the record is already in, is added by the
     * two callers, and means "no change".
     *
     * One list, read by the form and by PrintJobRequest's Rule::in, so the form cannot
     * offer a transition the request would refuse.
     *
     * @return array<int, PrintJobStatusEnum>
     */
    public static function allowedTransitions(PrintJob $printJob): array
    {
        $current = $printJob->status;

        if (! $current instanceof PrintJobStatusEnum) {
            return [];
        }

        $targets = collect(PrintJobStatusEnum::cases())
            ->filter(fn (PrintJobStatusEnum $case) => $current->canTransitionTo($case))
            ->reject(fn (PrintJobStatusEnum $case) => in_array($case, self::AGENT_ONLY, true));

        if ($current === PrintJobStatusEnum::Failed) {
            $targets->push(PrintJobStatusEnum::Pending);
        }

        return $targets->unique()->values()->all();
    }

    /**
     * `Print Jobs`, or `Print Jobs - {name}` while the printer filter is on.
     *
     * Filament read `request('printer')`; the filter is `filter[printer]` now, and
     * Filter::CLEARED means the operator turned it off rather than never set it.
     */
    private function title(Request $request): string
    {
        $printerId = $request->input('filter.printer');

        if ($printerId === null || $printerId === '' || $printerId === Filter::CLEARED) {
            return 'Print Jobs';
        }

        $name = Printer::find($printerId)?->name ?? 'Unknown';

        return "Print Jobs - {$name}";
    }

    /**
     * @return array<string, mixed>
     */
    private function table(Request $request): array
    {
        return Table::make($this->query())
            ->name('print-jobs')
            ->columns($this->columns())
            // PrintJobResource: ->defaultSort('id', 'desc').
            ->defaultSort('id', 'desc')
            ->filters($this->filters())
            ->rows(fn (PrintJob $job) => $this->cells($job))
            ->recordUrl(fn (PrintJob $job) => Gate::allows('view', $job)
                ? route('admin.print-jobs.show', $job)
                : null)
            ->rowActions(fn (PrintJob $job) => $this->rowActions($job))
            ->bulkActions($this->bulkActions())
            ->pageActions($this->pageActions())
            ->toArray($request);
    }

    /**
     * The list query.
     *
     * `printer`, `processingMachine` and `printable` were all lazy-loaded on a table
     * polling every five seconds, which is three queries a row on every tick (audit 97).
     * `batch` joins them because the batch a card belongs to is now on the list at all.
     */
    private function query(): Builder
    {
        return PrintJob::query()->with(['printer', 'processingMachine', 'printable', 'batch']);
    }

    /**
     * The audit's eleven columns, in order, with Filament's own auto labels verbatim,
     * plus the batch.
     *
     * `printer.name` and `processingMachine.name` are keyed `printer_name` and
     * `machine_name`, labels unchanged: a dot in a cell key is read as a path by every
     * data_get consumer, including Inertia's own prop assertions.
     *
     * The batch column is the one addition. `print_batch_id` and `sequence` are surfaced
     * nowhere in the Filament resource, so from the print-jobs list you cannot tell which
     * run a card belongs to - which is also what made the retry action so awkward to use
     * (audit 4.9, audit 85).
     *
     * @return array<int, Column>
     */
    private function columns(): array
    {
        return [
            Column::text('id', 'ID')->sortable(),

            // Sorted through a correlated subquery rather than a join, so the list keeps
            // one shape whether or not the sort is on.
            Column::text('printer_name', 'Printer')
                ->sortUsing(fn (Builder $query, string $dir) => $query->orderBy(
                    Printer::select('name')->whereColumn('printers.id', 'print_jobs.printer_id'),
                    $dir,
                ))
                ->searchable('printer.name'),

            Column::badge('type', 'Type'),
            Column::badge('status', 'Status'),
            Column::text('printable', 'Printable'),
            Column::badge('priority', 'Priority')->sortable(),
            Column::badge('retry_count', 'Retries'),
            Column::text('machine_name', 'Machine')->fallback('Not assigned'),
            Column::datetime('created_at', 'Created')->sortable(),
            Column::datetime('printed_at', 'Printed')->fallback('Not printed'),
            Column::text('error_message', 'Error')->fallback('None'),

            // Toggleable rather than fixed: it is the one column the Filament list never
            // had, so an operator who wants the old shape can put it away.
            Column::text('batch', 'Batch')->toggleable()->fallback('Unbatched'),
        ];
    }

    /**
     * One row, already formatted.
     *
     * @return array<string, mixed>
     */
    private function cells(PrintJob $job): array
    {
        return [
            'id' => $job->id,
            'printer_name' => $job->printer?->name,
            // One vocabulary and one tone set for both enums, decided server-side. The
            // Filament column printed the raw ->value through a deprecated BadgeColumn
            // whose 'secondary' entries rendered unstyled (plan 1.3, audit 7.9, 7.10).
            'type' => Status::printJobType($job->type),
            'status' => Status::printJob($job->status),
            'printable' => $this->printable($job),
            'priority' => $this->priority($job->priority),
            'retry_count' => $this->retries($job->retry_count),
            'machine_name' => $job->processingMachine?->name,
            'created_at' => $this->datetime($job->created_at),
            'printed_at' => $this->datetime($job->printed_at),
            'error_message' => $this->error($job->error_message),
            'batch' => $this->batchCell($job),
        ];
    }

    /**
     * `Badge #{custom_id}` for a badge, `{ClassBasename} #{id}` for anything else.
     *
     * The type test goes through the model's morph class rather than the hardcoded
     * literal `'App\Models\Badge\Badge'` the Filament closure compared against, which
     * falls into the class_basename branch the moment a morph map is registered
     * (audit 90). A soft-deleted badge has no `custom_id` to show, so the row falls back
     * to the id it still has rather than rendering `Badge #` with nothing after it.
     */
    private function printable(PrintJob $job): ?string
    {
        if ($job->printable_type === null) {
            return null;
        }

        if ($job->printable_type === Badge::class || $job->printable_type === (new Badge)->getMorphClass()) {
            return 'Badge #'.($job->printable?->custom_id ?? $job->printable_id);
        }

        return class_basename($job->printable_type).' #'.$job->printable_id;
    }

    /**
     * The priority ladder, verbatim: >= 10 danger, >= 5 warning, >= 1 info, else gray.
     *
     * Null-safe. The Filament closure type-hinted `int $state`, so one null priority was
     * a TypeError and a 500 for the whole table (plan 2.10 #7, audit 29).
     *
     * @return array{label: string, tone: string, icon: string|null}
     */
    private function priority(?int $priority): array
    {
        if ($priority === null) {
            return Status::make('None', Status::IDLE, null);
        }

        $tone = match (true) {
            $priority >= 10 => Status::DANGER,
            $priority >= 5 => Status::WARN,
            $priority >= 1 => Status::INFO,
            default => Status::IDLE,
        };

        return Status::make((string) $priority, $tone, null);
    }

    /**
     * The retry ladder, verbatim: >= 3 danger, >= 1 warning, else gray. Null-safe for the
     * same reason as the priority ladder.
     *
     * @return array{label: string, tone: string, icon: string|null}
     */
    private function retries(?int $retryCount): array
    {
        if ($retryCount === null) {
            return Status::make('None', Status::IDLE, null);
        }

        $tone = match (true) {
            $retryCount >= 3 => Status::DANGER,
            $retryCount >= 1 => Status::WARN,
            default => Status::IDLE,
        };

        return Status::make((string) $retryCount, $tone, null);
    }

    /**
     * `->limit(50)` with the full message as the tooltip, as today.
     *
     * @return array{display: string, title: string}|null
     */
    private function error(?string $message): ?array
    {
        if ($message === null || $message === '') {
            return null;
        }

        return [
            'display' => Str::limit($message, self::ERROR_LIMIT),
            'title' => $message,
        ];
    }

    /**
     * The run this card belongs to, and where it sits in it.
     *
     * @return array{display: string, title: string|null, url: string|null}|null
     */
    private function batchCell(PrintJob $job): ?array
    {
        $batch = $job->batch;

        if ($batch === null) {
            return null;
        }

        $display = $batch->name ?? 'Batch #'.$batch->id;

        return [
            'display' => $job->sequence === null ? $display : $display.' #'.$job->sequence,
            'title' => $batch->status?->label(),
            // The batch module lands in phase 7; until its route exists this is text
            // rather than a dead link.
            'url' => Route::has('admin.print-batches.show')
                ? route('admin.print-batches.show', $batch)
                : null,
        ];
    }

    /**
     * The audit's five filters, plus the verification state.
     *
     * `printer` is the one that changes shape: it was a resource-wide
     * `getEloquentQuery()` scope keyed off `request()->has('printer')`, which applied to
     * the view and edit pages as well and had no chip to clear it (plan 2.3, audit 88).
     *
     * `verified` is the sixth, and it is not a parity item: the status strip shipped in
     * phase 0 links here with `filter[status]=printed&filter[verified]=0` to reach the
     * cards nobody has vouched for, and without the filter that link would silently show
     * every printed card instead.
     *
     * `printable_id` and `printable_type` are declared as selects with no options because
     * that is the shape Filter offers for a single free value; the Index page renders them
     * as the text inputs they are, with the audit's own indicators.
     *
     * @return array<int, Filter>
     */
    private function filters(): array
    {
        return [
            // All seven cases, including `cancelled`, which the Filament select, the
            // colour map and this filter all omitted (plan 1.3, audit 87). The wording is
            // the enum's own label(), so `queued` reads `Claimed` here and on the batch
            // card list rather than one of each (audit 7.9).
            Filter::select('status', 'Status')
                ->placeholder('All statuses')
                ->options(collect(PrintJobStatusEnum::cases())
                    ->mapWithKeys(fn (PrintJobStatusEnum $case) => [$case->value => $case->label()])
                    ->all()),

            Filter::select('type', 'Type')
                ->placeholder('All types')
                ->options([
                    PrintJobTypeEnum::Badge->value => 'Badge',
                    PrintJobTypeEnum::Receipt->value => 'Receipt',
                ]),

            Filter::select('printer', 'Printer')
                ->placeholder('All printers')
                ->options(Printer::orderBy('name')
                    ->get(['id', 'name'])
                    ->mapWithKeys(fn (Printer $printer) => [(string) $printer->id => $printer->name])
                    ->all())
                ->apply(fn (Builder $query, string $value) => $query->where('printer_id', $value)),

            // Free values, declared as such rather than as optionless selects. Filament
            // rendered them as TextInputs inside a filter form; as selects they were
            // dropdowns with nothing in them, so ListPrintJobs drew its own inputs on the
            // page. The filter bar renders both from the declaration now.
            Filter::number('printable_id', 'Printable ID')
                ->placeholder('Printable ID')
                ->apply(fn (Builder $query, string $value) => $query->where('printable_id', $value)),

            Filter::text('printable_type', 'Printable Type')
                ->chipLabel('Type')
                ->placeholder('Printable Type')
                ->apply(fn (Builder $query, string $value) => $query->where('printable_type', $value)),

            Filter::ternary('verified', 'Verified')
                ->placeholder('Verified and not')
                ->trueLabel('Verified only')
                ->falseLabel('Unverified only')
                ->apply(fn (Builder $query, string $value) => $value === '1'
                    ? $query->whereNotNull('verified_print_at')
                    : $query->whereNull('verified_print_at')),
        ];
    }

    /**
     * View, Edit and Retry, as PrintJobResource declared them.
     *
     * Retry is offered only where the model says it is possible - Failed, with fewer than
     * three retries behind it - and only to an operator the policy allows, because it puts
     * another card through a printer.
     *
     * @return array<int, Action>
     */
    private function rowActions(PrintJob $job): array
    {
        return array_values(array_filter([
            Gate::allows('view', $job)
                ? Action::link('view', 'View', route('admin.print-jobs.show', $job))->icon('eye')
                : null,

            Gate::allows('update', $job)
                ? Action::link('edit', 'Edit', route('admin.print-jobs.edit', $job))->icon('pencil')
                : null,

            $job->canRetry() && Gate::allows('retry', $job)
                ? Action::post('retry', 'Retry', route('admin.print-jobs.retry', $job))
                    // heroicon-o-arrow-path.
                    ->icon('refresh-cw')
                    ->tone(Status::WARN)
                    // A bare requiresConfirmation(): the action label as the heading, the
                    // framework's default body, and Confirm to submit.
                    ->confirmDefault()
                : null,
        ]));
    }

    /**
     * @return array<int, Action>
     */
    private function bulkActions(): array
    {
        // A bare class name would reach PrintJobPolicy::delete() as its $printJob
        // argument and fail the type hint, so the question "may this operator delete print
        // jobs at all" is asked with a throwaway instance.
        if (! Gate::allows('delete', new PrintJob)) {
            return [];
        }

        return [
            Action::delete('delete', 'Delete selected', route('admin.print-jobs.bulk.destroy'))
                ->icon('trash-2')
                ->tone(Status::DANGER)
                ->confirm('Delete selected print jobs', Action::DEFAULT_CONFIRM_DESCRIPTION, 'Delete'),
        ];
    }

    /**
     * @return array<int, Action>
     */
    private function pageActions(): array
    {
        if (! Gate::allows('create', PrintJob::class)) {
            return [];
        }

        return [
            Action::link('create', 'New print job', route('admin.print-jobs.create'))->icon('plus'),
        ];
    }

    /**
     * The view page's fields, in the order the form schema declares them, plus the four
     * the Filament resource surfaced nowhere: the batch, the sequence, the completion
     * source and the verification.
     *
     * @return array<string, mixed>
     */
    private function viewData(PrintJob $job): array
    {
        return [
            'id' => $job->id,
            'printer' => $job->printer?->name,
            'type' => Status::printJobType($job->type),
            'status' => Status::printJob($job->status),
            'printable' => $this->printable($job),
            'priority' => $job->priority,
            'retry_count' => $job->retry_count,
            'error_message' => $job->error_message,
            'firmware_job_id' => $job->firmware_job_id,
            'firmware_job_uuid' => $job->firmware_job_uuid,
            'machine' => $job->processingMachine?->name,
            'batch' => $job->batch?->name ?? ($job->print_batch_id === null ? null : 'Batch #'.$job->print_batch_id),
            'sequence' => $job->sequence,
            'completion_source' => $job->completion_source === null
                ? null
                : Status::completionSource($job->completion_source),
            'verified' => Status::verified($job->verified_print_at !== null),
            'created_at' => $this->datetime($job->created_at)['display'] ?? null,
            'printed_at' => $this->datetime($job->printed_at)['display'] ?? null,
        ];
    }

    /**
     * Shared by the create and edit pages: the record, the two relation option lists, the
     * status picker and the page's own header actions.
     *
     * @return array<string, mixed>
     */
    private function formProps(?PrintJob $printJob): array
    {
        return [
            'job' => $printJob === null ? null : [
                'id' => $printJob->id,
                'printer_id' => $printJob->printer_id,
                'print_batch_id' => $printJob->print_batch_id,
                'sequence' => $printJob->sequence,
                'printable' => $this->printable($printJob),
                'type' => $printJob->type?->value,
                'status' => $printJob->status?->value,
                'statusLabel' => Status::printJob($printJob->status),
                'priority' => $printJob->priority,
                'retry_count' => $printJob->retry_count,
                'error_message' => $printJob->error_message,
                'firmware_job_id' => $printJob->firmware_job_id,
                'firmware_job_uuid' => $printJob->firmware_job_uuid,
            ],
            'printers' => Printer::orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Printer $printer) => ['value' => $printer->id, 'label' => $printer->name])
                ->all(),
            /*
             * Only batches that can still take work. Attaching a new card to a completed
             * or cancelled run would put it somewhere nothing will ever claim it from,
             * which is the orphan audit 89 describes by another route.
             */
            'batches' => PrintBatch::query()
                ->whereIn('status', self::openBatchStatuses())
                ->orderByDesc('id')
                ->get()
                ->map(fn (PrintBatch $batch) => [
                    'value' => $batch->id,
                    'label' => ($batch->name ?? 'Batch #'.$batch->id).' - '.$batch->status?->label(),
                ])
                ->all(),
            'printableTypes' => collect(self::PRINTABLES)
                ->map(fn (array $printable, string $class) => ['value' => $class, 'label' => $printable['label']])
                ->values()
                ->all(),
            'typeOptions' => [
                ['value' => PrintJobTypeEnum::Badge->value, 'label' => 'Badge'],
                ['value' => PrintJobTypeEnum::Receipt->value, 'label' => 'Receipt'],
            ],
            /*
             * The transition picker. On an existing record: the state it is in, plus the
             * edges PrintJobStatusEnum allows from there. On a new one there is nothing to
             * transition from, so the status is fixed at Pending and shown read-only.
             */
            'statusOptions' => $printJob === null ? [] : collect([
                $printJob->status,
                ...self::allowedTransitions($printJob),
            ])
                ->filter()
                ->unique()
                ->map(fn (PrintJobStatusEnum $case) => ['value' => $case->value, 'label' => $case->label()])
                ->values()
                ->all(),
            /*
             * EditPrintJob's own header: View, and Delete with Filament's default delete
             * copy (audit 4.9).
             */
            'actions' => $printJob === null ? [] : array_map(
                fn (Action $action) => $action->toArray(),
                array_values(array_filter([
                    Action::link('view', 'View', route('admin.print-jobs.show', $printJob))->icon('eye'),
                    Gate::allows('delete', $printJob)
                        ? Action::delete('delete', 'Delete', route('admin.print-jobs.destroy', $printJob))
                            ->icon('trash-2')
                            ->tone(Status::DANGER)
                            ->confirmDelete(self::MODEL_LABEL)
                        : null,
                ])),
            ),
        ];
    }

    /**
     * The batch statuses that can still take a new card. Read by the create form and by
     * PrintJobRequest, so the select and the rule cannot disagree.
     *
     * @return array<int, string>
     */
    public static function openBatchStatuses(): array
    {
        return collect(PrintBatchStatusEnum::cases())
            ->reject(fn (PrintBatchStatusEnum $case) => $case->isTerminal())
            ->map(fn (PrintBatchStatusEnum $case) => $case->value)
            ->values()
            ->all();
    }

    /**
     * @return array{display: string, title: string}|null
     */
    private function datetime(?CarbonInterface $value): ?array
    {
        if ($value === null) {
            return null;
        }

        return [
            'display' => $value->format(self::DATETIME_FORMAT),
            'title' => $value->toIso8601String(),
        ];
    }
}
