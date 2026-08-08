<?php

namespace App\Http\Controllers\Manage;

use App\Enum\PrintJobStatusEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Manage\BadgeRequest;
use App\Models\Badge\Badge;
use App\Models\Badge\State_Fulfillment\BadgeFulfillmentStatusState;
use App\Models\Badge\State_Payment\BadgePaymentStatusState;
use App\Models\Fursuit\Fursuit;
use App\Support\Manage\Action;
use App\Support\Manage\Column;
use App\Support\Manage\EventScope;
use App\Support\Manage\Filter;
use App\Support\Manage\Status;
use App\Support\Manage\Table;
use App\Support\Manage\Toast;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Inertia\Response;

/**
 * Badges, the successor to BadgeResource (audit 4.2) and its ListBadges / EditBadge pages.
 *
 * The biggest resource in the panel, and the one carrying the most money risk. Three
 * things change, all authorised by the plan:
 *
 *  - `total` renders through Column::money(), which always divides by 100. The Filament
 *    column called `->money('EUR')` with no `divideBy` on a cents column, so every badge
 *    total on the list read a hundred times too high (plan 2.10 #1, audit 1). The form
 *    field is read-only for the mirror image of the same bug: it rendered euros and had
 *    no inverse on write, so saving an unchanged badge wrote "3.00" into a cents column
 *    and turned 300 cents into 3 (plan 2.10 #3, audit 3). Nothing on this module's write
 *    path touches a money column at all now.
 *  - the attendee-id sort and the attendee range filter drop `CAST(x AS UNSIGNED)`, which
 *    is MySQL-only and breaks on the SQLite database `.env.example` defaults to
 *    (plan 2.10 #30, audit 16). The direction is no longer interpolated into raw SQL
 *    either (audit 17).
 *  - both status selects become transition pickers driven by the state machines, rather
 *    than raw writes that skipped `custom_id` allocation, the timestamps, the
 *    notifications and the activity entries (plan 2.10 #8, audit 20).
 *
 * The Create page is deliberately not ported: `fursuit_id` was disabled, disabled fields
 * do not dehydrate, and `badges.fursuit_id` is NOT NULL with a foreign key, so creating a
 * badge from admin has always thrown an integrity error (plan 2.10 #6, audit 25).
 *
 * The `printBadge` row action and the `printBadgeBulk` bulk action are declared here and
 * implemented in BadgePrintController, which landed in phase 7 with the rest of the print
 * pipeline, against `BadgePrintQueue` (plan part 3). Nothing on this controller's read
 * path touches a printer, a print job or a batch: the two actions are POSTs of their own,
 * so the five-second poll behind this list cannot queue a card.
 */
class BadgeController extends Controller
{
    /**
     * Filament's model label for this resource, as its delete modal renders it.
     */
    private const MODEL_LABEL = 'badge';

    /**
     * The three date formats BadgeResource used, verbatim (audit 7.6).
     */
    private const DATE_FORMAT = 'M j, Y';

    private const DATETIME_FORMAT = 'M j, Y H:i';

    /**
     * The print-job statuses BadgeResource's `print_jobs_count` closure counted as still
     * outstanding, verbatim.
     *
     * @var array<int, string>
     */
    private const PENDING_JOB_STATUSES = [
        PrintJobStatusEnum::Pending->value,
        PrintJobStatusEnum::Queued->value,
        PrintJobStatusEnum::Printing->value,
        PrintJobStatusEnum::Retrying->value,
    ];

    /**
     * The list envelope is spread across top-level props rather than nested under one,
     * because useTableQuery reloads `rows`, `meta`, `filters`, `sort` and `search` as a
     * partial visit and Inertia filters partials by top-level key. Nested under a `table`
     * prop those five keys resolve to null, the client merges the nulls over the props it
     * already has, and every sort, page and per-page click is a silent no-op.
     */
    public function index(Request $request, EventScope $scope): Response
    {
        Gate::authorize('viewAny', Badge::class);

        return inertia('Manage/Badges/Index', $this->table($request, $scope));
    }

