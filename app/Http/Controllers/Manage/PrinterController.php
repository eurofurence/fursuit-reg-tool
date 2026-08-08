<?php

namespace App\Http\Controllers\Manage;

use App\Domain\Printing\Models\Printer;
use App\Enum\PrinterConditionEnum;
use App\Enum\PrintJobStatusEnum;
use App\Enum\PrintJobTypeEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Manage\PrinterRequest;
use App\Models\Machine;
use App\Support\Manage\Action;
use App\Support\Manage\Column;
use App\Support\Manage\Status;
use App\Support\Manage\Table;
use App\Support\Manage\Toast;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Inertia\Response;

/**
 * Printers, the successor to PrinterResource and its three pages (audit 4.7).
 *
 * This is the screen that tells staff the hardware is jammed, so most of what changes
 * here is about making that screen honest.
 *
 *  - Status is rendered through PrinterStatusEnum via Status::printer(). The resource
 *    carried its own map of six hardcoded lowercase strings to colours, which left six of
 *    the enum's twelve cases unstyled and used 'secondary', not a valid Filament v3
 *    colour, for a seventh (audit 4.7 column 4, audit 7.10). A null status no longer
 *    500s the whole table either (plan 2.10 #7, landmine 28).
 *  - The three job counts compare against PrintJobStatusEnum cases rather than the
 *    hardcoded 'pending' / 'queued' / 'printing' / 'retrying' / 'failed' strings the
 *    resource wrote out by hand, and they run as one withCount aggregate instead of
 *    three separate COUNT(*) queries per row on an unpaginated table (audit 96).
 *  - The condition columns land. `condition`, `condition_message`, `cards_remaining`,
 *    `cards_capacity` and `condition_reported_at` have existed since 2026_08_05_100300
 *    and appear nowhere in admin (plan 2.10 #27); they become columns here and a panel
 *    on the record, carrying PrinterConditionEnum::remedy() where it has one.
 *  - `is_active` stays an inline toggle, but it posts to PrinterStateController with the
 *    state it means rather than writing through a table column (audit 92). The poll never
 *    touches it: usePoll reloads `rows` and `meta` only.
 *
 * The list is not event-scoped: printers belong to the hall, not to an event (plan 2.9).
 */
class PrinterController extends Controller
{
    /**
     * Filament's default table date-time format, kept so the column reads the same after
     * the move. Rendered on the server; the ISO string rides along as the cell title.
     */
    private const DATETIME_FORMAT = 'M j, Y H:i:s';

    /**
     * `->paginated(false)` becomes 200 a page with the pager visible, so an unbounded
     * printer table cannot become an unbounded page render (plan 2.3).
     */
    private const PER_PAGE = 200;

