<?php

/*
 * Settings, the configuration screen with its own vertical submenu.
 *
 * Four things this locks in:
 *
 *  - each pane is a real URL, so it is linkable and back-button-able rather than tab state
 *    that vanishes on reload, and /admin/settings itself is the General pane;
 *  - the panel's two gates apply the way they do everywhere else: a guest is pushed into
 *    SSO, an attendee is refused, a reviewer may read, and `canEdit` is the `manage-admin`
 *    answer rather than a second, wider rule;
 *  - reading a pane writes nothing, which matters more here than on a list page because a
 *    settings screen is exactly where a "seed the defaults on first view" shortcut would
 *    look reasonable;
 *  - no pane duplicates a field the Events form owns. That is asserted structurally, by
 *    pinning the exact list of write routes Settings registers, so a duplicate cannot
 *    appear later without this test going red.
 */

use App\Models\Event;
use App\Models\User;
use App\Support\Manage\EventScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\withSession;

/** The pane URLs, with the component each one must render. */
dataset('settings panes', [
    'general' => ['admin.settings.general', 'Manage/Settings/General'],
    'on-site desk' => ['admin.settings.on-site-desk', 'Manage/Settings/OnSiteDesk'],
    'review reasons' => ['admin.settings.review-reasons', 'Manage/Settings/ReviewReasons'],
]);

/**
 * The panes that configure something belonging to one event, and therefore carry an `event` prop
 * of their own. Review Reasons is not one of them: the wording is the same at every event, so it
 * declares no event context and the globally shared prop is all there is on that page.
 */
dataset('event-scoped settings panes', [
    'general' => ['admin.settings.general', 'Manage/Settings/General'],
    'on-site desk' => ['admin.settings.on-site-desk', 'Manage/Settings/OnSiteDesk'],
]);

beforeEach(function () {
    $this->event = Event::factory()->create([
        'name' => 'Eurofurence 30',
        'starts_at' => now()->addDays(30),
        'ends_at' => now()->addDays(35),
        'order_starts_at' => now()->subDay(),
        'order_ends_at' => now()->addDays(20),
    ]);

    $this->admin = User::factory()->create(['is_admin' => true, 'is_reviewer' => false]);
    $this->reviewer = User::factory()->create(['is_admin' => false, 'is_reviewer' => true]);
    $this->attendee = User::factory()->create(['is_admin' => false, 'is_reviewer' => false]);

    $this->session = [
        EventScope::SESSION_ID => $this->event->id,
        EventScope::SESSION_CHOSEN => true,
    ];
});

test('every pane is its own URL under /admin/settings', function () {
    expect(route('admin.settings.general', absolute: false))->toBe('/admin/settings')
        ->and(route('admin.settings.on-site-desk', absolute: false))->toBe('/admin/settings/on-site-desk')
        ->and(route('admin.settings.review-reasons', absolute: false))->toBe('/admin/settings/review-reasons');
});

test('a pane renders its own component for an admin', function (string $name, string $component) {
    actingAs($this->admin);

    withSession($this->session)
        ->get(route($name))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component($component)
            ->where('event.name', 'Eurofurence 30')
            ->where('canEdit', true)
        );
})->with('settings panes');

test('/admin/settings is the General pane rather than a redirect', function () {
    // A redirect would put a hop between the rail item and the first pane; General is a
    // real pane, so the bare URL renders it.
    actingAs($this->admin);

    withSession($this->session)
        ->get('/admin/settings')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->component('Manage/Settings/General'));
});

test('the panes still render with no event selected', function (string $name, string $component) {
    // "All events" is a reachable selection (EventScope), and a settings screen that 500s
    // on it would be a dead end reachable from the header selector.
    actingAs($this->admin);

    withSession([EventScope::SESSION_ID => null, EventScope::SESSION_CHOSEN => true])
        ->get(route($name))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component($component)
            ->where('event', null)
        );
})->with('event-scoped settings panes');

test('a guest is pushed into the SSO flow rather than shown a settings page', function (string $name) {
    get(route($name))->assertRedirect(route('login'));
})->with('settings panes');

test('a signed-in attendee is refused every pane', function (string $name) {
    actingAs($this->attendee);

    withSession($this->session)->get(route($name))->assertForbidden();
})->with('settings panes');

test('a reviewer is refused every pane', function (string $name) {
    /*
     * Reviewers could read every pane at cutover, on the reasoning that looking at how the
     * convention is configured harms nothing. They were then narrowed to Dashboard, Badges
     * and Fursuits, and Settings is not one of the three - review-reasons in particular
     * configures the queue rather than being part of working it. Holding `access-manage`
     * is no longer enough for anything here. See docs/admin/roles.md.
     */
    actingAs($this->reviewer);

    expect(Gate::forUser($this->reviewer)->allows('access-manage'))->toBeTrue()
        ->and(Gate::forUser($this->reviewer)->allows('manage-admin'))->toBeFalse();

    withSession($this->session)->get(route($name))->assertForbidden();
})->with('settings panes');

test('Settings appears once in the rail and points at the General pane', function () {
    actingAs($this->admin);

    $nav = withSession($this->session)
        ->get(route('admin.settings.general'))
        ->viewData('page')['props']['manageNav'];

    $items = collect($nav)
        ->flatMap(fn (array $group) => $group['items'])
        ->where('route', 'admin.settings.general')
        ->values();

    expect($items)->toHaveCount(1)
        ->and($items[0]['label'])->toBe('Settings')
        ->and($items[0]['url'])->toBe(route('admin.settings.general'));

    // The other panes are reached from the in-page submenu, not from the rail.
    $railRoutes = collect($nav)->flatMap(fn (array $group) => $group['items'])->pluck('route');

    expect($railRoutes)->not->toContain('admin.settings.on-site-desk')
        ->and($railRoutes)->not->toContain('admin.settings.review-reasons')
        // Events moved in beside them and left the rail with them.
        ->and($railRoutes)->not->toContain('admin.settings.events.index');
});