    /**
     * The badge detail. Authorized on `view`, not `update`, because this is the only badge
     * detail the panel has - there is no separate show page - so a reviewer who may read
     * badges must be able to open one. `canEdit` in formProps() is what decides whether it
     * renders as a form or as a record; the PUT is separately gated. See
     * docs/admin/roles.md.
     */
    public function edit(Badge $badge): Response
    {
        Gate::authorize('view', $badge);

        return inertia('Manage/Badges/Form', $this->formProps($badge));
    }

    /**
     * The only writable fields are the two statuses, and neither is written: both are
     * transitioned, so `custom_id` allocation, the `printed_at` / `ready_for_pickup_at` /
     * `picked_up_at` stamping, the notifications and the activity entries all happen
     * exactly as they do everywhere else in the app (plan 2.10 #8).
     *
     * Payment goes first. `ToReadyForPickup` and `ToPickedUp` stamp `paid_at`, and a
     * badge marked paid in the same save should already be Paid by the time the
     * fulfillment transition looks at it.
     */
    public function update(BadgeRequest $request, Badge $badge): RedirectResponse
    {
        $validated = $request->validated();

        if ($validated['status_payment'] !== $badge->status_payment->getValue()) {
            $badge->status_payment->transitionTo(
                BadgePaymentStatusState::resolveStateClass($validated['status_payment'])
            );
        }

        if ($validated['status_fulfillment'] !== $badge->status_fulfillment->getValue()) {
            $badge->status_fulfillment->transitionTo(
                BadgeFulfillmentStatusState::resolveStateClass($validated['status_fulfillment'])
            );
        }

        // Filament's stock save toast; BadgeResource declares no notifications of its
        // own anywhere (audit 4.2).
        Toast::flashSuccess('Saved');

        return redirect()->to(Table::returnUrl('badges', route('admin.badges.index')));
    }

    /**
     * Soft delete, which is what Filament's DeleteAction did on a model using
     * SoftDeletes. The panel exposes no trashed filter and no restore, exactly as today
     * (audit 136); that stays a recorded gap rather than a silent change here.
     */
    public function destroy(Badge $badge): RedirectResponse
    {
        Gate::authorize('delete', $badge);

        $badge->delete();

        Toast::flashSuccess('Deleted');

        return redirect()->to(Table::returnUrl('badges', route('admin.badges.index')));
    }

    /**
     * @return array<string, mixed>
     */
    private function table(Request $request, EventScope $scope): array
    {
        return Table::make($this->query($scope))
            ->name('badges')
            ->columns($this->columns())
            // BadgeResource: ->defaultSort('sort_attendee_id', 'asc'), on the joined
            // alias. The column declares the portable numeric sort below and Table uses
            // that callback for the default too, so the first page load is ordered the
            // same way clicking the header is.
            ->defaultSort('sort_attendee_id')
            ->filters($this->filters($scope))
            // ->paginationPageOptions([10, 25, 50, 100]), no 'all'. Which is the stock
            // list, stated here so a change to the default is visible.
            ->perPageOptions([10, 25, 50, 100])
            ->rows(fn (Badge $badge) => $this->cells($badge))
            // `view`, not `update`: the detail page is the record, and a reviewer reads
            // badges. What they cannot do is save it; see BadgeController::edit().
            ->recordUrl(fn (Badge $badge) => Gate::allows('view', $badge)
                ? route('admin.badges.edit', $badge)
                : null)
            ->rowActions(fn (Badge $badge) => array_values(array_filter([
                Gate::allows('view', $badge)
                    ? Action::link(
                        'edit',
                        Gate::allows('update', $badge) ? 'Edit' : 'View',
                        route('admin.badges.edit', $badge)
                    )->icon(Gate::allows('update', $badge) ? 'pencil' : 'eye')
                    : null,
                // `printBadge`. Declared by BadgePrintController, which owns the verb and
                // the endpoint, so the button and the write cannot answer the "may this
                // operator print" question differently.
                BadgePrintController::rowAction($badge),
            ])))
            // `printBadgeBulk` is the only bulk action this table has ever had: there is
            // deliberately no bulk delete, no export and no dissociate, because
            // BadgeResource passes bulkActions() an explicit array (audit 4.2).
            //
            // The selection keeps ->selectCurrentPageOnly() semantics for free: DataTable
            // derives it from the current page's rows and prunes it on every reload, so it
            // cannot cross a page. That cap is deliberate, not accidental (plan 2.3), and
            // the bulk print inherits it.
            ->bulkActions(array_values(array_filter([
                BadgePrintController::bulkAction(),
            ])))
            // ListBadges offers a CreateAction labelled `New badge`. It is not ported: the
            // page it opens has never been able to save (plan 2.10 #6, audit 25).
            ->pageActions([])
            ->toArray($request);
    }

