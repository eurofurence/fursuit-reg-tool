<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Http\Requests\Manage\UserRequest;
use App\Models\User;
use App\Support\Manage\Action;
use App\Support\Manage\Column;
use App\Support\Manage\Tab;
use App\Support\Manage\Table;
use App\Support\Manage\Toast;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Response;

/**
 * Users, the successor to the old user list plus its ManageUsers page.
 *
 * Two shapes change and neither is a parity gap. Create, edit and delete were the old panel
 * modals on a ManageRecords page, so the resource had no create or edit URL at all; they
 * become real pages. And `valid_registration` is gone from both the table and
 * the form: the column was dropped from `users` by
 * 2025_08_03_195303_remove_old_columns_from_users_table and moved to `event_users`, so
 * every save of this form throws SQL 1054 today and Create and Edit are both broken
 *. The field is not ported.
 *
 * The list is deliberately not event-scoped: plan 2.9 lists Users among the surfaces that
 * stay unscoped, matching today.
 */
class UserController extends Controller
{
    /**
     * the old panel's default table date-time format, kept so the column reads the same after
     * the move. Rendered on the server; the ISO string rides along as the cell title.
     */
    private const DATETIME_FORMAT = 'M j, Y H:i:s';

    /**
     * The list envelope is spread across top-level props rather than nested under one,
     * because useTableQuery reloads `rows`, `meta`, `filters`, `sort` and `search` as a
     * partial visit and Inertia filters partials by top-level key.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', User::class);

        return inertia('Manage/Users/Index', $this->table($request));
    }

    public function create(): Response
    {
        Gate::authorize('create', User::class);

        return inertia('Manage/Users/Form', [
            'user' => null,
        ]);
    }

    public function store(UserRequest $request): RedirectResponse
    {
        User::create($request->validated());

        // the old panel's built-in Created toast; the old user list defines none of its own
        // .
        Toast::flashSuccess('Created');

        return redirect()->route('admin.settings.users.index');
    }

    public function edit(User $user): Response
    {
        Gate::authorize('update', $user);

        return inertia('Manage/Users/Form', [
            'user' => $this->formData($user),
        ]);
    }

    public function update(UserRequest $request, User $user): RedirectResponse
    {
        $user->update($request->validated());

        Toast::flashSuccess('Saved');

        return redirect()->route('admin.settings.users.index');
    }

    /**
     * Hard delete: User carries no SoftDeletes, and audit 7.7 lists users among the
     * tables that stay hard deletes.
     */
    public function destroy(User $user): RedirectResponse
    {
        Gate::authorize('delete', $user);

        $user->delete();

        Toast::flashSuccess('Deleted');

        return back();
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
        Gate::authorize('delete', new User);

        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);

        $users = User::whereIn('id', $validated['ids'])->get();

        foreach ($users as $user) {
            if (Gate::denies('delete', $user)) {
                Toast::flashDanger(
                    'Nothing was deleted',
                    'You are not allowed to delete one or more of the selected users.'
                );

                return back();
            }
        }

        // Per record rather than a mass delete, so model events still fire, which is
        // what the old panel's DeleteBulkAction did.
        $users->each->delete();

        Toast::flashSuccess('Deleted');

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function table(Request $request): array
    {
        return Table::make(User::query())
            ->name('users')
            ->columns($this->columns())
            // the old user list declares no defaultSort and falls back to primary-key order.
            // Stated rather than left implicit, so the order does not depend on whatever
            // the driver happens to return.
            ->defaultSort('id')
            ->tabs($this->tabs())
            ->filters([])
            ->rows(fn (User $user) => [
                'remote_id' => $user->remote_id,
                'name' => $user->name,
                'email' => $user->email,
                'is_admin' => (bool) $user->is_admin,
                'is_reviewer' => (bool) $user->is_reviewer,
                'created_at' => $this->datetime($user->created_at),
                'updated_at' => $this->datetime($user->updated_at),
            ])
            ->recordUrl(fn (User $user) => Gate::allows('update', $user)
                ? route('admin.settings.users.edit', $user)
                : null)
            ->rowActions(fn (User $user) => array_values(array_filter([
                Gate::allows('update', $user)
                    ? Action::link('edit', 'Edit', route('admin.settings.users.edit', $user))->icon('pencil')
                    : null,
                Gate::allows('delete', $user)
                    ? Action::delete('delete', 'Delete', route('admin.settings.users.destroy', $user))
                        ->icon('trash-2')
                        ->tone('danger')
                        // the old panel's DeleteAction copy, never overridden in this
                        // resource: heading `Delete :label` with the model label.
                        ->confirmDelete('user')
                    : null,
            ])))
            ->bulkActions($this->bulkActions())
            ->pageActions($this->pageActions())
            ->toArray($request);
    }

