<?php

namespace App\Http\Controllers\Manage;

use App\Domain\Checkout\Enums\TseClientStateEnum;
use App\Domain\Checkout\Models\TseClient;
use App\Http\Controllers\Controller;
use App\Support\Manage\Action;
use App\Support\Manage\Column;
use App\Support\Manage\Status;
use App\Support\Manage\Table;
use App\Support\Manage\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Response;

/**
 * TSE clients, the successor to TseClientResource and its three pages (audit 4.12).
 *
 * A TSE client is the identity a Technical Security System signs fiscal transactions
 * under. `serial_number` is the number KassenSichV requires to stay traceable from every
 * signed receipt back to the security module that signed it, and `remote_id` is the
 * Fiskaly-side handle the signing calls address. Neither is configuration this panel
 * owns, so this module reads and never writes.
 *
 * Identity is still read-only, and that is the point of the module.
 *
 *  - `remote_id` and `serial_number` are never edited (plan 2.10 #14, audit landmine 8):
 *    rewriting them silently changes the identity that past checkouts were signed under.
 *    There is no PUT and no delete - a client whose serial receipts still point at has to
 *    stay readable for as long as those records are kept.
 *  - `state` is the one thing that moves, and it only moves through Fiskaly.
 *    `TseClientsObserver` PUTs on `created` and PATCHes on `updated`, so the three write
 *    endpoints below are requests to the TSS that happen to leave a local row behind,
 *    not local rows that happen to be mirrored.
 *
 * ## Registration
 *
 * Filament's `createnew` was dropped for what it did, not for what it was. It minted a
 * random UUID as both `remote_id` and `serial_number`, hardcoded `state` to the raw
 * string `'REGISTERED'`, and never spoke to Fiskaly at all (plan 2.10 #13, audit landmine
 * 7) - so every checkout signed against that row carried a serial the TSS had never
 * issued. `store()` does the same job the other way round: the row is created inside a
 * transaction, the observer's PUT to the TSS happens inside it too, and a refusal from
 * Fiskaly rolls the row back rather than leaving a client that exists only here.
 *
 * There are no fields to fill in. Fiskaly is asked for a client under an id we generate
 * and takes that same value as the serial, which is exactly what
 * `FiskalyService::createClient()` sends; a form would only offer new ways to collide
 * with a client that already exists upstream.
 *
 * ## One registered client at a time
 *
 * `store()` and `register()` both refuse while another client is REGISTERED, and the
 * buttons for them are not offered in that state either. Two live clients means two
 * serials in circulation for the same till with nothing recording which signed what, and
 * Fiskaly bills for each one that is left switched on. The yearly move is to deregister
 * the outgoing client and register the previous one again, which is why `register()`
 * exists as its own endpoint rather than being folded into `store()`.
 *
 * `state` is read through `TseClientStateEnum`, never as a hand-typed string. The Filament
 * side kept three copies of the same vocabulary - the fabricator's `'REGISTERED'`, the
 * Select's hand-duplicated option list, and the enum itself - so renaming a case broke two
 * of them at runtime with nothing failing first. Here the list cell is the enum case's own
 * value and the detail badge is `Status::tseClient()`, which matches on the case, so a
 * rename is a type error rather than a silent one.
 *
 * The list is deliberately not event-scoped: plan 2.9 lists TSE clients among the surfaces
 * that stay unscoped, matching today. A security module belongs to the hall, not to an
 * event.
 */
class TseClientController extends Controller
{
    /**
     * Filament's default table date-time format, kept so timestamps read the same across
     * the panel.
     */
    private const DATETIME_FORMAT = 'M j, Y H:i:s';

