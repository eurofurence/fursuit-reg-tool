<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Http\Requests\Manage\SumUpReaderRequest;
use App\Models\SumUpReader;
use App\Support\Manage\Action;
use App\Support\Manage\Column;
use App\Support\Manage\Table;
use App\Support\Manage\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Response;

/**
 * SumUp readers, the successor to the old reader list and its three pages.
 *
 * Three shapes change and none of them is a parity gap.
 *
 *  - The `paring_code` is masked. the old panel rendered the pairing code of a payment terminal
 *    as a plain table column and a plain text input, so every list view and every
 *    screenshot of this page leaked it. Masked here
 *    means the plaintext never enters the list payload at all: the cell carries a fixed
 *    dot string, and the real value only ever leaves the server through `reveal`, which is
 *    its own authorized request and writes an activity entry naming who asked.
 *  - `remote_id` is no longer writable. `readOnly()` was a client-side attribute over a
 *    field that still round-tripped through the request into a `$guarded = []` model, so a
 *    crafted POST rewrote the SumUp-side binding. It is
 *    shown on the form as read-only text and is not in the request payload at all.
 *  - Create, edit and delete are real pages and real routes rather than the resource's
 *    the old panel pages, and the single delete that lived on the Edit page header is
 *    registered as its own route alongside the bulk one.
 *
 * The column name stays misspelled. `paring_code` is baked into
 * 2024_09_14_224516_create_sumup_readers_table and into the POS code paths that read it,
 * so correcting the spelling here would break them.
 *
 * On audit 132, deleting a reader still breaks the human-readable link between a past card
 * checkout and the terminal that took it: `machines.sumup_reader_id` is `nullOnDelete`.
 * The warning is deliberately not in the confirm copy, because both delete confirmations
 * are pinned to the old panel's default copy verbatim by the parity checklist and a second
 * sentence would break that. The decision is recorded here instead.
 *
 * The list is deliberately not event-scoped: plan 2.9 lists SumUp readers among the
 * surfaces that stay unscoped, matching today.
 */
class SumUpReaderController extends Controller
{
    /**
     * What the list ships in place of the pairing code. A fixed width rather than one dot
     * per character, so the payload does not leak the length of the credential either.
     */
    private const MASK = '••••••••';

    /**
     * Where a revealed pairing code is parked for exactly one request. A flash rather than
     * a prop the page keeps: reloading the page must not show the secret again.
     */
    private const REVEALED_KEY = 'manage_sumup_revealed';

    /**
     * The list envelope is spread across top-level props rather than nested under one,
     * because useTableQuery reloads `rows`, `meta`, `filters`, `sort` and `search` as a
     * partial visit and Inertia filters partials by top-level key.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', SumUpReader::class);

        return inertia('Manage/SumUpReaders/Index', [
            ...$this->table($request),
            'revealed' => $this->revealed(),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', SumUpReader::class);

        return inertia('Manage/SumUpReaders/Form', [
            'reader' => null,
            'revealed' => null,
            'actions' => [],
        ]);
    }

    public function store(SumUpReaderRequest $request): RedirectResponse
    {
        SumUpReader::create($request->validated());

        // the old panel's built-in Created toast; the old reader list defines none of its own
        // .
        Toast::flashSuccess('Created');

        return redirect()->to(Table::returnUrl('sumup-readers', route('admin.sumup-readers.index')));
    }

    public function edit(SumUpReader $reader): Response
    {
        Gate::authorize('update', $reader);

        return inertia('Manage/SumUpReaders/Form', [
            'reader' => $this->formData($reader),
            'revealed' => $this->revealed(),
            'actions' => $this->formActions($reader),
        ]);
    }

    /**
     * An empty `paring_code` means "keep the stored one": the form never receives the
     * current value, so an untouched field must not blank the credential. Dropping the key
     * rather than filtering it in the request keeps the validated array honest about what
     * was submitted.
     */
    public function update(SumUpReaderRequest $request, SumUpReader $reader): RedirectResponse
    {
        $data = $request->validated();

        if (($data['paring_code'] ?? '') === '' || $data['paring_code'] === null) {
            unset($data['paring_code']);
        }

        $reader->update($data);

        Toast::flashSuccess('Saved');

        return redirect()->to(Table::returnUrl('sumup-readers', route('admin.sumup-readers.index')));
    }