    /**
     * The list query.
     *
     * The event scope stays a `whereHas` on the relation even though `fursuits` is also
     * joined below. That is what BadgeResource does, and the two are not interchangeable:
     * folding the scope into the left join would start matching badges whose fursuit row
     * is gone (audit 67).
     *
     * The joins exist only to carry `event_users.attendee_id` as a sortable, filterable
     * column. `select('badges.*')` keeps the model's own attributes intact.
     */
    private function query(EventScope $scope): Builder
    {
        $query = $scope->apply(Badge::query(), 'fursuit');

        return $query
            ->leftJoin('fursuits', 'badges.fursuit_id', '=', 'fursuits.id')
            ->leftJoin('event_users', function ($join) {
                $join->on('fursuits.user_id', '=', 'event_users.user_id')
                    ->on('fursuits.event_id', '=', 'event_users.event_id');
            })
            ->select('badges.*')
            ->addSelect('event_users.attendee_id as sort_attendee_id')
            // Columns 2, 3 and 4 read across three relations, and the image column reads
            // a fourth attribute off the same fursuit.
            ->with(['fursuit.species', 'fursuit.user'])
            // Column 7 ran `$record->printJobs()->get()` twice per row with no eager
            // load, on a table polling every 5 seconds: 200 queries per render on a
            // 100-row page (audit 95). Three correlated counts in the one query answer
            // the same three questions.
            ->withCount([
                'printJobs',
                'printJobs as failed_print_jobs_count' => fn (Builder $jobs) => $jobs->where('status', PrintJobStatusEnum::Failed->value),
                'printJobs as pending_print_jobs_count' => fn (Builder $jobs) => $jobs->whereIn('status', self::PENDING_JOB_STATUSES),
            ]);
    }

    /**
     * The audit's fourteen columns, in order, with Filament's own labels verbatim.
     *
     * Five are hidden by default, which is every `isToggledHiddenByDefault: true` flag
     * BadgeResource carries: extra_copy, total, created_at, printed_at, picked_up_at
     * (plan 2.3).
     *
     * @return array<int, Column>
     */
    private function columns(): array
    {
        return [
            // Filament's ImageColumn is ->circular() here too (audit 4.2 column 1).
            Column::image('fursuit.image', 'Image')->circular(),
            Column::text('fursuit.name', 'Fursuit')
                // Filament sorted this relation column through its own join; the query
                // already carries one, so the sort is the joined column itself.
                ->sortable('fursuits.name')
                ->searchable('fursuit.name'),
            Column::text('fursuit.species.name', 'Species')->toggleable(),
            Column::text('fursuit.user.name', 'Owner')
                ->searchable('fursuit.user.name')
                ->toggleable(),
            Column::copyable('custom_id', 'Badge ID')
                ->searchable('custom_id')
                ->toggleable(),
            Column::text('sort_attendee_id', 'Attendee ID')
                ->sortUsing(fn (Builder $query, string $direction) => $query->orderBy(
                    self::numeric('sort_attendee_id'),
                    $direction,
                ))
                ->toggleable()
                // No ->align(): BadgeResource declares alignment on two columns only,
                // `print_jobs_count` (centre) and `total` (end), and this is not one of
                // them.
                ->fallback('N/A'),
            Column::badge('print_jobs_count', 'Print Jobs')->align('center'),
            Column::badge('status_fulfillment', 'Fulfillment'),
            Column::badge('status_payment', 'Payment'),
            Column::icon('extra_copy', 'Extra Copy')->toggleable(hiddenByDefault: true),
            // Cents in, euros out, always. This is landmine 1.
            Column::money('total', 'Total')->toggleable(hiddenByDefault: true),
            Column::datetime('created_at', 'Created')->toggleable(hiddenByDefault: true),
            Column::datetime('printed_at', 'Printed At')
                ->toggleable(hiddenByDefault: true)
                ->fallback('Not printed'),
            Column::datetime('picked_up_at', 'Picked Up')
                ->toggleable(hiddenByDefault: true)
                ->fallback('Not picked up'),
        ];
    }

