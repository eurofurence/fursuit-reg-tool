<?php

/*
 * POS staff and their RFID tags, phase 5 (plan part 4). Transcribed from audit 4.10 and
 * 4.10.1.
 *
 * These rows are login credentials for the till, so this file asserts two things beyond
 * parity. The PIN never appears in a list payload - Filament's table loaded the plaintext
 * PIN of every member into the page and only formatted it to `Set` / `Not Set` on the way
 * out - and every tag endpoint is closed to anyone the policy would not let write it,
 * because a tag's `content` is the whole credential a reader presents.
 *
 * The three fixes the plan mandates each get a test that fails on the Filament behaviour:
 * the SecurePinRule record id (2.10 #21), the blank setup code (2.10 #22), and the
 * Generate button that wrote to the database before the form was submitted (2.10 #23).
 *
 * Behaviour is asserted through the partial visit the client actually sends, not through
 * the declaration: a column that says it is sortable proves nothing about the row set.
 */

use App\Http\Controllers\Manage\StaffController;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Event;
use App\Models\RfidTag;
use App\Models\Staff;
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
use function Pest\Laravel\withHeaders;

/** The audit's staff table, in order. */
const MANAGE_STAFF_COLUMNS = [
    'name',
    'pin_code',
    'is_active',
    'rfid_tags_count',
    'last_login_at',
    'created_at',
];

/** The audit's RFID tag table, in order. */
const MANAGE_RFID_COLUMNS = [
    'content',
    'name',
    'is_active',
    'last_login_at',
    'created_at',
];

/** Where App\Support\Manage\Toast writes: Inertia's own flash bag, not a plain key. */
const MANAGE_STAFF_TOAST_TITLE = 'inertia.flash_data.toast.title';

/**
 * The visit useTableQuery makes: X-Inertia plus the five reloaded keys, against the
 * component that is already on screen. Both tables in this module are driven by it - the
 * staff list and the tag table nested in the edit page - so both are exercised through it.
 *
 * @return array<string, mixed>
 */
function manageStaffPartial(string $url, string $component): array
{
    $version = app(HandleInertiaRequests::class)->version(request());

    $response = withHeaders([
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) $version,
        'X-Inertia-Partial-Component' => $component,
        'X-Inertia-Partial-Data' => 'rows,meta,filters,sort,search',
    ])->get($url);

    $response->assertOk();

    $props = $response->json('props');

    expect($props)->toHaveKeys(['rows', 'meta', 'filters', 'sort', 'search']);
    expect($props['rows'])->toBeArray();

    return $props;
}

/**
 * @return array<string, mixed>
 */
function manageStaffPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Nightshift Ferret',
        'pin_code' => null,
        'setup_code' => null,
        'is_active' => true,
    ], $overrides);
}

/**
 * @return array<string, mixed>
 */
function manageRfidPayload(array $overrides = []): array
{
    return array_merge([
        'content' => '0417238844',
        'name' => 'Blue lanyard',
        'is_active' => true,
    ], $overrides);
}

beforeEach(function () {
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

    $this->props = fn (array $query = []) => get(route('manage.staff.index', $query))
        ->viewData('page')['props'];
});

/*
 * Access. StaffPolicy answers is_admin for every ability and is not touched by this
 * module; RfidTagPolicy is new and answers the same question, so the tags are no longer
 * protected only by the page they sit on (audit 54).
 */

test('a guest is redirected to login', function () {
    get(route('manage.staff.index'))->assertRedirect(route('login'));
});

test('an attendee cannot reach the staff list at all', function () {
    actingAs($this->attendee);

    get(route('manage.staff.index'))->assertForbidden();
});

test('a reviewer holds access-manage but is refused every staff ability', function () {
    actingAs($this->reviewer);

    $staff = Staff::factory()->create();

    get(route('manage.staff.index'))->assertForbidden();
    get(route('manage.staff.create'))->assertForbidden();
    post(route('manage.staff.store'), manageStaffPayload())->assertForbidden();
    get(route('manage.staff.edit', $staff))->assertForbidden();
    put(route('manage.staff.update', $staff), manageStaffPayload())->assertForbidden();
    delete(route('manage.staff.destroy', $staff))->assertForbidden();
    delete(route('manage.staff.bulk.destroy'), ['ids' => [$staff->id]])->assertForbidden();
    post(route('manage.staff.setup-code', $staff))->assertForbidden();
    post(route('manage.staff.setup-code.create'))->assertForbidden();

    // Nothing was written on the way to any of those 403s.
    assertDatabaseHas('staff', ['id' => $staff->id]);
});