    /**
     * Reads the pairing code back in the clear, for one response only.
     *
     * A POST rather than a GET and a separate ability rather than a client-side toggle
     * over data already in the browser: the list payload does not contain the code, so the
     * only way to see it is to ask for it, and every ask is logged against the record with
     * the operator as the causer.
     */
    public function reveal(SumUpReader $reader): RedirectResponse
    {
        Gate::authorize('reveal', $reader);

        activity()
            ->performedOn($reader)
            ->log('Revealed SumUp pairing code');

        session()->flash(self::REVEALED_KEY, [
            'id' => $reader->getKey(),
            'name' => $reader->name,
            'paring_code' => $reader->paring_code,
        ]);

        return back();
    }

    /**
     * Hard delete: SumUpReader carries no SoftDeletes, and audit 7.7 lists SumUp readers
     * among the tables that stay hard deletes.
     *
     * The route the old panel Edit page header action had, which the plan's route table
     * originally missed.
     */
    public function destroy(SumUpReader $reader): RedirectResponse
    {
        Gate::authorize('delete', $reader);

        $reader->delete();

        Toast::flashSuccess('Deleted');

        return redirect()->to(Table::returnUrl('sumup-readers', route('admin.sumup-readers.index')));
    }

    /**
     * All-or-nothing: if any selected record fails the policy nothing is
     * deleted and a danger toast says why, rather than half a selection disappearing.
     *
     * The endpoint authorizes the same question the bulk button is offered on, so an
     * operator who never sees the button gets a 403 rather than a toast.
     */
    public function bulkDestroy(Request $request): RedirectResponse
    {
        Gate::authorize('delete', new SumUpReader);

        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);

        $readers = SumUpReader::whereIn('id', $validated['ids'])->get();

        foreach ($readers as $reader) {
            if (Gate::denies('delete', $reader)) {
                Toast::flashDanger(
                    'Nothing was deleted',
                    'You are not allowed to delete one or more of the selected SumUp readers.'
                );

                return back();
            }
        }

        // Per record rather than a mass delete, so model events still fire, which is what
        // the old panel's DeleteBulkAction did.
        $readers->each->delete();

        Toast::flashSuccess('Deleted');

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function table(Request $request): array
    {
        return Table::make(SumUpReader::query())
            ->name('sumup-readers')
            ->columns($this->columns())
            // the old reader list declares no defaultSort and falls back to primary-key
            // order. Stated rather than left implicit, so the order does not depend on
            // whatever the driver happens to return.
            ->defaultSort('id')
            // the old reader list declares `->filters([ // ])`.
            ->filters([])
            ->rows(fn (SumUpReader $reader) => [
                'name' => $reader->name,
                'remote_id' => $reader->remote_id,
                // The plaintext never reaches this array. Everything the client could
                // render, copy or leak into a screenshot is the mask.
                'paring_code' => [
                    'display' => self::MASK,
                    'title' => 'Hidden. Use Reveal to read it.',
                ],
            ])
            ->recordUrl(fn (SumUpReader $reader) => Gate::allows('update', $reader)
                ? route('admin.sumup-readers.edit', $reader)
                : null)
            ->rowActions(fn (SumUpReader $reader) => array_values(array_filter([
                Gate::allows('reveal', $reader)
                    ? Action::post('reveal', 'Reveal', route('admin.sumup-readers.reveal', $reader))
                        ->icon('eye')
                        ->confirm(
                            'Reveal pairing code',
                            'The pairing code pairs a card terminal with this event. It is shown once and the request is written to the activity log.',
                            'Reveal'
                        )
                    : null,
                // Edit, and no delete: audit 4.11 records `EditAction` only on the row.
                // The single delete lives on the Edit page header, where the old panel put it,
                // and the bulk delete on the selection.
                Gate::allows('update', $reader)
                    ? Action::link('edit', 'Edit', route('admin.sumup-readers.edit', $reader))->icon('pencil')
                    : null,
            ])))
            ->bulkActions($this->bulkActions())
            ->pageActions($this->pageActions())
            ->toArray($request);
    }

