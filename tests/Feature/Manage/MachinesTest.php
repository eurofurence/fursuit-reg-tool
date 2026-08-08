<?php

/*
 * Machines, phase 5 (plan part 4.2). Transcribed from audit 4.6.
 *
 * The audit is the baseline this repository does not otherwise have, so the column list,
 * the filter labels and all six confirm strings below are literal copies out of it rather
 * than descriptions of them: a dropped column or a reworded modal fails a test instead of
 * quietly changing.
 *
 * Two cases carry more weight than the rest.
 *
 *  - The archived filter's blank branch. Nothing scopes archived machines at query level
 *    (audit 43): no global scope, and `withArchived()` is a scope that returns the query
 *    untouched. So "blank means notArchived" is the only thing keeping a retired till out
 *    of the list, and it is asserted for the unset filter and for the explicitly cleared
 *    one, because the client can send either.
 *  - The login link. It authenticates as the till (plan 2.10 #15, audit landmine 9), so
 *    the tests say when it may be minted, that it expires, that minting is logged, and
 *    that no page payload carries one until somebody asks.
 */

use App\Domain\Checkout\Models\TseClient;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Event;
use App\Models\Machine;
use App\Models\SumUpReader;
use App\Models\User;
use App\Support\Manage\Filter;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;
use function Pest\Laravel\delete;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\put;
use function Pest\Laravel\travel;
use function Pest\Laravel\withHeaders;

/** The audit's table, in order. */
const MANAGE_MACHINE_COLUMNS = [
    'name',
    'tseClient.remote_id',
    'sumupReader.name',
    'should_discover_printers',
];

/** Where App\Support\Manage\Toast writes: Inertia's own flash bag, not a plain key. */
const MANAGE_MACHINE_TOAST_TITLE = 'inertia.flash_data.toast.title';

/**
 * The visit useTableQuery makes: X-Inertia plus the five reloaded keys. Asserting that a
 * filter is declared proves nothing; only the partial visit shows whether the row set
 * really moved.
 *
 * @return array<string, mixed>
 */
function manageMachinesPartial(array $query = []): array
{
    // Without the version header Inertia answers 409 and asks the client to reload, so
    // the visit under test never reaches the controller.
    $version = app(HandleInertiaRequests::class)->version(request());

    $response = withHeaders([
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) $version,
        'X-Inertia-Partial-Component' => 'Manage/Machines/Index',
        'X-Inertia-Partial-Data' => 'rows,meta,filters,sort,search',
    ])->get(route('manage.machines.index', $query));

    $response->assertOk();

    $props = $response->json('props');

    expect($props)->toHaveKeys(['rows', 'meta', 'filters', 'sort', 'search']);
    expect($props['rows'])->toBeArray();

    return $props;
}

beforeEach(function () {
    // SumUpReaderObserver::created() posts the new reader to the SumUp API and rethrows
    // when that fails, so creating one in a test is a live HTTP call unless it is faked.
    // Nothing in this module talks to SumUp itself.
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

    $this->machine = fn (array $attributes = []) => Machine::factory()->create($attributes);

    $this->props = fn (array $query = []) => get(route('manage.machines.index', $query))
        ->viewData('page')['props'];
});

/*
 * Access. MachinePolicy answers is_admin for every ability, so holding access-manage is
 * not enough on this module.
 */

test('a guest is redirected to login', function () {
    get(route('manage.machines.index'))->assertRedirect(route('login'));
});

test('an attendee cannot reach the machine list at all', function () {
    actingAs($this->attendee);

    get(route('manage.machines.index'))->assertForbidden();
});

