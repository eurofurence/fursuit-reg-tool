<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Http\Requests\Manage\MachineRequest;
use App\Models\Machine;
use App\Models\SumUpReader;
use App\Support\Manage\Action;
use App\Support\Manage\Column;
use App\Support\Manage\Filter;
use App\Support\Manage\Status;
use App\Support\Manage\Table;
use App\Support\Manage\Toast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Response;

/**
 * Machines, the successor to MachineResource and its three pages (audit 4.6).
 *
 * Two shapes change and neither is a parity gap.
 *
 *  - The table gets a search box and a pager. Filament called ->searchable(false) and
 *    ->paginated(false), so `name`'s own searchable() was unreachable and every machine
 *    rendered on one page; the list becomes perPage 200 with the pager visible and the
 *    search box working (plan 2.3).
 *  - Archive and restore are endpoints rather than closures on a row, and they flash a
 *    toast. Both were completely silent (plan 2.10 #45).
 *
 * The login link lives in MachineLoginLinkController: it mints a credential, so it gets
 * its own route, its own policy ability and its own audit entry rather than riding along
 * with CRUD.
 *
 * Nothing here is event-scoped. Plan 2.9 lists Machines among the surfaces that stay
 * unscoped, matching today, and a till belongs to the hall rather than to an event.
 *
 * There is no delete of any kind, single or bulk. There is none in the Filament resource
 * either (audit 131) and archiving is the retirement path.
 */
class MachineController extends Controller
{
    /**
     * The list envelope is spread across top-level props rather than nested under one,
     * because useTableQuery reloads `rows`, `meta`, `filters`, `sort` and `search` as a
     * partial visit and Inertia filters partials by top-level key.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Machine::class);

        return inertia('Manage/Machines/Index', $this->table($request));
    }

    public function create(): Response
    {
        Gate::authorize('create', Machine::class);

        return inertia('Manage/Machines/Form', [
            'machine' => null,
            'sumupReaders' => $this->sumupReaderOptions(),
            'actions' => [],
        ]);
    }

    public function store(MachineRequest $request): RedirectResponse
    {
        Machine::create($request->validated());

        // Filament's built-in Created toast; MachineResource defines none of its own
        // (audit 7.2).
        Toast::flashSuccess('Created');

        return redirect()->route('admin.machines.index');
    }

    public function edit(Machine $machine): Response
    {
        Gate::authorize('update', $machine);

        return inertia('Manage/Machines/Form', [
            'machine' => $this->formData($machine),
            'sumupReaders' => $this->sumupReaderOptions(),
            // The Login Link header action of EditMachine, declared server-side so the
            // page can be looked at without minting anything. Nothing is generated until
            // the button is pressed.
            'actions' => $this->editActions($machine),
        ]);
    }

    public function update(MachineRequest $request, Machine $machine): RedirectResponse
    {
        $machine->update($request->validated());

        Toast::flashSuccess('Saved');

        return redirect()->route('admin.machines.index');
    }

    /**
     * Hide the machine from the default list and from POS login.
     *
     * Machine::$timestamps is false, so `archived_at` is the only trace this leaves;
     * that is what the Filament action did too (audit 131) and changing it is not this
     * phase's business.
     */
    public function archive(Machine $machine): RedirectResponse
    {
        Gate::authorize('update', $machine);

        $machine->archive();

        Toast::flashSuccess('Archived', $machine->name.' is hidden from normal view.');

        return back();
    }

    public function unarchive(Machine $machine): RedirectResponse
    {
        Gate::authorize('update', $machine);

        $machine->unarchive();

        Toast::flashSuccess('Restored', $machine->name.' is visible again.');

        return back();
    }

    public function bulkArchive(Request $request): RedirectResponse
    {
        return $this->bulk($request, archive: true);
    }

    public function bulkUnarchive(Request $request): RedirectResponse
    {
        return $this->bulk($request, archive: false);
    }

    /**
     * All-or-nothing (plan 2.5): if any selected machine fails the policy nothing moves
     * and a danger toast says why, rather than half a selection changing state.
     *
     * The endpoint authorizes the same question the bulk button is offered on, so an
     * operator who never sees the button gets a 403 rather than a toast.
     */
    private function bulk(Request $request, bool $archive): RedirectResponse
    {
        Gate::authorize('update', new Machine);

        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);

