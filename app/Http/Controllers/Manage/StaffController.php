<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Http\Requests\Manage\StaffRequest;
use App\Models\Event;
use App\Models\RfidTag;
use App\Models\Staff;
use App\Services\StaffStatisticsService;
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
use Inertia\Response;

/**
 * POS staff, the successor to the old staff list and its three pages.
 *
 * These rows are login credentials for the till: a `Staff` row has no `is_admin` column
 * and no user link, so whoever holds the PIN or one of the member's RFID tags is that
 * cashier as far as the POS is concerned. StaffPolicy answers `is_admin` for every
 * ability and stays exactly as it is; this module reads it, it does not widen it.
 *
 * The PIN never reaches a list payload. the old panel's table declared
 * `TextColumn::make('pin_code')->formatStateUsing(fn ($state) => $state ? 'Set' : 'Not Set')`,
 * which renders the right two words but loads the plaintext PIN of every staff member
 * into the page payload first and only formats it on the way out.
 * The row transformer here computes `Set` / `Not Set` on the server, so the column is
 * the same two words and the PIN is not in the response at all. Nor does it reach the
 * edit form: that payload gets a fixed sentinel, PIN_UNCHANGED, and StaffRequest drops
 * the sentinel before validation. It is still plaintext in the database
 * and POS login still compares it plaintext; that is a POS change, not an
 * admin one, and the form says so out loud.
 *
 * The list is deliberately not event-scoped: plan 2.9 lists Staff among the surfaces that
 * stay unscoped, matching today.
 *
 * Staff are archived, never deleted. `badges.picked_up_by_staff_id`,
 * `checkouts.cashier_id` and `print_batches.created_by_staff_id` all point here and are
 * all `nullOnDelete`, so the delete this module used to offer did not merely remove a POS
 * login, it detached every handover, till and print run that member had ever been
 * credited with - and the edit page now *shows* those numbers, which is exactly the
 * history there is no reason to ever throw away. Archive and restore replace it, taking
 * the shape machines already use: `archived_at` is the single answer to "may this person
 * log in", the `is_active` boolean it replaced is gone, and the list hides retired members
 * unless the filter asks for them.
 */
class StaffController extends Controller
{
    /**
     * the old panel's default table date-time format, kept so the column reads the same after
     * the move.
     */
    private const DATETIME_FORMAT = 'M j, Y H:i:s';

