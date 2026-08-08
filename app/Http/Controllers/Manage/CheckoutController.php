<?php

namespace App\Http\Controllers\Manage;

use App\Domain\Checkout\Models\Checkout\Checkout;
use App\Domain\Checkout\Models\Checkout\CheckoutItem;
use App\Domain\Checkout\Models\Checkout\States\Active;
use App\Domain\Checkout\Models\Checkout\States\Cancelled;
use App\Domain\Checkout\Models\Checkout\States\Finished;
use App\Http\Controllers\Controller;
use App\Jobs\CreateReceiptFromCheckoutJob;
use App\Models\Badge\Badge;
use App\Models\Machine;
use App\Models\Staff;
use App\Models\User;
use App\Support\Manage\Action;
use App\Support\Manage\Column;
use App\Support\Manage\Filter;
use App\Support\Manage\Status;
use App\Support\Manage\Table;
use App\Support\Manage\Toast;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Checkouts, the successor to CheckoutResource, ListCheckouts, ViewCheckout and
 * ItemsRelationManager (audit 4.5 and 4.5.1).
 *
 * A checkout is a German fiscal record: signed by a Fiskaly TSE, exported under DSFinV-K,
 * and legally required to be tamper-evident. The whole module is therefore read-only. There
 * is no create, no edit, no update and no delete here, no route for one, and CheckoutPolicy
 * refuses all four for everybody including an admin. The single write the panel may make is
 * queueing a receipt, and it lives in its own controller behind its own ability.
 *
 * Nothing on this page writes as a side effect of being rendered. `index()` and `show()`
 * read; `receipt()` renders a PDF of a record that can no longer change, which is a derived
 * artifact rather than a mutation, and only when it is not on disk already.
 *
 * Four things differ from Filament, all of them plan decisions.
 *
 *  - Money is rendered once, from cents. The Filament resource showed the same three
 *    numbers two contradictory ways on one screen: the table column divided by 100 and the
 *    `Financial Details` section rendered raw cents in a TextInput with a euro prefix
 *    (plan 2.10 #1, audit landmine 2). `Column::money()` takes cents everywhere here, on
 *    the list, on the detail page, on the items table and in the summary, and there is no
 *    variant that skips the division.
 *  - The status filter matches on the stored state names. Its options were keyed by FQCN
 *    while the column holds Spatie's `$name` strings, and Filament's SelectFilter issues a
 *    plain `whereIn('status', ...)`, so it matched zero rows and looked like it worked
 *    (plan 2.10 #35, audit landmine 6).
 *  - The receipt link is served here rather than by `pos.checkout.receipt`, which sits
 *    behind `pos-auth:machine` plus `pos-auth:machine-user`. An admin browsing /admin
 *    without an active till session was bounced instead of shown the receipt (plan 2.10
 *    #36, audit 13).
 *  - The TSE section shows the columns that exist. `tse_signature` is not one of them: the
 *    migration created `tse_start_signature` and `tse_end_signature`, so the Filament field
 *    was permanently blank and the actual signatures were invisible everywhere in admin
 *    (plan 2.10 #38, audit landmine 5). The serial and transaction numbers come with them,
 *    for the same reason.
 *
 * Deliberately not event-scoped: plan 2.9 lists checkouts among the surfaces that stay
 * unscoped, matching today. A sale belongs to the till, not to the event selector.
 */
class CheckoutController extends Controller
{
    /**
     * Filament's default table date-time format, so the timestamps read the same after the
     * move (checklist 978).
     */
    private const DATETIME_FORMAT = 'M j, Y H:i:s';

    /**
     * `->default('-')` on the cashier column, verbatim.
     */
    private const CASHIER_FALLBACK = '-';

    /**
     * What ItemsRelationManager's `description` and `payable` columns render when there is
     * nothing to show. Both return the literal `'-'`, not an empty cell.
     */
    private const ITEM_FALLBACK = '-';

    /**
     * The list envelope is spread across top-level props rather than nested under one,
     * because useTableQuery reloads `rows`, `meta`, `filters`, `sort` and `search` as an
     * Inertia partial visit and Inertia filters partials by top-level key.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Checkout::class);

        return inertia('Manage/Checkouts/Index', $this->table($request));
    }

    /**
     * The read-only detail page, successor to ViewCheckout.
     *
     * CheckoutResource defined no infolist, so Filament fell back to rendering the form
     * schema with every field `disabled()`. The same fields are here as read-only rows.
     * ItemsRelationManager becomes the second half of the same page and arrives as the
     * ordinary list envelope, so the items table sorts, searches and pages through exactly
     * the code every other list uses.
     */
    public function show(Request $request, Checkout $checkout): Response
    {
        Gate::authorize('view', $checkout);

        $checkout->loadMissing(['user', 'cashier', 'machine']);

        return inertia('Manage/Checkouts/Show', [
            'checkout' => $this->viewData($checkout),
            'actions' => array_map(
                fn (Action $action) => $action->toArray(),
                array_values($this->headerActions($checkout)),
            ),
            ...$this->itemsTable($request, $checkout),
        ]);
    }

    /**
     * The receipt PDF, served under the manage guard.
     *
     * Same document `pos.checkout.receipt` serves and the same storage path
     * `CreateReceiptFromCheckoutJob` writes; the only thing that changes is which guard
     * stands in front of it (plan 2.10 #36).
     *
     * The render is synchronous here, unlike the print action, and that is the difference
     * between the two verbs rather than an oversight: this request exists to hand back a
     * PDF, so there is nothing to hand back until one exists. It runs at most once per
     * checkout - a rendered receipt stays on disk - and a failing render is caught and
     * turned into a toast rather than a 500, which is the actual complaint behind
     * plan 2.10 #37.
     */
    public function receipt(Checkout $checkout): HttpResponse|RedirectResponse
    {
        Gate::authorize('view', $checkout);

        $path = CheckoutReceiptPrintController::receiptPath($checkout);

        if (! Storage::exists($path)) {
            try {
                CreateReceiptFromCheckoutJob::dispatchSync($checkout);
            } catch (\Throwable) {
                // Swallowed deliberately: the operator gets a toast, and the exception is
                // already on its way to Sentry through the queue worker's own reporting.
            }
        }

        if (! Storage::exists($path)) {
            Toast::flashDanger(
                'Receipt is not available',
                'The receipt for this checkout could not be rendered. Try again in a moment.',
            );

            return back();
        }

        return response(Storage::get($path), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="receipt.pdf"',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function table(Request $request): array
    {
        $query = $this->query();

        $envelope = Table::make($query)
            ->name('checkouts')
            ->columns($this->columns())
            // CheckoutResource: ->defaultSort('created_at', 'desc').
            ->defaultSort('created_at', 'desc')
            ->filters($this->filters())
            ->rows(fn (Checkout $checkout) => $this->cells($checkout))
            ->recordUrl(fn (Checkout $checkout) => Gate::allows('view', $checkout)
                ? route('admin.checkouts.show', $checkout)
                : null)
            ->rowActions(fn (Checkout $checkout) => $this->rowActions($checkout))
            // CheckoutResource: `// No bulk actions for checkouts` and
            // `// No create action - checkouts are created through POS only`.
            ->bulkActions([])
            ->pageActions([])
            ->toArray($request);

        $envelope['meta']['summary'] = $this->summary($query);

        return $envelope;
    }

    /**
     * The list query.
     *
     * The three relations are eager-loaded because all three are read on every row, and
     * `items_count` is Filament's `->counts('items')`: one subquery for the page rather
     * than a count per row.
     */
    private function query(): Builder
    {
        return Checkout::query()
            ->with(['user', 'cashier', 'machine'])
            ->withCount('items');
    }

    /**
     * The Sum summarizer on `total` (audit 4.5, table column 7 line 162).
     *
     * It rides inside `meta` rather than beside it, because `meta` is one of the five keys
     * useTableQuery reloads on a partial visit. A top-level `summary` prop would be correct
     * on the first paint and then frozen: filtering the list would move every row and leave
     * the total underneath them reading the previous set, which on a fiscal screen is worse
     * than having no total at all.
     *
     * Filament summarises the whole filtered set, not the visible page, so the paginator's
     * own limit and offset are lifted off the clone before the aggregate runs. Locked in by
     * CheckoutsTest, which asserts the figure follows a filter and ignores the page.
     *
     * @return array{label: string, value: string|null}
     */
    private function summary(Builder $query): array
    {
        $sum = clone $query;

        $base = $sum->getQuery();
        $base->limit = null;
        $base->offset = null;
        $base->orders = null;

        return [
            'label' => 'Total',
            'value' => Column::euros((int) $sum->sum('total')),
        ];
    }

    /**
     * The audit's nine columns, in order, with Filament's own labels.
     *
     * `user.name`, `cashier.name` and `machine.name` are keyed with underscores: a dot in a
     * cell key is read as a path by every data_get consumer, including Inertia's own prop
     * assertions. The labels are unchanged.
     *
     * @return array<int, Column>
     */
    private function columns(): array
    {
        return [
            Column::text('id', 'ID')->sortable()->searchable(),

            // Sorted through a correlated subquery rather than a join, so the list keeps
            // one shape whether or not the sort is on.
            Column::text('user_name', 'Customer')
                ->sortUsing(fn (Builder $query, string $dir) => $query->orderBy(
                    User::select('name')->whereColumn('users.id', 'checkouts.user_id'),
                    $dir,
                ))
                ->searchable('user.name'),

            Column::text('cashier_name', 'Cashier')
                ->sortUsing(fn (Builder $query, string $dir) => $query->orderBy(
                    Staff::select('name')->whereColumn('staff.id', 'checkouts.cashier_id'),
                    $dir,
                ))
                ->searchable('cashier.name')
                ->fallback(self::CASHIER_FALLBACK),

            // Toggleable, visible by default, exactly as the resource declares it.
            Column::text('machine_name', 'Machine')
                ->sortUsing(fn (Builder $query, string $dir) => $query->orderBy(
                    Machine::select('name')->whereColumn('machines.id', 'checkouts.machine_id'),
                    $dir,
                ))
                ->searchable('machine.name')
                ->toggleable(),

            Column::badge('status', 'Status'),
            Column::badge('payment_method', 'Payment Method'),

            // Cents in, euros out. The only renderer in the module.
            Column::money('total', 'Total')->sortable(),

            // `->counts('items')`, and not sortable, as declared.
            Column::number('items_count', 'Items'),

            Column::datetime('created_at', 'Created At')->sortable()->toggleable(),
        ];
    }

    /**
     * One row, already formatted.
     *
     * @return array<string, mixed>
     */
    private function cells(Checkout $checkout): array
    {
        return [
            'id' => $checkout->id,
            'user_name' => $this->customerCell($checkout),
            'cashier_name' => $checkout->cashier?->name,
            'machine_name' => $checkout->machine?->name,
            'status' => Status::checkout($checkout->status),
            'payment_method' => $this->paymentMethod($checkout->payment_method),
            'total' => (int) $checkout->total,
            'items_count' => (int) $checkout->items_count,
            'created_at' => $this->datetime($checkout->created_at),
        ];
    }

    /**
     * The customer, linking to the users list pre-filtered by their name.
     *
     * Filament linked to `UserResource::getUrl('index').'?tableSearch='.urlencode(name)`,
     * which is the users index with its search box filled in rather than the user record.
     * Same destination, same shape, in this panel's own query-string vocabulary.
     *
     * @return array{display: string, url: string}|null
     */
    private function customerCell(Checkout $checkout): ?array
    {
        $name = $checkout->user?->name;

        if ($name === null || $name === '') {
            return null;
        }

        return [
            'display' => $name,
            'url' => Gate::allows('viewAny', User::class)
                ? route('admin.settings.users.index', ['search' => $name])
                : null,
        ];
    }

    /**
     * The payment method badge: `cash` success, `card` info, anything else gray.
     *
     * The tones are the resource's, verbatim. The wording is the resource's own filter
     * labels (`Cash` / `Card`) rather than the raw column value the badge printed, which is
     * the same normalisation phase 6 made for the print-job enums: one vocabulary per value
     * across the panel, decided server-side. A method the map does not know still renders,
     * as itself. No glyph, because the Filament badge carried none.
     *
     * @return array{label: string, tone: string, icon: string|null}
     */
    private function paymentMethod(?string $method): array
    {
        return match ($method) {
            'cash' => Status::make('Cash', Status::OK, null),
            'card' => Status::make('Card', Status::INFO, null),
            null, '' => Status::make('Unknown', Status::IDLE, null),
            default => Status::make($method, Status::IDLE, null),
        };
    }

    /**
     * The audit's four filters.
     *
     * `status` is the one that changes. Its options were keyed by FQCN while the column
     * stores the states' own uppercase `$name` strings, so the whereIn matched nothing
     * (plan 2.10 #35, audit landmine 6). The names are read off the state classes rather
     * than retyped, so renaming a state moves the filter with it.
     *
     * `created_from` and `created_until` are the custom date form Filament rendered as two
     * DatePickers inside one filter. They are declared as selects with no options because
     * that is the shape Filter offers for a single free value, and the Index page renders
     * them as the date inputs they are - the same arrangement PrintJobController uses for
     * its two free-text filters. The comparison is `whereDate`, inclusive at both ends,
     * verbatim from the resource.
     *
     * @return array<int, Filter>
     */
    private function filters(): array
    {
        return [
            Filter::select('status', 'Status')
                ->multiple()
                ->placeholder('All statuses')
                ->options([
                    Active::$name => 'Active',
                    Finished::$name => 'Finished',
                    Cancelled::$name => 'Cancelled',
                ]),

            Filter::select('payment_method', 'Payment Method')
                ->placeholder('All payment methods')
                ->options(['cash' => 'Cash', 'card' => 'Card']),

            Filter::select('machine_id', 'Machine')
                ->placeholder('All machines')
                ->options(Machine::orderBy('name')
                    ->get(['id', 'name'])
                    ->mapWithKeys(fn (Machine $machine) => [(string) $machine->id => $machine->name])
                    ->all()),

            // Declared as dates rather than as optionless selects. Filament rendered these
            // two as DatePickers inside one filter form; as selects they were a dropdown
            // with nothing in it, so ListCheckouts drew its own date inputs on the page.
            // The filter bar renders a declared date itself, and the page no longer has a
            // second filter row of its own.
            Filter::date('created_from', 'Created From')
                ->chipLabel('Created from')
                ->placeholder('Created from')
                ->apply(fn (Builder $query, string $value) => $query->whereDate('created_at', '>=', $value)),

            Filter::date('created_until', 'Created Until')
                ->chipLabel('Created until')
                ->placeholder('Created until')
                ->apply(fn (Builder $query, string $value) => $query->whereDate('created_at', '<=', $value)),
        ];
    }

    /**
     * View, Receipt and Print, as CheckoutResource declared them.
     *
     * Print is offered only to an operator the policy allows, because it puts paper through
     * a printer. Filament offered it to every reviewer.
     *
     * @return array<int, Action>
     */
    private function rowActions(Checkout $checkout): array
    {
        return array_values(array_filter([
            Gate::allows('view', $checkout)
                ? Action::link('view', 'View', route('admin.checkouts.show', $checkout))->icon('eye')
                : null,

            Gate::allows('view', $checkout)
                // heroicon-o-document-text, colour gray, opens in a new tab.
                ? Action::link('receipt', 'Receipt', route('admin.checkouts.receipt', $checkout))
                    ->icon('file-text')
                    ->tone(Status::IDLE)
                    ->newTab()
                : null,

            Gate::allows('printReceipt', $checkout)
                ? Action::post('print', 'Print', route('admin.checkouts.print', $checkout))
                    // heroicon-o-printer, colour info.
                    ->icon('printer')
                    ->tone(Status::INFO)
                    // Modal heading and description, verbatim.
                    ->confirm('Print Receipt', 'This will add the receipt to the print queue.')
                : null,
        ]));
    }

    /**
     * ViewCheckout's own header: Download Receipt and Print Receipt, in that order.
     *
     * The print body is the same endpoint the row action posts to. In Filament the two were
     * byte-identical copies of one another (plan 2.10 #37, audit landmine 4).
     *
     * @return array<int, Action>
     */
    private function headerActions(Checkout $checkout): array
    {
        return array_values(array_filter([
            // heroicon-o-arrow-down-tray, colour gray, opens in a new tab.
            Action::link('receipt', 'Download Receipt', route('admin.checkouts.receipt', $checkout))
                ->icon('download')
                ->tone(Status::IDLE)
                ->newTab(),

            Gate::allows('printReceipt', $checkout)
                ? Action::post('print', 'Print Receipt', route('admin.checkouts.print', $checkout))
                    ->icon('printer')
                    ->tone(Status::INFO)
                    ->confirm('Print Receipt', 'This will add the receipt to the print queue.')
                : null,
        ]));
    }

    /**
     * The detail page's fields, in the order the form schema declares them.
     *
     * Money is three integers of cents formatted by the one formatter, so the detail page
     * and the list column can no longer disagree (plan 2.10 #1).
     *
     * The TSE block is the audit's, corrected: `tse_signature` does not exist as a column,
     * so it is replaced by the two signatures that do plus the serial and transaction
     * numbers, which are fiscally load-bearing and surfaced nowhere in admin today
     * (plan 2.10 #38).
     *
     * @return array<string, mixed>
     */
    private function viewData(Checkout $checkout): array
    {
        return [
            'id' => $checkout->id,
            'remote_id' => $checkout->remote_id,
            'user' => $checkout->user?->name,
            'cashier' => $checkout->cashier?->name,
            'machine' => $checkout->machine?->name,
            'status' => Status::checkout($checkout->status),
            'payment_method' => $this->paymentMethod($checkout->payment_method),
            'subtotal' => Column::euros((int) $checkout->subtotal),
            'tax' => Column::euros((int) $checkout->tax),
            'total' => Column::euros((int) $checkout->total),
            'tse_start_timestamp' => $this->datetime($checkout->tse_start_timestamp)['display'] ?? null,
            'tse_end_timestamp' => $this->datetime($checkout->tse_end_timestamp)['display'] ?? null,
            'tse_start_signature' => $checkout->tse_start_signature,
            'tse_end_signature' => $checkout->tse_end_signature,
            'tse_serial_number' => $checkout->tse_serial_number,
            'tse_transaction_number' => $checkout->tse_transaction_number,
            'created_at' => $this->datetime($checkout->created_at)['display'] ?? null,
            'updated_at' => $this->datetime($checkout->updated_at)['display'] ?? null,
        ];
    }

    /**
     * ItemsRelationManager, as the ordinary list envelope (audit 4.5.1).
     *
     * Six columns, no filters, no row actions, no bulk actions, no header actions: the
     * relation manager hard-refuses create, edit and delete, and so does everything here.
     *
     * `->paginated(false)` becomes perPage 200 with the pager visible (plan 2.3), so a
     * checkout with an unexpected number of lines cannot render an unbounded table.
     *
     * @return array<string, mixed>
     */
    private function itemsTable(Request $request, Checkout $checkout): array
    {
        /*
         * `payable` is a morphTo, so the nested `fursuit` is asked for through morphWith
         * rather than dotted: a dotted path is applied to every morph target, and the
         * moment a line item points at anything but a badge the eager load throws. Audit 98
         * records the relation manager lazy-loading the morph plus `payable->fursuit` per
         * row with the table unpaginated.
         */
        $items = $checkout->items()
            ->with(['payable' => fn (MorphTo $payable) => $payable->morphWith([Badge::class => ['fursuit']])])
            ->getQuery();

        return Table::make($items)
            ->name('checkout-items')
            ->columns([
                Column::text('name', 'Item')->searchable(),
                Column::text('description', 'Features'),
                Column::text('payable', 'Badge'),
                Column::money('subtotal', 'Subtotal'),
                Column::money('tax', 'Tax'),
                Column::money('total', 'Total'),
            ])
            ->rows(fn (CheckoutItem $item) => [
                'name' => $item->name,
                'description' => $this->features($item),
                'payable' => $this->payableCell($item),
                'subtotal' => (int) $item->subtotal,
                'tax' => (int) $item->tax,
                'total' => (int) $item->total,
            ])
            ->bulkActions([])
            ->pageActions([])
            ->perPage(200)
            ->perPageOptions([25, 50, 100, 200])
            ->toArray($request);
    }

    /**
     * `description` is an array cast. Filament joined it with `, ` and rendered `-` when it
     * was empty or not an array.
     */
    private function features(CheckoutItem $item): string
    {
        $description = $item->description;

        if (is_array($description) && $description !== []) {
            return implode(', ', $description);
        }

        return self::ITEM_FALLBACK;
    }

    /**
     * `{fursuit name} (#{custom_id})` for a badge, `-` for anything else, linking to the
     * badge edit page in a new tab.
     *
     * Two corrections inside the same shape. The type test goes through the model's morph
     * class as well as the literal, so a registered morph map does not silently turn every
     * badge line into `-`. And a soft-deleted fursuit no longer takes the page down: the
     * Filament closure read `$badge->fursuit->name` with no null guard, and `fursuit` is a
     * soft-deleting relation, so one deleted suit was a 500 on a fiscal document
     * (checklist 3a, audit 113).
     *
     * @return array{display: string, url: string|null}|string
     */
    private function payableCell(CheckoutItem $item): array|string
    {
        $badge = $item->payable;

        if (! $badge instanceof Badge) {
            return self::ITEM_FALLBACK;
        }

        $name = $badge->fursuit?->name;

        $display = $name === null
            ? '#'.$badge->custom_id
            : "{$name} (#{$badge->custom_id})";

        return [
            'display' => $display,
            'url' => Gate::allows('update', $badge)
                ? route('admin.badges.edit', $badge)
                : null,
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
}
