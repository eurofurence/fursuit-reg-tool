<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Badge\Badge;
use App\Models\Badge\State_Fulfillment\Pending as FulfillmentPending;
use App\Models\Badge\State_Fulfillment\Printed;
use App\Models\Event;
use App\Models\EventUser;
use App\Models\Fursuit\Fursuit;
use App\Models\Fursuit\States\Approved;
use App\Models\Fursuit\States\Pending as FursuitPending;
use App\Models\Machine;
use App\Models\User;
use App\Support\Manage\Filter;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

beforeEach(function () {
    Storage::fake('s3');

    $this->admin = User::factory()->create(['is_admin' => true]);

    $this->event = Event::factory()->create([
        'name' => 'Eurofurence 29',
        'starts_at' => now()->addDays(30),
        'ends_at' => now()->addDays(35),
        'order_starts_at' => now()->subDay(),
        'order_ends_at' => now()->addDays(20),
    ]);

    actingAs($this->admin);
});

function makeBadge(Event $event, int $attendeeId, string $fulfillment): Badge
{
    $user = User::factory()->create();

    EventUser::create([
        'user_id' => $user->id,
        'event_id' => $event->id,
        'attendee_id' => (string) $attendeeId,
        'valid_registration' => true,
        'prepaid_badges' => 0,
    ]);

    $fursuit = Fursuit::factory()->create([
        'user_id' => $user->id,
        'event_id' => $event->id,
        'status' => Approved::$name,
    ]);

    return Badge::factory()->create([
        'fursuit_id' => $fursuit->id,
        'status_fulfillment' => $fulfillment,
    ]);
}

test('badges multi-select filter narrows rows and is absent from the URL until applied', function () {
    makeBadge($this->event, 10, FulfillmentPending::$name);
    makeBadge($this->event, 20, FulfillmentPending::$name);
    makeBadge($this->event, 30, Printed::$name);

    // First load: no filter applied, every row is there and the filter carries no value.
    get(route('admin.badges.index'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('meta.total', 3)
            ->where('filters.0.key', 'status_fulfillment')
            ->where('filters.0.multiple', true)
            ->where('filters.0.value', [])
            ->where('filters.0.default', [])
            ->etc()
        );

    get(route('admin.badges.index', ['filter' => ['status_fulfillment' => [FulfillmentPending::$name]]]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('meta.total', 2)
            ->where('filters.0.value', [FulfillmentPending::$name])
            ->etc()
        );

    // Two choices accumulate rather than replace.
    get(route('admin.badges.index', ['filter' => ['status_fulfillment' => [FulfillmentPending::$name, Printed::$name]]]))
        ->assertInertia(fn (Assert $page) => $page->where('meta.total', 3)->etc());

    // Emptied but still on the bar: the cleared token, not a missing key.
    get(route('admin.badges.index', ['filter' => ['status_fulfillment' => Filter::CLEARED]]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('meta.total', 3)
            ->where('filters.0.value', [])
            ->etc()
        );
});

test('the badges attendee-id range narrows on either bound and both', function () {
    makeBadge($this->event, 100, FulfillmentPending::$name);
    makeBadge($this->event, 300, FulfillmentPending::$name);
    makeBadge($this->event, 900, FulfillmentPending::$name);

    $range = fn (array $bounds) => get(route('admin.badges.index', ['filter' => ['attendee_id_range' => $bounds]]));

    $range(['min' => 200])->assertInertia(fn (Assert $page) => $page->where('meta.total', 2)->etc());
    $range(['max' => 200])->assertInertia(fn (Assert $page) => $page->where('meta.total', 1)->etc());
    $range(['min' => 200, 'max' => 400])->assertInertia(fn (Assert $page) => $page
        ->where('meta.total', 1)
        ->where('filters.3.key', 'attendee_id_range')
        ->where('filters.3.chipLabel', 'Attendee')
        ->where('filters.3.value', ['min' => '200', 'max' => '400'])
        ->etc()
    );

    // Numeric, not lexicographic: '900' must not sort below '200'.
    $range(['min' => 200, 'max' => 1000])->assertInertia(fn (Assert $page) => $page->where('meta.total', 2)->etc());

    // Removed entirely: the key is gone from the URL and nothing is narrowed.
    get(route('admin.badges.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('meta.total', 3)
            ->where('filters.3.value', ['min' => '', 'max' => ''])
            ->etc()
        );
});

test('the machines archived ternary is three-state and survives a reload', function () {
    Machine::factory()->count(2)->create(['archived_at' => null]);
    Machine::factory()->create(['archived_at' => now()]);

    $url = fn (?string $value) => route('admin.machines.index', $value === null ? [] : ['filter' => ['archived' => $value]]);

    // Blank is the default: active machines only.
    get($url(null))->assertInertia(fn (Assert $page) => $page
        ->where('meta.total', 2)
        ->where('filters.0.type', 'ternary')
        ->where('filters.0.value', '')
        ->etc()
    );

    get($url('1'))->assertInertia(fn (Assert $page) => $page
        ->where('meta.total', 1)
        ->where('filters.0.value', '1')
        ->etc()
    );

    get($url('0'))->assertInertia(fn (Assert $page) => $page
        ->where('meta.total', 3)
        ->where('filters.0.value', '0')
        ->etc()
    );

    // A reload of the same URL is the same request; the value has to come back identical.
    get($url('0'))->assertInertia(fn (Assert $page) => $page
        ->where('meta.total', 3)
        ->where('filters.0.value', '0')
        ->etc()
    );
});

test('the fursuit status default applies on first load and is still removable', function () {
    Fursuit::factory()->count(2)->create(['event_id' => $this->event->id, 'status' => FursuitPending::$name]);
    Fursuit::factory()->count(3)->create(['event_id' => $this->event->id, 'status' => Approved::$name]);

    get(route('admin.fursuits.index'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('meta.total', 2)
            ->where('filters.0.value', FursuitPending::$name)
            ->where('filters.0.default', FursuitPending::$name)
            ->etc()
        );

    // Removing a defaulted filter sends the cleared token, and that has to stick.
    get(route('admin.fursuits.index', ['filter' => ['status' => Filter::CLEARED]]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('meta.total', 5)
            ->where('filters.0.value', '')
            ->etc()
        );
});

test('the real partial visit the filter bar makes returns the narrowed rows', function () {
    makeBadge($this->event, 10, FulfillmentPending::$name);
    makeBadge($this->event, 20, Printed::$name);

    $version = (string) (new HandleInertiaRequests)->version(request()) ?? '';

    // Exactly what useTableQuery's router.get({ only: [...] }) sends.
    $response = get(
        route('admin.badges.index', ['filter' => ['status_fulfillment' => [Printed::$name]]]),
        [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => $version,
            'X-Inertia-Partial-Component' => 'Manage/Badges/Index',
            'X-Inertia-Partial-Data' => 'rows,meta,filters,sort,search',
        ]
    );

    $response->assertSuccessful();

    $props = $response->json('props');

    expect($response->json('component'))->toBe('Manage/Badges/Index')
        ->and(array_keys($props))->toContain('rows', 'meta', 'filters')
        ->and($props['meta']['total'])->toBe(1)
        ->and($props['rows'])->toHaveCount(1)
        ->and($props['filters'][0]['value'])->toBe([Printed::$name])
        // Every other filter comes back declared but unset, which is what keeps it off
        // the bar and out of the query string.
        ->and($props['filters'][2]['value'])->toBe('')
        ->and($props['filters'][3]['value'])->toBe(['min' => '', 'max' => '']);
});
