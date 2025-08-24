<?php

use App\Models\Badge\Badge;
use App\Models\Badge\State_Fulfillment\Transitions\ToPrinted;
use App\Models\Event;
use App\Models\EventUser;
use App\Models\Fursuit\Fursuit;
use App\Models\User;
use App\Notifications\BadgePrintedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    Notification::fake();
});

test('BadgePrintedNotification is sent when badge is printed during event days', function () {
    // Create an event that is currently running (during event days)
    $event = Event::factory()->create([
        'starts_at' => now()->subDays(2), // Event started 2 days ago
        'ends_at' => now()->addDays(3),   // Event ends in 3 days
        'order_starts_at' => now()->subDays(5),
        'order_ends_at' => now()->addDays(1),
    ]);

    // Create a user
    $user = User::factory()->create();

    // Create event user relationship
    EventUser::create([
        'user_id' => $user->id,
        'event_id' => $event->id,
        'attendee_id' => '12345',
        'valid_registration' => true,
        'prepaid_badges' => 0,
    ]);

    // Create a fursuit and badge
    $fursuit = Fursuit::factory()
        ->for($user)
        ->for($event, 'event')
        ->create();

    $badge = Badge::factory()
        ->for($fursuit)
        ->create();

    // Verify event is during event days
    expect($event->isDuringEvent())->toBeTrue();

    // Transition badge to printed state
    $transition = new ToPrinted($badge);
    $transition->handle();

    // Verify notification was sent
    Notification::assertSentTo($user, BadgePrintedNotification::class, function ($notification) use ($badge) {
        return $notification->badge->id === $badge->id;
    });
});

test('BadgePrintedNotification is NOT sent during mass printing before convention', function () {
    // Create an event that hasn't started yet (mass printing phase)
    $event = Event::factory()->create([
        'starts_at' => now()->addDays(10), // Event starts in 10 days
        'ends_at' => now()->addDays(40),   // Event ends in 40 days
        'order_starts_at' => now()->subDays(30), // Orders started 30 days ago
        'order_ends_at' => now()->subDays(5),    // Orders ended 5 days ago
    ]);

    // Create a user
    $user = User::factory()->create();

    // Create event user relationship
    EventUser::create([
        'user_id' => $user->id,
        'event_id' => $event->id,
        'attendee_id' => '67890',
        'valid_registration' => true,
        'prepaid_badges' => 0,
    ]);

    // Create a fursuit and badge
    $fursuit = Fursuit::factory()
        ->for($user)
        ->for($event, 'event')
        ->create();

    $badge = Badge::factory()
        ->for($fursuit)
        ->create();

    // Verify event is NOT during event days
    expect($event->isDuringEvent())->toBeFalse();

    // Transition badge to printed state
    $transition = new ToPrinted($badge);
    $transition->handle();

    // Verify notification was NOT sent
    Notification::assertNotSentTo($user, BadgePrintedNotification::class);
});

test('BadgePrintedNotification is NOT sent after event has ended', function () {
    // Create an event that has already ended
    $event = Event::factory()->create([
        'starts_at' => now()->subDays(40), // Event started 40 days ago
        'ends_at' => now()->subDays(10),   // Event ended 10 days ago
        'order_starts_at' => now()->subDays(50),
        'order_ends_at' => now()->subDays(45),
    ]);

    // Create a user
    $user = User::factory()->create();

    // Create event user relationship
    EventUser::create([
        'user_id' => $user->id,
        'event_id' => $event->id,
        'attendee_id' => '11111',
        'valid_registration' => true,
        'prepaid_badges' => 0,
    ]);

    // Create a fursuit and badge
    $fursuit = Fursuit::factory()
        ->for($user)
        ->for($event, 'event')
        ->create();

    $badge = Badge::factory()
        ->for($fursuit)
        ->create();

    // Verify event is NOT during event days (has ended)
    expect($event->isDuringEvent())->toBeFalse();

    // Transition badge to printed state
    $transition = new ToPrinted($badge);
    $transition->handle();

    // Verify notification was NOT sent
    Notification::assertNotSentTo($user, BadgePrintedNotification::class);
});

test('badge printing during event sets correct status and generates custom_id', function () {
    // Create an event that is currently running
    $event = Event::factory()->create([
        'starts_at' => now()->subDays(1),
        'ends_at' => now()->addDays(4),
        'order_starts_at' => now()->subDays(10),
        'order_ends_at' => now()->addDays(2),
    ]);

    // Create a user
    $user = User::factory()->create();

    // Create event user relationship
    EventUser::create([
        'user_id' => $user->id,
        'event_id' => $event->id,
        'attendee_id' => '99999',
        'valid_registration' => true,
        'prepaid_badges' => 0,
    ]);

    // Create a fursuit and badge
    $fursuit = Fursuit::factory()
        ->for($user)
        ->for($event, 'event')
        ->create();

    $badge = Badge::factory()
        ->for($fursuit)
        ->create([
            'printed_at' => null, // Override factory default
        ]);

    // Verify initial state
    expect($badge->custom_id)->toBeNull();
    expect($badge->printed_at)->toBeNull();

    // Transition badge to printed state
    $transition = new ToPrinted($badge);
    $transition->handle();

    // Refresh badge from database
    $badge->refresh();

    // Verify badge was processed correctly
    expect($badge->custom_id)->toBe('99999-1');
    expect($badge->printed_at)->not()->toBeNull();
    
    // Verify notification was sent (since we're during event)
    Notification::assertSentTo($user, BadgePrintedNotification::class);
});