    /**
     * The type options PrinterResource's form declared, verbatim and in its order. The
     * values come off PrintJobTypeEnum rather than being retyped; the enum owns no
     * label() of its own, so the two words stay here (audit 4.7 form, audit 7.11).
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function typeOptions(): array
    {
        return [
            ['value' => PrintJobTypeEnum::Receipt->value, 'label' => 'Receipt'],
            ['value' => PrintJobTypeEnum::Badge->value, 'label' => 'Badge'],
        ];
    }

    /**
     * The list envelope is spread across top-level props rather than nested under one,
     * because useTableQuery reloads `rows`, `meta`, `filters`, `sort` and `search` as a
     * partial visit and Inertia filters partials by top-level key.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Printer::class);

        return inertia('Manage/Printers/Index', $this->table($request));
    }

    public function create(): Response
    {
        Gate::authorize('create', Printer::class);

        return inertia('Manage/Printers/Form', [
            'printer' => null,
            'machines' => $this->machineOptions(),
            'types' => self::typeOptions(),
            // A printer that has never reported has no paper sizes to choose from. The
            // Filament closure type-hinted a non-nullable `Printer $record` here, so the
            // create page threw a TypeError the moment it rendered (plan 2.10 #7,
            // landmine 27); an empty list and a helper text is what it should have done.
            'paperSizes' => [],
            'condition' => null,
            'actions' => [],
        ]);
    }

    public function store(PrinterRequest $request): RedirectResponse
    {
        $attributes = $request->validated();

        // `paper_sizes` is a disabled field: the print agent fills it in when the printer
        // reports itself. The column is a non-nullable json, so a create still has to
        // write something, and Filament's own default for the field was '{}'.
        $attributes['paper_sizes'] = [];

        Printer::create($attributes);

        // Filament's built-in Created toast; PrinterResource defines none of its own
        // (audit 7.2).
        Toast::flashSuccess('Created');

        return redirect()->to(Table::returnUrl('printers', route('admin.printers.index')));
    }

    public function edit(Printer $printer): Response
    {
        Gate::authorize('update', $printer);

        return inertia('Manage/Printers/Form', [
            'printer' => $this->formData($printer),
            'machines' => $this->machineOptions(),
            'types' => self::typeOptions(),
            'paperSizes' => $this->paperSizeOptions($printer),
            'condition' => $this->conditionPanel($printer),
            // EditPrinter's header actions were `[Actions\DeleteAction::make()]`, so the
            // delete lives on this page rather than on the row (audit 4.7).
            'actions' => array_values(array_filter([
                $this->clearErrorAction($printer),
                Gate::allows('delete', $printer)
                    ? Action::delete('delete', 'Delete', route('admin.printers.destroy', $printer))
                        ->icon('trash-2')
                        ->tone('danger')
                        ->confirmDelete('printer')
                    : null,
            ])),
        ]);
    }

    public function update(PrinterRequest $request, Printer $printer): RedirectResponse
    {
        // `paper_sizes` is never in the validated set, so the agent's reading cannot be
        // overwritten by a form post.
        $printer->update($request->validated());

        Toast::flashSuccess('Saved');

        return redirect()->to(Table::returnUrl('printers', route('admin.printers.index')));
    }

    /**
     * Hard delete: Printer carries no SoftDeletes, and audit 7.7 lists printers among the
     * tables that stay hard deletes.
     *
     * Refused while any print job still points at this printer. `print_jobs.printer_id`
     * is `cascadeOnDelete`, so deleting the printer would take its jobs with it without
     * anyone calling PrintBatch::recalculateCounters(), which is exactly how
     * `total_jobs` / `printed_count` / `verified_count` / `failed_count` get permanently
     * desynced (audit landmines 23 and 80). Every progress badge in the printing slice
     * reads those counters, so this is refused rather than repaired afterwards.
     */
    public function destroy(Printer $printer): RedirectResponse
    {
        Gate::authorize('delete', $printer);

        if ($printer->printJobs()->exists()) {
            Toast::flashDanger(
                'Nothing was deleted',
                'This printer still has print jobs. Deleting it would delete them too and leave every batch counter wrong.'
            );

            return back();
        }

        $printer->delete();

        Toast::flashSuccess('Deleted');

        return redirect()->to(Table::returnUrl('printers', route('admin.printers.index')));
    }

    /**
     * All-or-nothing (plan 2.5): if any selected record fails the policy, or still holds
     * print jobs, nothing is deleted and a danger toast says why.
     */
    public function bulkDestroy(Request $request): RedirectResponse
    {
        Gate::authorize('delete', new Printer);

        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);

        $printers = Printer::query()
            ->whereIn('id', $validated['ids'])
            ->withCount('printJobs')
            ->get();

        foreach ($printers as $printer) {
            if (Gate::denies('delete', $printer)) {
                Toast::flashDanger(
                    'Nothing was deleted',
                    'You are not allowed to delete one or more of the selected printers.'
                );

                return back();
            }

            if ($printer->print_jobs_count > 0) {
                Toast::flashDanger(
                    'Nothing was deleted',
                    'One or more of the selected printers still has print jobs. Deleting them would delete those jobs too and leave every batch counter wrong.'
                );

                return back();
            }
        }

        // Per record rather than a mass delete, so model events still fire, which is
        // what Filament's DeleteBulkAction did.
        $printers->each->delete();

        Toast::flashSuccess('Deleted');

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function table(Request $request): array
    {
        return Table::make($this->query())
            ->name('printers')
            // PrinterResource declares no defaultSort and falls back to primary-key
            // order. Stated rather than left implicit, so the order does not depend on
            // whatever the driver happens to return.
            ->defaultSort('id')
            ->columns($this->columns())
            // `->filters([//])`: the resource declares none, and none are added.
            ->filters([])
            ->perPage(self::PER_PAGE)
            ->rows(fn (Printer $printer) => $this->row($printer))
            ->recordUrl(fn (Printer $printer) => Gate::allows('update', $printer)
                ? route('admin.printers.edit', $printer)
                : null)
            ->rowActions(fn (Printer $printer) => array_values(array_filter([
                Gate::allows('update', $printer)
                    ? Action::link('edit', 'Edit', route('admin.printers.edit', $printer))->icon('pencil')
                    : null,
                $this->clearErrorAction($printer),
            ])))
            ->bulkActions($this->bulkActions())
            ->pageActions($this->pageActions())
            ->toArray($request);
    }