test('a reviewer is refused every rfid tag endpoint', function () {
    actingAs($this->reviewer);

    $staff = Staff::factory()->create();
    $tag = $staff->rfidTags()->create(manageRfidPayload());

    post(route('manage.staff.rfid-tags.store', $staff), manageRfidPayload(['content' => '9']))->assertForbidden();
    put(route('manage.staff.rfid-tags.update', [$staff, $tag]), manageRfidPayload())->assertForbidden();
    delete(route('manage.staff.rfid-tags.destroy', [$staff, $tag]))->assertForbidden();
    delete(route('manage.staff.rfid-tags.bulk.destroy', $staff), ['ids' => [$tag->id]])->assertForbidden();

    assertDatabaseHas('rfid_tags', ['id' => $tag->id]);
});

test('an admin gets the list', function () {
    actingAs($this->admin);

    get(route('manage.staff.index'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->component('Manage/Staff/Index'));
});

/*
 * The list, and the PIN that must not be in it.
 */

test('the column list is the audit table', function () {
    actingAs($this->admin);

    expect(collect(($this->props)()['columns'])->pluck('key')->all())->toBe(MANAGE_STAFF_COLUMNS);
});

test('the PIN column is the two literal words and the PIN itself never reaches the payload', function () {
    actingAs($this->admin);

    $withPin = Staff::factory()->create(['name' => 'Has Pin', 'pin_code' => '408271']);
    $withoutPin = Staff::factory()->create(['name' => 'No Pin', 'pin_code' => null]);

    $response = get(route('manage.staff.index'));
    $rows = collect($response->viewData('page')['props']['rows']);

    expect($rows->firstWhere('id', $withPin->id)['cells']['pin_code'])->toBe('Set')
        ->and($rows->firstWhere('id', $withoutPin->id)['cells']['pin_code'])->toBe('Not Set');

    // Filament loaded the plaintext PIN into the page and formatted it on the way out.
    // This one is never serialised, hidden column or not.
    $response->assertDontSee('408271', false);
});

test('the edit page never carries the plaintext PIN either', function () {
    // Plan 2.10 #66. The list transformer computes Set / Not Set server-side so the PIN
    // stays out of that payload; an edit payload is the same payload, kept in the page
    // props, in the DOM and in Inertia's history state for as long as the tab is open,
    // and reachable without the reveal gesture and activity entry the SumUp pairing code
    // gets. The form is handed a sentinel instead.
    actingAs($this->admin);

    $staff = Staff::factory()->create(['name' => 'Has Pin', 'pin_code' => '408271']);

    $response = get(route('manage.staff.edit', $staff));

    $response->assertSuccessful()->assertDontSee('408271', false);

    expect($response->viewData('page')['props']['staff']['pin_code'])
        ->toBe(StaffController::PIN_UNCHANGED);
});

test('a member with no PIN gets an empty field rather than the sentinel', function () {
    // The Generate button's visibility reads this value, so "no PIN" has to stay falsy.
    actingAs($this->admin);

    $staff = Staff::factory()->create(['name' => 'No Pin', 'pin_code' => null]);

    expect(get(route('manage.staff.edit', $staff))->viewData('page')['props']['staff']['pin_code'])
        ->toBe('');
});

test('submitting the sentinel unchanged keeps the stored PIN', function () {
    actingAs($this->admin);

    $staff = Staff::factory()->create(['name' => 'Steady Skunk', 'pin_code' => '408271']);

    put(route('manage.staff.update', $staff), manageStaffPayload([
        'name' => 'Steady Skunk',
        'pin_code' => StaffController::PIN_UNCHANGED,
    ]))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('manage.staff.index'));

    expect($staff->fresh()->pin_code)->toBe('408271');
});

