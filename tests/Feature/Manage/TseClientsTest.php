<?php

/*
 * TSE clients, phase 8 (plan part 4.4). Transcribed from audit 4.12.
 *
 * A TSE client is the identity a Technical Security System signs fiscal transactions
 * under, and KassenSichV requires its serial to stay traceable from every signed receipt
 * back to the module that signed it. So the interesting assertions here are about what
 * cannot happen to that identity: no edit, no delete, and no write of any kind as a side
 * effect of looking at the list or a record.
 *
 * `remote_id` and `serial_number` are still untouchable (2.10 #14): the edit form that
 * rewrote them does not exist and neither does a destroy route. `state` is the one thing
 * that moves, through register and deregister, and both go through Fiskaly.
 *
 * Registration is back, but not the way Filament had it. `createnew` minted a random UUID
 * and never called anyone (2.10 #13), so the row named a client the TSS had never issued.
 * `store` runs the creation inside a transaction with the observer's outbound PUT, so a
 * refusal upstream leaves nothing behind - and it is refused outright while another client
 * is registered, because only one may sign at a time.
 *
 * The third theme is the enum. Audit landmine 7 records that the fabricator wrote the raw
 * string `'REGISTERED'` and that the Filament Select duplicated the vocabulary by hand, so
 * renaming a `TseClientStateEnum` case broke both at runtime with nothing failing first
 * and no test anywhere. Every state assertion below is written against the enum case
 * rather than a literal, and `FiskalyService::updateClient()` reads `$tseClient->state->value`,
 * so the case that pins the raw column value pins what Fiskaly is told too.
 */

use App\Domain\Checkout\Enums\TseClientStateEnum;
use App\Domain\Checkout\Models\TseClient;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Event;
use App\Models\Machine;
use App\Models\User;
use App\Support\Manage\Status;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseCount;
use function Pest\Laravel\delete;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\put;
use function Pest\Laravel\withHeaders;

/** The audit's table, in order. */
const MANAGE_TSE_COLUMNS = ['remote_id', 'serial_number', 'state'];

beforeEach(function () {
    // App\Observers\TseClientsObserver PUTs and PATCHes Fiskaly on created and updated.
    // No API key is configured under phpunit so the service returns early, but the fake
    // is what lets the read-only cases assert that no outbound call happened either.
    Http::fake();

    // ManageEventScope runs on every /admin request whether or not the page is scoped,
    // and this list deliberately is not (plan 2.9).
    Event::factory()->create([
        'name' => 'Eurofurence 29',
        'starts_at' => now()->addDays(30),
        'ends_at' => now()->addDays(35),
    ]);

    $this->admin = User::factory()->create(['is_admin' => true, 'is_reviewer' => false]);
    $this->reviewer = User::factory()->create(['is_admin' => false, 'is_reviewer' => true]);
    $this->attendee = User::factory()->create(['is_admin' => false, 'is_reviewer' => false]);

    $this->client = TseClient::create([
        'remote_id' => 'fiskaly-client-0001',
        'serial_number' => 'TSE-SERIAL-AAA111',
        'state' => TseClientStateEnum::REGISTERED,
    ]);

    $this->props = fn (array $query = []) => get(route('admin.tse-clients.index', $query))
        ->viewData('page')['props'];
});

/*
 * Access. TseClientPolicy answers is_admin for every ability, docblock verbatim: "Only
 * admins can view TSE clients (sensitive security equipment)." Holding access-manage is
 * not enough.
 */

test('a guest is redirected to login', function () {
    get(route('admin.tse-clients.index'))->assertRedirect(route('login'));
});

test('an attendee cannot reach the client list at all', function () {
    actingAs($this->attendee);

    get(route('admin.tse-clients.index'))->assertForbidden();
    get(route('admin.tse-clients.show', $this->client))->assertForbidden();
});

test('a reviewer holds access-manage but is refused both client abilities', function () {
    actingAs($this->reviewer);

    get(route('admin.tse-clients.index'))->assertForbidden();
    get(route('admin.tse-clients.show', $this->client))->assertForbidden();
});

