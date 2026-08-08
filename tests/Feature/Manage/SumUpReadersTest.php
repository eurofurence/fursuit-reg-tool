<?php

/*
 * SumUp readers, phase 5 (plan part 4.2). Transcribed from audit 4.11.
 *
 * The case this file exists for is the pairing code. SumUpReaderResource rendered the
 * pairing secret of a card terminal as a plain table column and a plain text input, so
 * every list view and every screenshot leaked it (audit landmine 10). Plan 2.10 #16 makes
 * it masked with a real reveal action, and "masked" here is a claim about the payload, not
 * about the CSS: the assertions below check the rendered body and the Inertia JSON of the
 * list for the literal code, so a client-side toggle over data already shipped to the
 * browser would fail them.
 *
 * The second fix is `remote_id`. Filament's `readOnly()` is a client-side attribute over a
 * field that still round-trips into a `$guarded = []` model (audit landmine 12), so both
 * write paths are asserted to drop it.
 */

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Event;
use App\Models\SumUpReader;
use App\Models\User;
use App\Support\Manage\Action;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;
use function Pest\Laravel\delete;
use function Pest\Laravel\from;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\put;

/** The audit's table, in order. */
const MANAGE_SUMUP_COLUMNS = ['name', 'remote_id', 'paring_code'];

/** Where App\Support\Manage\Toast writes: Inertia's own flash bag, not a plain key. */
const MANAGE_SUMUP_TOAST_TITLE = 'inertia.flash_data.toast.title';

/** Distinctive enough that a substring search over a whole response means something. */
const MANAGE_SUMUP_CODE = 'PAIRCODE-Z9X8W7';

beforeEach(function () {
    // App\Observers\SumUpReaderObserver talks to the SumUp merchant API on created,
    // updated and deleted, and rethrows on failure for the first two. It is registered in
    // AppServiceProvider and the audit never mentions it, so every write path through this
    // module makes a real outbound call. Faked here rather than worked around: the calls
    // are existing behaviour and the panel is not the place to change them.
    Http::fake(['*' => Http::response(['id' => 'rdr_FROM_SUMUP'], 200)]);

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

    // Fixtures are built without events, so the observer does not overwrite the
    // remote_id these assertions are written against.
    $this->reader = manageSumUpReader([
        'name' => 'Cashdesk 1',
        'remote_id' => 'rdr_ABC123',
        'paring_code' => MANAGE_SUMUP_CODE,
    ]);

    $this->props = fn (array $query = []) => get(route('admin.sumup-readers.index', $query))
        ->viewData('page')['props'];
});

/*
 * Access. SumUpReaderPolicy answers is_admin for every ability, so holding access-manage
 * is not enough here.
 */

test('a guest is redirected to login', function () {
    get(route('admin.sumup-readers.index'))->assertRedirect(route('login'));
});

test('an attendee cannot reach the reader list at all', function () {
    actingAs($this->attendee);

    get(route('admin.sumup-readers.index'))->assertForbidden();
});

test('a reviewer holds access-manage but is refused every reader ability', function () {
    actingAs($this->reviewer);

    get(route('admin.sumup-readers.index'))->assertForbidden();
    get(route('admin.sumup-readers.create'))->assertForbidden();
    post(route('admin.sumup-readers.store'), manageSumUpPayload())->assertForbidden();
    get(route('admin.sumup-readers.edit', $this->reader))->assertForbidden();
    put(route('admin.sumup-readers.update', $this->reader), manageSumUpPayload())->assertForbidden();
    post(route('admin.sumup-readers.reveal', $this->reader))->assertForbidden();
    delete(route('admin.sumup-readers.destroy', $this->reader))->assertForbidden();
    delete(route('admin.sumup-readers.bulk.destroy'), ['ids' => [$this->reader->id]])->assertForbidden();

    // Nothing was written, and nothing was revealed, on the way to any of those 403s.
    assertDatabaseHas('sumup_readers', ['id' => $this->reader->id]);
    assertDatabaseMissing('activity_log', ['description' => 'Revealed SumUp pairing code']);
});

