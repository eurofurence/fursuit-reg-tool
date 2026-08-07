<?php

/*
 * Special codes (plan phase 1, audit 4.4).
 *
 * The module 500s in production on two formatting closures that type-hint `string $state`
 * for values that are routinely absent: `class_name`, which the form never made required
 * (audit 30), and `event_id`, whose event row can be gone because events are hard-deleted
 * (audit 31). Both are covered below, because a null-safe list is the only reason this
 * module is in phase 1 at all.
 *
 * The rest is parity: four columns, no filters, the two `code` uniqueness rules, hard
 * delete with Filament's own confirm copy, and the stock Created / Saved / Deleted toasts
 * this resource never overrode.
 */

use App\Domain\CatchEmAll\Models\SpecialCode;
use App\Http\Controllers\Manage\SpecialCodeController;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Event;
use App\Models\Fursuit\Fursuit;
use App\Models\User;
use App\Policies\SpecialCodePolicy;
use App\Support\Manage\Action;
use App\Support\Manage\EventScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\delete;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\put;

const BUG_BOUNTY = 'App\\Domain\\CatchEmAll\\SpecialActions\\BugBountyAction';

beforeEach(function () {
    $this->event = Event::factory()->create(['name' => 'Eurofurence 29', 'starts_at' => now()->addDays(30)]);
    $this->otherEvent = Event::factory()->create(['name' => 'Eurofurence 28', 'starts_at' => now()->subYear()]);

    $this->admin = User::factory()->create(['is_admin' => true, 'is_reviewer' => false]);
    $this->reviewer = User::factory()->create(['is_admin' => false, 'is_reviewer' => true]);

    $this->code = fn (array $attributes = []) => SpecialCode::create([
        'event_id' => $this->event->id,
        'code' => 'ABC45',
        'class_name' => BUG_BOUNTY,
        'constructor_data' => null,
        ...$attributes,
    ]);

    // The list is event-scoped from phase 1 on, so every read below states which scope
    // it is asking for rather than inheriting whatever the seeder picked.
    $this->scoped = fn (?int $eventId) => actingAs($this->admin)->withSession([
        EventScope::SESSION_ID => $eventId,
        EventScope::SESSION_CHOSEN => true,
    ]);
});