test('the sentinel itself can never be written as a PIN', function () {
    actingAs($this->admin);

    post(route('manage.staff.store'), manageStaffPayload([
        'name' => 'Sentinel Serval',
        'pin_code' => StaffController::PIN_UNCHANGED,
    ]))->assertSessionHasNoErrors();

    expect(Staff::where('name', 'Sentinel Serval')->first()->pin_code)->toBeNull();
});

test('emptying the PIN field still clears the PIN, as the helper text says', function () {
    actingAs($this->admin);

    $staff = Staff::factory()->create(['name' => 'Cleared Coyote', 'pin_code' => '408271']);

    put(route('manage.staff.update', $staff), manageStaffPayload([
        'name' => 'Cleared Coyote',
        'pin_code' => '',
    ]))->assertSessionHasNoErrors();

    expect($staff->fresh()->pin_code)->toBeNull();
});

test('the two hidden-by-default columns are the PIN and created_at', function () {
    actingAs($this->admin);

    $props = ($this->props)();

    expect(collect($props['columns'])->where('toggleable', true)->pluck('key')->all())
        ->toBe(['pin_code', 'created_at'])
        ->and($props['hiddenColumns'])->toBe(['pin_code', 'created_at']);
});

test('name, last login and created_at are the sortable columns', function () {
    actingAs($this->admin);

    expect(collect(($this->props)()['columns'])->where('sortable', true)->pluck('key')->all())
        ->toBe(['name', 'last_login_at', 'created_at']);
});

test('the RFID tag count column counts the member tags', function () {
    actingAs($this->admin);

    $staff = Staff::factory()->create();
    $staff->rfidTags()->create(manageRfidPayload(['content' => 'aaa1']));
    $staff->rfidTags()->create(manageRfidPayload(['content' => 'aaa2']));

    $row = collect(($this->props)()['rows'])->firstWhere('id', $staff->id);

    expect($row['cells']['rfid_tags_count'])->toBe(2);
});

test('a member who never logged in gets a blank cell, not a placeholder', function () {
    // Audit 122: StaffResource sets no placeholder on `last_login_at` while the RFID
    // table sets `Never used`. The inconsistency is kept rather than silently unified,
    // because a formatted or placeholdered cell changes what the column reads as.
    actingAs($this->admin);

    $staff = Staff::factory()->create(['last_login_at' => null]);
    $props = ($this->props)();

    expect(collect($props['columns'])->firstWhere('key', 'last_login_at')['fallback'])->toBe('')
        ->and(collect($props['rows'])->firstWhere('id', $staff->id)['cells']['last_login_at'])->toBeNull();
});

test('search covers the name only', function () {
    actingAs($this->admin);

    $target = Staff::factory()->create(['name' => 'Wanted Wolf', 'setup_code' => 'ABC123']);
    Staff::factory()->create(['name' => 'Someone Else']);

    expect(collect(($this->props)(['search' => 'Wanted Wolf'])['rows'])->pluck('id')->all())
        ->toBe([$target->id]);

    // The setup code is not a searchable column, and searching for one must not confirm
    // that it exists.
    expect(($this->props)(['search' => 'ABC123'])['rows'])->toBe([]);
});

test('the active status filter narrows the row set through the partial visit', function () {
    actingAs($this->admin);

    $active = Staff::factory()->create(['is_active' => true]);
    $inactive = Staff::factory()->create(['is_active' => false]);

    $props = ($this->props)();
    $filter = collect($props['filters'])->firstWhere('key', 'is_active');

    expect($filter['type'])->toBe('ternary')
        ->and($filter['label'])->toBe('Active Status')
        ->and($filter['default'])->toBe('');

    $all = manageStaffPartial('/admin/staff', 'Manage/Staff/Index');
    $onlyActive = manageStaffPartial('/admin/staff?filter[is_active]=1', 'Manage/Staff/Index');
    $onlyInactive = manageStaffPartial('/admin/staff?filter[is_active]=0', 'Manage/Staff/Index');

    expect(collect($all['rows'])->pluck('id')->all())->toContain($active->id, $inactive->id)
        ->and(collect($onlyActive['rows'])->pluck('id')->all())->toContain($active->id)->not->toContain($inactive->id)
        ->and(collect($onlyInactive['rows'])->pluck('id')->all())->toContain($inactive->id)->not->toContain($active->id);
});

