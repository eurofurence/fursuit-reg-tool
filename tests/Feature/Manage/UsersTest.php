<?php

/*
 * Users, phase 1. Transcribed from audit 4.13.
 *
 * The audit is the baseline this repository does not otherwise have, so the column list
 * below is a literal array copied out of it rather than a description of one: a dropped
 * column fails a test instead of quietly disappearing.
 *
 * The case this file exists for is `valid_registration`. the old user list declares a Toggle
 * and an IconColumn for a column that 2025_08_03_195303_remove_old_columns_from_users_table
 * dropped from `users`, so both Create and Edit throw SQL 1054 in production today. The field is not ported, and "the form saves" is
 * asserted for both directions rather than assumed.
 */

use App\Models\Event;
use App\Models\User;
use App\Support\Manage\Action;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;
use function Pest\Laravel\delete;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\put;

/** The audit's table, in order, minus the column that no longer exists. */
const MANAGE_USER_COLUMNS = [
    'remote_id',
    'name',
    'email',
    'is_admin',
    'is_reviewer',
    'created_at',
    'updated_at',
];

/** Where App\Support\Manage\Toast writes: Inertia's own flash bag, not a plain key. */
const MANAGE_TOAST_TITLE = 'inertia.flash_data.toast.title';

beforeEach(function () {
    // ManageEventScope runs on every /admin request whether or not the page is scoped,
    // and this list deliberately is not.
    Event::factory()->create([
        'name' => 'Eurofurence 29',
        'starts_at' => now()->addDays(30),
        'ends_at' => now()->addDays(35),
    ]);

    $this->admin = User::factory()->create(['is_admin' => true, 'is_reviewer' => false]);
    $this->reviewer = User::factory()->create(['is_admin' => false, 'is_reviewer' => true]);
    $this->attendee = User::factory()->create(['is_admin' => false, 'is_reviewer' => false]);

    $this->props = fn (array $query = []) => get(route('admin.settings.users.index', $query))
        ->viewData('page')['props'];
});

/*
 * Access. UserPolicy answers is_admin for every ability, so this is the one module where
 * holding access-manage is not enough.
 */

test('a guest is redirected to login', function () {
    get(route('admin.settings.users.index'))->assertRedirect(route('login'));
});

test('an attendee cannot reach the user list at all', function () {
    actingAs($this->attendee);

    get(route('admin.settings.users.index'))->assertForbidden();
});

test('a reviewer holds access-manage but is refused every user ability', function () {
    actingAs($this->reviewer);

    $target = User::factory()->create();

    get(route('admin.settings.users.index'))->assertForbidden();
    get(route('admin.settings.users.create'))->assertForbidden();
    post(route('admin.settings.users.store'), manageUserPayload())->assertForbidden();
    get(route('admin.settings.users.edit', $target))->assertForbidden();
    put(route('admin.settings.users.update', $target), manageUserPayload())->assertForbidden();
    delete(route('admin.settings.users.destroy', $target))->assertForbidden();
    delete(route('admin.settings.users.bulk.destroy'), ['ids' => [$target->id]])->assertForbidden();

    // Nothing was written on the way to any of those 403s.
    assertDatabaseHas('users', ['id' => $target->id]);
});