test('the submenu is built server-side and lists every pane for an admin', function () {
    /*
     * The submenu used to be a constant in SettingsLayout.vue. It is server-side because
     * the panes are policy-gated, and it stays server-side now that the whole of Settings
     * is admin-only: the filtering still runs, it just has nobody left to filter for. The
     * reviewer half of this assertion moved to ReviewerScopeTest, where a reviewer never
     * reaches the page the submenu is attached to.
     */
    $panes = fn (User $user) => collect(
        actingAs($user)
            ->withSession($this->session)
            ->get(route('admin.settings.general'))
            ->viewData('page')['props']['manageSettingsNav']
    )->pluck('key');

    expect($panes($this->admin)->all())
        ->toBe(['general', 'events', 'on-site-desk', 'review-reasons', 'users']);
});

test('reading every pane writes nothing', function () {
    /*
     * `events` is the table Settings stores into, so it is the one a stray write would show
     * up in; `event_users` and `badges` are the two the panes read around. Every column of
     * every row, not a count, because the interesting failure is a value changing in place
     * rather than a row appearing.
     */
    $snapshot = fn () => collect(['events', 'event_users', 'badges'])
        ->mapWithKeys(fn (string $table) => [
            $table => DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
        ])
        ->all();

    $before = $snapshot();

    actingAs($this->admin);

    // Twice each, and once more with no event selected: a first paint, a reload, and the
    // branch where there is no row to write to at all.
    foreach ([$this->session, $this->session, [EventScope::SESSION_ID => null, EventScope::SESSION_CHOSEN => true]] as $session) {
        foreach ([
            'admin.settings.general',
            'admin.settings.on-site-desk',
            'admin.settings.review-reasons',
        ] as $name) {
            withSession($session)->get(route($name))->assertSuccessful();
        }
    }

    expect($snapshot())->toEqual($before);

    // Spelled out, so a failure names the thing that moved rather than dumping a diff.
    expect($this->event->fresh()->pickup_booths)->toBeNull()
        ->and($this->event->fresh()->badge_class)->toBe($this->event->badge_class)
        ->and(Event::count())->toBe(1);
});

test('Settings writes only its own records, never a column Events owns', function () {
    /*
     * The overlap guard, asserted structurally rather than by reading the forms. The Events
     * module owns badge_class, cost and the seven date fields, and it now sits under
     * /admin/settings/events, so it is inside this name prefix rather than beside it; Users
     * moved in the same way and owns the `users` table. Every other Settings write must
     * still be one of its own: On-Site Desk touches `desk_opening_hours` and `pickup_booths`,
     * and Review Reasons owns its own table. A write appearing on any other pane is a second
     * editor for a field somebody else owns until proven otherwise, and turns this red.
     */
    $settingsWrites = collect(Route::getRoutes()->getRoutesByName())
        ->filter(fn ($route, string $name) => str_starts_with($name, 'admin.settings.'))
        ->reject(fn ($route) => $route->methods() === ['GET', 'HEAD']);

    expect($settingsWrites->keys()->all())->toBe([
        'admin.settings.events.store',
        'admin.settings.events.bulk.destroy',
        'admin.settings.events.update',
        'admin.settings.events.destroy',
        'admin.settings.on-site-desk.hours',
        'admin.settings.on-site-desk.booths',
        'admin.settings.on-site-desk.booths.reset',
        'admin.settings.review-reasons.store',
        'admin.settings.review-reasons.update',
        'admin.settings.review-reasons.destroy',
        'admin.settings.review-reasons.restore-defaults',
        'admin.settings.users.store',
        'admin.settings.users.bulk.destroy',
        'admin.settings.users.update',
        'admin.settings.users.destroy',
    ]);

    /*
     * The pane writes are admin-gated by middleware, both at the route and inside the method.
     * Events and Users are gated by their policies instead - EventPolicy in EventRequest and
     * the controller, UserPolicy in every UserController method - which is a stricter answer
     * than `manage-admin` (`is_admin`, not the panel gate) and is asserted over the HTTP
     * boundary in EventsTest and UsersTest. Splitting them here rather than loosening the
     * assertion keeps the middleware requirement real for everything that has one.
     */
    [$moduleWrites, $paneWrites] = $settingsWrites->partition(
        fn ($route, string $name) => str_starts_with($name, 'admin.settings.events.')
            || str_starts_with($name, 'admin.settings.users.')
    );

    $eventWrites = $moduleWrites->filter(
        fn ($route, string $name) => str_starts_with($name, 'admin.settings.events.')
    );

    $paneWrites->each(function ($route) {
        expect($route->gatherMiddleware())->toContain('can:manage-admin');
    });

    // And only the Events module may reach the controller that owns the event record.
    $eventEditors = collect(Route::getRoutes()->getRoutesByName())
        ->filter(fn ($route, string $name) => str_starts_with($name, 'admin.settings.'))
        ->filter(fn ($route) => str_contains((string) ($route->getAction('controller') ?? ''), 'EventController'));

    expect($eventEditors->keys()->every(fn (string $name) => str_starts_with($name, 'admin.settings.events.')))
        ->toBeTrue()
        ->and($eventWrites->keys()->all())->toBe($eventEditors->reject(
            fn ($route) => $route->methods() === ['GET', 'HEAD']
        )->keys()->all());
});