    /**
     * One row, already formatted.
     *
     * Every relation hop is null-safe. `Fursuit` uses SoftDeletes and the join deliberately
     * ignores that scope, so a badge whose fursuit or user has been soft-deleted still has
     * a row here; in Filament the same row threw while rendering and took the whole table
     * down (audit 113).
     *
     * @return array<string, mixed>
     */
    private function cells(Badge $badge): array
    {
        return [
            'fursuit.image' => $this->thumbUrl($badge->fursuit),
            'fursuit.name' => $this->linked($badge->fursuit?->name, $this->fursuitUrl($badge)),
            'fursuit.species.name' => $badge->fursuit?->species?->name,
            'fursuit.user.name' => $this->linked(
                $badge->fursuit?->user?->name,
                $badge->fursuit?->user?->name === null
                    ? null
                    : route('admin.settings.users.index', ['search' => $badge->fursuit->user->name]),
            ),
            'custom_id' => $badge->custom_id,
            'sort_attendee_id' => $badge->sort_attendee_id,
            'print_jobs_count' => $this->printJobs($badge),
            'status_fulfillment' => Status::badgeFulfillment($badge->status_fulfillment),
            'status_payment' => Status::badgePayment($badge->status_payment),
            // IconColumn->boolean() with trueIcon heroicon-o-document-plus and
            // falseIcon(null): a mark when it is an extra copy, and nothing at all
            // when it is not.
            'extra_copy' => $badge->extra_copy
                ? ['icon' => 'file-text', 'tone' => Status::INFO, 'title' => 'Extra copy']
                : null,
            'total' => $badge->total,
            'created_at' => $this->datetime($badge->created_at, self::DATE_FORMAT),
            'printed_at' => $this->datetime($badge->printed_at),
            'picked_up_at' => $this->datetime($badge->picked_up_at),
        ];
    }

    /**
     * Column 7's state and colour, from the three counts the query already carries.
     *
     * The strings are BadgeResource's, verbatim. So is the colour ladder: gray at zero,
     * warning with failures, info with anything still outstanding, success otherwise;
     * `$printed`, which that closure computed and never used, is not reproduced.
     *
     * @return array{label: string, tone: string, icon: string|null}
     */
    private function printJobs(Badge $badge): array
    {
        $total = (int) $badge->print_jobs_count;
        $failed = (int) $badge->failed_print_jobs_count;
        $pending = (int) $badge->pending_print_jobs_count;

        $label = match (true) {
            $total === 0 => '0',
            $failed > 0 => "{$total} ({$failed} failed)",
            $pending > 0 => "{$total} ({$pending} pending)",
            default => (string) $total,
        };

        $tone = match (true) {
            $total === 0 => Status::IDLE,
            $failed > 0 => Status::WARN,
            $pending > 0 => Status::INFO,
            default => Status::OK,
        };

        $status = Status::make($label, $tone, null);

        // Filament linked the chip at the print-jobs list filtered to this badge. That
        // module lands in phase 6; until its route exists the chip is just a chip.
        if (Route::has('admin.print-jobs.index')) {
            $status['url'] = route('admin.print-jobs.index', [
                'filter' => ['printable_id' => $badge->id, 'printable_type' => $badge::class],
            ]);
        }

        return $status;
    }