test('an admin gets the list', function () {
    actingAs($this->admin);

    get(route('admin.tse-clients.index'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->component('Manage/TseClients/Index'));
});

/*
 * Index contract.
 */

test('the column list is the audit table with its labels', function () {
    actingAs($this->admin);

    $columns = collect(($this->props)()['columns']);

    expect($columns->pluck('key')->all())->toBe(MANAGE_TSE_COLUMNS)
        ->and($columns->pluck('label')->all())->toBe(['Remote ID', 'Serial Number', 'State'])
        ->and($columns->pluck('type')->unique()->all())->toBe(['text']);
});

test('no column is sortable or toggleable, matching the audit', function () {
    actingAs($this->admin);

    $columns = collect(($this->props)()['columns']);

    // Searchability is not in the payload at all - search runs server-side - so it is
    // asserted by the search case below rather than by a flag on the column.
    expect($columns->where('sortable', true))->toBeEmpty()
        ->and($columns->where('toggleable', true))->toBeEmpty()
        ->and(($this->props)()['hiddenColumns'])->toBe([]);
});

test('the table declares no filters', function () {
    actingAs($this->admin);

    get(route('admin.tse-clients.index'))
        ->assertInertia(fn (Assert $page) => $page->where('filters', [])->etc());
});

test('the search box covers each of the three columns', function () {
    actingAs($this->admin);

    TseClient::create([
        'remote_id' => 'fiskaly-client-0002',
        'serial_number' => 'TSE-SERIAL-BBB222',
        'state' => TseClientStateEnum::DEREGISTERED,
    ]);

    $found = fn (string $term) => collect(($this->props)(['search' => $term])['rows'])->pluck('id')->all();

    expect($found('SERIAL-AAA111'))->toBe([$this->client->id])
        ->and($found('client-0002'))->toHaveCount(1)
        ->and($found('client-0002'))->not->toContain($this->client->id)
        // The state column is searchable in Filament too, and it searches the raw stored
        // string, which is why the cell renders that string rather than a label.
        ->and($found(TseClientStateEnum::DEREGISTERED->value))->toHaveCount(1);
});

test('the list is not scoped to the selected event', function () {
    // Plan 2.9 lists TSE clients among the surfaces that stay unscoped. A security module
    // belongs to the hall, not to an event.
    actingAs($this->admin);

    TseClient::create([
        'remote_id' => 'fiskaly-client-0002',
        'serial_number' => 'TSE-SERIAL-BBB222',
        'state' => TseClientStateEnum::REGISTERED,
    ]);

    expect(($this->props)()['meta']['total'])->toBe(TseClient::count());
});

test('the default order is by primary key', function () {
    actingAs($this->admin);

    TseClient::create([
        'remote_id' => 'fiskaly-client-0002',
        'serial_number' => 'TSE-SERIAL-BBB222',
        'state' => TseClientStateEnum::REGISTERED,
    ]);

    $props = ($this->props)();

    expect($props['sort'])->toBe(['key' => 'id', 'dir' => 'asc'])
        ->and(collect($props['rows'])->pluck('id')->all())
        ->toBe(TseClient::orderBy('id')->pluck('id')->all());
});

test('the list sorts nothing and paginates under the partial visit the client sends', function () {
    actingAs($this->admin);

    foreach (range(2, 12) as $index) {
        TseClient::create([
            'remote_id' => 'fiskaly-client-'.$index,
            'serial_number' => 'TSE-SERIAL-'.$index,
            'state' => TseClientStateEnum::REGISTERED,
        ]);
    }

    // The visit useTableQuery makes: X-Inertia plus the five reloaded keys. Asserting the
    // envelope through the real partial is the only way to catch a nested one, which
    // renders fine on a full load and then makes paging silently inert.
    $partial = function (array $query) {
        $response = withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
            'X-Inertia-Partial-Component' => 'Manage/TseClients/Index',
            'X-Inertia-Partial-Data' => 'rows,meta,filters,sort,search',
        ])->get(route('admin.tse-clients.index', $query));

        $response->assertOk();

        return $response->json('props');
    };

    $first = $partial(['per_page' => 10, 'page' => 1]);
    $second = $partial(['per_page' => 10, 'page' => 2]);

    expect($first)->toHaveKeys(['rows', 'meta', 'filters', 'sort', 'search'])
        ->and($first['rows'])->toHaveCount(10)
        ->and($second['rows'])->toHaveCount(2)
        ->and($second['meta']['page'])->toBe(2)
        ->and($second['rows'][0]['id'])->not->toBe($first['rows'][0]['id'])
        // No column declares a sort, so asking for one has to come back as the default
        // rather than reordering the list.
        ->and($partial(['sort' => 'serial_number', 'dir' => 'desc'])['sort'])->toBe(['key' => 'id', 'dir' => 'asc']);
});

/*
 * The state column. The enum is the vocabulary, in the cell and in the badge.
 */