    /**
     * `->paginated(false)` becomes a real page size with the pager visible, so
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
            // . Null until the operator asks for one.
            'generatedSetupCode' => session('admin.staff.generated_setup_code'),
            'headerActions' => [],
        ]);
    }

    public function store(StaffRequest $request): RedirectResponse
    {
        Staff::create($this->writable($request));

        // the old panel's built-in Created toast; the old staff list defines none of its own
        // .
        Toast::flashSuccess('Created');

        return redirect()->to(Table::returnUrl('staff', route('admin.staff.index')));
    }

    /**
     * The edit page carries the RFID tag table, which the old panel rendered as a
     * relation manager on this same page and nowhere else.
     *
     * Its envelope is spread flat for the same reason the list's is: the nested table
     * drives sorting, filtering, search and paging through useTableQuery, which reloads
     * those five keys as a partial visit against this component.
     *
     * It also carries the member's own numbers - handovers, till time, print runs, hours
     * worked - for one event at a time, chosen by `?event=`. The picker reloads `statistics`
     * and `selectedEventId` as a partial visit, so switching events does not re-run the
     * RFID table query or throw away anything typed into the form above.
     */
    public function edit(Request $request, Staff $staff): Response
    {
        Gate::authorize('update', $staff);

        [$event, $events] = $this->statisticsEvent($request);

        return inertia('Manage/Staff/Form', [
            'staff' => $this->formData($staff),
            'generatedSetupCode' => session('admin.staff.generated_setup_code'),
            'headerActions' => $this->headerActions($staff),

            // Lazily evaluated: the timeline reads three tables, and a partial visit for
            // the RFID table below has no business paying for it.
            'statistics' => fn () => app(StaffStatisticsService::class)->for($staff, $event),
            'statisticsEvents' => $events,
            'selectedEventId' => $event?->id,
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
        $staff->update($this->writable($request, $staff));

        Toast::flashSuccess('Saved');

        return redirect()->to(Table::returnUrl('staff', route('admin.staff.index')));
    }

    /**
     * The validated form, with the `Active` toggle turned back into `archived_at`.
     *
     * The form asks the question the way it always did, one checkbox, because that is
     * what the operator is deciding: may this person sign in. The column stores when they
     * stopped, which the checkbox cannot say on its own.
     *
     * Re-stamping is avoided deliberately: saving an already-archived member with the box
     * still clear must not move the date they stopped staffing to today.
     *
     * @return array<string, mixed>
     */
    private function writable(StaffRequest $request, ?Staff $staff = null): array
    {
        $data = $request->validated();

        $active = (bool) ($data['is_active'] ?? true);
        unset($data['is_active']);

        if ($active) {
            $data['archived_at'] = null;
        } elseif (! $staff?->isArchived()) {
            $data['archived_at'] = now();
        }

        return $data;
    }

    /**
     * Retire the member. Their POS login stops working; every handover, till and print
     * run they are credited with stays exactly where it is.
     *
     * This is what used to be the delete, and the reason it is not one is in the class
     * docblock. Lands back rather than on the index, because unlike a delete the record
     * is still there to return to.
     */
    public function archive(Staff $staff): RedirectResponse
    {
        Gate::authorize('update', $staff);

        $staff->archive();

        Toast::flashSuccess('Archived', $staff->name.' can no longer sign in to the POS.');

        return back();
    }

    public function unarchive(Staff $staff): RedirectResponse
    {
        Gate::authorize('update', $staff);

        $staff->unarchive();

        Toast::flashSuccess('Restored', $staff->name.' can sign in to the POS again.');

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
     * All-or-nothing: if any selected record fails the policy nothing moves
     * and a danger toast says why, rather than half a selection changing state.
     */
    private function bulk(Request $request, bool $archive): RedirectResponse
    {
        Gate::authorize('update', new Staff);

        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);

        $members = Staff::whereIn('id', $validated['ids'])->get();

        foreach ($members as $member) {
            if (Gate::denies('update', $member)) {
                Toast::flashDanger(
                    $archive ? 'Nothing was archived' : 'Nothing was restored',
                    'You are not allowed to change one or more of the selected staff.'
                );

                return back();
            }
        }

        // Per record rather than a mass update, so each row goes through the model's own
        // method and its events still fire.
        $archive ? $members->each->archive() : $members->each->unarchive();

        $archive
            ? Toast::flashSuccess('Archived', $this->countBody($members->count()).' locked out of the POS.')
            : Toast::flashSuccess('Restored', $this->countBody($members->count()).' able to sign in again.');

        return back();
    }

    private function countBody(int $count): string
    {
        return $count === 1 ? '1 staff member is' : $count.' staff members are';
    }

    /**
     * The event the statistics panel is showing, and the list the picker offers.
     *
     * `?event=all` is the lifetime view and returns no event at all. Anything else falls
     * back to the active event, so the panel opens on the convention that is running.
     *
     * @return array{0: Event|null, 1: array<int, array<string, mixed>>}
     */
    private function statisticsEvent(Request $request): array
    {
        $events = Event::query()
            ->orderByDesc('starts_at')
            ->get(['id', 'name'])
            ->map(fn (Event $event) => ['id' => $event->id, 'name' => $event->name])
            ->all();

        $requested = $request->input('event');

        if ($requested === 'all') {
            return [null, $events];
        }

        $event = is_numeric($requested)
            ? Event::find((int) $requested)
            : null;

        return [$event ?? Event::getActiveEvent(), $events];
    }

    /**
     * @return array<string, mixed>
     */
    private function table(Request $request): array
    {
        return Table::make($this->baseQuery($request))
            ->name('staff')
            ->columns($this->columns())
            // the old staff list declares no defaultSort and falls back to primary-key order.
            // Stated rather than left implicit, so the order does not depend on whatever
            // the driver happens to return.
            ->defaultSort('id')
            ->filters($this->filters())
            ->perPage(self::PER_PAGE)
            ->rows(fn (Staff $staff) => [
                'name' => $staff->name,
                // Two literal words, decided here. The PIN itself is never serialised.
                'pin_code' => $staff->pin_code ? 'Set' : 'Not Set',
                'is_manager' => (bool) $staff->is_manager,
                'rfid_tags_count' => (int) $staff->rfid_tags_count,
                'last_login_at' => $this->since($staff->last_login_at),
                'archived_at' => $this->datetime($staff->archived_at),
                'created_at' => $this->datetime($staff->created_at),
            ])
            ->recordUrl(fn (Staff $staff) => Gate::allows('update', $staff)
                ? route('admin.staff.edit', $staff)
                : null)
            ->rowActions(fn (Staff $staff) => $this->rowActions($staff))
            ->bulkActions($this->bulkActions())
            ->pageActions($this->pageActions())
            ->toArray($request);
    }

    /**
     * The list query, already carrying the blank branch of the archived filter.
     *
     * There is no global scope on `Staff`, so this is the only thing keeping retired
     * members out of the default list. It cannot live in the filter's apply(): a blank
     * filter value is inactive by design in App\Support\Manage\Filter, because an unset
     * filter must not narrow anything. Here, cleared and unset both land on
     * `notArchived()` and both read as "Active staff".
     */
    private function baseQuery(Request $request): Builder
    {
        $query = Staff::query()->withCount('rfidTags');

        $archived = $request->input('filter.archived');

        if ($archived === null || $archived === Filter::CLEARED || $archived === '') {
            $query->notArchived();
        }

        return $query;
    }

    /**
     * The audit's table, in order, with the old panel's own labels verbatim.
     *
     * @return array<int, Column>
     */
    private function columns(): array
    {
        return [
            Column::text('name', 'Name')->searchable()->sortable(),
            Column::text('pin_code', 'PIN Code')->toggleable(hiddenByDefault: true),
            // Who may approve a price override at the till.
            Column::bool('is_manager', 'Manager'),
            Column::number('rfid_tags_count', 'RFID Tags'),
            // Blank for everyone the default list shows, so it only earns its width once
            // the filter is switched to archived members.
            Column::datetime('archived_at', 'Archived')
                ->sortable()
                ->fallback('')
                ->toggleable(hiddenByDefault: true),
            // `->dateTime()->since()` renders as a human diff, and a member who never
            // logged in gets a blank cell rather than a placeholder: the old staff list sets
            // none, and the RFID table's `Never used` is the inconsistency, not this
            // . Kept as it reads today; a formatted date here would change the
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
            Filter::ternary('archived', 'Archived')
                ->placeholder('Active staff')
                ->trueLabel('Archived staff')
                ->falseLabel('All staff')
                // Blank is the default and is applied by baseQuery(); see the note there.
                // `false` widens to everyone, so it narrows nothing.
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
    private function rowActions(Staff $staff): array
    {
        if (Gate::denies('update', $staff)) {
            return [];
        }

        return array_values(array_filter([
            Action::link('edit', 'Edit', route('admin.staff.edit', $staff))->icon('pencil'),

            $staff->isArchived()
                ? null
                : Action::post('archive', 'Archive', route('admin.staff.archive', $staff))
                    ->icon('archive')
                    ->tone(Status::WARN)
                    ->confirm(
                        'Archive staff member',
                        'They will no longer be able to sign in to the POS.',
                        'Yes, archive them',
                    ),

            $staff->isArchived()
                ? Action::delete('unarchive', 'Restore', route('admin.staff.unarchive', $staff))
                    ->icon('rotate-ccw')
                    ->tone(Status::OK)
                    ->confirm(
                        'Restore staff member',
                        'They will be able to sign in to the POS again.',
                        'Yes, restore them',
                    )
                : null,
        ]));
    }

    /**
     * Both bulk actions, unconditionally: a selection can hold archived and active
     * members at once.
     *
     * @return array<int, Action>
     */
    private function bulkActions(): array
    {
        // A bare class name would reach StaffPolicy::update() as its $staff argument and
        // fail the type hint, so the question "may this operator change staff at all" is
        // asked with a throwaway instance. The policy answers on the actor, not the row.
        if (Gate::denies('update', new Staff)) {
            return [];
        }

        return [
            Action::post('archive', 'Archive selected', route('admin.staff.bulk.archive'))
                ->icon('archive')
                ->tone(Status::WARN)
                ->confirm(
                    'Archive selected staff',
                    'They will no longer be able to sign in to the POS.',
                    'Yes, archive them',
                ),

            Action::delete('unarchive', 'Restore selected', route('admin.staff.bulk.unarchive'))
                ->icon('rotate-ccw')
                ->tone(Status::OK)
                ->confirm(
                    'Restore selected staff',
                    'They will be able to sign in to the POS again.',
                    'Yes, restore them',
                ),
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
            Action::link('create', 'New staff', route('admin.staff.create'))->icon('plus'),
        ];
    }

    /**
     * None. The Delete that used to sit here is gone, and its replacement is the `Active`
     * toggle in the form rather than a second control saying the same thing.
     *
     * @return array<int, array<string, mixed>>
     */
    private function headerActions(Staff $staff): array
    {
        return [];
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
     * not of the credential: SecurePinRule now receives the record id, so
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
            'is_active' => ! $staff->isArchived(),
            'is_manager' => (bool) $staff->is_manager,
            'archived_at' => $staff->archived_at?->toIso8601String(),
        ];
    }
}
