<?php

/*
 * Dashboard (plan phase 9, audit 6).
 *
 * The three Filament widgets become one page: four stats, the badge-status doughnut and
 * the event-comparison bars, all of them scoped to the header's event selection and all
 * of them shaped server-side, so every string, colour and tone in the audit is assertable
 * without rendering a canvas.
 *
 * Four things beyond plain parity are pinned here:
 *
 *  - `No previous event` means there is no previous event, not that the diff is zero
 *    (audit 115);
 *  - the doughnut's labels read `Paid / Ready for Pickup`, and every payment x
 *    fulfillment combination gets its own stable colour, past the five the ramp had
 *    (audit 114);
 *  - the grouped count is portable. The test database is SQLite and production is MySQL,
 *    so the one query is executed here and compiled under both grammars (audit 18);
 *  - rendering and polling write nothing. The page is four counts and a GROUP BY, and it
 *    reloads itself every 15 seconds, so a stray write would repeat forever.
 */

use App\Http\Controllers\Manage\DashboardController;
use App\Models\Badge\Badge;
use App\Models\Badge\State_Fulfillment\PickedUp;
use App\Models\Badge\State_Fulfillment\Printed;
use App\Models\Badge\State_Fulfillment\Processing;
use App\Models\Badge\State_Fulfillment\ReadyForPickup;
use App\Models\Badge\State_Payment\Paid;
use App\Models\Badge\State_Payment\Unpaid;
use App\Models\Event;
use App\Models\Fursuit\Fursuit;
use App\Models\Fursuit\States\Approved;
use App\Models\Fursuit\States\Pending;
use App\Models\Fursuit\States\Rejected;
use App\Models\Species;
use App\Models\User;
use App\Support\Manage\EventScope;
use App\Support\Manage\Status;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    Storage::fake('s3');

    $this->admin = User::factory()->create(['is_admin' => true, 'is_reviewer' => false]);
    $this->species = Species::create(['name' => 'Wolf']);

    // Oldest first, so "previous" cannot pass through insertion order.
    $this->older = Event::factory()->create([
        'name' => 'Eurofurence 28',
        'starts_at' => now()->subYear(),
        'ends_at' => now()->subYear()->addDays(5),
        'order_starts_at' => now()->subYear()->subDays(30),
        'order_ends_at' => now()->subYear()->subDays(10),
    ]);

    $this->newer = Event::factory()->create([
        'name' => 'Eurofurence 29',
        'starts_at' => now()->addDays(30),
        'ends_at' => now()->addDays(35),
        'order_starts_at' => now()->subDay(),
        'order_ends_at' => now()->addDays(20),
    ]);

    $this->fursuit = fn (Event $event, array $attributes = []) => Fursuit::factory()->create([
        'event_id' => $event->id,
        'species_id' => $this->species->id,
        'status' => Approved::$name,
        ...$attributes,
    ]);

    /** A badge on a given event, in a given payment x fulfillment combination. */
    $this->badge = fn (Event $event, string $payment, string $fulfillment) => Badge::factory()->create([
        'fursuit_id' => ($this->fursuit)($event)->id,
        'extra_copy_of' => null,
        'status_payment' => $payment,
        'status_fulfillment' => $fulfillment,
    ]);

    $this->scoped = fn (?int $eventId) => actingAs($this->admin)->withSession([
        EventScope::SESSION_ID => $eventId,
        EventScope::SESSION_CHOSEN => true,
    ]);

    /** The four stats of one visit, keyed for readability. */
    $this->stats = function (?int $eventId) {
        $stats = ($this->scoped)($eventId)->get(route('manage.dashboard'))
            ->assertSuccessful()
            ->viewData('page')['props']['stats'];

        return collect($stats)->keyBy('key')->all();
    };

    $this->charts = fn (?int $eventId) => ($this->scoped)($eventId)->get(route('manage.dashboard'))
        ->assertSuccessful()
        ->viewData('page')['props']['charts'];
});

/*
|--------------------------------------------------------------------------
| Shell
|--------------------------------------------------------------------------
*/

