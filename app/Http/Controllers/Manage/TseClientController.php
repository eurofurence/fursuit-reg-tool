<?php

namespace App\Http\Controllers\Manage;

use App\Domain\Checkout\Enums\TseClientStateEnum;
use App\Domain\Checkout\Models\TseClient;
use App\Http\Controllers\Controller;
use App\Support\Manage\Action;
use App\Support\Manage\Column;
use App\Support\Manage\Status;
use App\Support\Manage\Table;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
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
 * That makes it the shortest controller in the panel, and the two things it does not have
 * are the point.
 *
 *  - There is no `createnew`. The Filament list page fabricated a client locally from a
 *    random UUID used as both `remote_id` and `serial_number`, with `state` hardcoded as
 *    the raw string `'REGISTERED'`, no confirmation, no notification and no audit entry
 *    (plan 2.10 #13, audit landmine 7). Nothing about that row exists upstream, so every
 *    checkout later signed against it inherits a serial Fiskaly never issued. The real
 *    lifecycle is `tse:update-state` and `tse:change-admin-pin`, which talk to the TSE.
 *  - There is no write path at all. `remote_id`, `serial_number` and `state` become
 *    read-only (plan 2.10 #14, audit landmine 8): editing them silently rewrites the
 *    identity that past checkouts were signed under. Since those three fields are the
 *    whole record, an edit form would have nothing left to edit, so the Filament row's
 *    EditAction becomes View and the module registers no PUT. `TseClientsObserver`
 *    PATCHes Fiskaly on every `updated` event, so a form here would also have been a
 *    remote write dressed up as a local one.
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
        ]);
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
                ? route('manage.tse-clients.show', $client)
                : null)
            ->rowActions(fn (TseClient $client) => array_values(array_filter([
                // Filament's row had EditAction only. The edit is gone with the write
                // path (plan 2.10 #14), so the row opens the record instead of changing
                // it. There is no delete here and none anywhere else either: audit 133
                // records that only an empty `getHeaderActions()` kept the stock
                // DeleteAction off the Filament edit page.
                Gate::allows('view', $client)
                    ? Action::link('view', 'View', route('manage.tse-clients.show', $client))->icon('eye')
                    : null,
            ])))
            // No bulk actions and no page actions. `createnew` is the one header action
            // the resource had and it does not come across (plan 2.10 #13).
            ->bulkActions([])
            ->pageActions([])
            ->toArray($request);
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