test('the list sorts by last login through the partial visit', function () {
    actingAs($this->admin);

    $old = Staff::factory()->create(['last_login_at' => now()->subDays(9)]);
    $recent = Staff::factory()->create(['last_login_at' => now()->subMinutes(5)]);

    $ascending = manageStaffPartial('/admin/staff?sort=last_login_at&dir=asc', 'Manage/Staff/Index');
    $descending = manageStaffPartial('/admin/staff?sort=last_login_at&dir=desc', 'Manage/Staff/Index');

    $first = fn (array $props) => collect($props['rows'])
        ->pluck('id')
        ->intersect([$old->id, $recent->id])
        ->values()
        ->first();

    expect($first($ascending))->toBe($old->id)
        ->and($first($descending))->toBe($recent->id);
});

test('the unpaginated table becomes a page of 200 with the pager visible', function () {
    // Plan 2.3: ->paginated(false) becomes perPage 200 rather than an unbounded render.
    actingAs($this->admin);

    $meta = ($this->props)()['meta'];

    expect($meta['perPage'])->toBe(200)
        ->and($meta['perPageOptions'])->toContain(200);
});

test('the list is not scoped to the selected event', function () {
    actingAs($this->admin);

    Staff::factory()->count(3)->create();

    expect(($this->props)()['meta']['total'])->toBe(Staff::count());
});

/*
 * Actions, with the Filament default copy the audit records verbatim.
 */

test('the page action is New staff', function () {
    actingAs($this->admin);

    $actions = ($this->props)()['pageActions'];

    expect($actions)->toHaveCount(1)
        ->and($actions[0]['label'])->toBe('New staff')
        ->and($actions[0]['url'])->toBe(route('manage.staff.create'))
        ->and($actions[0]['method'])->toBe('get');
});

test('each row offers Edit and Delete, with Filament default delete copy', function () {
    actingAs($this->admin);

    $staff = Staff::factory()->create();
    $row = collect(($this->props)()['rows'])->firstWhere('id', $staff->id);

    expect(collect($row['actions'])->pluck('label')->all())->toBe(['Edit', 'Delete']);

    $delete = collect($row['actions'])->firstWhere('name', 'delete');

    expect($delete['method'])->toBe('delete')
        ->and($delete['url'])->toBe(route('manage.staff.destroy', $staff))
        ->and($delete['confirm'])->toBe([
            'heading' => 'Delete staff',
            'description' => Action::DEFAULT_CONFIRM_DESCRIPTION,
            'submit' => 'Delete',
        ]);
});

test('the bulk action is Delete selected, with Filament default bulk delete copy', function () {
    actingAs($this->admin);

    $bulk = ($this->props)()['bulkActions'];

    expect($bulk)->toHaveCount(1)
        ->and($bulk[0]['label'])->toBe('Delete selected')
        ->and($bulk[0]['url'])->toBe(route('manage.staff.bulk.destroy'))
        ->and($bulk[0]['confirm'])->toBe([
            'heading' => 'Delete selected staff',
            'description' => Action::DEFAULT_CONFIRM_DESCRIPTION,
            'submit' => 'Delete',
        ]);
});

test('the edit page carries a header delete action', function () {
    actingAs($this->admin);

    $staff = Staff::factory()->create();

    get(route('manage.staff.edit', $staff))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Manage/Staff/Form')
            ->where('headerActions.0.label', 'Delete')
            ->where('headerActions.0.confirm.heading', 'Delete staff')
            ->etc());
});

test('deleting a member is a hard delete that takes their tags with it', function () {
    actingAs($this->admin);

    $staff = Staff::factory()->create();
    $tag = $staff->rfidTags()->create(manageRfidPayload());

    delete(route('manage.staff.destroy', $staff))
        ->assertRedirect(route('manage.staff.index'))
        ->assertSessionHas(MANAGE_STAFF_TOAST_TITLE, 'Deleted');

    assertDatabaseMissing('staff', ['id' => $staff->id]);
    // rfid_tags.staff_id is onDelete('cascade'), as today (audit 7.7).
    assertDatabaseMissing('rfid_tags', ['id' => $tag->id]);
});

