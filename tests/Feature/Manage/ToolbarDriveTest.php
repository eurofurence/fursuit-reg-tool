<?php

/**
 * Drives the real partial visit the merged toolbar makes, on one module per filter shape.
 *
 * DataTable now owns the filter bar, the column toggle and the pager. Every one of those
 * controls reaches the server the same way: `useTableQuery` fires
 * `router.get(url, { only: ['rows', 'meta', 'filters', 'sort', 'search'] })`, which is an
 * Inertia partial visit. A page can render perfectly and still have lost its wiring, so
 * these tests send that exact request - headers included - rather than a plain GET, and
 * assert the row set actually moved.
 *
 * FilterDriveTest already covers the filter shapes themselves (multi-select, ternary,
 * range, a declared default). This file covers what that one does not: the date range, a
 * module with no filters at all, and the three controls every module shares - sort, search
 * and pagination.
 */

use App\Domain\Checkout\Models\Checkout\Checkout;
use App\Domain\Checkout\Models\Checkout\States\Finished;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Event;
use App\Models\Machine;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

beforeEach(function () {
    Storage::fake('s3');

    $this->admin = User::factory()->create(['is_admin' => true]);

    $this->event = Event::factory()->create([
        'starts_at' => now()->addDays(30),
        'ends_at' => now()->addDays(35),
        'order_starts_at' => now()->subDay(),
        'order_ends_at' => now()->addDays(20),
    ]);

    actingAs($this->admin);
});

/**
 * The request `useTableQuery` actually sends. `X-Inertia-Partial-Data` is the part that
 * matters: with it, Inertia evaluates only those five props, so a module whose envelope is
 * assembled outside them would come back missing its rows.
 */
function partial(string $url, string $component): array
{
    $response = get($url, [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) (new HandleInertiaRequests)->version(request()),
        'X-Inertia-Partial-Component' => $component,
        'X-Inertia-Partial-Data' => 'rows,meta,filters,sort,search',
    ]);

    $response->assertSuccessful();

    expect($response->json('component'))->toBe($component)
        ->and(array_keys($response->json('props')))
        ->toContain('rows', 'meta', 'filters', 'sort', 'search');

    return $response->json('props');
}

test('the checkouts date range narrows on either bound over the partial visit', function () {
    $machine = Machine::factory()->create();
    $cashier = Staff::factory()->create();

    // Checkout has no factory; the manage suite builds them by hand the same way.
    foreach (['2024-03-01', '2024-03-10', '2024-03-20'] as $index => $day) {
        Checkout::create([
            'remote_id' => 'REMOTE-'.$index,
            'status' => Finished::class,
            'payment_method' => 'cash',
            'user_id' => User::factory()->create()->id,
            'cashier_id' => $cashier->id,
            'machine_id' => $machine->id,
            'subtotal' => 1000,
            'tax' => 190,
            'total' => 1190,
            'fiskaly_data' => [],
            'created_at' => $day,
        ]);
    }

    $drive = function (array $filter) {
        $url = route('admin.checkouts.index', $filter ? ['filter' => $filter] : []);

        return partial($url, 'Manage/Checkouts/Index');
    };

    // No bounds: everything.
    expect($drive([])['meta']['total'])->toBe(3);

    // Lower bound only.
    expect($drive(['created_from' => '2024-03-05'])['meta']['total'])->toBe(2);

    // Upper bound only.
    expect($drive(['created_until' => '2024-03-05'])['meta']['total'])->toBe(1);

    // Both, which is the shape the two chips make together.
    $both = $drive(['created_from' => '2024-03-05', 'created_until' => '2024-03-15']);

    expect($both['meta']['total'])->toBe(1);

    // The two date filters come back carrying exactly what was applied, so their chips
    // redraw with the bounds the operator picked.
    $byKey = collect($both['filters'])->keyBy('key');

    expect($byKey['created_from']['value'])->toBe('2024-03-05')
        ->and($byKey['created_until']['value'])->toBe('2024-03-15')
        ->and($byKey['created_from']['type'])->toBe('date');
});

