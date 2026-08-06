<?php

/*
 * The top strip's counts (plan 1.2 and 2.8).
 *
 * Two things are locked in here. First, the strip has a data path at all: the segments
 * arrive as the shared `manageStrip` prop, which is what the 15s poll reloads, so the
 * component is not a shell that renders whatever it is handed and is handed nothing.
 *
 * Second, and the reason Navigation takes an event id at all: the counts follow the
 * global event scope. Resolved out of the container instead, Navigation gets its null
 * default, every count is an all-events count, and the number in the strip contradicts
 * the event named two elements to its left.
 */

use App\Models\Event;
use App\Models\Fursuit\Fursuit;
use App\Models\Fursuit\States\Approved;
use App\Models\Fursuit\States\Pending;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\from;
use function Pest\Laravel\get;

beforeEach(function () {
    // The counts are cached for a few seconds because the strip polls; a stale entry
    // from a previous case would make these assertions meaningless.
    Cache::flush();

    $this->admin = User::factory()->create(['is_admin' => true]);

    $this->older = Event::factory()->create([
        'name' => 'Eurofurence 28',
        'starts_at' => now()->subYear(),
        'ends_at' => now()->subYear()->addDays(5),
    ]);

    $this->newer = Event::factory()->create([
        'name' => 'Eurofurence 29',
        'starts_at' => now()->addDays(30),
        'ends_at' => now()->addDays(35),
    ]);

    actingAs($this->admin);
});

/**
 * @param  int  $pending  fursuits awaiting review
 * @param  int  $approved  fursuits that must not be counted
 */
function seedFursuits(Event $event, int $pending, int $approved = 0): void
{
    Fursuit::factory()->count($pending)->create([
        'event_id' => $event->id,
        'status' => Pending::$name,
    ]);

    Fursuit::factory()->count($approved)->create([
        'event_id' => $event->id,
        'status' => Approved::$name,
    ]);
}

test('the strip is shared as its own prop so the poll has something to reload', function () {
    get(route('manage.dashboard'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->has('manageStrip.segments'));
});

test('the strip counts pending approvals for the selected event only', function () {
    seedFursuits($this->newer, pending: 3, approved: 4);
    seedFursuits($this->older, pending: 7);

    // Newest event is the seeded default.
    get(route('manage.dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('manageStrip.segments.0.key', 'pending_fursuits')
            ->where('manageStrip.segments.0.value', 3)
            ->where('manageStrip.segments.0.tone', 'warn')
        );

    from(route('manage.dashboard'))
        ->post(route('manage.event.select'), ['event_id' => $this->older->id]);

    get(route('manage.dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('manageStrip.segments.0.value', 7));

    // All events is a real selection, and it means every event's pending queue.
    from(route('manage.dashboard'))->post(route('manage.event.select'), []);

    get(route('manage.dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('manageStrip.segments.0.value', 10));
});

test('a segment at zero still renders, in the idle tone', function () {
    get(route('manage.dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('manageStrip.segments.0.value', 0)
            ->where('manageStrip.segments.0.tone', 'idle')
        );
});

test('the rail chip and the strip segment agree on the pending count', function () {
    seedFursuits($this->newer, pending: 2);

    // The rail item itself only appears once phase 3 registers its route, so this
    // asserts against the service rather than the prop.
    $navigation = new App\Support\Manage\Navigation($this->newer->id);

    expect($navigation->strip()['segments'][0]['value'])->toBe(2);
});

test('a reviewer sees the segments their policies allow and no others', function () {
    // PrintJobPolicy::viewAny is admin-only; FursuitPolicy::viewAny admits reviewers.
    $reviewer = User::factory()->create(['is_admin' => false, 'is_reviewer' => true]);

    actingAs($reviewer);

    get(route('manage.dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('manageStrip.segments', 1)
            ->where('manageStrip.segments.0.key', 'pending_fursuits')
        );
});

test('a segment whose module has not been built yet carries no dead link', function () {
    // Phase 0 registers the dashboard only, so neither list route exists. The segment
    // still shows its number; it just is not a link.
    get(route('manage.dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('manageStrip.segments.0.url', null));
});
