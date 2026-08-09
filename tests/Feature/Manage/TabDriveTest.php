<?php

/*
 * Preset views (tabs) on a manage list, driven the way the strip actually drives them.
 *
 * A tab is a server declaration - App\Support\Manage\Tab, handed to Table::tabs() - and
 * the client renders it, so the thing worth asserting is that `?tab=` moves the row set,
 * not that a flag came back in the envelope. Every request here is therefore the real
 * partial visit `useTableQuery` makes on a tab switch: X-Inertia plus
 * X-Inertia-Partial-Data over the same five props a filter change reloads. Sending only
 * those five is the point - the tab must narrow the rows without `tabs` itself being
 * reloaded, because the strip resolves the active tab from the URL.
 *
 * The reset rule this locks in: switching tabs keeps the chip filters, the sort and the
 * search, and drops the page. See setTab in useTableQuery.js for why.
 */

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Event;
use App\Models\Machine;
use App\Models\User;
use App\Support\Manage\Column;
use App\Support\Manage\EventScope;
use App\Support\Manage\Filter;
use App\Support\Manage\Tab;
use App\Support\Manage\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

beforeEach(function () {
    Storage::fake('s3');

    // Two admins exist before a line of this runs: the acting user, and the one
    // 2026_06_11_000000_set_user_as_admin_by_remote_id creates in every migrated database.
    // Both are counted into the expectations below rather than excluded, because both are
    // real rows and the strip would show them.
    $this->admin = User::factory()->create(['is_admin' => true]);

    User::factory()->count(2)->create(['is_admin' => true]);
    User::factory()->count(3)->create(['is_reviewer' => true]);
    $this->both = User::factory()->create(['is_admin' => true, 'is_reviewer' => true]);
    $this->plain = User::factory()->count(2)->create();

    actingAs($this->admin);
});

/**
 * The request a tab switch sends. Named apart from ToolbarDriveTest's `partial` because
 * Pest loads every test file into the one process and a second global of that name would
 * be a fatal redeclare.
 */
function tabPartial(string $url, string $component = 'Manage/Users/Index'): array
{
    $response = get($url, [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) (new HandleInertiaRequests)->version(request()),
        'X-Inertia-Partial-Component' => $component,
        'X-Inertia-Partial-Data' => 'rows,meta,filters,sort,search',
    ]);

    $response->assertSuccessful();

    expect($response->json('component'))->toBe($component);

    return $response->json('props');
}

test('switching tabs changes the row set over the partial visit', function () {
    $all = tabPartial(route('admin.settings.users.index'));
    $admins = tabPartial(route('admin.settings.users.index', ['tab' => 'admins']));
    $reviewers = tabPartial(route('admin.settings.users.index', ['tab' => 'reviewers']));

    // 10 users: 5 admins, 4 reviewers, one of whom is both. 5 + 4 is not 10 and must not
    // be made to be: the tabs overlap by design.
    expect($all['meta']['total'])->toBe(10)
        ->and($admins['meta']['total'])->toBe(5)
        ->and($reviewers['meta']['total'])->toBe(4);

    // Not the same rows re-rendered under a different heading: the constraint is real.
    $adminIds = collect($admins['rows'])->pluck('id');
    $reviewerIds = collect($reviewers['rows'])->pluck('id');

    expect($adminIds)->toContain($this->admin->id)
        ->and($reviewerIds)->not->toContain($this->admin->id)
        // The overlap is exactly the one user holding both roles, which is the whole
        // reason the counts do not sum. Nothing here "fixes" that.
        ->and($adminIds->intersect($reviewerIds)->values()->all())->toBe([$this->both->id]);

    // Exact sets, not just the totals: a tab that returned the right count of the wrong
    // rows would pass everything above. Neither role tab may carry someone holding
    // neither role, and All has to carry all of them.
    $allIds = collect(tabPartial(route('admin.settings.users.index', ['per_page' => 100]))['rows'])->pluck('id');

    foreach ($this->plain as $plain) {
        expect($allIds)->toContain($plain->id)
            ->and($adminIds)->not->toContain($plain->id)
            ->and($reviewerIds)->not->toContain($plain->id);
    }

    expect($adminIds->sort()->values()->all())
        ->toBe(User::query()->where('is_admin', true)->orderBy('id')->pluck('id')->all())
        ->and($reviewerIds->sort()->values()->all())
        ->toBe(User::query()->where('is_reviewer', true)->orderBy('id')->pluck('id')->all());
});