    /**
     * The audit's four filters.
     *
     * @return array<int, Filter>
     */
    private function filters(EventScope $scope): array
    {
        return [
            Filter::select('status_fulfillment', 'Fulfillment Status')
                ->multiple()
                ->placeholder('All Statuses')
                ->options([
                    'pending' => 'Pending',
                    'processing' => 'Processing',
                    'ready_for_pickup' => 'Ready for Pickup',
                    'picked_up' => 'Picked Up',
                ])
                // Qualified: `fursuits` and `event_users` are joined in, so an
                // unqualified column name is only unambiguous by luck.
                ->apply(fn (Builder $query, array $value) => $query->whereIn('badges.status_fulfillment', $value)),

            Filter::select('status_payment', 'Payment Status')
                ->multiple()
                ->placeholder('All Payments')
                ->options([
                    'unpaid' => 'Unpaid',
                    'paid' => 'Paid',
                ])
                ->apply(fn (Builder $query, array $value) => $query->whereIn('badges.status_payment', $value)),

            Filter::ternary('is_free_badge', 'Free Badge')
                ->placeholder('All Badges')
                ->trueLabel('Free Badges Only')
                ->falseLabel('Paid Badges Only')
                ->apply(fn (Builder $query, string $value) => $query->where('badges.is_free_badge', $value === '1')),

            // Filament set no label, so it rendered its auto label: `Attendee id range`.
            Filter::range('attendee_id_range', 'Attendee id range')
                // The chip already shows the bounds, so it says `Attendee 1 to 600`
                // rather than repeating the word range next to them.
                ->chipLabel('Attendee')
                ->apply(fn (Builder $query, array $value) => $this->applyAttendeeRange($query, $value, $scope)),

            // The print cutoff. Everything approved before the last run is already printed
            // and filed, so the next run only wants what came in after it; without this the
            // list re-offers the whole backlog on every run. BadgePrintController::bulk()
            // sets `approved_from` to the newest badge it just queued, and the redirect
            // carries it back onto the list.
            Filter::datetime('approved_from', 'Approved from')
                ->chipLabel('Approved after')
                ->apply(fn (Builder $query, string $value) => $this->applyApprovedBound($query, '>=', $value)),

            Filter::datetime('approved_until', 'Approved until')
                ->chipLabel('Approved before')
                ->apply(fn (Builder $query, string $value) => $this->applyApprovedBound($query, '<=', $value)),
        ];
    }

    /**
     * One end of the approval cutoff, as a `whereHas` on the badge's own fursuit.
     *
     * The bound is parsed rather than passed through: a `datetime-local` control sends
     * `2026-08-08T14:30`, and the `T` is not something every driver reads as a datetime.
     * An unparseable bound narrows nothing rather than throwing, because it can only come
     * from a hand-edited URL.
     */
    private function applyApprovedBound(Builder $query, string $operator, string $value): void
    {
        try {
            $bound = Carbon::parse($value);
        } catch (InvalidFormatException) {
            return;
        }

        $query->whereHas(
            'fursuit',
            fn (Builder $fursuits) => $fursuits->where('fursuits.approved_at', $operator, $bound),
        );
    }