    /**
     * One aggregate query for the three job counts, plus the machine the Machine column
     * names. The resource ran `$record->printJobs()->...->count()` three times per row
     * with pagination off (audit 96).
     */
    private function query(): Builder
    {
        $active = collect(PrintJobStatusEnum::cases())
            ->filter(fn (PrintJobStatusEnum $status) => $status->isActive())
            ->map(fn (PrintJobStatusEnum $status) => $status->value)
            ->values()
            ->all();

        return Printer::query()
            ->with('machine')
            ->withCount([
                'printJobs as pending_jobs_count' => fn (Builder $query) => $query
                    ->where('status', PrintJobStatusEnum::Pending->value),
                // PrintJobStatusEnum::isActive() is Queued|Printing|Retrying, which is
                // the trio the resource listed as literal strings.
                'printJobs as active_jobs_count' => fn (Builder $query) => $query
                    ->whereIn('status', $active),
                'printJobs as failed_jobs_count' => fn (Builder $query) => $query
                    ->where('status', PrintJobStatusEnum::Failed->value),
            ]);
    }

    /**
     * The audit's nine columns, in order, with the five condition columns of plan 2.10
     * #27 inserted behind `status`, which is where the hardware picture belongs.
     *
     * @return array<int, Column>
     */
    private function columns(): array
    {
        return [
            // `->searchable(false)` at table level hid the search box today and made this
            // column's own searchable unreachable. The box comes back (plan 2.3).
            Column::text('name', 'Name')->searchable(),
            Column::text('type', 'Type'),
            Column::text('machine.name', 'Machine'),
            Column::badge('status', 'Status'),
            Column::badge('condition', 'Condition'),
            Column::text('condition_message', 'Condition detail')->toggleable(),
            Column::text('cards', 'Cards')->align('right')->toggleable(),
            Column::datetime('condition_reported_at', 'Condition reported')
                ->toggleable(hiddenByDefault: true),
            Column::badge('pending_jobs', 'Pending Jobs')->align('center'),
            Column::badge('active_jobs', 'Active Jobs')->align('center'),
            Column::badge('failed_jobs', 'Failed Jobs')->align('center'),
            Column::toggle('is_active', 'Active'),
            Column::datetime('last_state_update', 'Last Update'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Printer $printer): array
    {
        $condition = $this->condition($printer);

        return [
            'name' => $printer->name,
            // The raw enum-backed value, `badge` or `receipt`, which is what the column
            // shows today. Read off the cast rather than the attribute so it cannot drift
            // from the enum.
            'type' => $printer->type?->value,
            'machine.name' => $printer->machine?->name,
            'status' => Status::printer($printer->status),
            'condition' => Status::printerCondition($condition),
            'condition_message' => $this->conditionDetail($printer, $condition),
            'cards' => $this->cards($printer),
            'condition_reported_at' => $this->datetime($printer->condition_reported_at),
            'pending_jobs' => $this->jobCount((int) $printer->pending_jobs_count, Status::WARN, $printer),
            'active_jobs' => $this->jobCount((int) $printer->active_jobs_count, Status::INFO, $printer),
            'failed_jobs' => $this->jobCount((int) $printer->failed_jobs_count, Status::DANGER, $printer),
            'is_active' => [
                'value' => (bool) $printer->is_active,
                // The URL carries the state the operator is asking for, taken from what
                // they are looking at, rather than "flip whatever is in the database when
                // this arrives". A poll that refreshed the row in between rewrites the
                // URL with it, so a click can never mean the opposite of what it showed.
                'url' => route('admin.printers.active', [
                    'printer' => $printer->getKey(),
                    'active' => $printer->is_active ? 0 : 1,
                ]),
            ],
            // `->dateTime()->since()`: since() wins, so this renders as a human diff
            // (audit 7.4).
            'last_state_update' => $this->since($printer->last_state_update),
        ];
    }

    /**
     * A count badge that links to that printer's jobs, the way the resource's three count
     * columns did through `PrintJobResource::getUrl('index', ['printer' => $record->id])`.
     *
     * Zero is toned idle rather than kept warning or danger: three coloured chips on every
     * row is noise on the one table that exists to make a real fault obvious.
     *
     * The link is only attached once the print-job list exists. Module route files land
     * phase by phase, and route() on a name that is not registered throws.
     *
     * @return array<string, mixed>
     */
    private function jobCount(int $count, string $tone, Printer $printer): array
    {
        $status = Status::make((string) $count, $count > 0 ? $tone : Status::IDLE);

        if (! Route::has('admin.print-jobs.index')) {
            return $status;
        }

        return $status + [
            'url' => route('admin.print-jobs.index', ['filter' => ['printer' => $printer->getKey()]]),
        ];
    }

    /**
     * The condition the agent last reported. Null-safe for the same reason `status` is:
     * nothing in this panel should 500 over a value it has not seen before.
     */
    private function condition(Printer $printer): ?PrinterConditionEnum
    {
        $raw = $printer->condition;

        if ($raw instanceof PrinterConditionEnum) {
            return $raw;
        }

        return $raw === null ? null : PrinterConditionEnum::tryFrom((string) $raw);
    }

    /**
     * What staff read next to the condition badge: the agent's own message where it left
     * one, otherwise the enum's remedy, which is the sentence the POS alert shows and
     * admin has never carried (plan 2.10 #27).
     *
     * @return array{display: string, title: string|null}|null
     */
    private function conditionDetail(Printer $printer, ?PrinterConditionEnum $condition): ?array
    {
        $remedy = $condition?->remedy();
        $message = $printer->condition_message;
        $display = $message ?: $remedy;

        if ($display === null || $display === '') {
            return null;
        }

        return ['display' => $display, 'title' => $remedy];
    }

    /**
     * Cards left in the hopper against its capacity, from the SNMP supply reading.
     *
     * @return array{display: string, title: string}|null
     */
    private function cards(Printer $printer): ?array
    {
        $remaining = $printer->cards_remaining;
        $capacity = $printer->cards_capacity;

        if ($remaining === null && $capacity === null) {
            return null;
        }

        return [
            'display' => ($remaining === null ? '?' : (string) $remaining)
                .' / '
                .($capacity === null ? '?' : (string) $capacity),
            'title' => 'Cards remaining of hopper capacity',
        ];
    }

    /**
     * The Clear error action of plan 2.10 #27, built on Printer::clearPrinterError(),
     * which has always existed and which the panel never called (audit 94).
     *
     * Offered on every printer rather than hidden when it cannot fire, carrying the
     * reason: the Filament panel hid actions instead, which leaves an operator staring
     * at a paused printer wondering where the button went.
     */
    private function clearErrorAction(Printer $printer): ?Action
    {
        if (Gate::denies('update', $printer)) {
            return null;
        }

        $action = Action::post('clear-error', 'Clear error', route('admin.printers.clear-error', $printer))
            ->icon('rotate-ccw')
            ->tone('warn')
            ->confirm(
                'Clear printer error',
                'This sets the printer back to Ready and drops the job it is holding. Only do it once the hardware itself is fixed.',
                'Clear error',
            );

        if (! PrinterStateController::hasClearableError($printer)) {
            $action->disabled('This printer has no paused or offline state to clear.');
        }

        return $action;
    }

    /**
     * @return array<int, Action>
     */
    private function bulkActions(): array
    {
        // A bare class name would reach PrinterPolicy::delete() as its $printer argument
        // and fail the type hint, so the question "may this operator delete printers at
        // all" is asked with a throwaway instance.
        if (! Gate::allows('delete', new Printer)) {
            return [];
        }

        return [
            Action::delete('delete', 'Delete selected', route('admin.printers.bulk.destroy'))
                ->icon('trash-2')
                ->tone('danger')
                ->confirm('Delete selected printers', Action::DEFAULT_CONFIRM_DESCRIPTION, 'Delete'),
        ];
    }

    /**
     * ListPrinters returned `[]` from getHeaderActions(), so the create page and its
     * route existed with nothing in the UI pointing at them (audit landmine 39, which
     * asks for an explicit decision). The button is surfaced: the create page is a real,
     * policy-gated page, and an orphaned route is worse than a button.
     *
     * @return array<int, Action>
     */
    private function pageActions(): array
    {
        if (! Gate::allows('create', Printer::class)) {
            return [];
        }

        return [
            Action::link('create', 'New printer', route('admin.printers.create'))->icon('plus'),
        ];
    }

    /**
     * @return array<int, array{value: int, label: string}>
     */
    private function machineOptions(): array
    {
        return Machine::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Machine $machine) => ['value' => $machine->id, 'label' => $machine->name])
            ->all();
    }