    /**
     * The three views of this list: everyone, the admins, the reviewers.
     *
     * Both roles are plain boolean columns on `users` - `is_admin` and `is_reviewer`, cast
     * to bool on the model - and neither is exclusive, so a user who is both appears under
     * Admins and under Reviewers. The counts therefore do not add up to the All count, and
     * that is the truth about the data rather than something to reconcile: 4 + 3 with two
     * people holding both roles is 5 users, not 7.
     *
     * All is first, so it is the default and its URL is the bare /admin/settings/users.
     *
     * Counted, because each is one `select count(*) from users where is_x = 1` against a
     * table this page is already listing, with no join and no relation behind it. That is
     * the case the count opt-in exists for; a tab that had to count through a relation
     * would simply not ask.
     *
     * @return array<int, Tab>
     */
    private function tabs(): array
    {
        return [
            // Counted like the other two, not left bare: a strip where one tab has no
            // number reads as a number that failed to load.
            Tab::make('all', 'All')->counted(),
            Tab::make('admins', 'Admins')
                ->apply(fn (Builder $query) => $query->where('is_admin', true))
                ->counted(),
            Tab::make('reviewers', 'Reviewers')
                ->apply(fn (Builder $query) => $query->where('is_reviewer', true))
                ->counted(),
        ];
    }

    /**
     * The audit's table, minus `valid_registration`. Labels are the old panel's own auto
     * labels, verbatim, so nothing reads differently after the move.
     *
     * @return array<int, Column>
     */
    private function columns(): array
    {
        return [
            Column::text('remote_id', 'Remote id')->searchable(),
            Column::text('name', 'Name')->searchable(),
            Column::text('email', 'Email')->searchable(),
            Column::bool('is_admin', 'Is admin'),
            Column::bool('is_reviewer', 'Is reviewer'),
            Column::datetime('created_at', 'Created at')->sortable()->toggleable(hiddenByDefault: true),
            Column::datetime('updated_at', 'Updated at')->sortable()->toggleable(hiddenByDefault: true),
        ];
    }

    /**
     * @return array<int, Action>
     */
    private function bulkActions(): array
    {
        // A bare class name would reach UserPolicy::delete() as its $model argument and
        // fail the type hint, so the question "may this operator delete users at all" is
        // asked with a throwaway instance. The policy answers on the actor, not the row.
        if (! Gate::allows('delete', new User)) {
            return [];
        }

        return [
            Action::delete('delete', 'Delete selected', route('admin.settings.users.bulk.destroy'))
                ->icon('trash-2')
                ->tone('danger')
                ->confirm('Delete selected users', Action::DEFAULT_CONFIRM_DESCRIPTION, 'Delete'),
        ];
    }

    /**
     * @return array<int, Action>
     */
    private function pageActions(): array
    {
        if (! Gate::allows('create', User::class)) {
            return [];
        }

        return [
            Action::link('create', 'New user', route('admin.settings.users.create'))->icon('plus'),
        ];
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

    /**
     * Exactly the fields the form writes, so nothing else can round-trip through it.
     *
     * @return array<string, mixed>
     */
    private function formData(User $user): array
    {
        return [
            'id' => $user->id,
            'remote_id' => $user->remote_id,
            'name' => $user->name,
            'email' => $user->email,
            'avatar' => $user->avatar,
            'is_reviewer' => (bool) $user->is_reviewer,
            'is_admin' => (bool) $user->is_admin,
        ];
    }
}