    /**
     * The attendee range, as a `whereHas` on `fursuit.user.eventUsers` exactly as today,
     * with two differences: the comparison is portable rather than `CAST(x AS UNSIGNED)`
     * (plan 2.10 #30), and the bound is a binding rather than raw text.
     *
     * The event constraint follows the global scope instead of a second reader of it.
     * With "all events" selected there is no event to constrain to, and the range then
     * means what it says across every event, which is the branch the old middleware could
     * never reach (plan 2.9).
     *
     * @param  array{min: string, max: string}  $value
     */
    private function applyAttendeeRange(Builder $query, array $value, EventScope $scope): void
    {
        $eventId = $scope->id();

        $bound = function (string $operator, string $bound) use ($query, $eventId) {
            $query->whereHas('fursuit.user.eventUsers', function (Builder $eventUsers) use ($operator, $bound, $eventId) {
                if ($eventId !== null) {
                    $eventUsers->where('event_id', $eventId);
                }

                $eventUsers->where(self::numeric('attendee_id'), $operator, (int) $bound);
            });
        };

        if ($value['min'] !== '') {
            $bound('>=', $value['min']);
        }

        if ($value['max'] !== '') {
            $bound('<=', $value['max']);
        }
    }

    /**
     * Attendee ids are stored as strings and have to compare as numbers.
     *
     * `CAST(x AS UNSIGNED)` is MySQL and MariaDB only, and this repo's default database
     * is SQLite, so the sort and both halves of the range filter fail there today
     * (audit 16). `DECIMAL` is in the SQL standard: MySQL casts to a fixed-point number,
     * SQLite gives the type name NUMERIC affinity and converts the same way, and both
     * yield 0 for a value that is not a number rather than raising.
     */
    private static function numeric(string $column): Expression
    {
        return DB::raw('CAST('.$column.' AS DECIMAL(20, 0))');
    }

    /**
     * Everything the edit form renders, already formatted. Nothing here is written back
     * except the two statuses; see BadgeRequest.
     *
     * @return array<string, mixed>
     */
    private function formProps(Badge $badge): array
    {
        return [
            'badge' => [
                'id' => $badge->id,
                'fursuit' => $badge->fursuit?->name,
                'custom_id' => $badge->custom_id,
                'species_name' => $badge->fursuit?->species?->name,
                'owner_name' => $badge->fursuit?->user?->name,
                'status_fulfillment' => $badge->status_fulfillment->getValue(),
                'status_payment' => $badge->status_payment->getValue(),
                // Read through the one money formatter, so the form and the list column
                // cannot disagree about what a cents column means (plan 2.10 #2).
                'total' => Column::euros($badge->total),
                'subtotal' => Column::euros($badge->subtotal),
                'tax' => Column::euros($badge->tax),
                'is_free_badge' => (bool) $badge->is_free_badge,
                'extra_copy' => (bool) $badge->extra_copy,
                'dual_side_print' => (bool) $badge->dual_side_print,
                'apply_late_fee' => (bool) $badge->apply_late_fee,
                'created_at' => $this->datetime($badge->created_at)['display'] ?? null,
                'printed_at' => $this->datetime($badge->printed_at)['display'] ?? null,
                'picked_up_at' => $this->datetime($badge->picked_up_at)['display'] ?? null,
            ],
            'fulfillmentOptions' => self::fulfillmentOptions($badge),
            'paymentOptions' => self::paymentOptions($badge),
            // False for a reviewer, who reads badges but does not move them between
            // states. The page then renders the two status selects as text and drops its
            // save bar, the same shape the other twelve fields already have.
            'canEdit' => Gate::allows('update', $badge),
            'deleteAction' => Gate::allows('delete', $badge)
                ? Action::delete('delete', 'Delete', route('admin.badges.destroy', $badge))
                    ->icon('trash-2')
                    ->tone(Status::DANGER)
                    // EditBadge's DeleteAction, never overridden: heading `Delete :label`
                    // with the model label.
                    ->confirmDelete(self::MODEL_LABEL)
                    ->toArray()
                : null,
        ];
    }