test('the dashboard renders the four stats and both charts as top-level props', function () {
    ($this->scoped)($this->newer->id)->get(route('manage.dashboard'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Manage/Dashboard')
            ->count('stats', 4)
            // Audit order: Current Event, badges, fursuits, Pending Approval.
            ->where('stats.0.label', 'Current Event')
            ->where('stats.1.label', 'Current Event Badges')
            ->where('stats.2.label', 'Current Event Fursuits')
            ->where('stats.3.label', 'Pending Approval')
            ->has('charts.badgeStatus')
            ->has('charts.eventComparison')
        );
});

test('the poll asks for stats and charts only, and gets them', function () {
    $visit = ($this->scoped)($this->newer->id);

    // The first paint, which is also what settles the asset version the poll has to send.
    $visit->get(route('manage.dashboard'))->assertSuccessful();

    // What usePoll(15000, { only: ['stats', 'charts'] }) actually sends.
    $response = $visit
        ->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => Inertia\Inertia::getVersion(),
            'X-Inertia-Partial-Data' => 'stats,charts',
            'X-Inertia-Partial-Component' => 'Manage/Dashboard',
        ])
        ->get(route('manage.dashboard'))
        ->assertSuccessful();

    $props = $response->json('props');

    expect($props)->toHaveKeys(['stats', 'charts'])
        ->and($props['stats'])->toHaveCount(4)
        ->and($props['charts'])->toHaveKeys(['badgeStatus', 'eventComparison']);
});

/*
|--------------------------------------------------------------------------
| Stat 1: Current Event
|--------------------------------------------------------------------------
*/

test('stat 1 reports the selected event and its open order window', function () {
    $stat = ($this->stats)($this->newer->id)['current-event'];

    expect($stat['label'])->toBe('Current Event')
        ->and($stat['value'])->toBe('Eurofurence 29')
        ->and($stat['description'])->toBe('Orders Open')
        ->and($stat['icon'])->toBe('circle-check')
        ->and($stat['tone'])->toBe(Status::OK);
});

test('stat 1 reports a closed order window', function () {
    $stat = ($this->stats)($this->older->id)['current-event'];

    expect($stat['value'])->toBe('Eurofurence 28')
        ->and($stat['description'])->toBe('Orders Closed')
        ->and($stat['icon'])->toBe('circle-x')
        ->and($stat['tone'])->toBe(Status::DANGER);
});

test('stat 1 keeps the No Event copy for an empty events table', function () {
    Event::query()->delete();

    $stat = ($this->stats)(null)['current-event'];

    expect($stat['value'])->toBe('No Event')
        ->and($stat['description'])->toBe('No current event')
        ->and($stat['icon'])->toBe('circle-x')
        ->and($stat['tone'])->toBe(Status::DANGER);
});

test('stat 1 says all events rather than claiming an order window nobody selected', function () {
    /*
     * "All events" is reachable now (plan 2.9) and is not the same thing as having no
     * events, so it does not borrow the empty-table copy and it does not report an order
     * window: no single event owns one.
     */
    $stat = ($this->stats)(null)['current-event'];

    expect($stat['value'])->toBe('All events')
        ->and($stat['description'])->toBe('Not scoped to one event')
        ->and($stat['tone'])->toBe(Status::IDLE);
});

/*
|--------------------------------------------------------------------------
| Stats 2 and 3: the counts and their comparison
|--------------------------------------------------------------------------
*/

test('stat 2 counts the selected event badges through the fursuit and compares upward', function () {
    ($this->badge)($this->newer, Paid::$name, ReadyForPickup::$name);
    ($this->badge)($this->newer, Paid::$name, ReadyForPickup::$name);
    ($this->badge)($this->newer, Unpaid::$name, Pending::$name);
    ($this->badge)($this->older, Paid::$name, PickedUp::$name);

    $stat = ($this->stats)($this->newer->id)['current-event-badges'];

    expect($stat['label'])->toBe('Current Event Badges')
        ->and($stat['value'])->toBe(3)
        ->and($stat['description'])->toBe('+2 vs Eurofurence 28')
        ->and($stat['icon'])->toBe('trending-up')
        ->and($stat['tone'])->toBe(Status::OK);
});

test('stat 3 counts fursuits and compares downward', function () {
    ($this->fursuit)($this->newer);
    ($this->fursuit)($this->older);
    ($this->fursuit)($this->older);
    ($this->fursuit)($this->older);

    $stat = ($this->stats)($this->newer->id)['current-event-fursuits'];

    expect($stat['label'])->toBe('Current Event Fursuits')
        ->and($stat['value'])->toBe(1)
        ->and($stat['description'])->toBe('-2 vs Eurofurence 28')
        ->and($stat['icon'])->toBe('trending-down')
        ->and($stat['tone'])->toBe(Status::DANGER);
});

