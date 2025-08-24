<?php

use App\Enum\EventStateEnum;
use App\Models\Badge\Badge;
use App\Models\Event;
use App\Models\EventUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\delete;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\put;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();

    Storage::fake('local'); // Use local instead of s3 for tests
    Notification::fake();
    Http::fake(); // Mock all HTTP requests
});

describe('Event State: Past Event (CLOSED - Event Ended)', function () {
    beforeEach(function () {
        // Event that has ended
        $this->event = Event::factory()->create([
            'starts_at' => now()->subDays(30),
            'ends_at' => now()->subDays(1), // Event ended yesterday
            'order_starts_at' => now()->subDays(25),
            'order_ends_at' => now()->subDays(5),
        ]);
    });

    test('getActiveEvent returns event even when event has ended', function () {
        expect(Event::getActiveEvent())->not->toBeNull();
        expect(Event::getActiveEvent()->id)->toBe($this->event->id);
    });

    test('event state is CLOSED when event has ended', function () {
        expect($this->event->state)->toBe(EventStateEnum::CLOSED);
        expect($this->event->allowsOrders())->toBeFalse();
    });

    test('badge index redirects when event ended', function () {
        actingAs($this->user);

        $response = get(route('badges.index'));

        $response->assertRedirect();
    });

    test('badge creation redirects when event has ended', function () {
        actingAs($this->user);

        get(route('badges.create'))->assertRedirect();

        post(route('badges.store'), [
            'species' => 'Wolf',
            'name' => 'Test Badge',
            'image' => UploadedFile::fake()->image('test.png', 400, 400),
            'catchEmAll' => true,
            'publish' => true,
            'tos' => true,
            'upgrades' => ['spareCopy' => false],
        ])->assertRedirect();
    });
});

describe('Event State: External Purchase Period (CLOSED - Pre-Event)', function () {
    beforeEach(function () {
        // Event hasn't started yet
        $this->event = Event::factory()->create([
            'starts_at' => now()->addDays(10),
            'ends_at' => now()->addDays(40),
            'order_starts_at' => now()->addDays(15),
            'order_ends_at' => now()->addDays(35),
        ]);
    });

    test('getActiveEvent returns event even when event hasnt started', function () {
        expect(Event::getActiveEvent())->not->toBeNull();
        expect(Event::getActiveEvent()->id)->toBe($this->event->id);
    });

    test('event state is CLOSED when event hasnt started', function () {
        expect($this->event->state)->toBe(EventStateEnum::CLOSED);
        expect($this->event->allowsOrders())->toBeFalse();
    });

    test('badge creation redirects when event hasnt started', function () {
        actingAs($this->user);

        get(route('badges.create'))->assertRedirect();

        post(route('badges.store'), [
            'species' => 'Wolf',
            'name' => 'Test Badge',
            'image' => UploadedFile::fake()->image('test.png', 400, 400),
            'catchEmAll' => true,
            'publish' => true,
            'tos' => true,
            'upgrades' => ['spareCopy' => false],
        ])->assertRedirect();
    });

    test('badge index shows existing badges but no create button', function () {
        // Create a past event that has ended
        $pastEvent = Event::factory()->create([
            'starts_at' => now()->subDays(90),
            'ends_at' => now()->subDays(61),
            'order_starts_at' => now()->subDays(85),
            'order_ends_at' => now()->subDays(65),
        ]);

        // Create a badge from the past event that's ready for pickup
        Badge::factory()
            ->recycle($pastEvent)
            ->recycle($this->user)
            ->create([
                'status_fulfillment' => 'ready_for_pickup',
            ]);

        actingAs($this->user);

        $response = get(route('badges.index'));

        $response->assertRedirect();
    });
});

describe('Event State: External Purchase Period (CLOSED - Order Window Not Started)', function () {
    beforeEach(function () {
        // Event started but order window hasn't
        $this->event = Event::factory()->create([
            'starts_at' => now()->subDays(5),
            'ends_at' => now()->addDays(25),
            'order_starts_at' => now()->addDays(5), // Orders start in 5 days
            'order_ends_at' => now()->addDays(20),
        ]);
    });

    test('event state is CLOSED when order window hasnt started', function () {
        expect($this->event->state)->toBe(EventStateEnum::CLOSED);
        expect($this->event->allowsOrders())->toBeFalse();
    });

    test('badge creation redirects when order window hasnt started', function () {
        actingAs($this->user);

        get(route('badges.create'))->assertRedirect();
    });
});