test('the state cell is the raw stored value, taken from the enum case', function () {
    actingAs($this->admin);

    $deregistered = TseClient::create([
        'remote_id' => 'fiskaly-client-0002',
        'serial_number' => 'TSE-SERIAL-BBB222',
        'state' => TseClientStateEnum::DEREGISTERED,
    ]);

    $cells = collect(($this->props)()['rows'])->keyBy('id')->map(fn (array $row) => $row['cells']);

    expect($cells[$this->client->id])->toBe([
        'remote_id' => 'fiskaly-client-0001',
        'serial_number' => 'TSE-SERIAL-AAA111',
        'state' => TseClientStateEnum::REGISTERED->value,
    ])
        ->and($cells[$deregistered->id]['state'])->toBe(TseClientStateEnum::DEREGISTERED->value)
        // Written against the enum, but the column really does hold these two strings:
        // FiskalyService::updateClient() PATCHes `$tseClient->state->value` upstream, and
        // DSFinV-K exports read the same rows.
        ->and([TseClientStateEnum::REGISTERED->value, TseClientStateEnum::DEREGISTERED->value])
        ->toBe(['REGISTERED', 'DEREGISTERED']);
});

test('a stored state the enum does not know renders as itself instead of throwing', function () {
    // tse_clients.state is a plain string column and the cast's from() raises a ValueError
    // on anything outside the enum, so a row written by an older build or by hand would
    // 500 a list that only reads. Inserted straight through the query builder because no
    // code path in this app can produce it any more.
    DB::table('tse_clients')->insert([
        'remote_id' => 'fiskaly-client-legacy',
        'serial_number' => 'TSE-SERIAL-LEGACY',
        'state' => 'INITIALIZED',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    actingAs($this->admin);

    $legacy = collect(($this->props)()['rows'])->firstWhere('cells.remote_id', 'fiskaly-client-legacy');

    expect($legacy['cells']['state'])->toBe('INITIALIZED');

    get(route('admin.tse-clients.show', DB::table('tse_clients')->where('state', 'INITIALIZED')->value('id')))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->where('client.state.label', 'INITIALIZED')->etc());
});

/*
 * Actions. The row opens the record; nothing on this screen changes one.
 */

test('a registered client offers View and Deregister, and nothing may be issued alongside it', function () {
    actingAs($this->admin);

    $props = ($this->props)();
    $row = collect($props['rows'])->firstWhere('id', $this->client->id);

    expect(collect($row['actions'])->pluck('name')->all())->toBe(['view', 'deregister'])
        ->and($row['actions'][0]['method'])->toBe('get')
        ->and($row['actions'][0]['url'])->toBe(route('admin.tse-clients.show', $this->client))
        ->and($row['actions'][1]['method'])->toBe('delete')
        ->and($row['actions'][1]['url'])->toBe(route('admin.tse-clients.deregister', $this->client))
        ->and($row['url'])->toBe(route('admin.tse-clients.show', $this->client))
        // Issuing is not offered while one is already signing, so the one-at-a-time rule
        // is visible on the page and not only enforced after the click.
        ->and($props['pageActions'])->toBe([])
        ->and($props['bulkActions'])->toBe([]);
});

test('with nothing registered the page offers Issue new client and each row offers Register', function () {
    actingAs($this->admin);

    $this->client->update(['state' => TseClientStateEnum::DEREGISTERED]);

    $props = ($this->props)();
    $row = collect($props['rows'])->firstWhere('id', $this->client->id);

    expect(collect($props['pageActions'])->pluck('name')->all())->toBe(['store'])
        ->and($props['pageActions'][0]['method'])->toBe('post')
        ->and($props['pageActions'][0]['url'])->toBe(route('admin.tse-clients.store'))
        ->and(collect($row['actions'])->pluck('name')->all())->toBe(['view', 'register'])
        ->and($row['actions'][1]['url'])->toBe(route('admin.tse-clients.register', $this->client));
});

test('a second client is never offered Register while one is already signing', function () {
    actingAs($this->admin);

    $spare = TseClient::create([
        'remote_id' => 'fiskaly-client-0002',
        'serial_number' => 'TSE-SERIAL-BBB222',
        'state' => TseClientStateEnum::DEREGISTERED,
    ]);

    $row = collect(($this->props)()['rows'])->firstWhere('id', $spare->id);

    expect(collect($row['actions'])->pluck('name')->all())->toBe(['view']);
});

test('no action anywhere in the module deletes or edits the identity', function () {
    actingAs($this->admin);

    $props = ($this->props)();

    $urls = collect($props['rows'])
        ->flatMap(fn (array $row) => collect($row['actions'])->pluck('url'))
        ->merge(collect($props['pageActions'])->pluck('url'))
        ->merge(collect($props['bulkActions'])->pluck('url'))
        ->all();

    // Audit 133: only an empty getHeaderActions() kept the stock DeleteAction off the
    // Filament edit page. Nothing in this payload points at an edit or a destroy; the one
    // DELETE here is deregistration, which changes `state` and nothing else.
    foreach ($urls as $url) {
        expect($url)->not->toContain('/edit');
    }

    expect(collect($props['rows'])->flatMap(fn (array $row) => collect($row['actions'])->pluck('name'))->unique()->values()->all())
        ->toBe(['view', 'deregister']);
});

/*
 * Read-only, as a claim about the routing table rather than about the UI.
 */

test('the module registers the lifecycle writes and nothing that edits or deletes', function () {
    // The identity is not editable and a client is never removed: its serial is on every
    // receipt it signed.
    foreach (['create', 'edit', 'update', 'destroy', 'bulk.destroy'] as $name) {
        expect(Route::has('admin.tse-clients.'.$name))->toBeFalse();
    }

    expect(Route::has('admin.tse-clients.index'))->toBeTrue()
        ->and(Route::has('admin.tse-clients.show'))->toBeTrue()
        ->and(Route::has('admin.tse-clients.store'))->toBeTrue()
        ->and(Route::has('admin.tse-clients.register'))->toBeTrue()
        ->and(Route::has('admin.tse-clients.deregister'))->toBeTrue();
});

test('the policy is untouched, so the legacy panel keeps the screens it still has', function () {
    // TseClientPolicy is shared: Filament consults the same class, and /admin-legacy keeps
    // its create page and its row EditAction until cutover. Closing an ability here would
    // take two screens the parity contract documents as working off a panel the plan says
    // keeps running. The new module carries neither screen and routes neither verb, which
    // is what the case above asserts; this one pins that it did so by not routing them
    // rather than by changing a policy the other panel depends on.
    expect(Gate::forUser($this->admin)->allows('create', TseClient::class))->toBeTrue()
        ->and(Gate::forUser($this->admin)->allows('update', $this->client))->toBeTrue()
        ->and(Gate::forUser($this->admin)->allows('delete', $this->client))->toBeTrue()
        // Still admin-only, which is the ability the checklist records.
        ->and(Gate::forUser($this->reviewer)->allows('create', TseClient::class))->toBeFalse()
        ->and(Gate::forUser($this->reviewer)->allows('update', $this->client))->toBeFalse();
});

test('an admin cannot write to a client through the URLs the Filament resource had', function () {
    actingAs($this->admin);

    $payload = [
        'remote_id' => 'rewritten',
        'serial_number' => 'rewritten',
        'state' => TseClientStateEnum::DEREGISTERED->value,
    ];

    // /admin/tse-clients/create and /{record}/edit were real Filament URLs, and the
    // create page was reachable by typing it even though no button led there.
    get('/admin/tse-clients/create')->assertNotFound();
    get('/admin/tse-clients/'.$this->client->id.'/edit')->assertNotFound();

    put(route('admin.tse-clients.show', $this->client), $payload)->assertMethodNotAllowed();
    delete(route('admin.tse-clients.show', $this->client))->assertMethodNotAllowed();

    // POST /admin/tse-clients is the register endpoint now, and it carries no fields: a
    // payload aimed at the identity is simply not read.
    post(route('admin.tse-clients.store'), $payload)->assertRedirect();

    $this->client->refresh();

    expect($this->client->remote_id)->toBe('fiskaly-client-0001')
        ->and($this->client->serial_number)->toBe('TSE-SERIAL-AAA111')
        ->and($this->client->state)->toBe(TseClientStateEnum::REGISTERED);

    assertDatabaseCount('tse_clients', 1);
});

test('rendering the list or a record writes nothing and calls Fiskaly not at all', function () {
    actingAs($this->admin);

    $before = $this->client->updated_at;

    get(route('admin.tse-clients.index'))->assertSuccessful();
    get(route('admin.tse-clients.show', $this->client))->assertSuccessful();

    $this->client->refresh();

    // No row appeared, none changed, and TseClientsObserver never fired, so nothing was
    // PUT or PATCHed to the TSS either.
    assertDatabaseCount('tse_clients', 1);
    expect($this->client->updated_at->equalTo($before))->toBeTrue();
    Http::assertNothingSent();
});

/*
 * Show. The Filament resource had no view page and no infolist at all.
 */

test('the show page carries the identity, the state and the bound machine', function () {
    $machine = Machine::factory()->create([
        'name' => 'Cashdesk 1',
        'tse_client_id' => $this->client->id,
    ]);

    actingAs($this->admin);

    get(route('admin.tse-clients.show', $this->client))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Manage/TseClients/Show')
            ->where('client.id', $this->client->id)
            ->where('client.remote_id', 'fiskaly-client-0001')
            ->where('client.serial_number', 'TSE-SERIAL-AAA111')
            ->where('client.state_value', TseClientStateEnum::REGISTERED->value)
            // The badge is Status::tseClient(), which matches on the enum case, so a
            // renamed case is a type error rather than an unstyled cell.
            ->where('client.state', Status::tseClient(TseClientStateEnum::REGISTERED))
            // TseClient::machine() exists but the old screen surfaced it nowhere, so
            // there was no way to see which POS terminal a client signs for (audit 4.12).
            ->where('client.machine', $machine->name)
            ->has('client.created_at')
            ->has('client.updated_at')
        );
});