    /**
     * `collect($record->paper_sizes)->pluck('name', 'name')`, without the null record the
     * Filament closure could not survive.
     *
     * @return array<int, array{value: string, label: string}>
     */
    private function paperSizeOptions(Printer $printer): array
    {
        return collect($printer->paper_sizes ?? [])
            ->pluck('name')
            ->filter(fn ($name) => is_string($name) && $name !== '')
            ->unique()
            ->values()
            ->map(fn (string $name) => ['value' => $name, 'label' => $name])
            ->all();
    }

    /**
     * The detail panel of plan 2.10 #27: everything the agent reports that no column has
     * room for, plus the error the Clear error action clears, so nobody clears a state
     * they cannot see.
     *
     * @return array<string, mixed>
     */
    private function conditionPanel(Printer $printer): array
    {
        $condition = $this->condition($printer);

        return [
            'status' => Status::printer($printer->status),
            'condition' => Status::printerCondition($condition),
            'message' => $printer->condition_message,
            'remedy' => $condition?->remedy(),
            'cards' => $this->cards($printer)['display'] ?? null,
            'reportedAt' => $this->datetime($printer->condition_reported_at),
            'lastStateUpdate' => $this->since($printer->last_state_update),
            'lastErrorMessage' => $printer->last_error_message,
            'handlingMachineName' => $printer->handling_machine_name,
        ];
    }

