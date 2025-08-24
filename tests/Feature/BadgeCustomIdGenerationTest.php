<?php

use App\Models\Badge\Badge;
use App\Models\Badge\State_Fulfillment\Transitions\ToPrinted;
use App\Models\Event;
use App\Models\EventUser;
use App\Models\Fursuit\Fursuit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('badge custom id generation uses correct event attendee id', function () {
    // Create two events - an older event and a current event
    $olderEvent = Event::factory()->create([
        'name' => 'Older Event',
        'starts_at' => now()->subYears(2)->startOfDay(),
        'ends_at' => now()->subYears(2)->addDays(30)->endOfDay(),
        'order_starts_at' => now()->subYears(2)->startOfDay(),
        'order_ends_at' => now()->subYears(2)->addDays(25)->endOfDay(),
    ]);

    $currentEvent = Event::factory()->create([
        'name' => 'Current Event',
        'starts_at' => now()->addMonths(6)->startOfDay(),
        'ends_at' => now()->addMonths(6)->addDays(30)->endOfDay(),
        'order_starts_at' => now()->subDays(30)->startOfDay(),
        'order_ends_at' => now()->addDays(30)->endOfDay(),
    ]);

    // Create a user
    $user = User::factory()->create();

    // Create event user relationships - user had attendee_id 14 in older event, 25 in current event
    EventUser::create([
        'user_id' => $user->id,
        'event_id' => $olderEvent->id,
        'attendee_id' => '14',
        'valid_registration' => true,
        'prepaid_badges' => 0,
    ]);

    EventUser::create([
        'user_id' => $user->id,
        'event_id' => $currentEvent->id,
        'attendee_id' => '25',
        'valid_registration' => true,
        'prepaid_badges' => 0,
    ]);

    // Create a fursuit for the current event
    $fursuit = Fursuit::factory()
        ->for($user)
        ->for($currentEvent, 'event')
        ->create([
            'name' => 'Test Fursuit',
        ]);

    // Create a badge for this fursuit
    $badge = Badge::factory()
        ->for($fursuit)
        ->create();

    // Simulate printing the badge (this triggers custom_id generation)
    $transition = new ToPrinted($badge);
    $transition->handle();

    // Refresh the badge from database
    $badge->refresh();

    // The custom_id should use the attendee_id from the current event (25), not the older event (14)
    expect($badge->custom_id)->toBe('25-1');
});

test('badge custom id increments correctly within same event', function () {
    // Create an event
    $event = Event::factory()->create([
        'starts_at' => now()->addMonths(6)->startOfDay(),
        'ends_at' => now()->addMonths(6)->addDays(30)->endOfDay(),
        'order_starts_at' => now()->subDays(30)->startOfDay(),
        'order_ends_at' => now()->addDays(30)->endOfDay(),
    ]);

    // Create a user
    $user = User::factory()->create();

    // Create event user relationship
    EventUser::create([
        'user_id' => $user->id,
        'event_id' => $event->id,
        'attendee_id' => '42',
        'valid_registration' => true,
        'prepaid_badges' => 0,
    ]);

    // Create multiple fursuits for the same event
    $fursuit1 = Fursuit::factory()
        ->for($user)
        ->for($event, 'event')
        ->create(['name' => 'First Fursuit']);

    $fursuit2 = Fursuit::factory()
        ->for($user)
        ->for($event, 'event')
        ->create(['name' => 'Second Fursuit']);

    // Create badges for these fursuits
    $badge1 = Badge::factory()->for($fursuit1)->create();
    $badge2 = Badge::factory()->for($fursuit2)->create();

    // Print the badges (this triggers custom_id generation)
    (new ToPrinted($badge1))->handle();
    (new ToPrinted($badge2))->handle();

    // Refresh badges from database
    $badge1->refresh();
    $badge2->refresh();

    // The custom_ids should increment correctly: 42-1, 42-2
    expect($badge1->custom_id)->toBe('42-1');
    expect($badge2->custom_id)->toBe('42-2');
});