test('the tab round-trips through the URL, and an unknown one falls back to the first', function () {
    // The full page load is what a shared link or a reload is, and it has to agree with
    // the partial visit above: same rows, and the strip told which view it is showing.
    get(route('admin.settings.users.index', ['tab' => 'reviewers']))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('meta.total', 4)
            ->where('tabs.0.key', 'all')
            ->where('tabs.0.active', false)
            ->where('tabs.2.key', 'reviewers')
            ->where('tabs.2.active', true)
            ->etc()
        );

    // Back and forward land on the same two URLs, so the same two URLs must keep meaning
    // the same thing however many times they are asked for.
    expect(tabPartial(route('admin.settings.users.index', ['tab' => 'reviewers']))['meta']['total'])->toBe(4)
        ->and(tabPartial(route('admin.settings.users.index', ['tab' => 'admins']))['meta']['total'])->toBe(5)
        ->and(tabPartial(route('admin.settings.users.index', ['tab' => 'reviewers']))['meta']['total'])->toBe(4);

    // A renamed, mistyped or stale key is not an error page and not an empty list: it is
    // the first declared tab, and TabBar resolves it the same way so the strip agrees.
    get(route('admin.settings.users.index', ['tab' => 'nonsense']))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('meta.total', 10)
            ->where('tabs.0.active', true)
            ->etc()
        );

    // The default view is the bare URL, not ?tab=all, but both have to work: the key is
    // hand-editable and the pager writes back whatever is in the query string.
    expect(tabPartial(route('admin.settings.users.index', ['tab' => 'all']))['meta']['total'])->toBe(10);
});

test('counts are of the tab, not of the current filters, and are declared per tab', function () {
    get(route('admin.settings.users.index', ['search' => 'nothing matches this']))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            // Search emptied the rows, and the strip still says how many users each view
            // holds. A count that moved with the search would be answering a question
            // nobody asked in the one place used to decide where to go next.
            ->where('meta.total', 0)
            ->where('tabs.0.count', 10)
            ->where('tabs.1.count', 5)
            ->where('tabs.2.count', 4)
            ->etc()
        );
});

test('a tab and a chip filter compose, and the tab is applied first', function () {
    // Users declares no filters, so composition is asserted on a table that declares both,
    // which is the generic contract the concept exists for. Mounted on the real panel
    // middleware and answered over the real partial visit; only the declaration is local.
    Route::middleware(['web', 'auth', 'can:access-manage'])
        ->get('/admin/tab-compose-fixture', fn (Request $request) => inertia(
            'Manage/Users/Index',
            Table::make(User::query())
                ->name('users')
                ->columns([Column::text('name', 'Name')->searchable()])
                ->tabs([
                    Tab::make('all', 'All'),
                    Tab::make('admins', 'Admins')->apply(fn (Builder $query) => $query->where('is_admin', true)),
                ])
                ->filters([
                    Filter::ternary('is_reviewer', 'Reviewer')
                        ->apply(fn (Builder $query, string $value) => $query->where('is_reviewer', $value === '1')),
                ])
                ->rows(fn (User $user) => ['name' => $user->name])
                ->toArray($request)
        ));

    $drive = fn (array $query) => tabPartial('/admin/tab-compose-fixture?'.http_build_query($query));

    // Each on its own.
    expect($drive([])['meta']['total'])->toBe(10)
        ->and($drive(['tab' => 'admins'])['meta']['total'])->toBe(5)
        ->and($drive(['filter' => ['is_reviewer' => '1']])['meta']['total'])->toBe(4);

    // Together they intersect rather than one replacing the other: the tab picks the view,
    // the chip narrows inside it, and the only admin who is also a reviewer is left.
    $composed = $drive(['tab' => 'admins', 'filter' => ['is_reviewer' => '1']]);

    expect($composed['meta']['total'])->toBe(1)
        ->and($composed['rows'][0]['id'])->toBe($this->both->id)
        // The chip comes back carrying its value, so switching tabs redraws the same chip
        // rather than silently dropping it. That is the documented reset rule.
        ->and($composed['filters'][0]['value'])->toBe('1');

    // The other side of the intersection, so this is not passing on one lucky ordering.
    expect($drive(['tab' => 'admins', 'filter' => ['is_reviewer' => '0']])['meta']['total'])->toBe(4);
});

