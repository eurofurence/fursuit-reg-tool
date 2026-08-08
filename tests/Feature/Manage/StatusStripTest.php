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

use App\Models\Badge\Badge;
use App\Models\Event;
use App\Models\Fursuit\Fursuit;
use App\Models\Fursuit\States\Approved;
use App\Models\Fursuit\States\Pending;
use App\Models\User;
use App\Support\Manage\Navigation;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\from;
use function Pest\Laravel\get;

beforeEach(function () {
    // The counts are cached for a few seconds because the strip polls; a stale entry
    // from an earlier case would make these assertions meaningless.
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

    $this->pendingFursuits = function (Event $event, int $pending, int $approved = 0) {
        Fursuit::factory()->count($pending)->create([
            'event_id' => $event->id,
            'status' => Pending::$name,
        ]);

        if ($approved > 0) {
            Fursuit::factory()->count($approved)->create([
                'event_id' => $event->id,
                'status' => Approved::$name,
            ]);
        }
    };

    actingAs($this->admin);
});

test('the strip is shared as its own prop so the poll has something to reload', function () {
    get(route('admin.dashboard'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->has('manageStrip.segments'));
});

test('the strip counts pending approvals for the selected event only', function () {
    ($this->pendingFursuits)($this->newer, 3, 4);
    ($this->pendingFursuits)($this->older, 7);

    // The newest event is the seeded default.
    get(route('admin.dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('manageStrip.segments.0.key', 'pending_fursuits')
            ->where('manageStrip.segments.0.value', 3)
            ->where('manageStrip.segments.0.tone', 'warn')
        );

    from(route('admin.dashboard'))
        ->post(route('admin.event.select'), ['event_id' => $this->older->id]);

    get(route('admin.dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('manageStrip.segments.0.value', 7));

    // All events is a real selection, and it means every event's pending queue.
    from(route('admin.dashboard'))->post(route('admin.event.select'), []);

    get(route('admin.dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('manageStrip.segments.0.value', 10));
});

test('a segment at zero still renders, in the idle tone', function () {
    get(route('admin.dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('manageStrip.segments.0.value', 0)
            ->where('manageStrip.segments.0.tone', 'idle')
        );
});

test('the rail chip and the strip segment agree on the pending count', function () {
    ($this->pendingFursuits)($this->newer, 2);

    // The rail item itself only appears once phase 3 registers its route, so this
    // asserts against the service rather than against the prop.
    expect((new Navigation($this->newer->id))->strip()['segments'][0]['value'])->toBe(2);
});

test('a segment is dropped for a user whose policy refuses its model', function () {
    // Both segments are gated on a policy rather than on the panel gate. Reviewers pass
    // FursuitPolicy::viewAny and BadgePolicy::viewAny, so they see both; the gate is what
    // keeps a segment from appearing for anyone a later policy change shuts out.
    actingAs(User::factory()->create(['is_admin' => false, 'is_reviewer' => true]));

    get(route('admin.dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('manageStrip.segments', 3)
            ->where('manageStrip.segments.0.key', 'pending_fursuits')
            ->where('manageStrip.segments.1.key', 'unprinted_badges')
            ->where('manageStrip.segments.2.key', 'printed_badges')
        );
});

test('each segment links at the work its number was counted from', function () {
    // The fursuit segment links at the review queue, not the list: the number is a
    // backlog, so the click hands over the next record to judge. A segment whose module a
    // phase has not built yet still shows its number and simply carries no url, which is
    // Navigation::urlFor()'s Route::has() branch.
    actingAs($this->admin);

    get(route('admin.dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('manageStrip.segments.0.key', 'pending_fursuits')
            ->where('manageStrip.segments.0.label', 'pending reviews')
            ->where('manageStrip.segments.0.url', route('admin.fursuits.review'))
            ->where('manageStrip.segments.1.key', 'unprinted_badges')
            ->where('manageStrip.segments.1.label', 'left to print')
            // The badges whose card has not been printed: not queued, or queued and still
            // waiting on a printer.
            ->where('manageStrip.segments.1.url', route('admin.badges.index', [
                'filter' => ['status_fulfillment' => ['pending', 'processing']],
            ]))
            ->where('manageStrip.segments.2.key', 'printed_badges')
            ->where('manageStrip.segments.2.label', 'printed')
            // Its counterpart: the card exists, collected or not.
            ->where('manageStrip.segments.2.url', route('admin.badges.index', [
                'filter' => ['status_fulfillment' => ['ready_for_pickup', 'picked_up']],
            ]))
        );
});

test('the left-to-print segment counts the badges without a card, in the selected event', function () {
    $waiting = fn (Event $event, string $status, int $count) => Badge::factory()
        ->count($count)
        ->for(Fursuit::factory()->for($event)->state(['status' => Approved::$name]))
        ->create(['status_fulfillment' => $status]);

    $waiting($this->newer, 'pending', 2);
    $waiting($this->newer, 'processing', 1);
    $waiting($this->newer, 'ready_for_pickup', 4);
    $waiting($this->newer, 'picked_up', 3);
    $waiting($this->older, 'pending', 5);

    get(route('admin.dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('manageStrip.segments.1.value', 3)
            ->where('manageStrip.segments.1.tone', 'warn')
            // Printed is the same run seen from the other end: collected or not, the card
            // exists, and it is progress rather than a queue, so the tone is ok.
            ->where('manageStrip.segments.2.value', 7)
            ->where('manageStrip.segments.2.tone', 'ok')
        );

    Cache::flush();
    from(route('admin.dashboard'))
        ->post(route('admin.event.select'), ['event_id' => $this->older->id]);

    get(route('admin.dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('manageStrip.segments.1.value', 5)
            ->where('manageStrip.segments.2.value', 0)
            ->where('manageStrip.segments.2.tone', 'idle')
        );
});