test('an admin gets the list', function () {
    actingAs($this->admin);

    get(route('admin.settings.users.index'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->component('Manage/Users/Index'));
});

/*
 * Index contract.
 */

test('the column list is the audit table without valid_registration', function () {
    actingAs($this->admin);

    $props = ($this->props)();

    expect(collect($props['columns'])->pluck('key')->all())->toBe(MANAGE_USER_COLUMNS);
});

test('valid_registration is gone from the columns and from the row cells', function () {
    actingAs($this->admin);

    $props = ($this->props)();

    expect(collect($props['columns'])->pluck('key'))->not->toContain('valid_registration');

    foreach ($props['rows'] as $row) {
        expect($row['cells'])->not->toHaveKey('valid_registration');
    }
});

test('created_at and updated_at are the two toggleable columns and both start hidden', function () {
    actingAs($this->admin);

    $props = ($this->props)();

    $toggleable = collect($props['columns'])->where('toggleable', true)->pluck('key')->all();

    expect($toggleable)->toBe(['created_at', 'updated_at'])
        ->and($props['hiddenColumns'])->toBe(['created_at', 'updated_at']);
});

test('only the two timestamps sort, matching the audit', function () {
    actingAs($this->admin);

    expect(collect(($this->props)()['columns'])->where('sortable', true)->pluck('key')->all())
        ->toBe(['created_at', 'updated_at']);
});

test('the two flags render as boolean cells', function () {
    actingAs($this->admin);

    $props = ($this->props)();
    $types = collect($props['columns'])->pluck('type', 'key');

    expect($types['is_admin'])->toBe('bool')
        ->and($types['is_reviewer'])->toBe('bool')
        ->and($types['created_at'])->toBe('datetime');

    $row = collect($props['rows'])->firstWhere('id', $this->admin->id);

    expect($row['cells']['is_admin'])->toBeTrue()
        ->and($row['cells']['is_reviewer'])->toBeFalse();
});

test('the table declares no filters', function () {
    actingAs($this->admin);

    get(route('admin.settings.users.index'))
        ->assertInertia(fn (Assert $page) => $page->where('filters', [])->etc());
});

test('the list is not scoped to the selected event', function () {
    // Plan 2.9 lists Users among the surfaces that stay unscoped. Users carry no event
    // at all, so a scope here would empty the table.
    actingAs($this->admin);

    expect(($this->props)()['meta']['total'])->toBe(User::count());
});

/*
 * Search, sort, pagination.
 */

test('search covers exactly the three columns the audit marks searchable', function () {
    actingAs($this->admin);

    $target = User::factory()->create([
        'remote_id' => 'IDP-4711',
        'name' => 'Wanted Wolf',
        'email' => 'wanted@example.test',
        'avatar' => 'https://example.test/needle.png',
    ]);

    foreach (['IDP-4711', 'Wanted Wolf', 'wanted@example.test'] as $term) {
        expect(collect(($this->props)(['search' => $term])['rows'])->pluck('id')->all())
            ->toBe([$target->id]);
    }

    // avatar is not searchable, so a term that appears only there matches nothing.
    expect(($this->props)(['search' => 'needle.png'])['rows'])->toBe([]);
});

test('the default order is by primary key and created_at sorts both ways', function () {
    actingAs($this->admin);

    $props = ($this->props)();

    expect($props['sort'])->toBe(['key' => 'id', 'dir' => 'asc'])
        ->and(collect($props['rows'])->pluck('id')->all())
        ->toBe(User::orderBy('id')->pluck('id')->all());

    expect(($this->props)(['sort' => 'created_at', 'dir' => 'desc'])['sort'])
        ->toBe(['key' => 'created_at', 'dir' => 'desc']);
});

test('the list paginates', function () {
    actingAs($this->admin);

    User::factory()->count(30)->create();

    $props = ($this->props)(['per_page' => 10]);

    expect($props['rows'])->toHaveCount(10)
        ->and($props['meta']['perPage'])->toBe(10)
        ->and($props['meta']['total'])->toBe(User::count());
});

/*
 * Actions, including the old panel default confirm copy the audit records verbatim.
 */

test('the page action is New user and it points at the create page', function () {
    actingAs($this->admin);

    $actions = ($this->props)()['pageActions'];

    expect($actions)->toHaveCount(1)
        ->and($actions[0]['label'])->toBe('New user')
        ->and($actions[0]['url'])->toBe(route('admin.settings.users.create'))
        ->and($actions[0]['method'])->toBe('get');
});

test('each row offers Edit and Delete, with the old panel default delete copy', function () {
    actingAs($this->admin);

    $row = collect(($this->props)()['rows'])->firstWhere('id', $this->attendee->id);

    expect(collect($row['actions'])->pluck('label')->all())->toBe(['Edit', 'Delete']);

    $delete = collect($row['actions'])->firstWhere('name', 'delete');

    expect($delete['method'])->toBe('delete')
        ->and($delete['url'])->toBe(route('admin.settings.users.destroy', $this->attendee))
        ->and($delete['confirm'])->toBe([
            'heading' => 'Delete user',
            'description' => Action::DEFAULT_CONFIRM_DESCRIPTION,
            'submit' => 'Delete',
        ]);
});

test('the bulk action is Delete selected, with the old panel default bulk delete copy', function () {
    actingAs($this->admin);

    $bulkActions = ($this->props)()['bulkActions'];

    expect($bulkActions)->toHaveCount(1);

    expect($bulkActions[0]['label'])->toBe('Delete selected')
        ->and($bulkActions[0]['method'])->toBe('delete')
        ->and($bulkActions[0]['url'])->toBe(route('admin.settings.users.bulk.destroy'))
        ->and($bulkActions[0]['confirm'])->toBe([
            'heading' => 'Delete selected users',
            'description' => Action::DEFAULT_CONFIRM_DESCRIPTION,
            'submit' => 'Delete',
        ]);
});

/*
 * Create and edit. Both are broken in production; both are asserted end to end here.
 */

test('the create page renders the form with no record', function () {
    actingAs($this->admin);

    get(route('admin.settings.users.create'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Manage/Users/Form')
            ->where('user', null)
        );
});

test('creating a user works, which it does not today', function () {
    actingAs($this->admin);

    post(route('admin.settings.users.store'), manageUserPayload([
        'remote_id' => 'IDP-1000',
        'name' => 'New Newt',
        'email' => 'newt@example.test',
        'avatar' => 'https://example.test/newt.png',
        'is_reviewer' => true,
        'is_admin' => false,
    ]))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.settings.users.index'));

    assertDatabaseHas('users', [
        'remote_id' => 'IDP-1000',
        'name' => 'New Newt',
        'email' => 'newt@example.test',
        'avatar' => 'https://example.test/newt.png',
        'is_reviewer' => true,
        'is_admin' => false,
    ]);
});

test('the edit page carries exactly the fields the form writes', function () {
    actingAs($this->admin);

    $target = User::factory()->create(['avatar' => 'https://example.test/a.png']);

    get(route('admin.settings.users.edit', $target))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Manage/Users/Form')
            ->where('user', [
                'id' => $target->id,
                'remote_id' => (string) $target->remote_id,
                'name' => $target->name,
                'email' => $target->email,
                'avatar' => 'https://example.test/a.png',
                'is_reviewer' => false,
                'is_admin' => false,
            ])
        );
});

test('editing a user works, which it does not today', function () {
    actingAs($this->admin);

    $target = User::factory()->create(['name' => 'Before']);

    put(route('admin.settings.users.update', $target), manageUserPayload([
        'remote_id' => (string) $target->remote_id,
        'name' => 'After',
        'email' => $target->email,
        'is_admin' => true,
    ]))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.settings.users.index'));

    expect($target->refresh()->name)->toBe('After')
        ->and($target->is_admin)->toBeTrue();
});

test('saving an unchanged record is not blocked by its own unique columns', function () {
    actingAs($this->admin);

    $target = User::factory()->create();

    put(route('admin.settings.users.update', $target), manageUserPayload([
        'remote_id' => (string) $target->remote_id,
        'name' => $target->name,
        'email' => $target->email,
    ]))->assertSessionHasNoErrors();
});

test('a valid_registration value in the payload is dropped rather than written', function () {
    // Nothing renders the field any more, but a stale tab or a crafted request still can
    // send it. It must not reach the model: the column does not exist.
    actingAs($this->admin);

    post(route('admin.settings.users.store'), manageUserPayload([
        'remote_id' => 'IDP-2000',
        'email' => 'stale@example.test',
        'valid_registration' => true,
    ]))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.settings.users.index'));

    $created = User::where('email', 'stale@example.test')->firstOrFail();

    expect($created->getAttributes())->not->toHaveKey('valid_registration');
});

/*
 * Validation, transcribed from the audit's form table.
 */

test('remote_id, name, email, is_reviewer and is_admin are all required', function () {
    actingAs($this->admin);

    post(route('admin.settings.users.store'), [])
        ->assertSessionHasErrors(['remote_id', 'name', 'email', 'is_reviewer', 'is_admin']);
});

test('avatar is optional', function () {
    actingAs($this->admin);

    post(route('admin.settings.users.store'), manageUserPayload([
        'remote_id' => 'IDP-3000',
        'email' => 'noavatar@example.test',
        'avatar' => null,
    ]))->assertSessionHasNoErrors();
});

test('email must be an email and the two text fields cap at 255', function () {
    actingAs($this->admin);

    post(route('admin.settings.users.store'), manageUserPayload(['email' => 'not-an-email']))
        ->assertSessionHasErrors('email');

    post(route('admin.settings.users.store'), manageUserPayload([
        'remote_id' => str_repeat('a', 256),
        'name' => str_repeat('b', 256),
    ]))->assertSessionHasErrors(['remote_id', 'name']);
});

test('a false toggle is accepted, because required means present and not empty', function () {
    actingAs($this->admin);

    post(route('admin.settings.users.store'), manageUserPayload([
        'remote_id' => 'IDP-4000',
        'email' => 'falsey@example.test',
        'is_reviewer' => false,
        'is_admin' => false,
    ]))->assertSessionHasNoErrors();
});

test('a duplicate remote_id or email is a field error, not an SQL 1062', function () {
    actingAs($this->admin);

    post(route('admin.settings.users.store'), manageUserPayload([
        'remote_id' => (string) $this->attendee->remote_id,
    ]))->assertSessionHasErrors('remote_id');

    post(route('admin.settings.users.store'), manageUserPayload([
        'email' => $this->attendee->email,
    ]))->assertSessionHasErrors('email');
});

/*
 * Delete, single and bulk. Hard deletes: User has no SoftDeletes.
 */

test('deleting a user removes the row and flashes the old panel copy', function () {
    actingAs($this->admin);

    $target = User::factory()->create();

    delete(route('admin.settings.users.destroy', $target))
        ->assertRedirect()
        ->assertSessionHas(MANAGE_TOAST_TITLE, 'Deleted');

    assertDatabaseMissing('users', ['id' => $target->id]);
});

test('bulk delete removes every selected user', function () {
    actingAs($this->admin);

    $targets = User::factory()->count(3)->create();
    $survivor = User::factory()->create();

    delete(route('admin.settings.users.bulk.destroy'), ['ids' => $targets->modelKeys()])
        ->assertRedirect()
        ->assertSessionHas(MANAGE_TOAST_TITLE, 'Deleted');

    foreach ($targets as $target) {
        assertDatabaseMissing('users', ['id' => $target->id]);
    }

    assertDatabaseHas('users', ['id' => $survivor->id]);
});

test('bulk delete needs at least one id', function () {
    actingAs($this->admin);

    delete(route('admin.settings.users.bulk.destroy'), ['ids' => []])
        ->assertSessionHasErrors('ids');
});

test('create and update flash the old panel Created and Saved copy', function () {
    actingAs($this->admin);

    post(route('admin.settings.users.store'), manageUserPayload([
        'remote_id' => 'IDP-5000',
        'email' => 'toast@example.test',
    ]))->assertSessionHas(MANAGE_TOAST_TITLE, 'Created');

    put(route('admin.settings.users.update', $this->attendee), manageUserPayload([
        'remote_id' => (string) $this->attendee->remote_id,
        'email' => $this->attendee->email,
    ]))->assertSessionHas(MANAGE_TOAST_TITLE, 'Saved');
});

/*
 * The model change that rides with this module.
 */

test('is_reviewer is cast to bool so a strict comparison works', function () {
    // Uncast until now while is_admin was cast.
    expect($this->reviewer->fresh()->is_reviewer)->toBeTrue()
        ->and($this->admin->fresh()->is_reviewer)->toBeFalse();
});

/**
 * A complete, valid payload, so each validation case can vary exactly one thing.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function manageUserPayload(array $overrides = []): array
{
    return array_merge([
        'remote_id' => 'IDP-'.fake()->unique()->numberBetween(100000, 999999),
        'name' => 'Payload Panther',
        'email' => fake()->unique()->safeEmail(),
        'avatar' => null,
        'is_reviewer' => false,
        'is_admin' => false,
    ], $overrides);
}
