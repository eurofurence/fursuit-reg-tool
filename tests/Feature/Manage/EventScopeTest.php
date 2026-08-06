<?php

/*
 * The global event filter (plan part 2.9 and 4.2, item 8).
 *
 * This file is the one place the FilamentEventSelector bug is locked out. That middleware
 * forgot `filament_selected_event_id` when the request asked for "all events" and then,
 * in the same handle() call, re-seeded it with the newest event because the key was now
 * missing. Forgetting the id and having chosen all events were the same state, so
 * "all events" could not survive a single request, and every downstream "no event
 * selected" branch was unreachable.
 *
 * EventScope splits that into two keys: the id, and a separate flag recording that the
 * operator has chosen. The tests below are written against the observable behaviour
 * (the shared Inertia prop and what the next request sees), not against the keys, so
 * they would still fail if the split were re-collapsed some other way.
 */

use App\Models\Badge\Badge;
use App\Models\Event;
use App\Models\Fursuit\Fursuit;
use App\Models\User;
use App\Support\Manage\EventScope;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\from;
use function Pest\Laravel\get;

beforeEach(function () {
    $this->admin = User::factory()->create(['is_admin' => true]);

    // Two events, deliberately created oldest-first so "newest" cannot pass by accident
    // through insertion order: the seed sorts on starts_at.
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

    actingAs($this->admin);
});

test('with no session state the newest event is selected', function () {
    get(route('manage.dashboard'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('manageEvent.id', $this->newer->id)
            ->where('manageEvent.name', 'Eurofurence 29')
        );
});

test('selecting an event persists it across requests', function () {
    from(route('manage.dashboard'))
        ->post(route('manage.event.select'), ['event_id' => $this->older->id])
        ->assertRedirect(route('manage.dashboard'));

    // The next request must not be re-seeded with the newest event.
    get(route('manage.dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('manageEvent.id', $this->older->id)
            ->where('manageEvent.name', 'Eurofurence 28')
        );

    // And the one after that, because the middleware runs again every time.
    get(route('manage.dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('manageEvent.id', $this->older->id));
});

test('selecting all events actually means all events and survives the next request', function () {
    // This is the case FilamentEventSelector could never express. A null id is a
    // decision, not a missing value, and the seed has to leave it alone.
    from(route('manage.dashboard'))
        ->post(route('manage.event.select'), ['event_id' => null])
        ->assertRedirect(route('manage.dashboard'));

    get(route('manage.dashboard'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('manageEvent.id', null)
            ->where('manageEvent.name', null)
        );

    // Two more round trips: the old bug re-seeded on *every* request, so one was enough
    // to lose the choice, but a regression that only bites on the second is just as bad.
    get(route('manage.dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('manageEvent.id', null));

    get(route('manage.dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('manageEvent.id', null));
});

test('an omitted event_id is the same explicit all-events choice as an empty one', function () {
    // The selector posts nothing at all for the "All events" option.
    from(route('manage.dashboard'))->post(route('manage.event.select'), []);

    get(route('manage.dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('manageEvent.id', null));
});

test('a specific event can be chosen again after all events', function () {
    from(route('manage.dashboard'))->post(route('manage.event.select'), []);
    from(route('manage.dashboard'))->post(route('manage.event.select'), ['event_id' => $this->newer->id]);

    get(route('manage.dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('manageEvent.id', $this->newer->id));
});

test('the scope is shared as an Inertia prop with every option and its own orders_open flag', function () {
    // The blade select could only show the marker for the already-selected event, so the
    // prop carries the flag per option (landmine 68).
    get(route('manage.dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('manageEvent', fn (Assert $scope) => $scope
                ->where('id', $this->newer->id)
                ->where('name', 'Eurofurence 29')
                ->where('year', $this->newer->starts_at->format('Y'))
                ->where('orders_open', true)
                ->has('options', 2)
                // Newest first, same order as the seed.
                ->where('options.0.id', $this->newer->id)
                ->where('options.0.orders_open', true)
                ->where('options.1.id', $this->older->id)
                ->where('options.1.orders_open', false)
            )
        );
});

test('an unknown event id is a validation error, not a poisoned session', function () {
    from(route('manage.dashboard'))
        ->post(route('manage.event.select'), ['event_id' => 999999])
        ->assertInvalid(['event_id']);

    // The old selector wrote whatever the query string carried straight into the session.
    get(route('manage.dashboard'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->where('manageEvent.id', $this->newer->id));
});

test('a non-numeric event id is rejected instead of being returned as an int', function () {
    // getSelectedEventId(): ?int would TypeError on this.
    from(route('manage.dashboard'))
        ->post(route('manage.event.select'), ['event_id' => 'all'])
        ->assertInvalid(['event_id']);

    get(route('manage.dashboard'))->assertSuccessful();
});

test('an event deleted after it was selected does not fatal, it means all events', function () {
    from(route('manage.dashboard'))->post(route('manage.event.select'), ['event_id' => $this->older->id]);

    $this->older->delete();

    get(route('manage.dashboard'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('manageEvent.id', null)
            ->has('manageEvent.options', 1)
        );
});

test('junk left in the session by anything else resolves to all events', function () {
    session([
        EventScope::SESSION_ID => 'not-an-id',
        EventScope::SESSION_CHOSEN => true,
    ]);

    get(route('manage.dashboard'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->where('manageEvent.id', null));
});

test('apply() narrows on a selected event and widens on all events', function () {
    $scope = new EventScope;

    Fursuit::factory()->count(2)->create(['event_id' => $this->newer->id]);
    Fursuit::factory()->create(['event_id' => $this->older->id]);

    $scope->select($this->newer->id);
    expect($scope->apply(Fursuit::query())->count())->toBe(2);

    $scope->select($this->older->id);
    expect($scope->apply(Fursuit::query())->count())->toBe(1);

    // The "no id" branch used to be dead code. It now means what it says.
    $scope->select(null);
    expect($scope->apply(Fursuit::query())->count())->toBe(3);
});

test('apply() narrows through a relationship, which is how badges are scoped', function () {
    $scope = new EventScope;

    Badge::factory()->create(['fursuit_id' => Fursuit::factory()->create(['event_id' => $this->newer->id])]);
    Badge::factory()->create(['fursuit_id' => Fursuit::factory()->create(['event_id' => $this->older->id])]);

    $scope->select($this->newer->id);
    expect($scope->apply(Badge::query(), 'fursuit')->count())->toBe(1);

    $scope->select(null);
    expect($scope->apply(Badge::query(), 'fursuit')->count())->toBe(2);
});