test('the bulk delete removes the whole selection', function () {
    actingAs($this->admin);

    $members = Staff::factory()->count(3)->create();

    delete(route('manage.staff.bulk.destroy'), ['ids' => $members->pluck('id')->all()])
        ->assertSessionHas(MANAGE_STAFF_TOAST_TITLE, 'Deleted');

    expect(Staff::whereIn('id', $members->pluck('id'))->count())->toBe(0);
});

/*
 * The form.
 */

test('the create page renders with no record', function () {
    actingAs($this->admin);

    get(route('manage.staff.create'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Manage/Staff/Form')
            ->where('staff', null)
            ->where('generatedSetupCode', null)
            ->etc());
});

test('a member is created and the toast is Filaments Created', function () {
    actingAs($this->admin);

    post(route('manage.staff.store'), manageStaffPayload(['pin_code' => '408271']))
        ->assertRedirect(route('manage.staff.index'))
        ->assertSessionHas(MANAGE_STAFF_TOAST_TITLE, 'Created');

    assertDatabaseHas('staff', [
        'name' => 'Nightshift Ferret',
        'pin_code' => '408271',
        'is_active' => true,
    ]);
});

test('the name is required and the PIN must be exactly six digits', function () {
    actingAs($this->admin);

    post(route('manage.staff.store'), manageStaffPayload(['name' => '']))
        ->assertSessionHasErrors('name');

    post(route('manage.staff.store'), manageStaffPayload(['pin_code' => '4082']))
        ->assertSessionHasErrors('pin_code');

    // A leading zero survives: `digits:6` measures the string, where Filament's
    // numeric + length(6) measured the number (audit 121).
    post(route('manage.staff.store'), manageStaffPayload(['name' => 'Zero Fox', 'pin_code' => '048271']))
        ->assertSessionHasNoErrors();

    assertDatabaseHas('staff', ['name' => 'Zero Fox', 'pin_code' => '048271']);
});

test('a weak PIN is refused by SecurePinRule', function () {
    actingAs($this->admin);

    post(route('manage.staff.store'), manageStaffPayload(['pin_code' => '111111']))
        ->assertSessionHasErrors('pin_code');
});

test('saving an unchanged member with a PIN no longer fails against itself', function () {
    // Plan 2.10 #21, audit 34. `new SecurePinRule` with no record id made
    // Staff::validatePinStrength() find the row being edited and refuse the save with
    // `This PIN is not secure enough. Please choose a different PIN.`, which made editing
    // a staff member impossible once a PIN was set.
    actingAs($this->admin);

    $staff = Staff::factory()->create(['name' => 'Steady Skunk', 'pin_code' => '408271']);

    put(route('manage.staff.update', $staff), manageStaffPayload([
        'name' => 'Steady Skunk',
        'pin_code' => '408271',
    ]))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('manage.staff.index'))
        ->assertSessionHas(MANAGE_STAFF_TOAST_TITLE, 'Saved');

    expect($staff->fresh()->pin_code)->toBe('408271');
});

test('another members PIN is still refused, with the deliberately vague message', function () {
    // Audit 121: Staff::validatePinStrength reports a duplicate as "not secure enough" on
    // purpose, so the form cannot be used to confirm that somebody else holds a PIN.
    actingAs($this->admin);

    Staff::factory()->create(['pin_code' => '408271']);
    $other = Staff::factory()->create(['pin_code' => '739114']);

    put(route('manage.staff.update', $other), manageStaffPayload(['pin_code' => '408271']))
        ->assertSessionHasErrors([
            'pin_code' => 'This PIN is not secure enough. Please choose a different PIN.',
        ]);
});

