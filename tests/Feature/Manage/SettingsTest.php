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
    'general' => ['manage.settings.general', 'Manage/Settings/General'],
    'on-site desk' => ['manage.settings.on-site-desk', 'Manage/Settings/OnSiteDesk'],
    'printing' => ['manage.settings.printing', 'Manage/Settings/Printing'],
    'badges' => ['manage.settings.badges', 'Manage/Settings/Badges'],
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
    expect(route('manage.settings.general', absolute: false))->toBe('/admin/settings')
        ->and(route('manage.settings.on-site-desk', absolute: false))->toBe('/admin/settings/on-site-desk')
        ->and(route('manage.settings.printing', absolute: false))->toBe('/admin/settings/printing')
        ->and(route('manage.settings.badges', absolute: false))->toBe('/admin/settings/badges');
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
})->with('settings panes');

test('a guest is pushed into the SSO flow rather than shown a settings page', function (string $name) {
    get(route($name))->assertRedirect(route('login'));
})->with('settings panes');

test('a signed-in attendee is refused every pane', function (string $name) {
    actingAs($this->attendee);

    withSession($this->session)->get(route($name))->assertForbidden();
})->with('settings panes');

test('a reviewer may read every pane but is not offered the writes', function (string $name, string $component) {
    // Same split as every other configuration surface: `access-manage`
    // opens the page, `manage-admin` decides whether anything on it can be saved.
    actingAs($this->reviewer);

    expect(Gate::forUser($this->reviewer)->allows('access-manage'))->toBeTrue()
        ->and(Gate::forUser($this->reviewer)->allows('manage-admin'))->toBeFalse();

    withSession($this->session)
        ->get(route($name))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component($component)
            ->where('canEdit', false)
        );
})->with('settings panes');

test('Settings appears once in the rail and points at the General pane', function () {
    actingAs($this->admin);

    $nav = withSession($this->session)
        ->get(route('manage.settings.general'))
        ->viewData('page')['props']['manageNav'];

    $items = collect($nav)
        ->flatMap(fn (array $group) => $group['items'])
        ->where('route', 'manage.settings.general')
        ->values();

    expect($items)->toHaveCount(1)
        ->and($items[0]['label'])->toBe('Settings')
        ->and($items[0]['url'])->toBe(route('manage.settings.general'));

    // The three other panes are reached from the in-page submenu, not from the rail.
    $railRoutes = collect($nav)->flatMap(fn (array $group) => $group['items'])->pluck('route');

    expect($railRoutes)->not->toContain('manage.settings.on-site-desk')
        ->and($railRoutes)->not->toContain('manage.settings.printing')
        ->and($railRoutes)->not->toContain('manage.settings.badges');
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
            'manage.settings.general',
            'manage.settings.on-site-desk',
            'manage.settings.printing',
            'manage.settings.badges',
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

test('Settings writes only the two columns the Events form does not own', function () {
    /*
     * The overlap guard, asserted structurally rather than by reading the forms. The Events
     * module owns badge_class, cost and the seven date fields; Settings writes exactly
     * three routes, all of them On-Site Desk, and between them they touch only
     * `desk_opening_hours` and `pickup_booths`. A write appearing on any other pane, or a
     * fourth write here, is a second editor for a field Events already owns until someone
     * proves otherwise, and turns this red.
     */
    $settingsWrites = collect(Route::getRoutes()->getRoutesByName())
        ->filter(fn ($route, string $name) => str_starts_with($name, 'manage.settings.'))
        ->reject(fn ($route) => $route->methods() === ['GET', 'HEAD']);

    expect($settingsWrites->keys()->all())->toBe([
        'manage.settings.on-site-desk.hours',
        'manage.settings.on-site-desk.booths',
        'manage.settings.on-site-desk.booths.reset',
    ]);

    // Every one of them is admin-gated, both at the route and inside the method.
    $settingsWrites->each(function ($route) {
        expect($route->gatherMiddleware())->toContain('can:manage-admin');
    });

    // And no Settings route may reach the controller that owns the event record.
    $eventEditors = collect(Route::getRoutes()->getRoutesByName())
        ->filter(fn ($route, string $name) => str_starts_with($name, 'manage.settings.'))
        ->filter(fn ($route) => str_contains((string) ($route->getAction('controller') ?? ''), 'EventController'));

    expect($eventEditors->keys()->all())->toBe([]);
});
