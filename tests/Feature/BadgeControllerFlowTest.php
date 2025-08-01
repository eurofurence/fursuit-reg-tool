<?php

use App\Models\Badge\Badge;
use App\Models\Event;
use App\Models\User;
use App\Enum\EventStateEnum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\put;
use function Pest\Laravel\delete;
use function Pest\Laravel\travelTo;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create([
        'has_free_badge' => false,
        'free_badge_copies' => 0,
    ]);
    
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

    test('getActiveEvent returns null when event has ended', function () {
        expect(Event::getActiveEvent())->toBeNull();
    });

    test('event state is CLOSED when event has ended', function () {
        expect($this->event->state)->toBe(EventStateEnum::CLOSED);
        expect($this->event->allowsOrders())->toBeFalse();
    });

    test('badge index shows no create button when event ended', function () {
        actingAs($this->user);
        
        $response = get(route('badges.index'));
        
        $response->assertSuccessful();
        $response->assertInertia(fn ($page) => 
            $page->where('canCreate', false)
        );
    });

    test('badge creation is forbidden when event has ended', function () {
        actingAs($this->user);
        
        get(route('badges.create'))->assertForbidden();
        
        post(route('badges.store'), [
            'species' => 'Wolf',
            'name' => 'Test Badge',
            'image' => UploadedFile::fake()->image('test.png', 400, 400),
            'catchEmAll' => true,
            'publish' => true,
            'tos' => true,
            'upgrades' => ['spareCopy' => false],
        ])->assertForbidden();
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

    test('getActiveEvent returns null when event exists but hasnt started', function () {
        expect(Event::getActiveEvent())->toBeNull();
    });

    test('event state is CLOSED when event hasnt started', function () {
        expect($this->event->state)->toBe(EventStateEnum::CLOSED);
        expect($this->event->allowsOrders())->toBeFalse();
    });

    test('badge creation is forbidden when event hasnt started', function () {
        actingAs($this->user);
        
        get(route('badges.create'))->assertForbidden();
        
        post(route('badges.store'), [
            'species' => 'Wolf',
            'name' => 'Test Badge',
            'image' => UploadedFile::fake()->image('test.png', 400, 400),
            'catchEmAll' => true,
            'publish' => true,
            'tos' => true,
            'upgrades' => ['spareCopy' => false],
        ])->assertForbidden();
    });

    test('badge index shows existing badges but no create button', function () {
        // Create a badge from a previous event/session
        $badge = Badge::factory()
            ->recycle($this->event)
            ->recycle($this->user)
            ->create();

        actingAs($this->user);
        
        $response = get(route('badges.index'));
        
        $response->assertSuccessful();
        $response->assertInertia(fn ($page) => 
            $page->where('canCreate', false)
                 ->has('badges', 1)
        );
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

    test('badge creation is forbidden when order window hasnt started', function () {
        actingAs($this->user);
        
        get(route('badges.create'))->assertForbidden();
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

    test('badge index shows create button when orders are open', function () {
        actingAs($this->user);
        
        $response = get(route('badges.index'));
        
        $response->assertSuccessful();
        $response->assertInertia(fn ($page) => 
            $page->where('canCreate', true)
        );
    });

    test('user can access badge creation form when orders are open', function () {
        actingAs($this->user);
        
        $response = get(route('badges.create'));
        
        $response->assertSuccessful();
        $response->assertInertia(fn ($page) => 
            $page->component('Badges/BadgesCreate')
                 ->where('isFree', false)
                 ->where('freeBadgeCopies', 0)
        );
    });

    test('user can create badge when orders are open', function () {
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
            'total' => 200, // 2€ in cents
        ]);
    });

    test('user with free badge can claim it', function () {
        $this->user->update([
            'has_free_badge' => true,
            'free_badge_copies' => 0,
        ]);
        
        actingAs($this->user);
        
        $response = get(route('badges.create'));
        
        $response->assertInertia(fn ($page) => 
            $page->where('isFree', true)
                 ->where('freeBadgeCopies', 0)
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
        
        // User should no longer have free badge flag
        $this->user->refresh();
        expect($this->user->has_free_badge)->toBeFalse();
    });

    test('user with additional free badge copies can claim them', function () {
        $this->user->update([
            'has_free_badge' => true,
            'free_badge_copies' => 2,
        ]);
        
        actingAs($this->user);
        
        $response = get(route('badges.create'));
        
        $response->assertInertia(fn ($page) => 
            $page->where('isFree', true)
                 ->where('freeBadgeCopies', 2)
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
        
        // Should have main badge (free) + 2 copies
        $this->assertDatabaseCount('badges', 3);
        
        // Main badge should be free
        $mainBadge = Badge::where('is_free_badge', true)->first();
        expect($mainBadge->total)->toBe(0);
        
        // Extra copies should exist
        $extraBadges = Badge::where('extra_copy', true)->get();
        expect($extraBadges)->toHaveCount(2);
        
        // User should no longer have free copies
        $this->user->refresh();
        expect($this->user->free_badge_copies)->toBe(0);
    });

    test('user can edit pending badges during order window', function () {
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

    test('badge creation is forbidden when order window has ended', function () {
        actingAs($this->user);
        
        get(route('badges.create'))->assertForbidden();
        
        post(route('badges.store'), [
            'species' => 'Wolf',
            'name' => 'Test Badge',
            'image' => UploadedFile::fake()->image('test.png', 400, 400),
            'catchEmAll' => true,
            'publish' => true,
            'tos' => true,
            'upgrades' => ['spareCopy' => false],
        ])->assertForbidden();
    });

    test('existing badges can still be viewed when order window has ended', function () {
        $badge = Badge::factory()
            ->recycle($this->event)
            ->recycle($this->user)
            ->create();

        actingAs($this->user);
        
        $response = get(route('badges.index'));
        
        $response->assertSuccessful();
        $response->assertInertia(fn ($page) => 
            $page->where('canCreate', false)
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
        ]);
        
        // Future event
        Event::factory()->create([
            'starts_at' => now()->addDays(60),
            'ends_at' => now()->addDays(90),
        ]);
        
        expect(Event::getActiveEvent()->id)->toBe($activeEvent->id);
    });

    test('getActiveEvent returns null when no active events exist', function () {
        // Only past events
        Event::factory()->create([
            'starts_at' => now()->subDays(60),
            'ends_at' => now()->subDays(30),
        ]);
        
        Event::factory()->create([
            'starts_at' => now()->subDays(90),
            'ends_at' => now()->subDays(61),
        ]);
        
        expect(Event::getActiveEvent())->toBeNull();
    });
});