test('a blank setup code stores null, so a second blank member does not collide', function () {
    // Plan 2.10 #22, audit 35. `strtoupper($state ?? '')` wrote '' into a column with a
    // UNIQUE index: the first blank saved, the second blew up with SQL 1062.
    actingAs($this->admin);

    post(route('manage.staff.store'), manageStaffPayload(['name' => 'First Blank']))
        ->assertSessionHasNoErrors();
    post(route('manage.staff.store'), manageStaffPayload(['name' => 'Second Blank']))
        ->assertSessionHasNoErrors();

    expect(Staff::whereIn('name', ['First Blank', 'Second Blank'])->count())->toBe(2)
        ->and(Staff::where('name', 'First Blank')->value('setup_code'))->toBeNull()
        ->and(Staff::where('name', 'Second Blank')->value('setup_code'))->toBeNull();
});

test('a setup code is uppercased on save and cannot duplicate another one', function () {
    actingAs($this->admin);

    post(route('manage.staff.store'), manageStaffPayload(['name' => 'Upper Otter', 'setup_code' => 'ab12cd']))
        ->assertSessionHasNoErrors();

    expect(Staff::where('name', 'Upper Otter')->value('setup_code'))->toBe('AB12CD');

    // The UNIQUE index has always been there and the Filament form never validated it,
    // so a collision surfaced as SQL 1062 rather than a field error.
    post(route('manage.staff.store'), manageStaffPayload(['name' => 'Copy Cat', 'setup_code' => 'AB12CD']))
        ->assertSessionHasErrors('setup_code');
});

/*
 * The Generate button. Plan 2.10 #23, audit 36.
 */

test('Generate proposes a setup code without writing one', function () {
    actingAs($this->admin);

    $staff = Staff::factory()->create(['setup_code' => null]);

    post(route('manage.staff.setup-code', $staff))->assertRedirect();

    // The Filament suffix action called Staff::generateSetupCode(), which ends in
    // $this->update([...]): pressing Generate rotated a live POS credential before the
    // form was ever submitted.
    expect($staff->fresh()->setup_code)->toBeNull();

    $code = session('manage.staff.generated_setup_code');

    expect($code)->toBeString()->toHaveLength(6);
});

test('the proposed code reaches the form as a prop and only persists on save', function () {
    actingAs($this->admin);

    $staff = Staff::factory()->create(['setup_code' => null]);

    post(route('manage.staff.setup-code', $staff));

    $code = get(route('manage.staff.edit', $staff))
        ->assertInertia(fn (Assert $page) => $page->component('Manage/Staff/Form')->etc())
        ->viewData('page')['props']['generatedSetupCode'];

    expect($code)->toBeString();
    expect($staff->fresh()->setup_code)->toBeNull();

    put(route('manage.staff.update', $staff), manageStaffPayload([
        'name' => $staff->name,
        'setup_code' => $code,
    ]))->assertSessionHasNoErrors();

    expect($staff->fresh()->setup_code)->toBe($code);
});

test('Generate works on the create screen, where there is no record', function () {
    actingAs($this->admin);

    post(route('manage.staff.setup-code.create'))->assertRedirect();

    expect(session('manage.staff.generated_setup_code'))->toBeString()->toHaveLength(6);
});

/*
 * The RFID tag table nested in the edit page.
 */

test('the edit page carries the tag table as flat top-level props', function () {
    actingAs($this->admin);

    $staff = Staff::factory()->create();
    $staff->rfidTags()->create(manageRfidPayload());

    get(route('manage.staff.edit', $staff))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Manage/Staff/Form')
            ->where('name', 'staff-rfid-tags')
            ->has('rows', 1)
            ->has('columns')
            ->has('filters')
            ->has('meta')
            ->where('canCreateRfidTag', true)
            ->etc());
});

test('the tag table is the audit column list and shows only this members tags', function () {
    actingAs($this->admin);

    $staff = Staff::factory()->create();
    $mine = $staff->rfidTags()->create(manageRfidPayload(['content' => 'MINE-1']));
    $theirs = Staff::factory()->create()->rfidTags()->create(manageRfidPayload(['content' => 'THEIRS-1']));

    $props = get(route('manage.staff.edit', $staff))->viewData('page')['props'];

    expect(collect($props['columns'])->pluck('key')->all())->toBe(MANAGE_RFID_COLUMNS)
        ->and(collect($props['rows'])->pluck('id')->all())->toBe([$mine->id])
        ->and(collect($props['rows'])->pluck('id')->all())->not->toContain($theirs->id);
});

