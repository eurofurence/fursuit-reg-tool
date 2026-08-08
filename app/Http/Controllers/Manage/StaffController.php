<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Http\Requests\Manage\StaffRequest;
use App\Models\RfidTag;
use App\Models\Staff;
use App\Support\Manage\Action;
use App\Support\Manage\Column;
use App\Support\Manage\Filter;
use App\Support\Manage\Table;
use App\Support\Manage\Toast;
use Carbon\CarbonInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Response;

/**
 * POS staff, the successor to StaffResource and its three pages (audit 4.10).
 *
 * These rows are login credentials for the till: a `Staff` row has no `is_admin` column
 * and no user link, so whoever holds the PIN or one of the member's RFID tags is that
 * cashier as far as the POS is concerned. StaffPolicy answers `is_admin` for every
 * ability and stays exactly as it is; this module reads it, it does not widen it.
 *
 * The PIN never reaches a list payload. Filament's table declared
 * `TextColumn::make('pin_code')->formatStateUsing(fn ($state) => $state ? 'Set' : 'Not Set')`,
 * which renders the right two words but loads the plaintext PIN of every staff member
 * into the page payload first and only formats it on the way out (audit 4.10 column 2).
 * The row transformer here computes `Set` / `Not Set` on the server, so the column is
 * the same two words and the PIN is not in the response at all. Nor does it reach the
 * edit form: that payload gets a fixed sentinel, PIN_UNCHANGED, and StaffRequest drops
 * the sentinel before validation (plan 2.10 #66). It is still plaintext in the database
 * and POS login still compares it plaintext (audit 11); that is a POS change, not an
 * admin one, and the form says so out loud (plan 2.10 #24).
 *
 * The list is deliberately not event-scoped: plan 2.9 lists Staff among the surfaces that
 * stay unscoped, matching today.
 */
class StaffController extends Controller
{
    /**
     * Filament's default table date-time format, kept so the column reads the same after
     * the move.
     */
    private const DATETIME_FORMAT = 'M j, Y H:i:s';

    /**
     * `->paginated(false)` becomes a real page size with the pager visible (plan 2.3), so
     * an unbounded staff table cannot become an unbounded page render. Every convention
     * this has ever run at fits in one page of 200.
     */
    private const PER_PAGE = 200;

    /**
     * What the edit form receives in place of a stored PIN.
     *
     * Six dots rather than the PIN itself. The field still round-trips, so an untouched
     * form still saves and an emptied field still clears the PIN the way the helper text
     * promises, but the credential is not in the payload, not in the DOM and not in
     * Inertia's history state. StaffRequest drops the sentinel before validation, so it
     * can never be written; it is not six digits, so no real PIN can collide with it.
     */
    public const PIN_UNCHANGED = '••••••';

    /**
     * The list envelope is spread across top-level props rather than nested under one,
     * because useTableQuery reloads `rows`, `meta`, `filters`, `sort` and `search` as a
     * partial visit and Inertia filters partials by top-level key.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Staff::class);

        return inertia('Manage/Staff/Index', $this->table($request));
    }

    public function create(): Response
    {
        Gate::authorize('create', Staff::class);

        return inertia('Manage/Staff/Form', [
            'staff' => null,
            // The Generate button proposes a code into form state; it never writes
            // (plan 2.10 #23). Null until the operator asks for one.
            'generatedSetupCode' => session('manage.staff.generated_setup_code'),
            'headerActions' => [],
        ]);
    }

    public function store(StaffRequest $request): RedirectResponse
    {
        Staff::create($request->validated());

        // Filament's built-in Created toast; StaffResource defines none of its own
        // (audit 4.10 notifications).
        Toast::flashSuccess('Created');

        return redirect()->route('manage.staff.index');
    }

    /**
     * The edit page carries the RFID tag table, which the Filament panel rendered as a
     * relation manager on this same page and nowhere else.
     *
     * Its envelope is spread flat for the same reason the list's is: the nested table
     * drives sorting, filtering, search and paging through useTableQuery, which reloads
     * those five keys as a partial visit against this component.
     */
    public function edit(Request $request, Staff $staff): Response
    {
        Gate::authorize('update', $staff);

        return inertia('Manage/Staff/Form', [
            'staff' => $this->formData($staff),
            'generatedSetupCode' => session('manage.staff.generated_setup_code'),
            'headerActions' => $this->headerActions($staff),
            // Read is gated as tightly as write: a tag value is a POS credential, so an
            // operator who may not write one may not read one either.
            ...(Gate::allows('viewAny', RfidTag::class)
                ? RfidTagController::table($request, $staff)
                : []),
            'canCreateRfidTag' => Gate::allows('create', RfidTag::class),
        ]);
    }