test('an unbound client shows no machine rather than failing', function () {
    actingAs($this->admin);

    get(route('admin.tse-clients.show', $this->client))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->where('client.machine', null)->etc());
});

test('the show page header carries the lifecycle action and nothing that edits', function () {
    actingAs($this->admin);

    // A registered client can only be deregistered from here. There is no edit and no
    // delete: the identity is what receipts were signed under.
    get(route('admin.tse-clients.show', $this->client))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('headerActions.0.name', 'deregister')
            ->where('headerActions.0.url', route('admin.tse-clients.deregister', $this->client))
            ->count('headerActions', 1)
            ->etc());

    $this->client->update(['state' => TseClientStateEnum::DEREGISTERED]);

    get(route('admin.tse-clients.show', $this->client))
        ->assertInertia(fn (Assert $page) => $page
            ->where('headerActions.0.name', 'register')
            ->etc());
});

test('registering is refused while another client is already signing', function () {
    actingAs($this->admin);

    $spare = TseClient::create([
        'remote_id' => 'fiskaly-client-0002',
        'serial_number' => 'TSE-SERIAL-BBB222',
        'state' => TseClientStateEnum::DEREGISTERED,
    ]);

    post(route('admin.tse-clients.register', $spare))
        ->assertSessionHas('inertia.flash_data.toast.title', 'Nothing was registered');

    post(route('admin.tse-clients.store'))
        ->assertSessionHas('inertia.flash_data.toast.title', 'Nothing was registered');

    expect($spare->fresh()->isRegistered())->toBeFalse();
    assertDatabaseCount('tse_clients', 2);
});