test('the tag columns carry the audit labels, placeholders and copyable code', function () {
    actingAs($this->admin);

    $staff = Staff::factory()->create();
    $staff->rfidTags()->create(manageRfidPayload(['name' => null, 'last_login_at' => null]));

    $columns = collect(get(route('manage.staff.edit', $staff))->viewData('page')['props']['columns'])
        ->keyBy('key');

    expect($columns['content']['label'])->toBe('RFID Code')
        ->and($columns['content']['type'])->toBe('copyable')
        ->and($columns['name']['label'])->toBe('Tag Name')
        ->and($columns['name']['fallback'])->toBe('No name set')
        ->and($columns['is_active']['label'])->toBe('Active')
        ->and($columns['last_login_at']['label'])->toBe('Last Used')
        ->and($columns['last_login_at']['fallback'])->toBe('Never used')
        ->and($columns['last_login_at']['sortable'])->toBeTrue()
        ->and($columns['created_at']['label'])->toBe('Added')
        ->and($columns['created_at']['sortable'])->toBeTrue();
});

test('the tag table filters and sorts through the partial visit', function () {
    actingAs($this->admin);

    $staff = Staff::factory()->create();
    $active = $staff->rfidTags()->create(manageRfidPayload(['content' => 'A-1', 'last_login_at' => now()->subDay()]));
    $inactive = $staff->rfidTags()->create(manageRfidPayload(['content' => 'B-2', 'is_active' => false, 'last_login_at' => now()]));

    $url = route('manage.staff.edit', $staff, false);

    $onlyActive = manageStaffPartial($url.'?filter[is_active]=1', 'Manage/Staff/Form');
    $newestFirst = manageStaffPartial($url.'?sort=last_login_at&dir=desc', 'Manage/Staff/Form');

    expect(collect($onlyActive['rows'])->pluck('id')->all())->toBe([$active->id])
        ->and(collect($newestFirst['rows'])->pluck('id')->all())->toBe([$inactive->id, $active->id]);
});

test('the tag search covers the code and the tag name', function () {
    actingAs($this->admin);

    $staff = Staff::factory()->create();
    $byCode = $staff->rfidTags()->create(manageRfidPayload(['content' => 'FIND-ME', 'name' => 'Nothing']));
    $byName = $staff->rfidTags()->create(manageRfidPayload(['content' => 'OTHER', 'name' => 'Green fob']));

    $url = route('manage.staff.edit', $staff, false);

    expect(collect(manageStaffPartial($url.'?search=FIND-ME', 'Manage/Staff/Form')['rows'])->pluck('id')->all())
        ->toBe([$byCode->id])
        ->and(collect(manageStaffPartial($url.'?search=Green fob', 'Manage/Staff/Form')['rows'])->pluck('id')->all())
        ->toBe([$byName->id]);
});

test('a tag row offers Delete with Filament default delete copy, and the bulk action names rfid tags', function () {
    actingAs($this->admin);

    $staff = Staff::factory()->create();
    $tag = $staff->rfidTags()->create(manageRfidPayload());

    $props = get(route('manage.staff.edit', $staff))->viewData('page')['props'];
    $delete = collect($props['rows'][0]['actions'])->firstWhere('name', 'delete');

    expect($delete['method'])->toBe('delete')
        ->and($delete['url'])->toBe(route('manage.staff.rfid-tags.destroy', [$staff, $tag]))
        ->and($delete['confirm'])->toBe([
            'heading' => 'Delete rfid tag',
            'description' => Action::DEFAULT_CONFIRM_DESCRIPTION,
            'submit' => 'Delete',
        ]);

    expect($props['bulkActions'])->toHaveCount(1)
        ->and($props['bulkActions'][0]['label'])->toBe('Delete selected')
        ->and($props['bulkActions'][0]['url'])->toBe(route('manage.staff.rfid-tags.bulk.destroy', $staff))
        ->and($props['bulkActions'][0]['confirm'])->toBe([
            'heading' => 'Delete selected rfid tags',
            'description' => Action::DEFAULT_CONFIRM_DESCRIPTION,
            'submit' => 'Delete',
        ]);
});