test('a reviewer holds access-manage but is refused every machine ability', function () {
    actingAs($this->reviewer);

    $machine = ($this->machine)(['name' => 'Desk 1', 'should_discover_printers' => true]);

    get(route('manage.machines.index'))->assertForbidden();
    get(route('manage.machines.create'))->assertForbidden();
    post(route('manage.machines.store'), manageMachinePayload())->assertForbidden();
    get(route('manage.machines.edit', $machine))->assertForbidden();
    put(route('manage.machines.update', $machine), manageMachinePayload())->assertForbidden();
    post(route('manage.machines.archive', $machine))->assertForbidden();
    delete(route('manage.machines.unarchive', $machine))->assertForbidden();
    post(route('manage.machines.bulk.archive'), ['ids' => [$machine->id]])->assertForbidden();
    delete(route('manage.machines.bulk.unarchive'), ['ids' => [$machine->id]])->assertForbidden();
    post(route('manage.machines.login-link', $machine))->assertForbidden();

    // Nothing was written on the way to any of those 403s, and no credential was minted.
    expect($machine->fresh()->archived_at)->toBeNull();
    assertDatabaseMissing('activity_log', ['description' => 'POS login link created']);
});

test('an admin gets the list', function () {
    actingAs($this->admin);

    get(route('manage.machines.index'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->component('Manage/Machines/Index'));
});

/*
 * Index contract.
 */

test('the column list is the audit table', function () {
    actingAs($this->admin);

    expect(collect(($this->props)()['columns'])->pluck('key')->all())->toBe(MANAGE_MACHINE_COLUMNS);
});

test('the columns carry the audit labels, types and placeholders', function () {
    actingAs($this->admin);

    $columns = collect(($this->props)()['columns'])->keyBy('key');

    expect($columns['name']['label'])->toBe('Name')
        ->and($columns['tseClient.remote_id']['label'])->toBe('TSE Client')
        ->and($columns['tseClient.remote_id']['fallback'])->toBe('None assigned')
        ->and($columns['sumupReader.name']['label'])->toBe('SumUp Reader')
        ->and($columns['sumupReader.name']['fallback'])->toBe('None assigned')
        ->and($columns['should_discover_printers']['label'])->toBe('Auto-discover Printers')
        ->and($columns['should_discover_printers']['type'])->toBe('bool');
});

test('the relation columns render the assigned client and reader', function () {
    actingAs($this->admin);

    $client = TseClient::create(['remote_id' => 'tse-9', 'serial_number' => 'SN-9', 'state' => 'REGISTERED']);
    $reader = SumUpReader::create(['name' => 'Reader A', 'paring_code' => 'abcd']);

    ($this->machine)([
        'name' => 'Desk 1',
        'tse_client_id' => $client->id,
        'sumup_reader_id' => $reader->id,
        'should_discover_printers' => true,
    ]);
    ($this->machine)(['name' => 'Desk 2', 'tse_client_id' => null, 'sumup_reader_id' => null]);

    $rows = collect(($this->props)()['rows'])->keyBy(fn ($row) => $row['cells']['name']);

    expect($rows['Desk 1']['cells']['tseClient.remote_id'])->toBe('tse-9')
        ->and($rows['Desk 1']['cells']['sumupReader.name'])->toBe('Reader A')
        ->and($rows['Desk 1']['cells']['should_discover_printers'])->toBeTrue()
        // Nothing assigned leaves the cell empty; the column's fallback renders it.
        ->and($rows['Desk 2']['cells']['tseClient.remote_id'])->toBeNull()
        ->and($rows['Desk 2']['cells']['sumupReader.name'])->toBeNull();
});

test('the search box narrows on name, which the Filament table made unreachable', function () {
    actingAs($this->admin);

    $wanted = ($this->machine)(['name' => 'Registration Desk']);
    ($this->machine)(['name' => 'Artist Alley Till']);

    $props = manageMachinesPartial(['search' => 'Registration']);

    expect($props['rows'])->toHaveCount(1)
        ->and($props['rows'][0]['id'])->toBe($wanted->id);
});

test('the list is paginated at 200 rather than unbounded', function () {
    actingAs($this->admin);

    ($this->machine)(['name' => 'Desk 1']);

    $meta = ($this->props)()['meta'];

    expect($meta['perPage'])->toBe(200)
        ->and($meta['perPageOptions'])->toBe([25, 50, 100, 200]);
});

/*
 * The archived filter. Blank is the whole point of it (audit 43).
 */

test('the archived filter is declared with the audit labels and opens blank', function () {
    actingAs($this->admin);

    $props = ($this->props)();

    expect($props['filters'])->toHaveCount(1);

    $filter = $props['filters'][0];

    expect($filter['key'])->toBe('archived')
        ->and($filter['label'])->toBe('Archived')
        ->and($filter['type'])->toBe('ternary')
        ->and($filter['placeholder'])->toBe('Active machines')
        ->and($filter['trueLabel'])->toBe('Archived machines')
        ->and($filter['falseLabel'])->toBe('All machines')
        ->and($filter['default'])->toBe('')
        ->and($filter['value'])->toBe('');
});

test('the default list hides archived machines', function () {
    actingAs($this->admin);

    $active = ($this->machine)(['name' => 'Desk 1']);
    ($this->machine)(['name' => 'Retired', 'archived_at' => now()]);

    $props = manageMachinesPartial();

    expect($props['rows'])->toHaveCount(1)
        ->and($props['rows'][0]['id'])->toBe($active->id);
});

test('clearing the filter still hides archived machines, because blank means active', function () {
    actingAs($this->admin);

    $active = ($this->machine)(['name' => 'Desk 1']);
    ($this->machine)(['name' => 'Retired', 'archived_at' => now()]);

    // The token the client sends when the operator picks the placeholder option. An
    // empty string cannot be it: ConvertEmptyStringsToNull turns that into a missing key.
    $props = manageMachinesPartial(['filter' => ['archived' => Filter::CLEARED]]);

    expect($props['rows'])->toHaveCount(1)
        ->and($props['rows'][0]['id'])->toBe($active->id)
        ->and($props['filters'][0]['value'])->toBe('');
});

test('the true branch shows only archived machines and the false branch shows all', function () {
    actingAs($this->admin);

    $active = ($this->machine)(['name' => 'Desk 1']);
    $retired = ($this->machine)(['name' => 'Retired', 'archived_at' => now()]);

    $onlyArchived = manageMachinesPartial(['filter' => ['archived' => '1']]);

    expect($onlyArchived['rows'])->toHaveCount(1)
        ->and($onlyArchived['rows'][0]['id'])->toBe($retired->id);

    $all = manageMachinesPartial(['filter' => ['archived' => '0']]);

    expect(collect($all['rows'])->pluck('id')->sort()->values()->all())
        ->toBe(collect([$active->id, $retired->id])->sort()->values()->all());
});

/*
 * Actions and their copy.
 */

test('an active machine offers Edit and Archive with the audit copy', function () {
    actingAs($this->admin);

    ($this->machine)(['name' => 'Desk 1']);

    $actions = collect(($this->props)()['rows'][0]['actions'])->keyBy('name');

    expect($actions->keys()->all())->toBe(['edit', 'archive'])
        ->and($actions['archive']['label'])->toBe('Archive')
        ->and($actions['archive']['method'])->toBe('post')
        ->and($actions['archive']['confirm']['heading'])->toBe('Archive Machine')
        ->and($actions['archive']['confirm']['description'])
        ->toBe('Are you sure you want to archive this machine? It will be hidden from normal view.')
        ->and($actions['archive']['confirm']['submit'])->toBe('Yes, archive it');
});

test('an archived machine offers Edit and Restore with the audit copy', function () {
    actingAs($this->admin);

    ($this->machine)(['name' => 'Retired', 'archived_at' => now()]);

    $actions = collect(($this->props)(['filter' => ['archived' => '1']])['rows'][0]['actions'])->keyBy('name');

    expect($actions->keys()->all())->toBe(['edit', 'unarchive'])
        ->and($actions['unarchive']['label'])->toBe('Restore')
        ->and($actions['unarchive']['method'])->toBe('delete')
        ->and($actions['unarchive']['confirm']['heading'])->toBe('Restore Machine')
        ->and($actions['unarchive']['confirm']['description'])
        ->toBe('Are you sure you want to restore this machine? It will be visible again.')
        ->and($actions['unarchive']['confirm']['submit'])->toBe('Yes, restore it');
});

test('both bulk actions ship with the audit copy', function () {
    actingAs($this->admin);

    $bulk = collect(($this->props)()['bulkActions'])->keyBy('name');

    expect($bulk->keys()->all())->toBe(['archive', 'unarchive'])
        ->and($bulk['archive']['label'])->toBe('Archive selected')
        ->and($bulk['archive']['confirm']['heading'])->toBe('Archive Machines')
        ->and($bulk['archive']['confirm']['description'])
        ->toBe('Are you sure you want to archive the selected machines? They will be hidden from normal view and unable to log in to the POS system.')
        ->and($bulk['archive']['confirm']['submit'])->toBe('Yes, archive them')
        ->and($bulk['unarchive']['label'])->toBe('Restore selected')
        ->and($bulk['unarchive']['confirm']['heading'])->toBe('Restore Machines')
        ->and($bulk['unarchive']['confirm']['description'])
        ->toBe('Are you sure you want to restore the selected machines? They will be visible again and able to log in to the POS system.')
        ->and($bulk['unarchive']['confirm']['submit'])->toBe('Yes, restore them');
});

test('nothing in the module offers a delete, single, bulk or on the page', function () {
    actingAs($this->admin);

    ($this->machine)(['name' => 'Desk 1']);

    $props = ($this->props)();

    $names = collect($props['rows'][0]['actions'])
        ->concat($props['bulkActions'])
        ->concat($props['pageActions'])
        ->pluck('name');

    expect($names)->not->toContain('delete');

    // And there is no route to reach one with either (audit 131).
    expect(route('manage.machines.index'))->toBeString();
    expect(fn () => route('manage.machines.destroy', 1))->toThrow(Exception::class);
});

test('the page offers Create', function () {
    actingAs($this->admin);

    $pageActions = collect(($this->props)()['pageActions'])->keyBy('name');

    expect($pageActions->keys()->all())->toBe(['create'])
        ->and($pageActions['create']['url'])->toBe(route('manage.machines.create'));
});

/*
 * Archive and restore, single and bulk.
 */

test('archiving stamps archived_at and says so', function () {
    actingAs($this->admin);

    $machine = ($this->machine)(['name' => 'Desk 1']);

    post(route('manage.machines.archive', $machine))
        ->assertRedirect()
        ->assertSessionHas(MANAGE_MACHINE_TOAST_TITLE, 'Archived');

    expect($machine->fresh()->archived_at)->not->toBeNull();
});

test('restoring clears archived_at and says so', function () {
    actingAs($this->admin);

    $machine = ($this->machine)(['name' => 'Retired', 'archived_at' => now()]);

    delete(route('manage.machines.unarchive', $machine))
        ->assertRedirect()
        ->assertSessionHas(MANAGE_MACHINE_TOAST_TITLE, 'Restored');

    expect($machine->fresh()->archived_at)->toBeNull();
});

test('bulk archive and bulk restore move every selected machine', function () {
    actingAs($this->admin);

    $first = ($this->machine)(['name' => 'Desk 1']);
    $second = ($this->machine)(['name' => 'Desk 2']);
    $untouched = ($this->machine)(['name' => 'Desk 3']);

    post(route('manage.machines.bulk.archive'), ['ids' => [$first->id, $second->id]])
        ->assertRedirect()
        ->assertSessionHas(MANAGE_MACHINE_TOAST_TITLE, 'Archived');

    expect($first->fresh()->archived_at)->not->toBeNull()
        ->and($second->fresh()->archived_at)->not->toBeNull()
        ->and($untouched->fresh()->archived_at)->toBeNull();

    delete(route('manage.machines.bulk.unarchive'), ['ids' => [$first->id, $second->id]])
        ->assertRedirect()
        ->assertSessionHas(MANAGE_MACHINE_TOAST_TITLE, 'Restored');

    expect($first->fresh()->archived_at)->toBeNull()
        ->and($second->fresh()->archived_at)->toBeNull();
});

test('a bulk action without ids is a validation error, not a no-op that reports success', function () {
    actingAs($this->admin);

    post(route('manage.machines.bulk.archive'), [])->assertSessionHasErrors('ids');
});

/*
 * The form.
 */

test('the create page carries both relation option lists', function () {
    actingAs($this->admin);

    $client = TseClient::create(['remote_id' => 'tse-1', 'serial_number' => 'SN-1', 'state' => 'REGISTERED']);
    $reader = SumUpReader::create(['name' => 'Reader A', 'paring_code' => 'abcd']);

    get(route('manage.machines.create'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Manage/Machines/Form')
            ->where('machine', null)
            // Neither Select is required, so both keep the empty option.
            ->where('tseClients', [
                ['value' => '', 'label' => '-'],
                ['value' => (string) $client->id, 'label' => 'tse-1'],
            ])
            ->where('sumupReaders', [
                ['value' => '', 'label' => '-'],
                ['value' => (string) $reader->id, 'label' => 'Reader A'],
            ])
            ->where('actions', [])
        );
});

test('creating and saving a machine both work', function () {
    actingAs($this->admin);

    post(route('manage.machines.store'), manageMachinePayload(['name' => 'Desk 1']))
        ->assertRedirect(route('manage.machines.index'))
        ->assertSessionHas(MANAGE_MACHINE_TOAST_TITLE, 'Created');

    assertDatabaseHas('machines', ['name' => 'Desk 1', 'should_discover_printers' => true]);

    $machine = Machine::where('name', 'Desk 1')->firstOrFail();

    put(route('manage.machines.update', $machine), manageMachinePayload([
        'name' => 'Desk 1 renamed',
        'should_discover_printers' => false,
    ]))
        ->assertRedirect(route('manage.machines.index'))
        ->assertSessionHas(MANAGE_MACHINE_TOAST_TITLE, 'Saved');

    assertDatabaseHas('machines', ['id' => $machine->id, 'name' => 'Desk 1 renamed', 'should_discover_printers' => false]);
});

test('the edit page prefills the record', function () {
    actingAs($this->admin);

    $client = TseClient::create(['remote_id' => 'tse-1', 'serial_number' => 'SN-1', 'state' => 'REGISTERED']);
    $machine = ($this->machine)([
        'name' => 'Desk 1',
        'tse_client_id' => $client->id,
        'should_discover_printers' => false,
    ]);

    get(route('manage.machines.edit', $machine))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Manage/Machines/Form')
            ->where('machine.name', 'Desk 1')
            ->where('machine.tse_client_id', (string) $client->id)
            ->where('machine.sumup_reader_id', '')
            ->where('machine.should_discover_printers', false)
        );
});

test('the form validates the audit rules and refuses an unknown relation id', function () {
    actingAs($this->admin);

    post(route('manage.machines.store'), manageMachinePayload(['name' => '']))
        ->assertSessionHasErrors('name');

    post(route('manage.machines.store'), manageMachinePayload(['name' => str_repeat('a', 256)]))
        ->assertSessionHasErrors('name');

    post(route('manage.machines.store'), manageMachinePayload(['tse_client_id' => 9999]))
        ->assertSessionHasErrors('tse_client_id');

    post(route('manage.machines.store'), manageMachinePayload(['sumup_reader_id' => 9999]))
        ->assertSessionHasErrors('sumup_reader_id');
});

test('archived_at cannot be written through the form, even though the model guards nothing', function () {
    actingAs($this->admin);

    $machine = ($this->machine)(['name' => 'Desk 1']);

    put(route('manage.machines.update', $machine), manageMachinePayload([
        'name' => 'Desk 1',
        'archived_at' => now()->toDateTimeString(),
    ]))->assertRedirect(route('manage.machines.index'));

    // Machine::$guarded = [], so the only thing keeping this out is the request's rule
    // list: archiving is an action of its own, not a field.
    expect($machine->fresh()->archived_at)->toBeNull();
});

/*
 * The login link. A credential (plan 2.10 #15, audit landmine 9).
 */

test('the edit page declares the Login Link action but mints nothing', function () {
    actingAs($this->admin);

    $machine = ($this->machine)(['name' => 'Desk 1']);

    $response = get(route('manage.machines.edit', $machine))->assertSuccessful();

    $props = $response->viewData('page')['props'];

    expect($props['actions'])->toHaveCount(1)
        ->and($props['actions'][0]['name'])->toBe('login-link')
        ->and($props['actions'][0]['label'])->toBe('Login Link')
        ->and($props['actions'][0]['method'])->toBe('post')
        ->and($props['actions'][0]['url'])->toBe(route('manage.machines.login-link', $machine));

    // Nothing was generated by opening the page: no signature anywhere in the payload.
    expect(json_encode($props))->not->toContain('signature');
});

test('the list payload never carries a login link for any machine', function () {
    actingAs($this->admin);

    ($this->machine)(['name' => 'Desk 1']);
    ($this->machine)(['name' => 'Desk 2']);

    $payload = json_encode(($this->props)());

    expect($payload)->not->toContain('signature')
        ->and($payload)->not->toContain('machine-login');
});

test('minting a login link returns a signed URL that logs the machine in', function () {
    actingAs($this->admin);

    $machine = ($this->machine)(['name' => 'Desk 1']);

    $response = post(route('manage.machines.login-link', $machine))
        ->assertRedirect()
        ->assertSessionHas(MANAGE_MACHINE_TOAST_TITLE, 'Login link created');

    $minted = $response->getSession()->get('inertia.flash_data')['machineLoginLink'];

    expect($minted['machineId'])->toBe($machine->id)
        ->and($minted['minutes'])->toBe(15)
        ->and($minted['url'])->toContain('signature=')
        ->and($minted['url'])->toContain('expires=');

    // The URL still points at the route the POS already validates, with the parameter
    // that controller reads.
    expect($minted['url'])->toContain('machine_id='.$machine->id);

    get($minted['url'])->assertRedirect(route('pos.auth.user.select'));
});

test('the link stops working once its fifteen minutes are up', function () {
    actingAs($this->admin);

    $machine = ($this->machine)(['name' => 'Desk 1']);

    $response = post(route('manage.machines.login-link', $machine));
    $url = $response->getSession()->get('inertia.flash_data')['machineLoginLink']['url'];

    travel(16)->minutes();

    // The `signed` middleware on pos.auth.machine.login enforces the expiry; today's
    // URL::signedRoute carried none at all.
    get($url)->assertForbidden();
});

test('minting is logged against the machine and names the operator', function () {
    actingAs($this->admin);

    $machine = ($this->machine)(['name' => 'Desk 1']);

    post(route('manage.machines.login-link', $machine));

    assertDatabaseHas('activity_log', [
        'description' => 'POS login link created',
        'subject_type' => Machine::class,
        'subject_id' => $machine->id,
        'causer_id' => $this->admin->id,
    ]);
});

test('a machine URL cannot be forged from the login route without a signature', function () {
    $machine = Machine::factory()->create(['name' => 'Desk 1']);

    get(URL::route('pos.auth.machine.login', ['machine_id' => $machine->id]))->assertForbidden();
});

/**
 * A valid form payload; override one key to test the rule that guards it.
 *
 * @return array<string, mixed>
 */
function manageMachinePayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Desk 1',
        'tse_client_id' => null,
        'sumup_reader_id' => null,
        'should_discover_printers' => true,
    ], $overrides);
}