test('sort, search, pagination and column visibility still work, and work inside a tab', function () {
    // The strip was added to the shared table layer, so the four things that layer already
    // did have to keep working both with a tab in the URL and without one. `created_at` is
    // the sortable column Users declares; name and email are searchable only.
    User::factory()
        ->count(30)
        ->sequence(fn ($sequence) => ['is_admin' => true, 'created_at' => now()->addDays($sequence->index + 1)])
        ->create();

    $newest = User::query()->orderByDesc('created_at')->first();
    $oldest = User::query()->orderBy('created_at')->first();

    $ascending = tabPartial(route('admin.settings.users.index', ['tab' => 'admins', 'sort' => 'created_at', 'dir' => 'asc']));
    $descending = tabPartial(route('admin.settings.users.index', ['tab' => 'admins', 'sort' => 'created_at', 'dir' => 'desc']));

    expect($ascending['sort'])->toBe(['key' => 'created_at', 'dir' => 'asc'])
        ->and($ascending['rows'][0]['id'])->toBe($oldest->id)
        ->and($descending['rows'][0]['id'])->toBe($newest->id);

    // Paging inside the tab pages the tab, not the whole table: 35 admins, not 40 users.
    $page2 = tabPartial(route('admin.settings.users.index', ['tab' => 'admins', 'per_page' => 25, 'page' => 2]));

    expect($page2['meta']['page'])->toBe(2)
        ->and($page2['meta']['total'])->toBe(35)
        ->and($page2['rows'])->toHaveCount(10);

    // Search narrows within the tab rather than escaping it, which is the same rule the
    // chip filters follow.
    expect(tabPartial(route('admin.settings.users.index', ['tab' => 'admins', 'search' => $newest->email]))['meta']['total'])->toBe(1)
        ->and(tabPartial(route('admin.settings.users.index', ['tab' => 'reviewers', 'search' => $newest->email]))['meta']['total'])->toBe(0);

    // Column visibility is a per-user preference stored server-side, and the strip did not
    // change where it is read from.
    post(route('admin.tables.columns', 'users'), ['hidden' => ['email']])->assertRedirect();

    get(route('admin.settings.users.index', ['tab' => 'admins']))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->where('hiddenColumns', ['email'])->etc());
});

test('a module that declares no tabs is untouched by any of this', function () {
    Machine::factory()->count(2)->create(['archived_at' => null]);

    // Not "an empty tabs array": no key at all. These envelopes are spread into page
    // props, and a prop a page never declared falls through to $attrs and warns, so the
    // fifteen other list modules have to receive exactly the props they received before.
    get(route('admin.machines.index'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->missing('tabs')->etc());

    // And a stray ?tab= on a tabless list narrows nothing, rather than erroring or
    // emptying the table.
    $props = tabPartial(route('admin.machines.index', ['tab' => 'admins']), 'Manage/Machines/Index');

    expect($props['meta']['total'])->toBe(2);

    // Users itself still has no filters, which is the other half of "unaffected": tabs
    // did not arrive by turning into a filter.
    expect(tabPartial(route('admin.settings.users.index'))['filters'])->toBe([]);
});

test('no list page other than Users is sent a tabs key', function () {
    // One module asserting its own absence proves nothing about the other fifteen: the
    // envelope is built in one shared place, so the sweep is the assertion. `missing`
    // rather than an empty array, because an undeclared prop falls through to $attrs on a
    // two-root page and warns - see Table::tabEnvelope.
    session([EventScope::SESSION_ID => Event::factory()->create()->id, EventScope::SESSION_CHOSEN => true]);

    $lists = [
        'admin.badges.index',
        'admin.checkouts.index',
        'admin.settings.events.index',
        'admin.fursuits.index',
        'admin.machines.index',
        'admin.print-batches.index',
        'admin.print-jobs.index',
        'admin.printers.index',
        // The three that draw no toolbar at all: nothing searchable, no filters, nothing
        // toggleable. They are the ones an accidental always-on strip would show up on
        // first, because they have no band for it to hide in.
        'admin.special-codes.index',
        'admin.sumup-readers.index',
        'admin.tse-clients.index',
        'admin.staff.index',
    ];

    foreach ($lists as $name) {
        expect(Route::has($name))->toBeTrue("route {$name} is gone");

        $response = get(route($name));

        expect($response->status())->toBe(200, "{$name} answered {$response->status()}");

        $response->assertInertia(fn (Assert $page) => $page->missing('tabs')->etc());
    }
});