    /**
     * @return array{display: string, title: string}|null
     */
    private function datetime(mixed $value): ?array
    {
        $moment = $this->moment($value);

        if ($moment === null) {
            return null;
        }

        return [
            'display' => $moment->format(self::DATETIME_FORMAT),
            'title' => $moment->toIso8601String(),
        ];
    }

    /**
     * A relative time with the absolute one in the tooltip, which is what
     * `->dateTime()->since()` rendered.
     *
     * @return array{display: string, title: string}|null
     */
    private function since(mixed $value): ?array
    {
        $moment = $this->moment($value);

        if ($moment === null) {
            return null;
        }

        return [
            'display' => $moment->diffForHumans(),
            'title' => $moment->toIso8601String(),
        ];
    }

    /**
     * Printer::casts() covers `last_state_update` but not `condition_reported_at`, which
     * therefore arrives as a raw string. Both timestamps render the same way, so the
     * difference is absorbed here rather than by adding a cast to a model the POS and the
     * print agent also write through.
     */
    private function moment(mixed $value): ?CarbonInterface
    {
        if ($value instanceof CarbonInterface) {
            return $value;
        }

        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse($value);
    }

    /**
     * Exactly the fields the form writes, so nothing else can round-trip through it.
     * `reportedPaperSizes` rides along read-only: the agent owns the reading, the panel
     * only shows it, and PrinterRequest does not accept the column under any name.
     *
     * @return array<string, mixed>
     */
    private function formData(Printer $printer): array
    {
        return [
            'id' => $printer->id,
            'name' => $printer->name,
            'type' => $printer->type?->value,
            'machine_id' => $printer->machine_id,
            'default_paper_size' => $printer->default_paper_size,
            'reportedPaperSizes' => $this->reportedPaperSizes($printer),
            'is_active' => (bool) $printer->is_active,
        ];
    }

    /**
     * What the print agent last reported, as rows rather than as a JSON document.
     *
     * Filament printed this column into a Textarea, so the one place in the panel that
     * showed a printer's real capabilities showed them as pretty-printed JSON. It is a
     * short list of named sizes with a couple of measurements each, which is a table; the
     * braces were never information. Nothing here is editable, so this is a display
     * decision only - the column is still written exclusively by AgentSessionController.
     *
     * The shape the agent posts is free-form beyond `name`, so every other key is rendered
     * as `key: value` in the order it arrived rather than being mapped to fields this
     * controller would have to keep in step with the agent.
     *
     * @return list<array{name: string, detail: string|null}>
     */
    private function reportedPaperSizes(Printer $printer): array
    {
        return collect($printer->paper_sizes ?? [])
            ->map(function ($size, $key) {
                if (! is_array($size)) {
                    return ['name' => (string) $key, 'detail' => self::scalar($size)];
                }

                $name = isset($size['name']) && is_scalar($size['name'])
                    ? (string) $size['name']
                    : (string) $key;

                $detail = collect($size)
                    ->except('name')
                    ->map(fn ($value, string $attribute) => $attribute.': '.self::scalar($value))
                    ->implode(' · ');

                return ['name' => $name, 'detail' => $detail === '' ? null : $detail];
            })
            ->values()
            ->all();
    }

    /**
     * One reported measurement as text. Nested values (`mm: [54, 86]`) are joined rather
     * than json-encoded, so no row can put braces back on the page.
     */
    private static function scalar(mixed $value): string
    {
        if (is_array($value)) {
            return implode(' × ', array_map(self::scalar(...), $value));
        }

        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }

        return $value === null ? '—' : (string) $value;
    }
}
