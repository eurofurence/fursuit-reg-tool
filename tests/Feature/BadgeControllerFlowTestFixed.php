<?php

use App\Models\Event;
use App\Models\User;
use App\Models\Badge\Badge;
use App\Enum\EventStateEnum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Http;
use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\put;
use function Pest\Laravel\delete;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create([
        'has_free_badge' => false,
        'free_badge_copies' => 0,
    ]);
    
    Storage::fake('local');
    Notification::fake();
    Http::fake(); // Mock all HTTP requests
});

describe('Badge Controller Flow - No Active Event', function () {
    test('badge routes redirect to welcome when no active event exists', function () {
        // No events at all
        actingAs($this->user);
        
        // EventEndedMiddleware redirects to welcome when no active event
        get(route('badges.index'))->assertRedirect(route('welcome'));
        get(route('badges.create'))->assertRedirect(route('welcome'));
        
        post(route('badges.store'), [
            'species' => 'Wolf',
            'name' => 'Test Badge',
            'image' => UploadedFile::fake()->image('test.png', 400, 400),
            'catchEmAll' => true,
            'publish' => true,
            'tos' => true,
            'upgrades' => ['spareCopy' => false],
        ])->assertRedirect(route('welcome'));
    });

    test('badge routes redirect when event has ended', function () {
        // Create an event that has ended
        Event::factory()->create([
            'starts_at' => now()->subDays(30),
            'ends_at' => now()->subDays(1), // Event ended yesterday
            'order_starts_at' => now()->subDays(25),
            'order_ends_at' => now()->subDays(5),
        ]);

        actingAs($this->user);
        
        // EventEndedMiddleware redirects to welcome when no active event
        get(route('badges.index'))->assertRedirect(route('welcome'));
        get(route('badges.create'))->assertRedirect(route('welcome'));
    });
});

describe('Badge Controller Flow - Event Active, Orders Closed', function () {
    beforeEach(function () {
        // Event exists but order window hasn't started
        $this->event = Event::factory()->create([
            'starts_at' => now()->addDays(10),
            'ends_at' => now()->addDays(40),
            'order_starts_at' => now()->addDays(15),
            'order_ends_at' => now()->addDays(35),
        ]);
    });

    test('event state is CLOSED when order window hasnt started', function () {
        expect($this->event->state)->toBe(EventStateEnum::CLOSED);
        expect($this->event->allowsOrders())->toBeFalse();
    });

    test('badge index is accessible but shows no create button', function () {
        actingAs($this->user);
        
        $response = get(route('badges.index'));
        
        $response->assertSuccessful();
        $response->assertInertia(fn ($page) => 
            $page->where('canCreate', false)
        );
    });

    test('badge creation is forbidden when orders are closed', function () {
        actingAs($this->user);
        
        // Policy should prevent creation
        $response = get(route('badges.create'));
        $response->assertStatus(403);
        
        $response = post(route('badges.store'), [
            'species' => 'Wolf',
            'name' => 'Test Badge',
            'image' => UploadedFile::fake()->image('test.png', 400, 400),
            'catchEmAll' => true,
            'publish' => true,
            'tos' => true,
            'upgrades' => ['spareCopy' => false],
        ]);
        $response->assertStatus(403);
    });
});