        $machines = Machine::whereIn('id', $validated['ids'])->get();

        foreach ($machines as $machine) {
            if (Gate::denies('update', $machine)) {
                Toast::flashDanger(
                    $archive ? 'Nothing was archived' : 'Nothing was restored',
                    'You are not allowed to change one or more of the selected machines.'
                );

                return back();
            }
        }

        // Per record rather than a mass update, which is what `$records->each->archive()`
        // did, so each row goes through the model's own method.
        $archive ? $machines->each->archive() : $machines->each->unarchive();

        $archive
            ? Toast::flashSuccess('Archived', $this->countBody($machines->count()).' hidden from normal view.')
            : Toast::flashSuccess('Restored', $this->countBody($machines->count()).' visible again.');

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function table(Request $request): array
    {
        return Table::make($this->baseQuery($request))
            ->name('machines')
            ->columns($this->columns())
            // MachineResource declares no defaultSort and falls back to primary-key
            // order. Stated rather than left implicit, so the order does not depend on
            // whatever the driver happens to return.
            ->defaultSort('id')
            ->filters($this->filters())
            // ->paginated(false) today; 200 a page with the pager visible, so a hall
            // full of tills cannot become an unbounded page render (plan 2.3).
            ->perPage(200)
            ->perPageOptions([25, 50, 100, 200])
            ->rows(fn (Machine $machine) => [
                'name' => $machine->name,
                'sumupReader.name' => $machine->sumupReader?->name,
                'should_discover_printers' => (bool) $machine->should_discover_printers,
            ])
            ->recordUrl(fn (Machine $machine) => Gate::allows('update', $machine)
                ? route('admin.machines.edit', $machine)
                : null)
            ->rowActions(fn (Machine $machine) => $this->rowActions($machine))
            ->bulkActions($this->bulkActions())
            ->pageActions($this->pageActions())
            ->toArray($request);
    }

    /**
     * The list query, already carrying the blank branch of the archived filter.
     *
     * Nothing scopes archived machines at model level - there is no global scope and
     * `withArchived()` is a no-op that returns the query untouched (audit 43) - so the
     * Filament ternary's blank branch, `notArchived()`, is the only thing keeping retired
     * tills out of the list. A blank filter value is inactive by design in
     * App\Support\Manage\Filter, because an unset filter must not narrow anything, so the
     * blank branch cannot live in the filter's apply(). It lives here, where clearing the
     * filter lands on it as well: cleared and unset both mean "Active machines", exactly
     * as they do today.
     */
    private function baseQuery(Request $request): Builder
    {
        $query = Machine::query()->with('sumupReader');

        $archived = $request->input('filter.archived');

        if ($archived === null || $archived === Filter::CLEARED || $archived === '') {
            $query->notArchived();
        }

        return $query;
    }

    /**
     * The audit's table, in order. Labels are MachineResource's own, verbatim.
     *
     * `name` is the one searchable column the resource declared. Filament's
     * ->searchable(false) at table level made it unreachable; the box works here
     * (plan 2.3).
     *
     * @return array<int, Column>
     */
    private function columns(): array
    {
        return [
            Column::text('name', 'Name')->searchable(),
            Column::text('sumupReader.name', 'SumUp Reader')->fallback('None assigned'),
            Column::bool('should_discover_printers', 'Auto-discover Printers'),
        ];
    }

    /**
     * @return array<int, Filter>
     */
    private function filters(): array
    {
        return [
            Filter::ternary('archived', 'Archived')
                ->placeholder('Active machines')
                ->trueLabel('Archived machines')
                ->falseLabel('All machines')
                // Blank is the default and is applied by baseQuery(); see the note there.
                // `false` is Filament's `withArchived()`, a scope that returns the query
                // unchanged, so "All machines" narrows nothing.
                ->apply(function (Builder $query, string $value) {
                    if ($value === '1') {
                        $query->onlyArchived();
                    }
                }),
        ];
    }

