<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Http\Requests\Manage\EventRequest;
use App\Models\Event;
use App\Support\Manage\Action;
use App\Support\Manage\Column;
use App\Support\Manage\Table;
use App\Support\Manage\Toast;
use Carbon\CarbonInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Response;

/**
 * Events, the successor to EventResource plus its ManageEvents page (audit 4.1).
 *
 * Create, edit and delete were Filament modals on a ManageRecords page, so the resource had
 * no create or edit URL at all; they become real pages (plan 1.2).
 *
 * Event state is computed from the three date fields by `Event::state()` and there is no
 * `state` column anywhere in the schema, so nothing here stores, filters or sorts one. The
 * dates are the only levers, exactly as today (audit 4.1, landmine 105).
 *
 * The list is deliberately not event-scoped: plan 2.9 lists Events among the surfaces that
 * stay unscoped, matching today. Scoping the event list by the selected event would leave
 * an operator one row to pick from.
 */
class EventController extends Controller
{
    /**
     * The badge renderer classes the form offers, hardcoded per convention year exactly as
     * EventResource had them. Landmine 110 (a new event year needs a code change) is
     * recorded but not fixed here: the plan sanctions no change, and the value is validated
     * against this list so nothing outside it can reach `badge_class`.
     */
    public const BADGE_CLASS_OPTIONS = [
        'EF28_Badge' => 'EF28 Badge',
        'EF29_Badge' => 'EF29 Badge',
        'EF30_Badge' => 'EF30 Badge',
    ];

    /**
     * Filament's model label for this resource, as its delete modals render it.
     */
    private const MODEL_LABEL = 'event';

    private const PLURAL_LABEL = 'events';

    /**
     * Filament's `->date()` default, `config('tables.date_format')`, kept so `starts_at`
     * and `ends_at` read the same after the move.
     */
    private const DATE_FORMAT = 'M j, Y';

    /**
     * The explicit format the three timestamp columns pass to `->dateTime()`.
     */
    private const DATETIME_FORMAT = 'd.m.Y H:i';

    /**
     * `->limit(50)` on the Archival Notice column. The full value rides along as the cell
     * title, which is the `->tooltip()` that column declares.
     */
    private const NOTICE_LIMIT = 50;

    /**
     * The refusal an event with fursuits gets instead of a cascading hard delete.
     */
    private const BLOCKED_TITLE = 'Nothing was deleted';

    private const BLOCKED_BODY = 'This event still owns fursuits, and deleting it would permanently remove them and their badges. Move or remove them first.';

    /**
     * The list envelope is spread across top-level props rather than nested under one,
     * because useTableQuery reloads `rows`, `meta`, `filters`, `sort` and `search` as a
     * partial visit and Inertia filters partials by top-level key.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Event::class);

        return inertia('Manage/Events/Index', $this->table($request));
    }

    public function create(): Response
    {
        Gate::authorize('create', Event::class);

        return inertia('Manage/Events/Form', $this->formProps(null));
    }

    public function store(EventRequest $request): RedirectResponse
    {
        $event = new Event;
        $this->write($event, $request->payload());

        // Filament's built-in Created toast; EventResource defines none of its own
        // (audit 4.1: no custom notifications).
        Toast::flashSuccess('Created');

        return redirect()->route('manage.events.index');
    }

    public function edit(Event $event): Response
    {
        Gate::authorize('update', $event);

        return inertia('Manage/Events/Form', $this->formProps($event));
    }

    public function update(EventRequest $request, Event $event): RedirectResponse
    {
        $this->write($event, $request->payload());

        Toast::flashSuccess('Saved');

        return redirect()->route('manage.events.index');
    }

    /**
     * Hard delete: Event carries no SoftDeletes, and audit 7.7 lists events among the
     * tables that stay hard deletes. It is also why EventPolicy gains no `restore` or
     * `forceDelete` (plan 2.2, landmine 53).
     *
     * An event that still owns fursuits is refused. `fursuits.event_id` and
     * `badges.fursuit_id` are both `ON DELETE CASCADE`, so a hard delete here removes
     * every fursuit and every badge of that convention *physically*: SoftDeletes never
     * runs, FursuitObserver never runs, no `deleted_at` is written, no activity entry is
     * logged, and the paid badges backing DSFinV-K and TSE reconciliation are gone with
     * no restore path. See `hasDependentRecords()`.
     */
    public function destroy(Event $event): RedirectResponse
    {
        Gate::authorize('delete', $event);

        if ($this->hasDependentRecords($event)) {
            Toast::flashDanger(self::BLOCKED_TITLE, self::BLOCKED_BODY);

            return back();
        }

        $event->delete();

        Toast::flashSuccess('Deleted');

        return back();
    }