describe('Badge Controller Flow - Event Active, Orders Open', function () {
    beforeEach(function () {
        // Event is active and within order window
        $this->event = Event::factory()->create([
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->addDays(28),
            'order_starts_at' => now()->subDays(1),
            'order_ends_at' => now()->addDays(25),
        ]);
    });

    test('getActiveEvent returns correct event', function () {
        $activeEvent = Event::getActiveEvent();
        expect($activeEvent)->not->toBeNull();
        expect($activeEvent->id)->toBe($this->event->id);
    });

    test('event state is OPEN during active order window', function () {
        expect($this->event->state)->toBe(EventStateEnum::OPEN);
        expect($this->event->allowsOrders())->toBeTrue();
    });

    test('badge index shows create button when orders are allowed', function () {
        actingAs($this->user);
        
        $response = get(route('badges.index'));
        
        $response->assertSuccessful();
        $response->assertInertia(fn ($page) => 
            $page->where('canCreate', true)
        );
    });

    test('user can access badge creation form', function () {
        actingAs($this->user);
        
        $response = get(route('badges.create'));
        
        $response->assertSuccessful();
        $response->assertInertia(fn ($page) => 
            $page->component('Badges/BadgesCreate')
        );
    });

    test('user can create regular badge when orders are allowed', function () {
        actingAs($this->user);
        
        $response = post(route('badges.store'), [
            'species' => 'Wolf',
            'name' => 'Test Badge',
            'image' => UploadedFile::fake()->image('test.png', 400, 400),
            'catchEmAll' => true,
            'publish' => true,
            'tos' => true,
            'upgrades' => ['spareCopy' => false],
        ]);
        
        $response->assertRedirect(route('badges.index'));
        
        $this->assertDatabaseHas('badges', [
            'is_free_badge' => false,
        ]);
    });

    test('user with free badge can claim it', function () {
        $this->user->update([
            'has_free_badge' => true,
            'free_badge_copies' => 0,
        ]);
        
        actingAs($this->user);
        
        $response = post(route('badges.store'), [
            'species' => 'Cat',
            'name' => 'Free Badge',
            'image' => UploadedFile::fake()->image('test.png', 400, 400),
            'catchEmAll' => true,
            'publish' => true,
            'tos' => true,
            'upgrades' => ['spareCopy' => false],
        ]);
        
        $response->assertRedirect(route('badges.index'));
        
        $this->assertDatabaseHas('badges', [
            'total' => 0, // Free badge
            'is_free_badge' => true,
        ]);
        
        // User should no longer have free badge available
        $this->user->refresh();
        expect($this->user->has_free_badge)->toBeFalse();
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
    });

    test('user cannot edit printed badges', function () {
        $badge = Badge::factory()
            ->recycle($this->event)
            ->recycle($this->user)
            ->create([
                'status_fulfillment' => \App\Models\Badge\State_Fulfillment\Printed::$name,
            ]);
        
        actingAs($this->user);
        
        get(route('badges.edit', $badge))->assertStatus(403);
        
        put(route('badges.update', $badge), [
            'species' => 'Cat',
            'name' => 'Should Not Update',
            'catchEmAll' => true,
            'publish' => true,
        ])->assertStatus(403);
    });
});

describe('Badge Controller Flow - Order Window Ended', function () {
    beforeEach(function () {
        // Event is still active but order window has ended
        $this->event = Event::factory()->create([
            'starts_at' => now()->subDays(10),
            'ends_at' => now()->addDays(20),
            'order_starts_at' => now()->subDays(8),
            'order_ends_at' => now()->subDays(1), // Order window ended yesterday
        ]);
    });

    test('event state is CLOSED when order window has ended', function () {
        expect($this->event->state)->toBe(EventStateEnum::CLOSED);
        expect($this->event->allowsOrders())->toBeFalse();
    });

    test('badge creation is forbidden when order window has ended', function () {
        actingAs($this->user);
        
        get(route('badges.create'))->assertStatus(403);
        
        post(route('badges.store'), [
            'species' => 'Wolf',
            'name' => 'Test Badge',
            'image' => UploadedFile::fake()->image('test.png', 400, 400),
            'catchEmAll' => true,
            'publish' => true,
            'tos' => true,
            'upgrades' => ['spareCopy' => false],
        ])->assertStatus(403);
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
            $page->where('badges', fn ($badges) => count($badges) === 1)
        );
    });
});

describe('Multiple Events Handling', function () {
    test('getActiveEvent returns correct event when multiple events exist', function () {
        // Create multiple events
        $pastEvent = Event::factory()->create([
            'starts_at' => now()->subDays(60),
            'ends_at' => now()->subDays(30),
        ]);
        
        $activeEvent = Event::factory()->create([
            'starts_at' => now()->subDays(5),
            'ends_at' => now()->addDays(25),
        ]);
        
        $futureEvent = Event::factory()->create([
            'starts_at' => now()->addDays(40),
            'ends_at' => now()->addDays(70),
        ]);
        
        $result = Event::getActiveEvent();
        expect($result?->id)->toBe($activeEvent->id);
    });

    test('getActiveEvent returns null when no active events exist', function () {
        // Create only past and future events
        Event::factory()->create([
            'starts_at' => now()->subDays(60),
            'ends_at' => now()->subDays(30),
        ]);
        
        Event::factory()->create([
            'starts_at' => now()->addDays(40),
            'ends_at' => now()->addDays(70),
        ]);
        
        expect(Event::getActiveEvent())->toBeNull();
    });
});