    /**
     * The list envelope is spread across top-level props rather than nested under one,
     * because useTableQuery reloads `rows`, `meta`, `filters`, `sort` and `search` as a
     * partial visit and Inertia filters partials by top-level key.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', TseClient::class);

        return inertia('Manage/TseClients/Index', $this->table($request));
    }

    /**
     * The detail page the Filament resource never had: it declared no view page and no
     * infolist, so the only way to look at a client was the edit form that this module
     * does not ship.
     *
     * It also answers the question audit 4.12 records as unanswerable from this screen:
     * `TseClient::machine()` is a `hasOne(Machine::class)` that nothing surfaced, so there
     * was no way to see which POS machine a client is bound to.
     */
    public function show(TseClient $client): Response
    {
        Gate::authorize('view', $client);

        $client->load('machine');

        $state = $this->stateValue($client);

        return inertia('Manage/TseClients/Show', [
            'client' => [
                'id' => $client->getKey(),
                'remote_id' => $client->remote_id,
                'serial_number' => $client->serial_number,
                'state' => Status::tseClient($state),
                // The stored value alongside the label, because the serial and the state
                // are what an operator reconciles against the Fiskaly dashboard and the
                // DSFinV-K export, and those speak in raw values.
                'state_value' => $state,
                'machine' => $client->machine?->name,
                'created_at' => $client->created_at?->format(self::DATETIME_FORMAT),
                'updated_at' => $client->updated_at?->format(self::DATETIME_FORMAT),
            ],
            // The same two lifecycle actions the row offers, so the record page is not a
            // dead end an operator has to go back to the list from.
            'headerActions' => collect($this->rowActions($client))
                ->reject(fn (Action $action) => $action->name === 'view')
                ->map->toArray()
                ->values()
                ->all(),
        ]);
    }

    /**
     * Issue a new client on the TSS and keep the row it produced.
     *
     * The whole operation is one transaction, and `TseClientsObserver::created()` runs
     * inside it: a refusal from Fiskaly throws out of the observer, the transaction rolls
     * back, and nothing is left behind. That is the difference between this and the
     * Filament action it replaces, which wrote the row first and never called anyone.
     *
     * The id is generated here and Fiskaly is asked to take it as the serial too, which is
     * what `FiskalyService::createClient()` sends. Nothing is typed in, so nothing can
     * collide with a client that already exists upstream.
     */
    public function store(): RedirectResponse
    {
        Gate::authorize('create', TseClient::class);

        if ($active = TseClient::activeClient()) {
            return $this->refuseSecondClient($active);
        }

        $id = (string) Str::uuid();

        // `serial_number` is the same value on purpose: it is what
        // `FiskalyService::createClient()` sends as the serial, so storing anything else
        // would describe the client differently here than upstream.
        $client = DB::transaction(fn () => TseClient::create([
            'remote_id' => $id,
            'serial_number' => $id,
            'state' => TseClientStateEnum::REGISTERED,
        ]));

        Toast::flashSuccess('Registered', 'The TSS issued client '.$client->remote_id.'.');

        return redirect()->route('admin.tse-clients.show', $client);
    }

    /**
     * Bring a deregistered client back into service - the usual move between conventions.
     */
    public function register(TseClient $client): RedirectResponse
    {
        Gate::authorize('update', $client);

        if ($active = TseClient::activeClient(exceptId: $client->getKey())) {
            return $this->refuseSecondClient($active);
        }

        if ($client->isRegistered()) {
            Toast::flashSuccess('Already registered', 'This client is the one currently signing.');

            return back();
        }

        // The observer PATCHes the TSS on `updated`; if it refuses, nothing is saved.
        $client->update(['state' => TseClientStateEnum::REGISTERED]);

        Toast::flashSuccess('Registered', $client->remote_id.' is now the signing client.');

        return back();
    }

    /**
     * Take a client out of service. Its serial stays on every receipt it signed, so the
     * row stays too.
     */
    public function deregister(TseClient $client): RedirectResponse
    {
        Gate::authorize('update', $client);

        if (! $client->isRegistered()) {
            Toast::flashSuccess('Already deregistered', 'This client is not signing anything.');

            return back();
        }

        $client->update(['state' => TseClientStateEnum::DEREGISTERED]);

        Toast::flashSuccess('Deregistered', $client->remote_id.' will not sign anything further.');

        return back();
    }