test('a module with no declared filters still sorts, searches and paginates', function () {
    // Users declares no filters at all: its toolbar is the search box and the column
    // toggle, and nothing else may appear on it.
    //
    // Distinct created_at values, because a sort assertion over 30 rows written in the
    // same second would be asserting on tie-break order rather than on the sort.
    User::factory()->count(30)->create()->each(
        fn (User $user, int $index) => $user->forceFill(['created_at' => now()->subDays($index)])->save()
    );

    $first = partial(route('admin.settings.users.index'), 'Manage/Users/Index');

    expect($first['filters'])->toBe([])
        ->and($first['meta']['total'])->toBeGreaterThan(25)
        ->and($first['rows'])->toHaveCount(25);

    // Page 2 is a different row set, not the same one re-rendered.
    $second = partial(route('admin.settings.users.index', ['page' => 2]), 'Manage/Users/Index');

    expect($second['meta']['page'])->toBe(2)
        ->and(collect($second['rows'])->pluck('id')->intersect(collect($first['rows'])->pluck('id')))
        ->toBeEmpty();

    // per_page changes how many come back.
    $wide = partial(route('admin.settings.users.index', ['per_page' => 50]), 'Manage/Users/Index');

    expect($wide['meta']['perPage'])->toBe(50)
        ->and(count($wide['rows']))->toBeGreaterThan(count($first['rows']));

    // Sorting flips the row set, both ways.
    $asc = partial(route('admin.settings.users.index', ['sort' => 'created_at', 'dir' => 'asc']), 'Manage/Users/Index');
    $desc = partial(route('admin.settings.users.index', ['sort' => 'created_at', 'dir' => 'desc']), 'Manage/Users/Index');

    expect($asc['sort'])->toMatchArray(['key' => 'created_at', 'dir' => 'asc'])
        ->and($desc['sort'])->toMatchArray(['key' => 'created_at', 'dir' => 'desc'])
        ->and($asc['rows'][0]['id'])->not->toBe($desc['rows'][0]['id']);

    // Search narrows to the one record that matches.
    $needle = User::factory()->create(['name' => 'Zzyzx Uniquename']);

    $found = partial(route('admin.settings.users.index', ['search' => 'Zzyzx Uniquename']), 'Manage/Users/Index');

    expect($found['search'])->toBe('Zzyzx Uniquename')
        ->and($found['meta']['total'])->toBe(1)
        ->and($found['rows'][0]['id'])->toBe($needle->id);
});

test('every list module answers the partial visit with a whole envelope', function () {
    // A page that lost its wiring in the merge would come back without one of the five
    // props, or with a rows array the pager cannot describe. Cheap, and it covers the
    // modules the shape-specific tests above do not name.
    $modules = [
        'admin.badges.index' => 'Manage/Badges/Index',
        'admin.checkouts.index' => 'Manage/Checkouts/Index',
        'admin.settings.events.index' => 'Manage/Events/Index',
        'admin.fursuits.index' => 'Manage/Fursuits/Index',
        'admin.machines.index' => 'Manage/Machines/Index',
        'admin.print-batches.index' => 'Manage/PrintBatches/Index',
        'admin.print-jobs.index' => 'Manage/PrintJobs/Index',
        'admin.printers.index' => 'Manage/Printers/Index',
        'admin.special-codes.index' => 'Manage/SpecialCodes/Index',
        'admin.staff.index' => 'Manage/Staff/Index',
        'admin.sumup-readers.index' => 'Manage/SumUpReaders/Index',
        'admin.tse-clients.index' => 'Manage/TseClients/Index',
        'admin.settings.users.index' => 'Manage/Users/Index',
    ];

    foreach ($modules as $name => $component) {
        $props = partial(route($name), $component);

        expect($props['rows'])->toBeArray()
            ->and($props['filters'])->toBeArray()
            ->and($props['meta'])->toHaveKeys(['page', 'lastPage', 'perPage', 'perPageOptions', 'total']);
    }
});

test('column visibility persists per user per table and comes back on the next load', function () {
    $props = partial(route('admin.settings.users.index'), 'Manage/Users/Index');

    expect($props)->not->toBeEmpty();

    // The toggle posts the whole hidden set, exactly as DataTable does.
    $this->post(route('admin.tables.columns', 'users'), ['hidden' => ['created_at']])
        ->assertRedirect();

    get(route('admin.settings.users.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('hiddenColumns', ['created_at'])
            ->etc()
        );

    // And it is undone by posting the empty set back.
    $this->post(route('admin.tables.columns', 'users'), ['hidden' => []])
        ->assertRedirect();

    get(route('admin.settings.users.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('hiddenColumns', [])
            ->etc()
        );
});