    /**
     * The fulfillment states this badge may actually be moved to: the one it is in, plus
     * whatever `BadgeFulfillmentStatusState::config()` allows from there.
     *
     * That includes the POS error correction `picked_up -> ready_for_pickup`, which the
     * machine defines and the panel therefore offers. It excludes `printed`, which no
     * transition reaches, and the free select offered anyway.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function fulfillmentOptions(Badge $badge): array
    {
        return self::options(
            $badge->status_fulfillment->getValue(),
            $badge->status_fulfillment->transitionableStates(),
            fn (string $name) => match ($name) {
                'pending' => 'Pending',
                'processing' => 'Processing',
                'ready_for_pickup' => 'Ready for Pickup',
                'picked_up' => 'Picked Up',
                default => ucfirst($name),
            },
        );
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function paymentOptions(Badge $badge): array
    {
        return self::options(
            $badge->status_payment->getValue(),
            $badge->status_payment->transitionableStates(),
            fn (string $name) => ucfirst($name),
        );
    }

    /**
     * @param  array<int, string>  $transitionable
     * @param  callable(string): string  $label
     * @return array<int, array{value: string, label: string}>
     */
    private static function options(string $current, array $transitionable, callable $label): array
    {
        return collect([$current, ...$transitionable])
            ->unique()
            ->values()
            ->map(fn (string $name) => ['value' => $name, 'label' => $label($name)])
            ->all();
    }

    /**
     * The row avatar: the rendered thumbnail, falling back to the master photo.
     *
     * The masters are print-quality (2040x2720, comfortably over a megabyte), so a page
     * of twenty-five rows pulled twenty-five full-size photos through a 40px circle.
     * `image_thumb_url` is the gallery's grid render. That prefix is public, so the URL is
     * plain and stable - no presign per row, and `Cache-Control` means the second page load
     * costs nothing. A fursuit whose render has not landed - an old import, or a file GD
     * refused - still shows its master, which stays private and signed.
     */
    private function thumbUrl(?Fursuit $fursuit): ?string
    {
        return $fursuit?->image_thumb_url ?? $this->imageUrl($fursuit?->image);
    }

    /**
     * A signed read URL for a private S3 object, or the placeholder.
     *
     * The disk and visibility are BadgeResource's (`->disk('s3')->visibility('private')`)
     * and the fallback is its `defaultImageUrl`. `checkFileExistence(false)` means a
     * broken key renders as a broken image rather than being skipped, which is kept:
     * silently hiding a missing image hides the fact that it is missing.
     */
    private function imageUrl(?string $path): ?string
    {
        $placeholder = url('/images/placeholder.png');

        if ($path === null || $path === '') {
            return $placeholder;
        }

        // A machine with no S3 credentials cannot even build the disk, and one broken
        // image must not be the reason the whole list 500s.
        $disk = rescue(fn () => Storage::disk('s3'), null, report: false);

        if ($disk === null) {
            return $placeholder;
        }

        // Not every driver signs URLs; the local disk a dev machine falls back to does
        // not. A plain URL still points at the right object.
        return rescue(
            fn () => $disk->temporaryUrl($path, now()->addMinutes(15)),
            fn () => rescue(fn () => $disk->url($path), $placeholder, report: false),
            report: false,
        );
    }

    /**
     * A text cell that is also a link, when there is somewhere to go.
     *
     * @return array{display: string, url: string|null}|string|null
     */
    private function linked(?string $display, ?string $url): array|string|null
    {
        if ($display === null || $url === null) {
            return $display;
        }

        return ['display' => $display, 'url' => $url];
    }

    /**
     * BadgeResource links the fursuit name at the fursuit view page. That module lands in
     * phase 3; until its route exists the name is plain text rather than a dead link.
     */
    private function fursuitUrl(Badge $badge): ?string
    {
        if ($badge->fursuit === null || ! Route::has('admin.fursuits.show')) {
            return null;
        }

        return route('admin.fursuits.show', $badge->fursuit);
    }

    /**
     * @return array{display: string, title: string}|null
     */
    private function datetime(?CarbonInterface $value, string $format = self::DATETIME_FORMAT): ?array
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