test('an equal count against a real previous event no longer claims there is none', function () {
    // audit 115. Two events with the same count reported that the older one did not exist.
    ($this->fursuit)($this->newer);
    ($this->fursuit)($this->older);

    $stat = ($this->stats)($this->newer->id)['current-event-fursuits'];

    expect($stat['description'])->toBe('0 vs Eurofurence 28')
        ->and($stat['icon'])->toBe('minus')
        ->and($stat['tone'])->toBe(Status::IDLE);
});

test('No previous event is shown when the selected event really is the first one', function () {
    ($this->fursuit)($this->older);

    $stat = ($this->stats)($this->older->id)['current-event-fursuits'];

    expect($stat['description'])->toBe('No previous event')
        ->and($stat['icon'])->toBe('minus')
        ->and($stat['tone'])->toBe(Status::IDLE);
});

/*
|--------------------------------------------------------------------------
| Stat 4: Pending Approval
|--------------------------------------------------------------------------
*/

test('stat 4 counts pending fursuits, warns above zero and links to the fursuits list', function () {
    ($this->fursuit)($this->newer, ['status' => Pending::$name]);
    ($this->fursuit)($this->newer, ['status' => Pending::$name]);
    ($this->fursuit)($this->newer, ['status' => Approved::$name]);
    ($this->fursuit)($this->newer, ['status' => Rejected::$name]);

    $stat = ($this->stats)($this->newer->id)['pending-approval'];

    expect($stat['label'])->toBe('Pending Approval')
        ->and($stat['value'])->toBe(2)
        ->and($stat['description'])->toBe('Awaiting review')
        ->and($stat['icon'])->toBe('clock')
        ->and($stat['tone'])->toBe(Status::WARN)
        ->and($stat['url'])->toBe(route('manage.fursuits.index'));
});

test('stat 4 is success at zero and never counts another event', function () {
    ($this->fursuit)($this->older, ['status' => Pending::$name]);

    $stat = ($this->stats)($this->newer->id)['pending-approval'];

    expect($stat['value'])->toBe(0)
        ->and($stat['tone'])->toBe(Status::OK);
});

test('the pending count goes through whereState, not the raw column string', function () {
    /*
     * plan 2.10 #29. The widget compared `status` with the literal 'pending'; a state
     * whose $name changed would silently count zero. whereState resolves the class.
     */
    ($this->fursuit)($this->newer, ['status' => Pending::class]);

    expect(($this->stats)($this->newer->id)['pending-approval']['value'])->toBe(1);
});

/*
|--------------------------------------------------------------------------
| BadgeStatusChart
|--------------------------------------------------------------------------
*/

test('the doughnut carries its heading, type and bottom legend', function () {
    $chart = ($this->charts)($this->newer->id)['badgeStatus'];

    expect($chart['heading'])->toBe('Current Event Badge Status')
        ->and($chart['type'])->toBe('doughnut')
        ->and($chart['options'])->toBe([
            'plugins' => [
                'legend' => [
                    'display' => true,
                    'position' => 'bottom',
                ],
            ],
        ]);
});

test('the doughnut groups badges by payment and fulfillment with readable labels', function () {
    // audit 114: the widget built labels out of the raw column, so this read
    // `Paid / Readyforpickup`.
    ($this->badge)($this->newer, Paid::$name, ReadyForPickup::$name);
    ($this->badge)($this->newer, Paid::$name, ReadyForPickup::$name);
    ($this->badge)($this->newer, Unpaid::$name, Pending::$name);
    ($this->badge)($this->older, Paid::$name, PickedUp::$name);

    $chart = ($this->charts)($this->newer->id)['badgeStatus'];

    expect($chart['labels'])->toBe(['Paid / Ready for Pickup', 'Unpaid / Pending'])
        ->and($chart['datasets'][0]['data'])->toBe([2, 1]);
});

test('every payment and fulfillment combination gets its own stable colour', function () {
    /*
     * audit 114. The ramp held five colours and array_slice handed out fewer than there
     * were segments once a real event produced more, so chart.js drew undefined
     * segments; the mapping also moved with the grouping order. Six combinations here,
     * one more than the ramp had.
     */
    ($this->badge)($this->newer, Paid::$name, Pending::$name);
    ($this->badge)($this->newer, Paid::$name, Processing::$name);
    ($this->badge)($this->newer, Paid::$name, Printed::$name);
    ($this->badge)($this->newer, Paid::$name, ReadyForPickup::$name);
    ($this->badge)($this->newer, Paid::$name, PickedUp::$name);
    ($this->badge)($this->newer, Unpaid::$name, Pending::$name);

    $chart = ($this->charts)($this->newer->id)['badgeStatus'];
    $colors = $chart['datasets'][0]['backgroundColor'];

    expect($chart['labels'])->toHaveCount(6)
        ->and($colors)->toHaveCount(6)
        ->and(array_unique($colors))->toHaveCount(6)
        ->and($colors)->each->toStartWith('rgb');

    // Stable: a second render returns the same labels against the same colours.
    $again = ($this->charts)($this->newer->id)['badgeStatus'];

    expect($again['labels'])->toBe($chart['labels'])
        ->and($again['datasets'][0]['backgroundColor'])->toBe($colors);
});