test('deregistering frees the slot, and the previous client can be registered again', function () {
    actingAs($this->admin);

    $spare = TseClient::create([
        'remote_id' => 'fiskaly-client-0002',
        'serial_number' => 'TSE-SERIAL-BBB222',
        'state' => TseClientStateEnum::DEREGISTERED,
    ]);

    delete(route('admin.tse-clients.deregister', $this->client))
        ->assertSessionHas('inertia.flash_data.toast.title', 'Deregistered');

    expect(TseClient::activeClient())->toBeNull();

    post(route('admin.tse-clients.register', $spare))
        ->assertSessionHas('inertia.flash_data.toast.title', 'Registered');

    expect(TseClient::activeClient()?->id)->toBe($spare->id)
        // The outgoing client is kept: its serial is on every receipt it signed.
        ->and($this->client->fresh())->not->toBeNull();
});

test('issuing a client stores what Fiskaly was asked for, and only while nothing is signing', function () {
    actingAs($this->admin);

    delete(route('admin.tse-clients.deregister', $this->client));

    post(route('admin.tse-clients.store'))
        ->assertSessionHas('inertia.flash_data.toast.title', 'Registered');

    $issued = TseClient::activeClient();

    // FiskalyService::createClient() sends the id as the serial, so storing anything else
    // would describe the client differently here than upstream.
    expect($issued)->not->toBeNull()
        ->and($issued->serial_number)->toBe($issued->remote_id)
        ->and($issued->remote_id)->not->toBe($this->client->remote_id);

    // And a second one is refused now that this is signing.
    post(route('admin.tse-clients.store'))
        ->assertSessionHas('inertia.flash_data.toast.title', 'Nothing was registered');

    assertDatabaseCount('tse_clients', 2);
});