    /**
     * All-or-nothing (plan 2.5): if any selected record fails the policy nothing is
     * deleted and a danger toast says why, rather than half a selection disappearing.
     *
     * The endpoint authorizes the same question the bulk button is offered on, so an
     * operator who never sees the button gets a 403 rather than a toast, and an id list
     * that matches no rows cannot walk the empty loop into the success toast.
     */
    public function bulkDestroy(Request $request): RedirectResponse
    {
        Gate::authorize('delete', new Event);

        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);

        $events = Event::whereIn('id', $validated['ids'])->get();

        foreach ($events as $event) {
            if (Gate::denies('delete', $event)) {
                Toast::flashDanger(
                    'Nothing was deleted',
                    'You are not allowed to delete one or more of the selected events.'
                );

                return back();
            }

            // Same cascade guard as destroy(), and all-or-nothing for the same reason:
            // a selection that spans one deletable and one populated event must not take
            // half of it out.
            if ($this->hasDependentRecords($event)) {
                Toast::flashDanger(self::BLOCKED_TITLE, self::BLOCKED_BODY);

                return back();
            }
        }

        // Per record rather than a mass delete, so model events still fire, which is
        // what Filament's DeleteBulkAction did.
        $events->each->delete();

        Toast::flashSuccess('Deleted');