    /**
     * One registered client at a time; the reasoning is in the class docblock.
     */
    private function refuseSecondClient(TseClient $active): RedirectResponse
    {
        Toast::flashDanger(
            'Nothing was registered',
            'Client '.$active->remote_id.' is already registered. Deregister it first: only one client may sign at a time.'
        );

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function table(Request $request): array
    {
        return Table::make(TseClient::query())
            ->name('tse-clients')
            ->columns($this->columns())
            // TseClientResource declares no defaultSort and falls back to primary-key
            // order. Stated rather than left implicit, so the order does not depend on
            // whatever the driver happens to return.
            ->defaultSort('id')
            // TseClientResource declares `->filters([])`.
            ->filters([])
            ->rows(fn (TseClient $client) => [
                'remote_id' => $client->remote_id,
                'serial_number' => $client->serial_number,
                'state' => $this->stateValue($client),
            ])
            ->recordUrl(fn (TseClient $client) => Gate::allows('view', $client)
                ? route('admin.tse-clients.show', $client)
                : null)
            ->rowActions(fn (TseClient $client) => $this->rowActions($client))
            // No bulk actions: registering is a paid call against a live security module,
            // and doing several at once is never the intent.
            ->bulkActions([])
            ->pageActions($this->pageActions())
            ->toArray($request);
    }

    /**
     * View, plus whichever end of the lifecycle this client is not already at.
     *
     * A deregistered client is only offered `Register` while nothing else is signing, so
     * the one-at-a-time rule is visible on the page rather than only enforced after the
     * click.
     *
     * @return array<int, Action>
     */
    private function rowActions(TseClient $client): array
    {
        $blocked = TseClient::activeClient(exceptId: $client->getKey()) !== null;

        return array_values(array_filter([
            Gate::allows('view', $client)
                ? Action::link('view', 'View', route('admin.tse-clients.show', $client))->icon('eye')
                : null,

            Gate::allows('update', $client) && $client->isRegistered()
                ? Action::delete('deregister', 'Deregister', route('admin.tse-clients.deregister', $client))
                    ->icon('shield-off')
                    ->tone(Status::WARN)
                    ->confirm(
                        'Deregister this client',
                        'It will stop signing and Fiskaly stops billing for it. Receipts it already signed keep its serial.',
                        'Yes, deregister it',
                    )
                : null,

            Gate::allows('update', $client) && ! $client->isRegistered() && ! $blocked
                ? Action::post('register', 'Register', route('admin.tse-clients.register', $client))
                    ->icon('shield-check')
                    ->tone(Status::OK)
                    ->confirm(
                        'Register this client',
                        'It becomes the client every till signs under, and Fiskaly bills for it from now on.',
                        'Yes, register it',
                    )
                : null,
        ]));
    }

    /**
     * The one page action, and only while nothing is signing.
     *
     * Deliberately worded as issuing a new client rather than as a generic create: the
     * usual move between conventions is to register the previous client again from its
     * own row, and this button charges for a client that did not exist before.
     *
     * @return array<int, Action>
     */
    private function pageActions(): array
    {
        if (Gate::denies('create', TseClient::class) || TseClient::activeClient()) {
            return [];
        }

        return [
            Action::post('store', 'Issue new client', route('admin.tse-clients.store'))
                ->icon('plus')
                ->confirm(
                    'Issue a new TSE client',
                    'This asks the security module for a client that does not exist yet, and Fiskaly bills for it. To bring back last year, use Register on its row instead.',
                    'Yes, issue one',
                ),
        ];
    }

    /**
     * The audit's three columns, in order. All three are searchable and none of them is
     * sortable or toggleable in Filament, and none of them becomes so here.
     *
     * `state` renders the raw stored value, `REGISTERED` or `DEREGISTERED`, exactly as the
     * Filament TextColumn did: no badge, no colour. Search hits the same string, which is
     * what makes searching this column work at all.
     *
     * @return array<int, Column>
     */
    private function columns(): array
    {
        return [
            Column::text('remote_id', 'Remote ID')->searchable(),
            Column::text('serial_number', 'Serial Number')->searchable(),
            Column::text('state', 'State')->searchable(),
        ];
    }

    /**
     * The stored state as a string, resolved through `TseClientStateEnum` rather than
     * retyped, so a renamed case moves this with it.
     *
     * The raw attribute rather than `$client->state`, and `tryFrom` rather than the cast:
     * `tse_clients.state` is a plain string column, and the cast's `from()` throws a
     * ValueError on any value the enum does not know. A list that only reads must not 500
     * on a row it did not write, so an unrecognised value renders as itself.
     */
    private function stateValue(TseClient $client): ?string
    {
        $raw = $client->getAttributes()['state'] ?? null;

        if ($raw instanceof TseClientStateEnum) {
            return $raw->value;
        }

        if ($raw === null) {
            return null;
        }

        return TseClientStateEnum::tryFrom((string) $raw)?->value ?? (string) $raw;
    }
}