test('a tag is created against the owning member', function () {
    actingAs($this->admin);

    $staff = Staff::factory()->create();

    post(route('manage.staff.rfid-tags.store', $staff), manageRfidPayload())
        ->assertSessionHasNoErrors()
        ->assertSessionHas(MANAGE_STAFF_TOAST_TITLE, 'Created');

    assertDatabaseHas('rfid_tags', [
        'staff_id' => $staff->id,
        'content' => '0417238844',
        'name' => 'Blue lanyard',
        'is_active' => true,
    ]);
});

test('a tag code is required and unique across every member', function () {
    actingAs($this->admin);

    $staff = Staff::factory()->create();
    $other = Staff::factory()->create();
    $other->rfidTags()->create(manageRfidPayload(['content' => 'TAKEN']));

    post(route('manage.staff.rfid-tags.store', $staff), manageRfidPayload(['content' => '']))
        ->assertSessionHasErrors('content');

    post(route('manage.staff.rfid-tags.store', $staff), manageRfidPayload(['content' => 'TAKEN']))
        ->assertSessionHasErrors('content');
});

test('a tag keeps its own code when it is saved unchanged', function () {
    // The unique-ignore trap, the same shape as the PIN one.
    actingAs($this->admin);

    $staff = Staff::factory()->create();
    $tag = $staff->rfidTags()->create(manageRfidPayload(['content' => 'SAME-CODE']));

    put(route('manage.staff.rfid-tags.update', [$staff, $tag]), manageRfidPayload([
        'content' => 'SAME-CODE',
        'name' => 'Renamed',
        'is_active' => false,
    ]))
        ->assertSessionHasNoErrors()
        ->assertSessionHas(MANAGE_STAFF_TOAST_TITLE, 'Saved');

    expect($tag->fresh()->name)->toBe('Renamed')
        ->and($tag->fresh()->is_active)->toBeFalse();
});

test('a tag is hard deleted, one at a time and in bulk', function () {
    actingAs($this->admin);

    $staff = Staff::factory()->create();
    $one = $staff->rfidTags()->create(manageRfidPayload(['content' => 'ONE']));
    $two = $staff->rfidTags()->create(manageRfidPayload(['content' => 'TWO']));
    $three = $staff->rfidTags()->create(manageRfidPayload(['content' => 'THREE']));

    delete(route('manage.staff.rfid-tags.destroy', [$staff, $one]))
        ->assertSessionHas(MANAGE_STAFF_TOAST_TITLE, 'Deleted');

    assertDatabaseMissing('rfid_tags', ['id' => $one->id]);

    delete(route('manage.staff.rfid-tags.bulk.destroy', $staff), ['ids' => [$two->id, $three->id]])
        ->assertSessionHas(MANAGE_STAFF_TOAST_TITLE, 'Deleted');

    expect(RfidTag::whereIn('id', [$two->id, $three->id])->count())->toBe(0);
});

test('a tag belonging to another member cannot be reached through this one', function () {
    actingAs($this->admin);

    $staff = Staff::factory()->create();
    $other = Staff::factory()->create();
    $theirs = $other->rfidTags()->create(manageRfidPayload(['content' => 'THEIRS']));

    delete(route('manage.staff.rfid-tags.destroy', [$staff, $theirs]))->assertNotFound();
    put(route('manage.staff.rfid-tags.update', [$staff, $theirs]), manageRfidPayload())->assertNotFound();

    assertDatabaseHas('rfid_tags', ['id' => $theirs->id]);
});

test('a bulk delete that names a tag of another member deletes nothing', function () {
    actingAs($this->admin);

    $staff = Staff::factory()->create();
    $mine = $staff->rfidTags()->create(manageRfidPayload(['content' => 'MINE']));
    $theirs = Staff::factory()->create()->rfidTags()->create(manageRfidPayload(['content' => 'THEIRS']));

    delete(route('manage.staff.rfid-tags.bulk.destroy', $staff), ['ids' => [$mine->id, $theirs->id]])
        ->assertSessionHas(MANAGE_STAFF_TOAST_TITLE, 'Nothing was deleted');

    assertDatabaseHas('rfid_tags', ['id' => $mine->id]);
    assertDatabaseHas('rfid_tags', ['id' => $theirs->id]);
});
