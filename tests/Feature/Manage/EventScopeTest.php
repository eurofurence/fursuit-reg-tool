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

use App\Domain\Checkout\Enums\TseClientStateEnum;
use App\Domain\Checkout\Models\Checkout\Checkout;
use App\Domain\Checkout\Models\Checkout\States\Finished;
use App\Domain\Checkout\Models\TseClient;
use App\Domain\Printing\Models\PrintBatch;
use App\Domain\Printing\Models\Printer;
use App\Domain\Printing\Models\PrintJob;
use App\Enum\PrintJobStatusEnum;
use App\Enum\PrintJobTypeEnum;
use App\Models\Badge\Badge;
use App\Models\Event;
use App\Models\Fursuit\Fursuit;
use App\Models\Machine;
use App\Models\Staff;
use App\Models\SumUpReader;
use App\Models\User;
use App\Support\Manage\EventScope;
use Illuminate\Support\Facades\Storage;
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
    get(route('admin.dashboard'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('manageEvent.id', $this->newer->id)
            ->where('manageEvent.name', 'Eurofurence 29')
        );
});

test('selecting an event persists it across requests', function () {
    from(route('admin.dashboard'))
        ->post(route('admin.event.select'), ['event_id' => $this->older->id])
        ->assertRedirect(route('admin.dashboard'));

    // The next request must not be re-seeded with the newest event.
    get(route('admin.dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('manageEvent.id', $this->older->id)
            ->where('manageEvent.name', 'Eurofurence 28')
        );

    // And the one after that, because the middleware runs again every time.
    get(route('admin.dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('manageEvent.id', $this->older->id));
});

test('selecting all events actually means all events and survives the next request', function () {
    // This is the case FilamentEventSelector could never express. A null id is a
    // decision, not a missing value, and the seed has to leave it alone.
    from(route('admin.dashboard'))
        ->post(route('admin.event.select'), ['event_id' => null])
        ->assertRedirect(route('admin.dashboard'));

    get(route('admin.dashboard'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('manageEvent.id', null)
            ->where('manageEvent.name', null)
        );

    // Two more round trips: the old bug re-seeded on *every* request, so one was enough
    // to lose the choice, but a regression that only bites on the second is just as bad.
    get(route('admin.dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('manageEvent.id', null));

    get(route('admin.dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('manageEvent.id', null));
});

test('an omitted event_id is the same explicit all-events choice as an empty one', function () {
    // The selector posts nothing at all for the "All events" option.
    from(route('admin.dashboard'))->post(route('admin.event.select'), []);

    get(route('admin.dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('manageEvent.id', null));
});

test('a specific event can be chosen again after all events', function () {
    from(route('admin.dashboard'))->post(route('admin.event.select'), []);
    from(route('admin.dashboard'))->post(route('admin.event.select'), ['event_id' => $this->newer->id]);

    get(route('admin.dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('manageEvent.id', $this->newer->id));
});

test('the scope is shared as an Inertia prop with every option and its own orders_open flag', function () {
    // The blade select could only show the marker for the already-selected event, so the
    // prop carries the flag per option (landmine 68).
    get(route('admin.dashboard'))
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
    from(route('admin.dashboard'))
        ->post(route('admin.event.select'), ['event_id' => 999999])
        ->assertInvalid(['event_id']);

    // The old selector wrote whatever the query string carried straight into the session.
    get(route('admin.dashboard'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->where('manageEvent.id', $this->newer->id));
});

test('a non-numeric event id is rejected instead of being returned as an int', function () {
    // getSelectedEventId(): ?int would TypeError on this.
    from(route('admin.dashboard'))
        ->post(route('admin.event.select'), ['event_id' => 'all'])
        ->assertInvalid(['event_id']);

    get(route('admin.dashboard'))->assertSuccessful();
});

test('an event deleted after it was selected does not fatal, it means all events', function () {
    from(route('admin.dashboard'))->post(route('admin.event.select'), ['event_id' => $this->older->id]);

    $this->older->delete();

    get(route('admin.dashboard'))
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

    get(route('admin.dashboard'))
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

/*
 * Which lists the scope must not touch (plan 2.9, parity checklist line 58).
 *
 * Each module already asserts its own scoping in its own file where it has one, but the
 * ten lists below are defined by the absence of a behaviour, and an absence is exactly
 * what a per-module file forgets to assert. So this walks all ten in one place: a record
 * that belongs to the event that is NOT selected must still be listed. Print batches are
 * the one that could plausibly regress by accident, because `print_batches.event_id`
 * exists and looks scopeable.
 */

test('the ten unscoped lists ignore the selected event', function () {
    Storage::fake('s3');

    $machine = Machine::factory()->create(['name' => 'Unscoped Desk']);
    $printer = Printer::factory()->badge()->create(['name' => 'Unscoped Printer']);
    $staff = Staff::factory()->create(['name' => 'Unscoped Staffer']);
    // Built without model events so SumUpReaderObserver does not reach for the SumUp API.
    $reader = SumUpReader::withoutEvents(fn () => SumUpReader::create([
        'name' => 'Unscoped Reader',
        'remote_id' => 'rdr_unscoped',
        'paring_code' => 'unscoped-code',
    ]));

    $tseClient = TseClient::create([
        'remote_id' => 'unscoped-client',
        'serial_number' => 'TSE-UNSCOPED',
        'state' => TseClientStateEnum::REGISTERED,
    ]);

    // Deliberately hung off the OLDER event, which is not the one selected below.
    $batch = PrintBatch::factory()->create([
        'name' => 'Unscoped Batch',
        'printer_id' => $printer->id,
        'event_id' => $this->older->id,
    ]);

    $job = PrintJob::factory()->create([
        'printer_id' => $printer->id,
        'print_batch_id' => $batch->id,
        'printable_type' => Badge::class,
        'printable_id' => Badge::factory()->create([
            'fursuit_id' => Fursuit::factory()->create(['event_id' => $this->older->id])->id,
        ])->id,
        'type' => PrintJobTypeEnum::Badge,
        'status' => PrintJobStatusEnum::Pending,
    ]);

    $checkout = Checkout::create([
        'remote_id' => 'UNSCOPED-CHECKOUT',
        'status' => Finished::class,
        'payment_method' => 'cash',
        'user_id' => User::factory()->create(['name' => 'Unscoped Payer'])->id,
        'machine_id' => $machine->id,
        'subtotal' => 100,
        'tax' => 19,
        'total' => 119,
        'fiskaly_data' => [],
    ]);

    // Scope the panel to the newer event. Nothing above belongs to it.
    from(route('admin.dashboard'))->post(route('admin.event.select'), ['event_id' => $this->newer->id]);

    $ids = function (string $route) {
        $props = get(route($route))->viewData('page')['props'];

        return collect($props['rows'])->pluck('id')->map(fn ($id) => (int) $id)->all();
    };

    expect($ids('admin.checkouts.index'))->toContain($checkout->id)
        ->and($ids('admin.machines.index'))->toContain($machine->id)
        ->and($ids('admin.printers.index'))->toContain($printer->id)
        ->and($ids('admin.print-jobs.index'))->toContain($job->id)
        ->and($ids('admin.print-batches.index'))->toContain($batch->id)
        ->and($ids('admin.staff.index'))->toContain($staff->id)
        ->and($ids('admin.sumup-readers.index'))->toContain($reader->id)
        ->and($ids('admin.tse-clients.index'))->toContain($tseClient->id)
        ->and($ids('admin.settings.users.index'))->toContain($this->admin->id)
        ->and($ids('admin.settings.events.index'))->toContain($this->older->id);
});