test('the doughnut falls back to one grey No Active Event segment when there are no events', function () {
    Event::query()->delete();

    $chart = ($this->charts)(null)['badgeStatus'];

    expect($chart['labels'])->toBe(['No Active Event'])
        ->and($chart['datasets'])->toBe([
            [
                'data' => [0],
                'backgroundColor' => ['rgb(156, 163, 175)'],
            ],
        ]);
});

/*
|--------------------------------------------------------------------------
| EventComparisonChart
|--------------------------------------------------------------------------
*/

test('the bars compare the selected event with the one before it', function () {
    ($this->badge)($this->newer, Paid::$name, ReadyForPickup::$name);
    ($this->badge)($this->newer, Paid::$name, ReadyForPickup::$name);
    ($this->fursuit)($this->newer);
    ($this->badge)($this->older, Paid::$name, PickedUp::$name);

    $chart = ($this->charts)($this->newer->id)['eventComparison'];

    expect($chart['heading'])->toBe('Event Comparison')
        ->and($chart['type'])->toBe('bar')
        ->and($chart['labels'])->toBe(['Badges', 'Fursuits'])
        ->and($chart['options'])->toBe([
            'plugins' => ['legend' => ['display' => true]],
            'scales' => ['y' => ['beginAtZero' => true]],
        ])
        ->and($chart['datasets'])->toBe([
            [
                'label' => 'Eurofurence 29',
                // Two badges on two fursuits, plus the bare fursuit.
                'data' => [2, 3],
                'backgroundColor' => 'rgba(59, 130, 246, 0.8)',
            ],
            [
                'label' => 'Eurofurence 28',
                'data' => [1, 1],
                'backgroundColor' => 'rgba(16, 185, 129, 0.8)',
            ],
        ]);
});

test('the bars drop the second dataset when there is no previous event', function () {
    $chart = ($this->charts)($this->older->id)['eventComparison'];

    expect($chart['datasets'])->toHaveCount(1)
        ->and($chart['datasets'][0]['label'])->toBe('Eurofurence 28');
});

test('the bars fall back to a grey No Events dataset when there are no events', function () {
    Event::query()->delete();

    $chart = ($this->charts)(null)['eventComparison'];

    expect($chart['labels'])->toBe(['Badges', 'Fursuits'])
        ->and($chart['datasets'])->toBe([
            [
                'label' => 'No Events',
                'data' => [0, 0],
                'backgroundColor' => 'rgba(156, 163, 175, 0.8)',
            ],
        ]);
});

/*
|--------------------------------------------------------------------------
| Event scope
|--------------------------------------------------------------------------
*/

test('the dashboard narrows on the selected event and widens on all events', function () {
    ($this->badge)($this->newer, Paid::$name, ReadyForPickup::$name);
    ($this->badge)($this->older, Unpaid::$name, Pending::$name);
    ($this->fursuit)($this->older, ['status' => Pending::$name]);

    $narrowed = ($this->stats)($this->newer->id);

    expect($narrowed['current-event-badges']['value'])->toBe(1)
        ->and($narrowed['current-event-fursuits']['value'])->toBe(1)
        ->and($narrowed['pending-approval']['value'])->toBe(0);

    $widened = ($this->stats)(null);

    // Both events: two badges, three fursuits, and the one pending fursuit is visible.
    expect($widened['current-event-badges']['value'])->toBe(2)
        ->and($widened['current-event-fursuits']['value'])->toBe(3)
        ->and($widened['pending-approval']['value'])->toBe(1);
});

test('all events draws one doughnut over every event and one labelled bar', function () {
    ($this->badge)($this->newer, Paid::$name, ReadyForPickup::$name);
    ($this->badge)($this->older, Paid::$name, ReadyForPickup::$name);

    $charts = ($this->charts)(null);

    expect($charts['badgeStatus']['labels'])->toBe(['Paid / Ready for Pickup'])
        ->and($charts['badgeStatus']['datasets'][0]['data'])->toBe([2])
        // Nothing to compare all events against, so there is one dataset and it says so.
        ->and($charts['eventComparison']['datasets'])->toHaveCount(1)
        ->and($charts['eventComparison']['datasets'][0]['label'])->toBe('All events')
        ->and($charts['eventComparison']['datasets'][0]['data'])->toBe([2, 2]);
});