        return back();
    }

    /**
     * Would deleting this event take rows with it?
     *
     * Only `fursuits` is asked. `badges` hangs off `fursuits`, `event_users` off the
     * event, and both cascade through it, so one fursuit is enough to refuse. Trashed
     * fursuits count: a soft delete leaves the row in place and the database-level
     * cascade does not care about `deleted_at`.
     */
    private function hasDependentRecords(Event $event): bool
    {
        return $event->fursuits()->withTrashed()->exists();
    }

    /**
     * @return array<string, mixed>
     */
    private function table(Request $request): array
    {
        return Table::make(Event::query())
            ->name('events')
            ->columns($this->columns())
            ->defaultSort('starts_at', 'desc')
            // EventResource declares `->filters([ // ])`, and no column is searchable
            // either, so the list carries neither.
            ->filters([])
            ->rows(fn (Event $event) => [
                'name' => $event->name,
                // Empty string and null both read as "no class picked"; the column's
                // fallback renders `Not set` for either.
                'badge_class' => $event->badge_class ?: null,
                'starts_at' => $this->date($event->starts_at),
                'ends_at' => $this->date($event->ends_at),
                'mass_printed_at' => $this->datetime($event->mass_printed_at),
                'order_starts_at' => $this->datetime($event->order_starts_at),
                'order_ends_at' => $this->datetime($event->order_ends_at),
                'catch_em_all_enabled' => (bool) $event->catch_em_all_enabled,
                'archival_notice' => $this->notice($event->archival_notice),
            ])
            ->recordUrl(fn (Event $event) => Gate::allows('update', $event)
                ? route('manage.events.edit', $event)
                : null)
            ->rowActions(fn (Event $event) => array_values(array_filter([
                Gate::allows('update', $event)
                    ? Action::link('edit', 'Edit', route('manage.events.edit', $event))->icon('pencil')
                    : null,
                Gate::allows('delete', $event)
                    ? Action::delete('delete', 'Delete', route('manage.events.destroy', $event))
                        ->icon('trash-2')
                        ->tone('danger')
                        // Filament's DeleteAction copy, never overridden in this
                        // resource: heading `Delete :label` with the model label.
                        ->confirmDelete(self::MODEL_LABEL)
                    : null,
            ])))
            ->bulkActions($this->bulkActions())
            ->pageActions($this->pageActions())
            ->toArray($request);
    }

    /**
     * The audit's nine columns, in order, with Filament's own labels verbatim: five of
     * them are auto labels and four are declared, and both kinds are transcribed so
     * nothing reads differently after the move.
     *
     * `name` loses its `->numeric()`. The column holds a string, and Filament's numeric
     * formatter returns a non-numeric state untouched, so the rendered value is the same
     * one either way (landmine 111).
     *
     * @return array<int, Column>
     */
    private function columns(): array
    {
        return [
            Column::text('name', 'Name')->sortable(),
            Column::text('badge_class', 'Badge Class')
                ->sortable()
                ->fallback('Not set')
                ->toggleable(hiddenByDefault: true),
            Column::datetime('starts_at', 'Starts at')->sortable(),
            Column::datetime('ends_at', 'Ends at')->sortable(),
            Column::datetime('mass_printed_at', 'Mass printed at')->sortable(),
            Column::datetime('order_starts_at', 'Order Start')->sortable(),
            Column::datetime('order_ends_at', 'Order End')->sortable(),
            Column::bool('catch_em_all_enabled', 'Catch-Em-All')->toggleable(hiddenByDefault: true),
            Column::text('archival_notice', 'Archival Notice')
                ->fallback('None')
                ->toggleable(hiddenByDefault: true),
        ];
    }

    /**
     * @return array<int, Action>
     */
    private function bulkActions(): array
    {
        // A bare class name would reach EventPolicy::delete() as its $model argument and
        // fail the type hint, so the question "may this operator delete events at all" is
        // asked with a throwaway instance. The policy answers on the actor, not the row.
        if (! Gate::allows('delete', new Event)) {
            return [];
        }

        return [
            Action::delete('bulk-delete', 'Delete selected', route('manage.events.bulk.destroy'))
                ->icon('trash-2')
                ->tone('danger')
                ->confirm(
                    'Delete selected '.self::PLURAL_LABEL,
                    Action::DEFAULT_CONFIRM_DESCRIPTION,
                    'Delete',
                ),
        ];
    }

    /**
     * @return array<int, Action>
     */
    private function pageActions(): array
    {
        if (! Gate::allows('create', Event::class)) {
            return [];
        }

        return [
            Action::link('create', 'New event', route('manage.events.create'))->icon('plus'),
        ];
    }

    /**
     * A `->date()` column: the day, with `diffForHumans()` underneath as the column's
     * `->description()` and the ISO string as the cell title.
     *
     * @return array{display: string, description: string, title: string}|null
     */
    private function date(?CarbonInterface $value): ?array
    {
        return $this->cell($value, self::DATE_FORMAT);
    }

    /**
     * A `->dateTime('d.m.Y H:i')` column, same description.
     *
     * @return array{display: string, description: string, title: string}|null
     */
    private function datetime(?CarbonInterface $value): ?array
    {
        return $this->cell($value, self::DATETIME_FORMAT);
    }

    /**
     * @return array{display: string, description: string, title: string}|null
     */
    private function cell(?CarbonInterface $value, string $format): ?array
    {
        if ($value === null) {
            return null;
        }

        return [
            'display' => $value->format($format),
            'description' => $value->diffForHumans(),
            'title' => $value->toIso8601String(),
        ];
    }

    /**
     * `->limit(50)` with the full value as the `->tooltip()`. Both halves are computed
     * here rather than in the client, so the truncation point is the one Filament used.
     *
     * @return array{display: string, title: string}|null
     */
    private function notice(?string $notice): ?array
    {
        if ($notice === null || $notice === '') {
            return null;
        }

        return [
            'display' => Str::limit($notice, self::NOTICE_LIMIT),
            'title' => $notice,
        ];
    }

    /**
     * Writes the validated attributes.
     *
     * `forceFill` rather than `fill`, for one field: `archival_notice` is in the form and
     * in the table but not in `Event::$fillable`, so every save of it is silently dropped
     * today (audit 107). The request is the allow-list here, and it accepts exactly the
     * thirteen attributes the form declares, so nothing else can round-trip through this.
     * Going through `$fillable` instead would mean editing a model four other surfaces
     * mass-assign.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function write(Event $event, array $attributes): void
    {
        $event->forceFill($attributes)->save();
    }

    /**
     * Shared by create and edit: one page component, one set of fields.
     *
     * @return array<string, mixed>
     */
    private function formProps(?Event $event): array
    {
        return [
            'event' => $event ? $this->formData($event) : null,
            'badgeClassOptions' => collect(self::BADGE_CLASS_OPTIONS)
                ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                ->values()
                ->all(),
        ];
    }

    /**
     * Exactly the fields the form writes, so nothing else can round-trip through it.
     *
     * The two `DatePicker` fields go out as `Y-m-d` and the five `DateTimePicker` fields
     * as `Y-m-d\TH:i`, which are the value formats the native date and datetime-local
     * controls read; anything else silently renders an empty control (plan 2.6 keeps the
     * granularity of each of the seven fields).
     *
     * @return array<string, mixed>
     */
    private function formData(Event $event): array
    {
        return [
            'id' => $event->id,
            'name' => $event->name,
            'badge_class' => $event->badge_class ?? '',
            'starts_at' => $event->starts_at?->format('Y-m-d') ?? '',
            'ends_at' => $event->ends_at?->format('Y-m-d') ?? '',
            'order_starts_at' => $event->order_starts_at?->format('Y-m-d\TH:i') ?? '',
            'order_ends_at' => $event->order_ends_at?->format('Y-m-d\TH:i') ?? '',
            'free_badge_deadline' => $event->free_badge_deadline?->format('Y-m-d\TH:i') ?? '',
            'mass_printed_at' => $event->mass_printed_at?->format('Y-m-d\TH:i') ?? '',
            'catch_em_all_enabled' => (bool) $event->catch_em_all_enabled,
            'catch_em_all_start' => $event->catch_em_all_start?->format('Y-m-d\TH:i') ?? '',
            'catch_em_all_end' => $event->catch_em_all_end?->format('Y-m-d\TH:i') ?? '',
            'archival_notice' => $event->archival_notice ?? '',
        ];
    }
}