    /**
     * @return array<int, Action>
     */
    private function rowActions(Machine $machine): array
    {
        if (Gate::denies('update', $machine)) {
            return [];
        }

        return array_values(array_filter([
            Action::link('edit', 'Edit', route('admin.machines.edit', $machine))->icon('pencil'),

            $machine->isArchived()
                ? null
                : Action::post('archive', 'Archive', route('admin.machines.archive', $machine))
                    ->icon('archive')
                    ->tone(Status::WARN)
                    ->confirm(
                        'Archive Machine',
                        'Are you sure you want to archive this machine? It will be hidden from normal view.',
                        'Yes, archive it',
                    ),

            $machine->isArchived()
                ? Action::delete('unarchive', 'Restore', route('admin.machines.unarchive', $machine))
                    ->icon('rotate-ccw')
                    ->tone(Status::OK)
                    ->confirm(
                        'Restore Machine',
                        'Are you sure you want to restore this machine? It will be visible again.',
                        'Yes, restore it',
                    )
                : null,
        ]));
    }

    /**
     * Both bulk actions, unconditionally, exactly as the Filament table offered them:
     * neither carried a visibility predicate, and a selection can hold archived and
     * unarchived machines at once.
     *
     * @return array<int, Action>
     */
    private function bulkActions(): array
    {
        // A bare class name would reach MachinePolicy::update() as its $machine argument
        // and fail the type hint, so the question "may this operator change machines at
        // all" is asked with a throwaway instance. The policy answers on the actor.
        if (Gate::denies('update', new Machine)) {
            return [];
        }

        return [
            Action::post('archive', 'Archive selected', route('admin.machines.bulk.archive'))
                ->icon('archive')
                ->tone(Status::WARN)
                ->confirm(
                    'Archive Machines',
                    'Are you sure you want to archive the selected machines? They will be hidden from normal view and unable to log in to the POS system.',
                    'Yes, archive them',
                ),

            Action::delete('unarchive', 'Restore selected', route('admin.machines.bulk.unarchive'))
                ->icon('rotate-ccw')
                ->tone(Status::OK)
                ->confirm(
                    'Restore Machines',
                    'Are you sure you want to restore the selected machines? They will be visible again and able to log in to the POS system.',
                    'Yes, restore them',
                ),
        ];
    }

    /**
     * @return array<int, Action>
     */
    private function pageActions(): array
    {
        if (Gate::denies('create', Machine::class)) {
            return [];
        }

        return [
            Action::link('create', 'New machine', route('admin.machines.create'))->icon('plus'),
        ];
    }

    /**
     * The edit page's header actions: the login link, and only for an operator who may
     * mint one.
     *
     * @return array<int, array<string, mixed>>
     */
    private function editActions(Machine $machine): array
    {
        if (Gate::denies('loginLink', $machine)) {
            return [];
        }

        return [
            // The raw action name is what Filament rendered as the label, so the button
            // still reads `Login Link`.
            Action::post('login-link', 'Login Link', route('admin.machines.login-link', $machine))
                ->icon('key')
                ->toArray(),
        ];
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function sumupReaderOptions(): array
    {
        return $this->options(
            SumUpReader::query()->orderBy('id')->get(),
            fn (SumUpReader $reader) => $reader->name ?? 'Unknown SumUp Reader',
        );
    }

    /**
     * Neither Select is required, so both carry the empty option Filament rendered for a
     * nullable relation.
     *
     * @param  Collection<int, Model>  $records
     * @param  callable(Model): string  $label
     * @return array<int, array{value: string, label: string}>
     */
    private function options(Collection $records, callable $label): array
    {
        return $records
            ->map(fn ($record) => ['value' => (string) $record->getKey(), 'label' => $label($record)])
            ->prepend(['value' => '', 'label' => '-'])
            ->values()
            ->all();
    }

    private function countBody(int $count): string
    {
        return $count === 1 ? '1 machine is' : $count.' machines are';
    }

    /**
     * Exactly the fields the form writes, plus what the header needs to name the record.
     *
     * `Machine::$guarded = []`, so anything that reaches the model is written; this is
     * the other half of keeping MachineRequest's rule list closed.
     *
     * @return array<string, mixed>
     */
    private function formData(Machine $machine): array
    {
        return [
            'id' => $machine->id,
            'name' => $machine->name,
            'sumup_reader_id' => (string) ($machine->sumup_reader_id ?? ''),
            'should_discover_printers' => (bool) $machine->should_discover_printers,
        ];
    }
}