    /**
     * The audit's three columns, in order. None of them is sortable, searchable or
     * toggleable in the old panel, and none of them becomes so here.
     *
     * @return array<int, Column>
     */
    private function columns(): array
    {
        return [
            Column::text('name', 'Name'),
            Column::text('remote_id', 'Remote Id'),
            Column::text('paring_code', 'Paring Code'),
        ];
    }

    /**
     * @return array<int, Action>
     */
    private function bulkActions(): array
    {
        // A bare class name would reach SumUpReaderPolicy::delete() as its $model argument
        // and fail the type hint, so the question "may this operator delete readers at
        // all" is asked with a throwaway instance. The policy answers on the actor, not
        // the row.
        if (! Gate::allows('delete', new SumUpReader)) {
            return [];
        }

        return [
            Action::delete('delete', 'Delete selected', route('admin.sumup-readers.bulk.destroy'))
                ->icon('trash-2')
                ->tone('danger')
                ->confirm('Delete selected sum up readers', Action::DEFAULT_CONFIRM_DESCRIPTION, 'Delete'),
        ];
    }

    /**
     * @return array<int, Action>
     */
    private function pageActions(): array
    {
        if (! Gate::allows('create', SumUpReader::class)) {
            return [];
        }

        return [
            Action::link('create', 'New sum up reader', route('admin.sumup-readers.create'))->icon('plus'),
        ];
    }

    /**
     * The Edit page header. `EditSumUpReader` carried a DeleteAction and nothing else
     *; Reveal joins it because the edit form is where an operator is when
     * they need the code, and the form deliberately does not carry it.
     *
     * @return array<int, array<string, mixed>>
     */
    private function formActions(SumUpReader $reader): array
    {
        $actions = [];

        if (Gate::allows('reveal', $reader)) {
            $actions[] = Action::post('reveal', 'Reveal', route('admin.sumup-readers.reveal', $reader))
                ->icon('eye')
                ->confirm(
                    'Reveal pairing code',
                    'The pairing code pairs a card terminal with this event. It is shown once and the request is written to the activity log.',
                    'Reveal'
                );
        }

        if (Gate::allows('delete', $reader)) {
            $actions[] = Action::delete('delete', 'Delete', route('admin.sumup-readers.destroy', $reader))
                ->icon('trash-2')
                ->tone('danger')
                ->confirmDelete('sum up reader');
        }

        return array_map(fn (Action $action) => $action->toArray(), $actions);
    }

    /**
     * The pairing code a `reveal` just handed out, if this response is the redirect that
     * request landed on. Null on every other request, including a reload of the same page.
     *
     * @return array{id: int, name: string, paring_code: string}|null
     */
    private function revealed(): ?array
    {
        $revealed = session(self::REVEALED_KEY);

        return is_array($revealed) ? $revealed : null;
    }

    /**
     * Exactly the fields the form writes, plus `remote_id` for display only.
     *
     * `paring_code` is absent on purpose: shipping it here would put the credential back
     * into a page payload and make `reveal` decorative.
     *
     * @return array<string, mixed>
     */
    private function formData(SumUpReader $reader): array
    {
        return [
            'id' => $reader->id,
            'name' => $reader->name,
            'remote_id' => $reader->remote_id,
        ];
    }
}