    public function update(StaffRequest $request, Staff $staff): RedirectResponse
    {
        $staff->update($request->validated());

        Toast::flashSuccess('Saved');

        return redirect()->route('manage.staff.index');
    }

    /**
     * Hard delete. `Staff` carries no SoftDeletes and `rfid_tags.staff_id` is
     * `onDelete('cascade')`, so the member's tags go with them (audit 7.7). Same as
     * today, including the part where `checkouts.cashier_id` is left pointing at nothing
     * (audit 132); repointing fiscal records is not an admin-panel decision.
     *
     * Always lands on the index rather than back, because the header action on the edit
     * page would otherwise return to a record that no longer exists.
     */
    public function destroy(Staff $staff): RedirectResponse
    {
        Gate::authorize('delete', $staff);

        $staff->delete();

        Toast::flashSuccess('Deleted');

        return redirect()->route('manage.staff.index');
    }

    /**
     * All-or-nothing (plan 2.5): if any selected record fails the policy nothing is
     * deleted and a danger toast says why, rather than half a selection disappearing.
     */
    public function bulkDestroy(Request $request): RedirectResponse
    {
        Gate::authorize('delete', new Staff);

        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);

        $members = Staff::whereIn('id', $validated['ids'])->get();

        foreach ($members as $member) {
            if (Gate::denies('delete', $member)) {
                Toast::flashDanger(
                    'Nothing was deleted',
                    'You are not allowed to delete one or more of the selected staff.'
                );

                return back();
            }
        }

        // Per record rather than a mass delete, so model events still fire, which is
        // what Filament's DeleteBulkAction did.
        $members->each->delete();

        Toast::flashSuccess('Deleted');

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function table(Request $request): array
    {
        return Table::make(Staff::query()->withCount('rfidTags'))
            ->name('staff')
            ->columns($this->columns())
            // StaffResource declares no defaultSort and falls back to primary-key order.
            // Stated rather than left implicit, so the order does not depend on whatever
            // the driver happens to return.
            ->defaultSort('id')
            ->filters($this->filters())
            ->perPage(self::PER_PAGE)
            ->rows(fn (Staff $staff) => [
                'name' => $staff->name,
                // Two literal words, decided here. The PIN itself is never serialised.
                'pin_code' => $staff->pin_code ? 'Set' : 'Not Set',
                'is_active' => (bool) $staff->is_active,
                'rfid_tags_count' => (int) $staff->rfid_tags_count,
                'last_login_at' => $this->since($staff->last_login_at),
                'created_at' => $this->datetime($staff->created_at),
            ])
            ->recordUrl(fn (Staff $staff) => Gate::allows('update', $staff)
                ? route('manage.staff.edit', $staff)
                : null)
            ->rowActions(fn (Staff $staff) => array_values(array_filter([
                Gate::allows('update', $staff)
                    ? Action::link('edit', 'Edit', route('manage.staff.edit', $staff))->icon('pencil')
                    : null,
                Gate::allows('delete', $staff)
                    ? Action::delete('delete', 'Delete', route('manage.staff.destroy', $staff))
                        ->icon('trash-2')
                        ->tone('danger')
                        // Filament's DeleteAction copy, never overridden in this
                        // resource: heading `Delete :label` with the model label.
                        ->confirmDelete('staff')
                    : null,
            ])))
            ->bulkActions($this->bulkActions())
            ->pageActions($this->pageActions())
            ->toArray($request);
    }

