<?php

use App\Enum\EventStateEnum;
use App\Models\Event;
use App\Models\EventUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
});

describe('Welcome Page Flow States', function () {
    test('shows closed state when no active event exists', function () {
        // No events exist
        $response = get(route('welcome'));

        $response->assertSuccessful();
        $response->assertInertia(fn ($page) => $page->component('Welcome')
            ->where('showState', EventStateEnum::CLOSED->value)
        );
    });

    test('shows closed state when event has ended', function () {
        Event::factory()->create([
            'starts_at' => now()->subDays(30),
            'ends_at' => now()->subDays(1),
            'order_starts_at' => now()->subDays(25),
            'order_ends_at' => now()->subDays(5),
        ]);

        $response = get(route('welcome'));

        $response->assertSuccessful();
        $response->assertInertia(fn ($page) => $page->where('showState', EventStateEnum::CLOSED->value)
        );
    });

    test('shows closed state when event hasnt started', function () {
        Event::factory()->create([
            'starts_at' => now()->addDays(10),
            'ends_at' => now()->addDays(40),
            'order_starts_at' => now()->addDays(15),
            'order_ends_at' => now()->addDays(35),
        ]);

        $response = get(route('welcome'));

        $response->assertSuccessful();
        $response->assertInertia(fn ($page) => $page->where('showState', EventStateEnum::CLOSED->value)
        );
    });

    test('shows closed state when order window hasnt started', function () {
        Event::factory()->create([
            'starts_at' => now()->subDays(5),
            'ends_at' => now()->addDays(25),
            'order_starts_at' => now()->addDays(5),
            'order_ends_at' => now()->addDays(20),
        ]);

        $response = get(route('welcome'));

        $response->assertSuccessful();
        $response->assertInertia(fn ($page) => $page->where('showState', EventStateEnum::CLOSED->value)
        );
    });

    test('shows closed state when order window has ended', function () {
        Event::factory()->create([
            'starts_at' => now()->subDays(10),
            'ends_at' => now()->addDays(20),
            'order_starts_at' => now()->subDays(8),
            'order_ends_at' => now()->subDays(1),
        ]);

        $response = get(route('welcome'));

        $response->assertSuccessful();
        $response->assertInertia(fn ($page) => $page->where('showState', EventStateEnum::CLOSED->value)
        );
    });

    test('shows open state when in order window', function () {
        Event::factory()->create([
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->addDays(28),
            'order_starts_at' => now()->subDays(1),
            'order_ends_at' => now()->addDays(25),
        ]);

        $response = get(route('welcome'));

        $response->assertSuccessful();
        $response->assertInertia(fn ($page) => $page->where('showState', EventStateEnum::OPEN->value)
        );
    });
});

describe('Welcome Page User Interface States', function () {
    beforeEach(function () {
        // Create an active event for these tests
        $this->event = Event::factory()->create([
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->addDays(28),
            'order_starts_at' => now()->subDays(1),
            'order_ends_at' => now()->addDays(25),
        ]);
    });

    test('shows login button when user is not authenticated', function () {
        $response = get(route('welcome'));

        $response->assertSuccessful();
        // When not authenticated, user data is null
        $response->assertInertia(fn ($page) => $page->where('auth.user', null)
        );
    });

    test('shows appropriate buttons for user with no badges', function () {
        actingAs($this->user);

        $response = get(route('welcome'));

        $response->assertSuccessful();
        $response->assertInertia(fn ($page) => $page->where('auth.user.id', $this->user->id)
        );

        // Verify no EventUser exists (no prepaid badges)
        expect($this->user->eventUser($this->event->id))->toBeNull();
    });

    test('shows appropriate buttons for user with free badge', function () {
        $eventUser = EventUser::create([
            'user_id' => $this->user->id,
            'event_id' => $this->event->id,
            'attendee_id' => '12345',
            'valid_registration' => true,
            'prepaid_badges' => 1,
        ]);

        actingAs($this->user);

        $response = get(route('welcome'));

        $response->assertSuccessful();
        $response->assertInertia(fn ($page) => $page->where('auth.user.id', $this->user->id)
        );

        // Verify EventUser has 1 prepaid badge (no additional copies)
        expect($eventUser->prepaid_badges)->toBe(1);
        expect($eventUser->hasFreeBadge())->toBeTrue();
        expect($eventUser->free_badge_copies)->toBe(0);
    });

    test('shows appropriate buttons for user with additional free badges', function () {
        $eventUser = EventUser::create([
            'user_id' => $this->user->id,
            'event_id' => $this->event->id,
            'attendee_id' => '12345',
            'valid_registration' => true,
            'prepaid_badges' => 4, // 1 main + 3 copies
        ]);

        actingAs($this->user);

        $response = get(route('welcome'));

        $response->assertSuccessful();
        $response->assertInertia(fn ($page) => $page->where('auth.user.id', $this->user->id)
        );

        // Verify EventUser has 4 prepaid badges (1 main + 3 additional copies)
        expect($eventUser->prepaid_badges)->toBe(4);
        expect($eventUser->hasFreeBadge())->toBeTrue();
        expect($eventUser->free_badge_copies)->toBe(3);
    });

    test('shows badges count for user with existing badges', function () {
        // Create some badges for the user
        \App\Models\Badge\Badge::factory()
            ->count(2)
            ->recycle($this->event)
            ->recycle($this->user)
            ->create();

        actingAs($this->user);

        $response = get(route('welcome'));

        $response->assertSuccessful();
        $response->assertInertia(fn ($page) => $page->where('auth.user.badges', fn ($badges) => count($badges) === 2)
        );
    });
});