test('badge custom id does not conflict across different events', function () {
    // Create two events
    $event1 = Event::factory()->create([
        'name' => 'Event 1',
        'starts_at' => now()->addMonths(6)->startOfDay(),
        'ends_at' => now()->addMonths(6)->addDays(30)->endOfDay(),
        'order_starts_at' => now()->subDays(30)->startOfDay(),
        'order_ends_at' => now()->addDays(30)->endOfDay(),
    ]);

    $event2 = Event::factory()->create([
        'name' => 'Event 2',
        'starts_at' => now()->addMonths(12)->startOfDay(),
        'ends_at' => now()->addMonths(12)->addDays(30)->endOfDay(),
        'order_starts_at' => now()->addMonths(6)->startOfDay(),
        'order_ends_at' => now()->addMonths(12)->subDays(5)->endOfDay(),
    ]);

    // Create two users with the same attendee_id but for different events
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();

    // Both users have attendee_id 14 but in different events
    EventUser::create([
        'user_id' => $user1->id,
        'event_id' => $event1->id,
        'attendee_id' => '14',
        'valid_registration' => true,
        'prepaid_badges' => 0,
    ]);

    EventUser::create([
        'user_id' => $user2->id,
        'event_id' => $event2->id,
        'attendee_id' => '14',
        'valid_registration' => true,
        'prepaid_badges' => 0,
    ]);

    // Create fursuits for both events
    $fursuit1 = Fursuit::factory()
        ->for($user1)
        ->for($event1, 'event')
        ->create(['name' => 'Event 1 Fursuit']);

    $fursuit2 = Fursuit::factory()
        ->for($user2)
        ->for($event2, 'event')
        ->create(['name' => 'Event 2 Fursuit']);

    // Create badges for these fursuits
    $badge1 = Badge::factory()->for($fursuit1)->create();
    $badge2 = Badge::factory()->for($fursuit2)->create();

    // Print the badges
    (new ToPrinted($badge1))->handle();
    (new ToPrinted($badge2))->handle();

    // Refresh badges from database
    $badge1->refresh();
    $badge2->refresh();

    // Both badges should have custom_id 14-1 since they're in different events
    expect($badge1->custom_id)->toBe('14-1');
    expect($badge2->custom_id)->toBe('14-1');
});

test('badge custom id generation handles concurrent requests without race conditions', function () {
    // Create an event
    $event = Event::factory()->create([
        'starts_at' => now()->addMonths(6)->startOfDay(),
        'ends_at' => now()->addMonths(6)->addDays(30)->endOfDay(),
        'order_starts_at' => now()->subDays(30)->startOfDay(),
        'order_ends_at' => now()->addDays(30)->endOfDay(),
    ]);

    // Create a user
    $user = User::factory()->create();

    // Create event user relationship
    EventUser::create([
        'user_id' => $user->id,
        'event_id' => $event->id,
        'attendee_id' => '99',
        'valid_registration' => true,
        'prepaid_badges' => 0,
    ]);

    // Create multiple fursuits for concurrent processing
    $fursuits = [];
    $badges = [];
    for ($i = 0; $i < 5; $i++) {
        $fursuit = Fursuit::factory()
            ->for($user)
            ->for($event, 'event')
            ->create(['name' => "Concurrent Fursuit {$i}"]);
        $fursuits[] = $fursuit;
        $badges[] = Badge::factory()->for($fursuit)->create();
    }

    // Simulate concurrent printing (in real scenario this would be multiple processes/threads)
    // Process all badges through the transition
    foreach ($badges as $badge) {
        (new ToPrinted($badge))->handle();
    }

    // Refresh badges from database
    $customIds = [];
    foreach ($badges as $badge) {
        $badge->refresh();
        $customIds[] = $badge->custom_id;
    }

    // All custom_ids should be unique and sequential: 99-1, 99-2, 99-3, 99-4, 99-5
    $expectedIds = ['99-1', '99-2', '99-3', '99-4', '99-5'];
    sort($customIds); // Sort to ensure we can compare reliably

    expect($customIds)->toBe($expectedIds);
    expect(count(array_unique($customIds)))->toBe(5); // Ensure all IDs are unique
});

test('badge custom id generation with existing badges continues sequential numbering', function () {
    // Create an event
    $event = Event::factory()->create([
        'starts_at' => now()->addMonths(6)->startOfDay(),
        'ends_at' => now()->addMonths(6)->addDays(30)->endOfDay(),
        'order_starts_at' => now()->subDays(30)->startOfDay(),
        'order_ends_at' => now()->addDays(30)->endOfDay(),
    ]);

    // Create a user
    $user = User::factory()->create();

    // Create event user relationship
    EventUser::create([
        'user_id' => $user->id,
        'event_id' => $event->id,
        'attendee_id' => '88',
        'valid_registration' => true,
        'prepaid_badges' => 0,
    ]);

    // Create first two fursuits and badges, then print them
    $fursuit1 = Fursuit::factory()
        ->for($user)
        ->for($event, 'event')
        ->create(['name' => 'First Fursuit']);

    $fursuit2 = Fursuit::factory()
        ->for($user)
        ->for($event, 'event')
        ->create(['name' => 'Second Fursuit']);

    $badge1 = Badge::factory()->for($fursuit1)->create();
    $badge2 = Badge::factory()->for($fursuit2)->create();

    // Print these badges first
    (new ToPrinted($badge1))->handle();
    (new ToPrinted($badge2))->handle();

    // Refresh to get custom_ids
    $badge1->refresh();
    $badge2->refresh();

    // Verify first two badges got 88-1 and 88-2
    expect($badge1->custom_id)->toBe('88-1');
    expect($badge2->custom_id)->toBe('88-2');

    // Now create a third badge later
    $fursuit3 = Fursuit::factory()
        ->for($user)
        ->for($event, 'event')
        ->create(['name' => 'Third Fursuit']);

    $badge3 = Badge::factory()->for($fursuit3)->create();

    // Print the third badge - it should get 88-3, not 88-1
    (new ToPrinted($badge3))->handle();
    $badge3->refresh();

    expect($badge3->custom_id)->toBe('88-3');
});