test('the list renders the four columns in order, with their labels', function () {
    ($this->code)();

    ($this->scoped)(null)->get(route('manage.special-codes.index'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Manage/SpecialCodes/Index')
            ->where('columns.0', fn ($column) => $column['key'] === 'code' && $column['label'] === 'Code' && $column['sortable'])
            ->where('columns.1', fn ($column) => $column['key'] === 'class_name' && $column['label'] === 'Class' && $column['sortable'])
            ->where('columns.2', fn ($column) => $column['key'] === 'constructor_data' && $column['label'] === 'Data' && $column['sortable'])
            ->where('columns.3', fn ($column) => $column['key'] === 'event_id' && $column['label'] === 'Event' && $column['sortable'])
            ->count('columns', 4)
        );
});

test('the Class column renders the option label, and an unknown class as itself', function () {
    ($this->code)();
    ($this->code)(['code' => 'ZZZ99', 'class_name' => 'App\\Something\\Else']);

    $cells = ($this->scoped)(null)->get(route('manage.special-codes.index'))
        ->viewData('page')['props']['rows'];

    expect(collect($cells)->pluck('cells.class_name')->sort()->values()->all())
        ->toBe(['App\\Something\\Else', 'Bug Hunter Bounty']);
});

test('a code with no class does not take the whole table down', function () {
    // audit 30: `fn (string $state): string` against a column the form never required.
    // The list has to render it, and the formatter has to survive a literal null, which
    // is what production rows that predate the NOT NULL column actually hold.
    ($this->code)(['class_name' => '']);

    ($this->scoped)(null)->get(route('manage.special-codes.index'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->where('rows.0.cells.class_name', null));

    expect(SpecialCodeController::classLabel(null))->toBeNull()
        ->and(SpecialCodeController::classLabel(''))->toBeNull();
});

test('a code whose event row is gone renders an empty cell instead of a 500', function () {
    // audit 31: `fn (string $state): string` returning null once the event is hard-deleted.
    // The orphan is written with the foreign key deferred, because the constraint carries
    // ON DELETE CASCADE and deleting the event would take the code with it: the row this
    // has to survive is one that got separated from its event some other way, which is
    // what production rows that outlived their event look like.
    DB::statement('PRAGMA defer_foreign_keys = ON');

    $orphan = ($this->code)(['event_id' => 999999, 'code' => 'ORP01']);

    ($this->scoped)(null)->get(route('manage.special-codes.index'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('rows.0.id', $orphan->id)
            ->where('rows.0.cells.event_id', null)
        );
});

test('the Event column shows the event name and costs one query however many rows there are', function () {
    // audit 99: one `Event::where('id', $state)` per row. The relation is eager-loaded now,
    // so growing the list must not grow the number of event queries.
    $countEventQueries = function () {
        $queries = 0;
        DB::listen(function ($query) use (&$queries) {
            if (str_contains($query->sql, 'from "events"')) {
                $queries++;
            }
        });

        ($this->scoped)(null)->get(route('manage.special-codes.index'))->assertSuccessful();

        return $queries;
    };

    foreach (range(1, 3) as $i) {
        ($this->code)(['code' => 'AAA0'.$i]);
    }

    $withThree = $countEventQueries();

    foreach (range(4, 12) as $i) {
        ($this->code)(['code' => 'AAA'.str_pad((string) $i, 2, '0', STR_PAD_LEFT)]);
    }

    expect($countEventQueries())->toBe($withThree);

    ($this->scoped)(null)->get(route('manage.special-codes.index'))
        ->assertInertia(fn (Assert $page) => $page->where('rows.0.cells.event_id', 'Eurofurence 29'));
});

test('the Data column renders the stored JSON and nothing for an empty one', function () {
    ($this->code)(['constructor_data' => ['amount' => 100, 'reason' => 'An Example']]);
    ($this->code)(['code' => 'NUL01', 'constructor_data' => null]);

    $rows = ($this->scoped)(null)->get(route('manage.special-codes.index'))
        ->viewData('page')['props']['rows'];

    expect(collect($rows)->pluck('cells.constructor_data')->sort()->values()->all())
        ->toBe([null, '{"amount":100,"reason":"An Example"}']);
});

test('the list carries no filters and is sorted newest first', function () {
    $older = ($this->code)(['code' => 'OLD01']);
    $older->forceFill(['created_at' => now()->subDay()])->save();
    $newer = ($this->code)(['code' => 'NEW01']);

    ($this->scoped)(null)->get(route('manage.special-codes.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->count('filters', 0)
            ->where('sort.key', 'created_at')
            ->where('sort.dir', 'desc')
            ->where('rows.0.id', $newer->id)
            ->where('rows.1.id', $older->id)
        );
});

test('sorting and paging survive the partial reload the client actually sends', function () {
    // useTableQuery visits with only=[rows,meta,filters,sort,search], and Inertia resolves
    // those by top-level key. While the envelope was nested under one `table` prop all five
    // resolved to null, the client merged the nulls over what it already had, and every
    // sort, page and per-page click changed the URL and nothing else.
    $first = ($this->code)(['code' => 'AAA01']);
    $last = ($this->code)(['code' => 'ZZZ99']);

    // The asset version has to match or Inertia answers 409 before the controller runs.
    $version = app(HandleInertiaRequests::class)->version(request());

    $partial = fn (array $query) => ($this->scoped)(null)
        ->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) $version,
            'X-Inertia-Partial-Component' => 'Manage/SpecialCodes/Index',
            'X-Inertia-Partial-Data' => 'rows,meta,filters,sort,search',
        ])
        ->get(route('manage.special-codes.index', $query));

    // A partial visit answers with JSON rather than the page view, so these read the
    // props off the response directly: assertInertia only ever sees a full page load,
    // which is why the whole defect got past the existing suite.
    $ascending = $partial(['sort' => 'code', 'dir' => 'asc'])->assertSuccessful();

    expect($ascending->json('props.sort'))->toBe(['key' => 'code', 'dir' => 'asc'])
        ->and($ascending->json('props.rows.0.id'))->toBe($first->id)
        ->and($ascending->json('props.meta.page'))->toBe(1)
        // The five requested keys have to carry data. Nested under `table` every one of
        // them came back null and the client merged the nulls over live props.
        ->and($ascending->json('props.filters'))->toBe([])
        ->and($ascending->json('props.search'))->toBe('');

    expect($partial(['sort' => 'code', 'dir' => 'desc'])->json('props.rows.0.id'))->toBe($last->id);

    // Paging through the same path: per_page 10 with two rows leaves page 2 empty, which
    // is a real answer rather than the stale first page the nested envelope kept showing.
    $paged = $partial(['per_page' => 10, 'page' => 2]);

    expect($paged->json('props.rows'))->toBe([])
        ->and($paged->json('props.meta.perPage'))->toBe(10)
        ->and($paged->json('props.meta.page'))->toBe(2);
});

test('the list is scoped by the global event selector', function () {
    // New behaviour (plan 2.9): every row has an event_id and nothing ever filtered on it.
    $mine = ($this->code)();
    ($this->code)(['event_id' => $this->otherEvent->id, 'code' => 'OTH01']);

    ($this->scoped)($this->event->id)->get(route('manage.special-codes.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->count('rows', 1)
            ->where('rows.0.id', $mine->id)
        );

    ($this->scoped)(null)->get(route('manage.special-codes.index'))
        ->assertInertia(fn (Assert $page) => $page->count('rows', 2));
});

test('the row, bulk and page actions carry Filament default copy', function () {
    ($this->code)();

    ($this->scoped)(null)->get(route('manage.special-codes.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('rows.0.actions.0.name', 'edit')
            ->where('rows.0.actions.0.label', 'Edit')
            ->where('rows.0.actions.1.label', 'Delete')
            ->where('rows.0.actions.1.method', 'delete')
            ->where('rows.0.actions.1.confirm.heading', 'Delete special code')
            ->where('rows.0.actions.1.confirm.description', Action::DEFAULT_CONFIRM_DESCRIPTION)
            ->where('rows.0.actions.1.confirm.submit', 'Delete')
            ->where('bulkActions.0.label', 'Delete selected')
            ->where('bulkActions.0.confirm.heading', 'Delete selected special codes')
            ->where('bulkActions.0.confirm.description', Action::DEFAULT_CONFIRM_DESCRIPTION)
            ->where('bulkActions.0.confirm.submit', 'Delete')
            ->where('pageActions.0.label', 'New special code')
        );
});

test('the create form ships its options and the live catch-url base', function () {
    actingAs($this->admin)
        ->get(route('manage.special-codes.create'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Manage/SpecialCodes/Form')
            ->where('specialCode', null)
            ->where('classOptions', [['value' => BUG_BOUNTY, 'label' => 'Bug Hunter Bounty']])
            ->count('events', 2)
            // The unchanging half of {scheme}://{fcea.domain}/?code={code}&auto, so the
            // preview can be rebuilt on every keystroke instead of once at render (audit 33).
            ->where('catchUrlBase', SpecialCodeController::catchUrlBase())
        );

    expect(SpecialCodeController::catchUrl('ABC45'))
        ->toBe(SpecialCodeController::catchUrlBase().'?code=ABC45&auto')
        ->and(SpecialCodeController::catchUrlBase())
        ->toBe('http://'.config('fcea.domain').'/');
});

test('the edit form prefills the record, with constructor_data as text', function () {
    $code = ($this->code)(['constructor_data' => ['amount' => 100]]);

    actingAs($this->admin)
        ->get(route('manage.special-codes.edit', $code))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Manage/SpecialCodes/Form')
            ->where('specialCode.code', 'ABC45')
            ->where('specialCode.class_name', BUG_BOUNTY)
            ->where('specialCode.event_id', $this->event->id)
            ->where('specialCode.constructor_data', '{"amount":100}')
        );
});

test('storing a code writes it and flashes the stock Created toast', function () {
    actingAs($this->admin)
        ->post(route('manage.special-codes.store'), [
            'event_id' => $this->event->id,
            'class_name' => BUG_BOUNTY,
            'constructor_data' => '{"amount": 100, "reason": "An Example"}',
            'code' => 'ABC45',
        ])
        ->assertRedirect(route('manage.special-codes.index'))
        ->assertInertiaFlash('toast', ['tone' => 'success', 'title' => 'Created', 'body' => null]);

    $code = SpecialCode::sole();

    expect($code->code)->toBe('ABC45')
        ->and($code->class_name)->toBe(BUG_BOUNTY)
        // Decoded, not the raw string: the model casts this to object and hands it
        // straight to the action constructor, which is typed `?object`.
        ->and($code->constructor_data)->toBeObject()
        ->and($code->constructor_data->amount)->toBe(100);
});

test('constructor_data is editable and must be valid JSON', function () {
    // Change 39: the field was permanently disabled because its matcher compared the
    // selected class against a literal 'EXAMPLE' that is not one of the options.
    $code = ($this->code)();

    actingAs($this->admin)
        ->put(route('manage.special-codes.update', $code), [
            'event_id' => $this->event->id,
            'class_name' => BUG_BOUNTY,
            'constructor_data' => 'not json',
            'code' => 'ABC45',
        ])
        ->assertSessionHasErrors('constructor_data');

    actingAs($this->admin)
        ->put(route('manage.special-codes.update', $code), [
            'event_id' => $this->event->id,
            'class_name' => BUG_BOUNTY,
            'constructor_data' => '{"amount": 250}',
            'code' => 'ABC45',
        ])
        ->assertRedirect(route('manage.special-codes.index'))
        ->assertInertiaFlash('toast', ['tone' => 'success', 'title' => 'Saved', 'body' => null]);

    expect($code->fresh()->constructor_data->amount)->toBe(250);
});

test('class_name must be one of the offered options', function () {
    // The Select offered one option and validated nothing against it, while
    // createActionInstance() does `new $className(...)` on whatever is stored.
    actingAs($this->admin)
        ->post(route('manage.special-codes.store'), [
            'event_id' => $this->event->id,
            'class_name' => 'App\\Something\\Else',
            'code' => 'BAD01',
        ])
        ->assertSessionHasErrors('class_name');

    expect(SpecialCode::count())->toBe(0);

    actingAs($this->admin)
        ->post(route('manage.special-codes.store'), [
            'event_id' => $this->event->id,
            'class_name' => BUG_BOUNTY,
            'code' => 'OKA01',
        ])
        ->assertRedirect(route('manage.special-codes.index'));

    expect(SpecialCode::sole()->class_name)->toBe(BUG_BOUNTY);
});

test('constructor_data must be a JSON object, not just valid JSON', function () {
    // `json` accepts any JSON document. The stored value reaches
    // AbstractSpecialCodeAction::__construct, typed `?object`, so an array or a scalar
    // raises a TypeError there. GameController catches \Exception only, and a TypeError
    // is an \Error, so it escapes the handler and 500s the attendee's scan instead of
    // showing 'Error processing special code'. The write path is where that has to stop.
    foreach (['[1,2,3]', '5', '"a string"', 'true', 'null'] as $notAnObject) {
        actingAs($this->admin)
            ->post(route('manage.special-codes.store'), [
                'event_id' => $this->event->id,
                'class_name' => BUG_BOUNTY,
                'constructor_data' => $notAnObject,
                'code' => 'ARR01',
            ])
            ->assertSessionHasErrors('constructor_data');
    }

    expect(SpecialCode::count())->toBe(0);

    // The shape the action class is actually typed for still goes through.
    actingAs($this->admin)
        ->post(route('manage.special-codes.store'), [
            'event_id' => $this->event->id,
            'class_name' => BUG_BOUNTY,
            'constructor_data' => '{"amount": 100}',
            'code' => 'OBJ01',
        ])
        ->assertRedirect(route('manage.special-codes.index'));

    expect(SpecialCode::sole()->constructor_data)->toBeObject();
});

test('a stored constructor_data never trips the action constructor type hint', function () {
    // The reason the rule above exists, asserted against the constructor itself rather
    // than restating the rule: whatever survives validation has to be something
    // createActionInstance() can hand over without raising a TypeError.
    actingAs($this->admin)
        ->post(route('manage.special-codes.store'), [
            'event_id' => $this->event->id,
            'class_name' => BUG_BOUNTY,
            'constructor_data' => '{"amount": 100, "reason": "An Example"}',
            'code' => 'OBJ02',
        ])
        ->assertRedirect(route('manage.special-codes.index'));

    expect(SpecialCode::sole()->createActionInstance())->toBeInstanceOf(BUG_BOUNTY);
});

test('a code without a class saves rather than failing at the database', function () {
    // The field is not required, and `special_codes.class_name` is NOT NULL.
    actingAs($this->admin)
        ->post(route('manage.special-codes.store'), [
            'event_id' => $this->event->id,
            'code' => 'NOC01',
        ])
        ->assertRedirect(route('manage.special-codes.index'));

    expect(SpecialCode::sole()->class_name)->toBe('');
});

test('code is required and must be exactly five characters', function () {
    $payload = ['event_id' => $this->event->id];

    actingAs($this->admin)->post(route('manage.special-codes.store'), $payload)
        ->assertSessionHasErrors('code');

    actingAs($this->admin)->post(route('manage.special-codes.store'), $payload + ['code' => 'ABC4'])
        ->assertSessionHasErrors('code');

    actingAs($this->admin)->post(route('manage.special-codes.store'), $payload + ['code' => 'ABC456'])
        ->assertSessionHasErrors('code');

    expect(SpecialCode::count())->toBe(0);
});

test('code is unique among special codes, ignoring the record being edited', function () {
    $existing = ($this->code)();
    $other = ($this->code)(['code' => 'XYZ99']);

    actingAs($this->admin)
        ->post(route('manage.special-codes.store'), [
            'event_id' => $this->event->id,
            'code' => 'ABC45',
        ])
        ->assertSessionHasErrors('code');

    // Saving a record without touching its own code must not collide with itself.
    actingAs($this->admin)
        ->put(route('manage.special-codes.update', $existing), [
            'event_id' => $this->event->id,
            'class_name' => BUG_BOUNTY,
            'code' => 'ABC45',
        ])
        ->assertSessionHasNoErrors();

    actingAs($this->admin)
        ->put(route('manage.special-codes.update', $other), [
            'event_id' => $this->event->id,
            'code' => 'ABC45',
        ])
        ->assertSessionHasErrors('code');
});

test('code is cross-checked against fursuit catch codes, with the message verbatim', function () {
    // The catch code is generated on save, so it is written afterwards.
    $fursuit = Fursuit::factory()->create(['event_id' => $this->event->id]);
    Fursuit::whereKey($fursuit->id)->update(['catch_code' => 'CAT01']);

    actingAs($this->admin)
        ->post(route('manage.special-codes.store'), [
            'event_id' => $this->event->id,
            'code' => 'CAT01',
        ])
        ->assertSessionHasErrors(['code' => 'This code is already used in Fursuits.']);

    expect(SpecialCode::count())->toBe(0);
});

test('event_id is required and must exist', function () {
    actingAs($this->admin)->post(route('manage.special-codes.store'), ['code' => 'ABC45'])
        ->assertSessionHasErrors('event_id');

    actingAs($this->admin)->post(route('manage.special-codes.store'), ['code' => 'ABC45', 'event_id' => 99999])
        ->assertSessionHasErrors('event_id');
});

test('catch_url is never written, whatever the request carries', function () {
    // `dehydrated(false)` in Filament. The model is `$guarded = []`, so the request has
    // to be the thing that refuses it.
    actingAs($this->admin)
        ->post(route('manage.special-codes.store'), [
            'event_id' => $this->event->id,
            'code' => 'ABC45',
            'catch_url' => 'https://evil.example/?code=ABC45&auto',
        ])
        ->assertRedirect(route('manage.special-codes.index'));

    expect(SpecialCode::sole()->getAttributes())->not->toHaveKey('catch_url');
});

test('a code is hard deleted, one at a time or in bulk', function () {
    $first = ($this->code)();
    $second = ($this->code)(['code' => 'XYZ99']);

    actingAs($this->admin)
        ->delete(route('manage.special-codes.destroy', $first))
        ->assertRedirect()
        ->assertInertiaFlash('toast', ['tone' => 'success', 'title' => 'Deleted', 'body' => null]);

    expect(SpecialCode::whereKey($first->id)->exists())->toBeFalse();

    actingAs($this->admin)
        ->delete(route('manage.special-codes.bulk.destroy'), ['ids' => [$second->id]])
        ->assertRedirect()
        ->assertInertiaFlash('toast', ['tone' => 'success', 'title' => 'Deleted', 'body' => null]);

    expect(SpecialCode::count())->toBe(0);
});

test('DELETE /admin/special-codes/bulk is not read as a record id', function () {
    expect(route('manage.special-codes.bulk.destroy', absolute: false))->toBe('/admin/special-codes/bulk');
});

test('every ability belongs to an admin, so a reviewer is shut out of the whole module', function () {
    // audit 51: no policy exists today, so a reviewer can create, edit and delete codes
    // that award Catch-Em-All points. Plan 2.10 #19.
    $code = ($this->code)();

    actingAs($this->reviewer);

    get(route('manage.special-codes.index'))->assertForbidden();
    get(route('manage.special-codes.create'))->assertForbidden();
    post(route('manage.special-codes.store'), ['event_id' => $this->event->id, 'code' => 'ZZZ99'])->assertForbidden();
    get(route('manage.special-codes.edit', $code))->assertForbidden();
    put(route('manage.special-codes.update', $code), ['event_id' => $this->event->id, 'code' => 'ABC45'])->assertForbidden();
    delete(route('manage.special-codes.destroy', $code))->assertForbidden();

    delete(route('manage.special-codes.bulk.destroy'), ['ids' => [$code->id]])->assertForbidden();

    expect(SpecialCode::count())->toBe(1);
});

test('bulk delete refuses an unauthorized caller even when the ids match nothing', function () {
    // The all-or-nothing loop (plan 2.5) only speaks for rows it loaded, so an empty
    // result set walked straight past it and answered a reviewer with the success
    // 'Deleted' toast. The endpoint asks the same question the button is offered on,
    // which is what Users already did.
    ($this->code)();

    actingAs($this->reviewer)
        ->delete(route('manage.special-codes.bulk.destroy'), ['ids' => [999999]])
        ->assertForbidden();

    // And an admin still gets the all-or-nothing behaviour rather than a 403.
    actingAs($this->admin)
        ->delete(route('manage.special-codes.bulk.destroy'), ['ids' => [999999]])
        ->assertRedirect();

    expect(SpecialCode::count())->toBe(1);
});

test('the policy is registered, which auto-discovery would never have managed', function () {
    // The model lives under App\Domain\**, where discovery looks in a directory that
    // does not exist. Without the explicit mapping every ability silently falls open.
    expect(Gate::getPolicyFor(SpecialCode::class))->toBeInstanceOf(SpecialCodePolicy::class);
});

test('the rail links to the module for an admin and hides it from a reviewer', function () {
    actingAs($this->admin)->get(route('manage.dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where(
            'manageNav',
            fn ($groups) => collect($groups)->flatMap(fn ($group) => $group['items'])->contains(fn ($item) => $item['label'] === 'Special Codes')
        ));

    actingAs($this->reviewer)->get(route('manage.dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where(
            'manageNav',
            fn ($groups) => ! collect($groups)->flatMap(fn ($group) => $group['items'])->contains(fn ($item) => $item['label'] === 'Special Codes')
        ));
});