describe('Event State: Onsite Purchase Period (OPEN - During Event)', function () {
    beforeEach(function () {
        // Event is active and in order window
        $this->event = Event::factory()->create([
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->addDays(28),
            'order_starts_at' => now()->subDays(1),
            'order_ends_at' => now()->addDays(25),
        ]);
    });

    test('getActiveEvent returns correct event when active', function () {
        expect(Event::getActiveEvent())->not->toBeNull();
        expect(Event::getActiveEvent()->id)->toBe($this->event->id);
    });

    test('event state is OPEN during active order window', function () {
        expect($this->event->state)->toBe(EventStateEnum::OPEN);
        expect($this->event->allowsOrders())->toBeTrue();
    });

    test('badge index redirects when user has no badges', function () {
        actingAs($this->user);

        $response = get(route('badges.index'));

        $response->assertRedirect(route('welcome'));
    });

    test('user can access badge creation form when orders are open', function () {
        // Create EventUser so user can create paid badges
        EventUser::create([
            'user_id' => $this->user->id,
            'event_id' => $this->event->id,
            'attendee_id' => '12345',
            'valid_registration' => true,
            'prepaid_badges' => 0, // No prepaid badges, will be paid badge
        ]);

        actingAs($this->user);

        $response = get(route('badges.create'));

        $response->assertSuccessful();
        $response->assertInertia(fn ($page) => $page->component('Badges/BadgeForm')
            ->where('prepaidBadgesLeft', 0)
        );
    });

    test('user can create badge when orders are open', function () {
        // Create EventUser so user can create paid badges
        EventUser::create([
            'user_id' => $this->user->id,
            'event_id' => $this->event->id,
            'attendee_id' => '12345',
            'valid_registration' => true,
            'prepaid_badges' => 0, // No prepaid badges, will be paid badge
        ]);

        actingAs($this->user);

        $response = post(route('badges.store'), [
            'species' => 'Wolf',
            'name' => 'Test Badge',
            'image' => UploadedFile::fake()->image('test.png', 400, 400),
            'catchEmAll' => true,
            'publish' => true,
            'tos' => true,
            'upgrades' => [
                'spareCopy' => false,
            ],
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('badges.index'));

        $this->assertDatabaseHas('fursuits', [
            'name' => 'Test Badge',
            'catch_em_all' => true,
            'published' => true,
        ]);

        $this->assertDatabaseHas('badges', [
            'total' => 300, // 3€ in cents
        ]);
    });

    test('user with free badge can claim it', function () {
        EventUser::create([
            'user_id' => $this->user->id,
            'event_id' => $this->event->id,
            'attendee_id' => '12345',
            'valid_registration' => true,
            'prepaid_badges' => 2, // 2 prepaid badges - 1 deducted after order_starts_at = 1 left
        ]);

        actingAs($this->user);

        $response = get(route('badges.create'));

        $response->assertInertia(fn ($page) => $page->where('prepaidBadgesLeft', 1)
        );

        $response = post(route('badges.store'), [
            'species' => 'Fox',
            'name' => 'Free Badge',
            'image' => UploadedFile::fake()->image('test.png', 400, 400),
            'catchEmAll' => true,
            'publish' => true,
            'tos' => true,
            'upgrades' => [
                'spareCopy' => false,
            ],
        ]);

        $response->assertSessionHasNoErrors();

        $this->assertDatabaseHas('badges', [
            'total' => 0, // Free badge
            'is_free_badge' => true,
        ]);

        // User should have used their free badge (started with 2, deducted 1 after order_starts_at, used 1 for badge = 0 left)
        expect($this->user->getPrepaidBadgesLeft($this->event->id))->toBe(0);
    });

    test('user with additional free badge copies can claim them', function () {
        EventUser::create([
            'user_id' => $this->user->id,
            'event_id' => $this->event->id,
            'attendee_id' => '12345',
            'valid_registration' => true,
            'prepaid_badges' => 3, // 1 main + 2 copies
        ]);

        actingAs($this->user);

        $response = get(route('badges.create'));

        $response->assertInertia(fn ($page) => $page->where('prepaidBadgesLeft', 2)
        );

        $response = post(route('badges.store'), [
            'species' => 'Dragon',
            'name' => 'Free with Copies',
            'image' => UploadedFile::fake()->image('test.png', 400, 400),
            'catchEmAll' => true,
            'publish' => true,
            'tos' => true,
            'upgrades' => [
                'spareCopy' => true, // This should create additional copies
            ],
        ]);

        $response->assertSessionHasNoErrors();

        // Should have main badge (free) + 1 spare copy
        $this->assertDatabaseCount('badges', 2);

        // Main badge should be free
        $mainBadge = Badge::where('is_free_badge', true)->first();
        expect($mainBadge->total)->toBe(0);

        // One extra copy should exist
        $extraBadges = Badge::where('extra_copy', true)->get();
        expect($extraBadges)->toHaveCount(1);

        // User should have used 2 of their 2 remaining prepaid badges (started with 3, deducted 1 after order_starts_at, used 2 for badges = 0 left)
        expect($this->user->getPrepaidBadgesLeft($this->event->id))->toBe(0);
    });

    test('user can edit pending badges during order window', function () {
        // Create EventUser so user can edit badges
        EventUser::create([
            'user_id' => $this->user->id,
            'event_id' => $this->event->id,
            'attendee_id' => '12345',
            'valid_registration' => true,
            'prepaid_badges' => 0,
        ]);

        $badge = Badge::factory()
            ->recycle($this->event)
            ->recycle($this->user)
            ->create([
                'status_fulfillment' => \App\Models\Badge\State_Fulfillment\Pending::$name,
            ]);

        actingAs($this->user);

        $response = get(route('badges.edit', $badge));
        $response->assertSuccessful();

        $response = put(route('badges.update', $badge), [
            'species' => 'Updated Species',
            'name' => 'Updated Name',
            'publish' => true,
            'catchEmAll' => false,
        ]);

        $response->assertRedirect(route('badges.index'));
        $response->assertSessionHasNoErrors();

        $this->assertDatabaseHas('fursuits', [
            'name' => 'Updated Name',
        ]);
    });

    test('user can delete pending badges during order window', function () {
        // Create EventUser so user can delete badges
        EventUser::create([
            'user_id' => $this->user->id,
            'event_id' => $this->event->id,
            'attendee_id' => '12345',
            'valid_registration' => true,
            'prepaid_badges' => 0,
        ]);

        $badge = Badge::factory()
            ->recycle($this->event)
            ->recycle($this->user)
            ->create([
                'status_fulfillment' => \App\Models\Badge\State_Fulfillment\Pending::$name,
            ]);

        actingAs($this->user);

        $response = delete(route('badges.destroy', $badge));

        $response->assertRedirect(route('welcome'));

        $this->assertTrue($badge->fresh()->trashed());
    });

    test('user cannot edit printed badges', function () {
        // Create EventUser so authorization checks work
        EventUser::create([
            'user_id' => $this->user->id,
            'event_id' => $this->event->id,
            'attendee_id' => '12345',
            'valid_registration' => true,
            'prepaid_badges' => 0,
        ]);

        $badge = Badge::factory()
            ->recycle($this->event)
            ->recycle($this->user)
            ->create([
                'status_fulfillment' => \App\Models\Badge\State_Fulfillment\Printed::$name,
            ]);

        actingAs($this->user);

        get(route('badges.edit', $badge))->assertForbidden();

        put(route('badges.update', $badge), [
            'species' => 'Cat',
            'name' => 'Should Not Update',
            'catchEmAll' => true,
            'publish' => true,
        ])->assertForbidden();

        delete(route('badges.destroy', $badge))->assertForbidden();
    });
});

describe('Event State: Return to Closed State (Order Window Ended)', function () {
    beforeEach(function () {
        // Event is active but order window has ended
        $this->event = Event::factory()->create([
            'starts_at' => now()->subDays(10),
            'ends_at' => now()->addDays(20),
            'order_starts_at' => now()->subDays(8),
            'order_ends_at' => now()->subDays(1), // Orders ended yesterday
        ]);
    });

    test('event state is CLOSED when order window has ended', function () {
        expect($this->event->state)->toBe(EventStateEnum::CLOSED);
        expect($this->event->allowsOrders())->toBeFalse();
    });

    test('badge creation redirects when order window has ended', function () {
        actingAs($this->user);

        get(route('badges.create'))->assertRedirect();

        post(route('badges.store'), [
            'species' => 'Wolf',
            'name' => 'Test Badge',
            'image' => UploadedFile::fake()->image('test.png', 400, 400),
            'catchEmAll' => true,
            'publish' => true,
            'tos' => true,
            'upgrades' => ['spareCopy' => false],
        ])->assertRedirect();
    });

    test('existing badges can still be viewed when order window has ended', function () {
        // Create EventUser so user can view their badges
        EventUser::create([
            'user_id' => $this->user->id,
            'event_id' => $this->event->id,
            'attendee_id' => '12345',
            'valid_registration' => true,
            'prepaid_badges' => 0,
        ]);

        Badge::factory()
            ->recycle($this->event)
            ->recycle($this->user)
            ->create();

        actingAs($this->user);

        $response = get(route('badges.index'));

        $response->assertSuccessful();
        $response->assertInertia(fn ($page) => $page->where('canCreate', false)
            ->has('badges', 1)
        );
    });
});

describe('Multiple Events Handling', function () {
    test('getActiveEvent returns correct event when multiple events exist', function () {
        // Past event
        Event::factory()->create([
            'starts_at' => now()->subDays(60),
            'ends_at' => now()->subDays(30),
        ]);

        // Current active event
        $activeEvent = Event::factory()->create([
            'starts_at' => now()->subDays(5),
            'ends_at' => now()->addDays(25),
            'order_starts_at' => now()->subDays(2),
            'order_ends_at' => now()->addDays(20),
        ]);

        // Future event
        Event::factory()->create([
            'starts_at' => now()->addDays(60),
            'ends_at' => now()->addDays(90),
        ]);

        expect(Event::getActiveEvent()->id)->toBe(3); // Latest event by starts_at is the future event
    });

    test('getActiveEvent returns latest event even when all events are past', function () {
        // Only past events
        Event::factory()->create([
            'starts_at' => now()->subDays(60),
            'ends_at' => now()->subDays(30),
        ]);

        Event::factory()->create([
            'starts_at' => now()->subDays(90),
            'ends_at' => now()->subDays(61),
        ]);

        expect(Event::getActiveEvent())->not->toBeNull();
        expect(Event::getActiveEvent()->id)->toBe(1); // Latest event by starts_at
    });
});