/*
|--------------------------------------------------------------------------
| Portability
|--------------------------------------------------------------------------
*/

test('the grouped badge count runs on SQLite, which is what the tests and the default dev database use', function () {
    ($this->badge)($this->newer, Paid::$name, ReadyForPickup::$name);
    ($this->badge)($this->newer, Paid::$name, ReadyForPickup::$name);
    ($this->badge)($this->newer, Unpaid::$name, Pending::$name);

    expect(DB::connection()->getDriverName())->toBe('sqlite');

    $rows = DashboardController::badgeStatusCountQuery(Badge::query())->get();

    expect($rows)->toHaveCount(2)
        ->and(collect($rows)->pluck('badge_count')->sort()->values()->all())->toBe([1, 2]);
});

test('the grouped badge count compiles to portable SQL under both grammars', function () {
    // Building a query on the mysql connection compiles under its grammar without ever
    // opening a PDO handle, which is the only way to check MySQL from an SQLite suite.
    $sqlite = DashboardController::badgeStatusCountQuery(Badge::query())->toSql();
    $mysql = DashboardController::badgeStatusCountQuery(Badge::on('mysql'))->toSql();

    foreach ([$sqlite, $mysql] as $sql) {
        // ANSI COUNT(*), and an alias that is not the bare `count` the widget used
        // unquoted (audit 18).
        expect($sql)->toContain('COUNT(*) as badge_count')
            ->and($sql)->not->toContain(' as count ')
            // The MySQL-only constructs the audit found elsewhere in the panel.
            ->and(strtoupper($sql))->not->toContain('CAST(')
            ->and(strtoupper($sql))->not->toContain('UNSIGNED')
            ->and(strtoupper($sql))->not->toContain('SUBSTRING_INDEX');
    }

    // Each grammar quotes the grouped columns its own way; neither is hand-written.
    expect($sqlite)->toContain('group by "status_payment", "status_fulfillment"')
        ->and($mysql)->toContain('group by `status_payment`, `status_fulfillment`');
});

/*
|--------------------------------------------------------------------------
| Read safety
|--------------------------------------------------------------------------
*/

test('rendering the dashboard and polling it twice writes nothing', function () {
    /*
     * The page reloads itself every 15 seconds for as long as a tab is open, so a write
     * hidden in a stat would not happen once, it would happen four times a minute per
     * operator, forever.
     */
    ($this->badge)($this->newer, Paid::$name, ReadyForPickup::$name);
    ($this->badge)($this->older, Unpaid::$name, Pending::$name);
    ($this->fursuit)($this->newer, ['status' => Pending::$name]);

    $snapshot = fn () => [
        'badges' => DB::table('badges')->orderBy('id')->get(['id', 'status_payment', 'status_fulfillment', 'printed_at', 'updated_at'])->map(fn ($row) => (array) $row)->all(),
        'fursuits' => DB::table('fursuits')->orderBy('id')->get(['id', 'status', 'updated_at'])->map(fn ($row) => (array) $row)->all(),
        'events' => DB::table('events')->orderBy('id')->get(['id', 'name', 'starts_at', 'updated_at'])->map(fn ($row) => (array) $row)->all(),
    ];

    $before = $snapshot();

    $visit = ($this->scoped)($this->newer->id);

    $visit->get(route('manage.dashboard'))->assertSuccessful();

    $visit->withHeaders([
        'X-Inertia' => 'true',
        'X-Inertia-Version' => Inertia\Inertia::getVersion(),
        'X-Inertia-Partial-Data' => 'stats,charts',
        'X-Inertia-Partial-Component' => 'Manage/Dashboard',
    ]);

    $visit->get(route('manage.dashboard'))->assertSuccessful();
    $visit->get(route('manage.dashboard'))->assertSuccessful();

    expect($snapshot())->toEqual($before);
});

test('the dashboard URL answers no write verb of its own', function () {
    // POST /admin/event is the panel's one session write and it lives on its own path.
    actingAs($this->admin);

    foreach (['post', 'put', 'patch', 'delete'] as $verb) {
        expect($this->{$verb}('/admin')->getStatusCode())->toBeIn([403, 404, 405]);
    }
});
