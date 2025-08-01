<?php

use App\Models\Event;
use App\Models\User;
use App\Enum\EventStateEnum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create([
        'has_free_badge' => false,
        'free_badge_copies' => 0,
    ]);
});

describe('Welcome Page Flow States', function () {
    test('shows closed state when no active event exists', function () {
        // No events exist
        $response = get(route('welcome'));
        
        $response->assertSuccessful();
        $response->assertInertia(fn ($page) => 
            $page->component('Welcome')
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
        $response->assertInertia(fn ($page) => 
            $page->where('showState', EventStateEnum::CLOSED->value)
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
        $response->assertInertia(fn ($page) => 
            $page->where('showState', EventStateEnum::CLOSED->value)
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
        $response->assertInertia(fn ($page) => 
            $page->where('showState', EventStateEnum::CLOSED->value)
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
        $response->assertInertia(fn ($page) => 
            $page->where('showState', EventStateEnum::CLOSED->value)
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
        $response->assertInertia(fn ($page) => 
            $page->where('showState', EventStateEnum::OPEN->value)
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
        $response->assertInertia(fn ($page) => 
            $page->where('auth.user', null)
        );
    });

    test('shows appropriate buttons for user with no badges', function () {
        actingAs($this->user);
        
        $response = get(route('welcome'));
        
        $response->assertSuccessful();
        $response->assertInertia(fn ($page) => 
            $page->where('auth.user.id', $this->user->id)
                 ->where('auth.user.has_free_badge', false)
                 ->where('auth.user.free_badge_copies', 0)
        );
    });

    test('shows appropriate buttons for user with free badge', function () {
        $this->user->update([
            'has_free_badge' => true,
            'free_badge_copies' => 0,
        ]);
        
        actingAs($this->user);
        
        $response = get(route('welcome'));
        
        $response->assertSuccessful();
        $response->assertInertia(fn ($page) => 
            $page->where('auth.user.has_free_badge', true)
                 ->where('auth.user.free_badge_copies', 0)
        );
    });

    test('shows appropriate buttons for user with additional free badges', function () {
        $this->user->update([
            'has_free_badge' => true,
            'free_badge_copies' => 3,
        ]);
        
        actingAs($this->user);
        
        $response = get(route('welcome'));
        
        $response->assertSuccessful();
        $response->assertInertia(fn ($page) => 
            $page->where('auth.user.has_free_badge', true)
                 ->where('auth.user.free_badge_copies', 3)
        );
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
        $response->assertInertia(fn ($page) => 
            $page->where('auth.user.badges', fn ($badges) => count($badges) === 2)
        );
    });
});

describe('Welcome Page Mass Print Detection', function () {
    test('shows mass print message when badges were printed early', function () {
        $event = Event::factory()->create([
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->addDays(28),
            'order_starts_at' => now()->subDays(1),
            'order_ends_at' => now()->addDays(25),
            'mass_printed_at' => now()->subDays(3), // Printed before current time
        ]);
        
        $response = get(route('welcome'));
        
        $response->assertSuccessful();
        // The Vue component will check this condition client-side
        $response->assertInertia(fn ($page) => 
            $page->where('event.mass_printed_at', $event->mass_printed_at->toISOString())
        );
    });

    test('does not show mass print message when badges not yet printed', function () {
        $event = Event::factory()->create([
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->addDays(28),
            'order_starts_at' => now()->subDays(1),
            'order_ends_at' => now()->addDays(25),
            'mass_printed_at' => now()->addDays(5), // Will be printed in future
        ]);
        
        $response = get(route('welcome'));
        
        $response->assertSuccessful();
        $response->assertInertia(fn ($page) => 
            $page->where('event.mass_printed_at', $event->mass_printed_at->toISOString())
        );
    });
});
