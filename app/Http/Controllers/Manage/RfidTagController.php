<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Http\Requests\Manage\RfidTagRequest;
use App\Models\RfidTag;
use App\Models\Staff;
use App\Support\Manage\Action;
use App\Support\Manage\Column;
use App\Support\Manage\Filter;
use App\Support\Manage\Table;
use App\Support\Manage\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The RFID tags of one staff member, successor to RfidTagsRelationManager (audit 4.10.1).
 *
 * There is no index route. The relation manager only ever rendered on the Staff edit page
 * and these tags only mean anything next to the member they log in, so the table is built
 * here and handed to StaffController::edit as part of that page. The declaration lives
 * with the endpoints that mutate it rather than in the staff controller, so a column and
 * the action that writes it cannot drift apart.
 *
 * Every endpoint is nested under the owning member and authorized twice: on RfidTagPolicy,
 * which is new (plan 2.2, audit 54), and on the staff member itself. A tag's `content` is
 * the entire credential a reader presents at the till, so read and write are gated
 * identically; nothing here is reachable by anyone StaffPolicy would not let edit the
 * member.
 */
class RfidTagController extends Controller
{
    /**
     * The nested table's envelope, spread flat into the Staff edit page's props.
     *
     * Flat for the same reason every list is flat: useTableQuery reloads `rows`, `meta`,
     * `filters`, `sort` and `search` as an Inertia partial visit, and partials are
     * filtered by top-level key, so a nested envelope leaves the tag table's sorting,
     * filtering and paging silently inert.
     *
     * @return array<string, mixed>
     */
    public static function table(Request $request, Staff $staff): array
    {
        return Table::make($staff->rfidTags()->getQuery())
            ->name('staff-rfid-tags')
            ->columns(self::columns())
            // The relation manager declares no defaultSort and falls back to primary-key
            // order. Stated rather than left to the driver.
            ->defaultSort('id')
            ->filters([
                Filter::ternary('is_active', 'Active Status')
                    ->placeholder('All tags')
                    ->trueLabel('Active')
                    ->falseLabel('Inactive'),
            ])
            ->rows(fn (RfidTag $tag) => [
                'content' => $tag->content,
                'name' => $tag->name,
                'is_active' => (bool) $tag->is_active,
                'last_login_at' => StaffController::since($tag->last_login_at),
                'created_at' => StaffController::since($tag->created_at),
            ])
            ->rowActions(fn (RfidTag $tag) => array_values(array_filter([
                Gate::allows('delete', $tag)
                    ? Action::delete('delete', 'Delete', route('admin.staff.rfid-tags.destroy', [$staff, $tag]))
                        ->icon('trash-2')
                        ->tone('danger')
                        // Filament's DeleteAction copy, never overridden here: heading
                        // `Delete :label` with the model label.
                        ->confirmDelete('rfid tag')
                    : null,
            ])))
            ->bulkActions(self::bulkActions($staff))
            ->toArray($request);
    }

    public function store(RfidTagRequest $request, Staff $staff): RedirectResponse
    {
        $staff->rfidTags()->create($request->validated());

        // Filament's built-in CreateAction toast; the relation manager defines none of
        // its own (audit 4.10.1 notifications).
        Toast::flashSuccess('Created');

        return back();
    }

    public function update(RfidTagRequest $request, Staff $staff, RfidTag $rfidTag): RedirectResponse
    {
        $rfidTag->update($request->validated());

        Toast::flashSuccess('Saved');

        return back();
    }

    /**
     * Hard delete: `rfid_tags` carries no SoftDeletes (audit 7.7).
     */
    public function destroy(Staff $staff, RfidTag $rfidTag): RedirectResponse
    {
        Gate::authorize('update', $staff);
        Gate::authorize('delete', $rfidTag);

        $rfidTag->delete();

        Toast::flashSuccess('Deleted');

        return back();
    }

    /**
     * All-or-nothing (plan 2.5), and confined to the member in the URL: a selection that
     * names a tag belonging to somebody else deletes nothing at all, rather than reaching
     * across the nesting.
     */
    public function bulkDestroy(Request $request, Staff $staff): RedirectResponse
    {
        Gate::authorize('update', $staff);
        Gate::authorize('delete', new RfidTag);

        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);

        $tags = $staff->rfidTags()->whereIn('id', $validated['ids'])->get();

        if ($tags->count() !== count(array_unique($validated['ids']))) {
            Toast::flashDanger(
                'Nothing was deleted',
                'One or more of the selected tags do not belong to this staff member.'
            );

            return back();
        }

        foreach ($tags as $tag) {
            if (Gate::denies('delete', $tag)) {
                Toast::flashDanger(
                    'Nothing was deleted',
                    'You are not allowed to delete one or more of the selected tags.'
                );

                return back();
            }
        }

        // Per record rather than a mass delete, so model events still fire, which is
        // what Filament's DeleteBulkAction did.
        $tags->each->delete();

        Toast::flashSuccess('Deleted');

        return back();
    }

    /**
     * The relation manager's table, in order, with its labels verbatim.
     *
     * @return array<int, Column>
     */
    private static function columns(): array
    {
        return [
            // `->copyable()`: an operator reads a tag off a card and pastes it into a
            // POS ticket, and retyping it is how a wrong tag gets registered.
            Column::copyable('content', 'RFID Code')->searchable(),
            Column::text('name', 'Tag Name')->searchable()->fallback('No name set'),
            Column::bool('is_active', 'Active'),
            Column::datetime('last_login_at', 'Last Used')->sortable()->fallback('Never used'),
            Column::datetime('created_at', 'Added')->sortable(),
        ];
    }

    /**
     * @return array<int, Action>
     */
    private static function bulkActions(Staff $staff): array
    {
        if (! Gate::allows('delete', new RfidTag)) {
            return [];
        }

        return [
            Action::delete('delete', 'Delete selected', route('admin.staff.rfid-tags.bulk.destroy', $staff))
                ->icon('trash-2')
                ->tone('danger')
                ->confirm('Delete selected rfid tags', Action::DEFAULT_CONFIRM_DESCRIPTION, 'Delete'),
        ];
    }
}