test('an admin gets the list', function () {
    actingAs($this->admin);

    get(route('admin.sumup-readers.index'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->component('Manage/SumUpReaders/Index'));
});

/*
 * Index contract.
 */

test('the column list is the audit table, typo included', function () {
    actingAs($this->admin);

    expect(collect(($this->props)()['columns'])->pluck('key')->all())->toBe(MANAGE_SUMUP_COLUMNS);
});

test('no column is sortable, searchable or toggleable, matching the audit', function () {
    actingAs($this->admin);

    $columns = collect(($this->props)()['columns']);

    expect($columns->where('sortable', true))->toBeEmpty()
        ->and($columns->where('searchable', true))->toBeEmpty()
        ->and($columns->where('toggleable', true))->toBeEmpty()
        ->and(($this->props)()['hiddenColumns'])->toBe([]);
});

test('the table declares no filters', function () {
    actingAs($this->admin);

    get(route('admin.sumup-readers.index'))
        ->assertInertia(fn (Assert $page) => $page->where('filters', [])->etc());
});

test('the list is not scoped to the selected event', function () {
    // Plan 2.9 lists SumUp readers among the surfaces that stay unscoped.
    actingAs($this->admin);

    manageSumUpReader(['name' => 'Cashdesk 2', 'remote_id' => 'rdr_2', 'paring_code' => 'second']);

    expect(($this->props)()['meta']['total'])->toBe(SumUpReader::count());
});

test('the default order is by primary key', function () {
    actingAs($this->admin);

    manageSumUpReader(['name' => 'Cashdesk 2', 'remote_id' => 'rdr_2', 'paring_code' => 'second']);

    $props = ($this->props)();

    expect($props['sort'])->toBe(['key' => 'id', 'dir' => 'asc'])
        ->and(collect($props['rows'])->pluck('id')->all())
        ->toBe(SumUpReader::orderBy('id')->pluck('id')->all());
});

/*
 * The pairing code. Masked means absent from the payload, not hidden in it.
 */

test('the paring code cell is a mask and carries no part of the real code', function () {
    actingAs($this->admin);

    $row = collect(($this->props)()['rows'])->firstWhere('id', $this->reader->id);

    expect($row['cells']['name'])->toBe('Cashdesk 1')
        ->and($row['cells']['remote_id'])->toBe('rdr_ABC123')
        ->and($row['cells']['paring_code']['display'])->toBe('••••••••')
        ->and($row['cells']['paring_code'])->not->toContain(MANAGE_SUMUP_CODE);
});

test('the rendered index page does not contain the raw pairing code anywhere', function () {
    actingAs($this->admin);

    // The whole response, not just the cell: the props are serialised into the root
    // view's data-page attribute, so a value smuggled in under any other key shows up
    // here. `false` keeps the needle unescaped, since data-page is JSON.
    get(route('admin.sumup-readers.index'))
        ->assertSuccessful()
        ->assertDontSee(MANAGE_SUMUP_CODE, false);
});

test('the index Inertia payload does not contain the raw pairing code either', function () {
    actingAs($this->admin);

    $response = get(route('admin.sumup-readers.index'), [
        'X-Inertia' => 'true',
        // Without the asset version Inertia answers 409 rather than the page object.
        'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
    ]);

    $response->assertSuccessful();

    expect($response->getContent())->not->toContain(MANAGE_SUMUP_CODE);
});

test('the edit page does not carry the pairing code into the form', function () {
    actingAs($this->admin);

    get(route('admin.sumup-readers.edit', $this->reader))
        ->assertSuccessful()
        ->assertDontSee(MANAGE_SUMUP_CODE, false)
        ->assertInertia(fn (Assert $page) => $page
            ->component('Manage/SumUpReaders/Form')
            ->where('reader', [
                'id' => $this->reader->id,
                'name' => 'Cashdesk 1',
                'remote_id' => 'rdr_ABC123',
            ])
            ->where('revealed', null)
            ->etc()
        );
});

test('reveal hands the code back once, logs who asked, and changes nothing', function () {
    actingAs($this->admin);

    from(route('admin.sumup-readers.index'))
        ->post(route('admin.sumup-readers.reveal', $this->reader))
        ->assertRedirect(route('admin.sumup-readers.index'));

    assertDatabaseHas('activity_log', [
        'description' => 'Revealed SumUp pairing code',
        'subject_type' => SumUpReader::class,
        'subject_id' => $this->reader->id,
        'causer_id' => $this->admin->id,
    ]);

    // The redirect target is where the code surfaces, as a prop of that one response.
    get(route('admin.sumup-readers.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('revealed.id', $this->reader->id)
            ->where('revealed.paring_code', MANAGE_SUMUP_CODE)
            ->etc()
        );

    // And it is gone again on the next load: it was flashed, not stored.
    get(route('admin.sumup-readers.index'))
        ->assertDontSee(MANAGE_SUMUP_CODE, false)
        ->assertInertia(fn (Assert $page) => $page->where('revealed', null)->etc());

    expect($this->reader->refresh()->paring_code)->toBe(MANAGE_SUMUP_CODE);
});

/*
 * Actions, including the Filament default confirm copy the audit records verbatim.
 */

test('the page action is New sum up reader and it points at the create page', function () {
    actingAs($this->admin);

    $actions = ($this->props)()['pageActions'];

    expect($actions)->toHaveCount(1)
        ->and($actions[0]['label'])->toBe('New sum up reader')
        ->and($actions[0]['url'])->toBe(route('admin.sumup-readers.create'))
        ->and($actions[0]['method'])->toBe('get');
});

test('each row offers Reveal and Edit, and no delete', function () {
    // Audit 4.11: "Row actions: EditAction only. No delete row action." Reveal joins it
    // because the plaintext left the cell (plan 2.10 #16); a delete does not, because the
    // single delete belongs on the Edit page header where Filament put it.
    actingAs($this->admin);

    $row = collect(($this->props)()['rows'])->firstWhere('id', $this->reader->id);

    expect(collect($row['actions'])->pluck('label')->all())->toBe(['Reveal', 'Edit']);

    $reveal = collect($row['actions'])->firstWhere('name', 'reveal');

    expect($reveal['method'])->toBe('post')
        ->and($reveal['url'])->toBe(route('admin.sumup-readers.reveal', $this->reader))
        ->and($reveal['confirm']['submit'])->toBe('Reveal');
});

test('the bulk action is Delete selected, with Filament default bulk delete copy', function () {
    actingAs($this->admin);

    $bulkActions = ($this->props)()['bulkActions'];

    expect($bulkActions)->toHaveCount(1)
        ->and($bulkActions[0]['label'])->toBe('Delete selected')
        ->and($bulkActions[0]['method'])->toBe('delete')
        ->and($bulkActions[0]['url'])->toBe(route('admin.sumup-readers.bulk.destroy'))
        ->and($bulkActions[0]['confirm'])->toBe([
            'heading' => 'Delete selected sum up readers',
            'description' => Action::DEFAULT_CONFIRM_DESCRIPTION,
            'submit' => 'Delete',
        ]);
});

test('the edit page header carries Reveal and the delete the Filament Edit page had', function () {
    // Audit 4.11: EditSumUpReader declares Actions\DeleteAction::make(). The plan's route
    // table missed the single delete; it is registered and offered here.
    actingAs($this->admin);

    get(route('admin.sumup-readers.edit', $this->reader))
        ->assertInertia(function (Assert $page) {
            $actions = collect($page->toArray()['props']['actions']);

            expect($actions->pluck('name')->all())->toBe(['reveal', 'delete'])
                ->and($actions->firstWhere('name', 'delete')['confirm'])->toBe([
                    'heading' => 'Delete sum up reader',
                    'description' => Action::DEFAULT_CONFIRM_DESCRIPTION,
                    'submit' => 'Delete',
                ]);
        });
});

/*
 * Create and edit.
 */

test('the create page renders the form with no record', function () {
    actingAs($this->admin);

    get(route('admin.sumup-readers.create'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Manage/SumUpReaders/Form')
            ->where('reader', null)
            ->where('actions', [])
            ->etc()
        );
});

test('creating a reader writes name and paring code and flashes the Filament copy', function () {
    actingAs($this->admin);

    post(route('admin.sumup-readers.store'), manageSumUpPayload([
        'name' => 'Cashdesk 9',
        'paring_code' => 'NEWCODE-1',
    ]))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.sumup-readers.index'))
        ->assertSessionHas(MANAGE_SUMUP_TOAST_TITLE, 'Created');

    assertDatabaseHas('sumup_readers', [
        'name' => 'Cashdesk 9',
        'paring_code' => 'NEWCODE-1',
    ]);
});

test('editing a reader saves the name and flashes the Filament copy', function () {
    actingAs($this->admin);

    put(route('admin.sumup-readers.update', $this->reader), manageSumUpPayload([
        'name' => 'Cashdesk renamed',
        'paring_code' => '',
    ]))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.sumup-readers.index'))
        ->assertSessionHas(MANAGE_SUMUP_TOAST_TITLE, 'Saved');

    expect($this->reader->refresh()->name)->toBe('Cashdesk renamed');
});

test('an empty paring code on update keeps the stored one rather than blanking it', function () {
    // The form never receives the current code (plan 2.10 #16), so an untouched field
    // must not wipe the credential the card terminal is paired with.
    actingAs($this->admin);

    put(route('admin.sumup-readers.update', $this->reader), [
        'name' => 'Cashdesk 1',
        'paring_code' => '',
    ])->assertSessionHasNoErrors();

    expect($this->reader->refresh()->paring_code)->toBe(MANAGE_SUMUP_CODE);
});

test('a paring code sent on update replaces the stored one', function () {
    actingAs($this->admin);

    put(route('admin.sumup-readers.update', $this->reader), [
        'name' => 'Cashdesk 1',
        'paring_code' => 'ROTATED-2',
    ])->assertSessionHasNoErrors();

    expect($this->reader->refresh()->paring_code)->toBe('ROTATED-2');
});

/*
 * The remote_id fix. readOnly() was never a guard (audit landmine 12).
 */

test('a remote_id in the create payload is dropped rather than written', function () {
    actingAs($this->admin);

    post(route('admin.sumup-readers.store'), manageSumUpPayload([
        'name' => 'Crafted',
        'remote_id' => 'rdr_INJECTED',
    ]))->assertSessionHasNoErrors();

    $created = SumUpReader::where('name', 'Crafted')->firstOrFail();

    // The observer's response to the SumUp API is the only writer of this column.
    expect($created->remote_id)->toBe('rdr_FROM_SUMUP');
});

test('a remote_id in the update payload cannot rewrite the SumUp side binding', function () {
    actingAs($this->admin);

    put(route('admin.sumup-readers.update', $this->reader), manageSumUpPayload([
        'name' => 'Cashdesk 1',
        'remote_id' => 'rdr_INJECTED',
    ]))->assertSessionHasNoErrors();

    expect($this->reader->refresh()->remote_id)->toBe('rdr_ABC123');
});

/*
 * Validation, transcribed from the audit's form table.
 */

test('name and paring code are both required on create', function () {
    actingAs($this->admin);

    post(route('admin.sumup-readers.store'), [])
        ->assertSessionHasErrors(['name', 'paring_code']);
});

test('name stays required on update while the paring code becomes optional', function () {
    actingAs($this->admin);

    put(route('admin.sumup-readers.update', $this->reader), [])
        ->assertSessionHasErrors('name')
        ->assertSessionDoesntHaveErrors('paring_code');
});

test('both text fields cap at 255', function () {
    actingAs($this->admin);

    post(route('admin.sumup-readers.store'), manageSumUpPayload([
        'name' => str_repeat('a', 256),
        'paring_code' => str_repeat('b', 256),
    ]))->assertSessionHasErrors(['name', 'paring_code']);
});

/*
 * Delete, single and bulk. Hard deletes: SumUpReader has no SoftDeletes (audit 7.7).
 */

test('deleting a reader removes the row and flashes the Filament copy', function () {
    actingAs($this->admin);

    delete(route('admin.sumup-readers.destroy', $this->reader))
        ->assertRedirect(route('admin.sumup-readers.index'))
        ->assertSessionHas(MANAGE_SUMUP_TOAST_TITLE, 'Deleted');

    assertDatabaseMissing('sumup_readers', ['id' => $this->reader->id]);
});

test('bulk delete removes every selected reader', function () {
    actingAs($this->admin);

    $survivor = manageSumUpReader(['name' => 'Keep me', 'remote_id' => 'rdr_3', 'paring_code' => 'keep']);

    delete(route('admin.sumup-readers.bulk.destroy'), ['ids' => [$this->reader->id]])
        ->assertRedirect()
        ->assertSessionHas(MANAGE_SUMUP_TOAST_TITLE, 'Deleted');

    assertDatabaseMissing('sumup_readers', ['id' => $this->reader->id]);
    assertDatabaseHas('sumup_readers', ['id' => $survivor->id]);
});

test('bulk delete needs at least one id, and bulk is not read as a record id', function () {
    // The literal segment is declared before {reader}, or this request would try to bind
    // "bulk" as a model and 404 before the controller ever ran.
    actingAs($this->admin);

    delete(route('admin.sumup-readers.bulk.destroy'), ['ids' => []])
        ->assertSessionHasErrors('ids');
});

/**
 * A reader built without model events, so App\Observers\SumUpReaderObserver does not
 * reach for the SumUp API or rewrite the remote_id a test declared.
 *
 * @param  array<string, mixed>  $attributes
 */
function manageSumUpReader(array $attributes): SumUpReader
{
    return SumUpReader::withoutEvents(fn () => SumUpReader::create($attributes));
}

/**
 * A complete, valid payload, so each validation case can vary exactly one thing.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function manageSumUpPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Payload Reader',
        'paring_code' => 'PAYLOAD-CODE',
    ], $overrides);
}