    /**
     * The audit's table, in order, with Filament's own labels verbatim.
     *
     * @return array<int, Column>
     */
    private function columns(): array
    {
        return [
            Column::text('name', 'Name')->searchable()->sortable(),
            Column::text('pin_code', 'PIN Code')->toggleable(hiddenByDefault: true),
            Column::bool('is_active', 'Active'),
            Column::number('rfid_tags_count', 'RFID Tags'),
            // `->dateTime()->since()` renders as a human diff, and a member who never
            // logged in gets a blank cell rather than a placeholder: StaffResource sets
            // none, and the RFID table's `Never used` is the inconsistency, not this
            // (audit 122). Kept as it reads today; a formatted date here would change the
            // display contract.
            Column::datetime('last_login_at', 'Last Login')->sortable()->fallback(''),
            Column::datetime('created_at', 'Created at')->sortable()->toggleable(hiddenByDefault: true),
        ];
    }

    /**
     * @return array<int, Filter>
     */
    private function filters(): array
    {
        return [
            // Filament's TernaryFilter with no query override: blank leaves the query
            // alone, true and false compare the boolean column.
            Filter::ternary('is_active', 'Active Status')
                ->placeholder('All staff')
                ->trueLabel('Active')
                ->falseLabel('Inactive'),
        ];
    }

    /**
     * @return array<int, Action>
     */
    private function bulkActions(): array
    {
        // A bare class name would reach StaffPolicy::delete() as its $staff argument and
        // fail the type hint, so the question "may this operator delete staff at all" is
        // asked with a throwaway instance. The policy answers on the actor, not the row.
        if (! Gate::allows('delete', new Staff)) {
            return [];
        }

        return [
            Action::delete('delete', 'Delete selected', route('manage.staff.bulk.destroy'))
                ->icon('trash-2')
                ->tone('danger')
                ->confirm('Delete selected staff', Action::DEFAULT_CONFIRM_DESCRIPTION, 'Delete'),
        ];
    }

    /**
     * @return array<int, Action>
     */
    private function pageActions(): array
    {
        if (! Gate::allows('create', Staff::class)) {
            return [];
        }

        return [
            Action::link('create', 'New staff', route('manage.staff.create'))->icon('plus'),
        ];
    }

    /**
     * EditStaff's header DeleteAction, with Filament's default copy.
     *
     * @return array<int, array<string, mixed>>
     */
    private function headerActions(Staff $staff): array
    {
        if (! Gate::allows('delete', $staff)) {
            return [];
        }

        return [
            Action::delete('delete', 'Delete', route('manage.staff.destroy', $staff))
                ->icon('trash-2')
                ->tone('danger')
                ->confirmDelete('staff')
                ->toArray(),
        ];
    }

    /**
     * A `->dateTime()` column.
     *
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

    /**
     * A `->dateTime()->since()` column: `since()` wins, so the cell is the human diff and
     * the exact instant stays in the tooltip.
     *
     * @return array{display: string, title: string}|null
     */
    public static function since(?CarbonInterface $value): ?array
    {
        if ($value === null) {
            return null;
        }

        return [
            'display' => $value->diffForHumans(),
            'title' => $value->toIso8601String(),
        ];
    }

    /**
     * Exactly the fields the form writes, plus the id, so nothing else can round-trip
     * through it.
     *
     * `pin_code` is the sentinel, never the PIN. The docblock above argues the plaintext
     * must not reach a list payload; an edit payload is the same payload, kept in the
     * page props, in the DOM and in Inertia's history state for as long as the tab is
     * open, and reachable without the `reveal` gesture and activity entry the SumUp
     * pairing code gets. The round trip the field needs is a round trip of *something*,
     * not of the credential: SecurePinRule now receives the record id (plan 2.10 #21), so
     * an unchanged PIN no longer has to be resubmitted to validate against itself.
     *
     * @return array<string, mixed>
     */
    private function formData(Staff $staff): array
    {
        return [
            'id' => $staff->id,
            'name' => $staff->name,
            'pin_code' => $staff->pin_code === null || $staff->pin_code === '' ? '' : self::PIN_UNCHANGED,
            'setup_code' => $staff->setup_code,
            'is_active' => (bool) $staff->is_active,
        ];
    }
}
