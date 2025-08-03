<?php

use App\Models\Badge\Badge;
use App\Models\EventUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\post;
use function Pest\Laravel\putJson;
use function Pest\Laravel\travelTo;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->event = \App\Models\Event::factory()->create([
        'starts_at' => \Carbon\Carbon::parse('2024-06-01'),
        'ends_at' => \Carbon\Carbon::parse('2024-06-30'),
        'order_starts_at' => \Carbon\Carbon::parse('2024-06-01'),
        'order_ends_at' => \Carbon\Carbon::parse('2024-06-25'),
    ]);

    // Create EventUser relationship with no prepaid badges
    EventUser::create([
        'user_id' => $this->user->id,
        'event_id' => $this->event->id,
        'attendee_id' => '12345',
        'valid_registration' => true,
        'prepaid_badges' => 0,
    ]);
    // Fake Storage
    Storage::fake('local'); // Use local instead of s3 for tests
    Http::fake(); // Mock all HTTP requests
});

test('user can create badge', function () {
    // Fake Notification
    Notification::fake();

    // Travel to valid time
    travelTo(\Carbon\Carbon::parse('2024-06-02'));
    actingAs($this->user);
    $response = post(route('badges.store'), [
        'species' => 'Wolf',
        'name' => 'Test Badge',
        'image' => UploadedFile::fake()->image('test.png', 400, 400),
        'catchEmAll' => true,
        'publish' => true,
        'tos' => true,
        'upgrades' => [
            'doubleSided' => true,
            'spareCopy' => true,
        ],
    ]);
    // No Errors
    $response->assertSessionHasNoErrors();
    $response->assertStatus(302);
    $this->assertDatabaseHas('fursuits', [
        'name' => 'Test Badge',
        'catch_em_all' => true,
        'published' => true,
    ]);
    $this->assertDatabaseHas('badges', [
        'dual_side_print' => true,
    ]);
    $this->assertDatabaseHas('species', [
        'name' => 'Wolf',
    ]);
    // Get the badge from db
    $badge = Badge::first();
    // check if image was uploaded
    Storage::assertExists($badge->fursuit->image);
    // Check notification was sent
    Notification::assertSentTo($this->user, \App\Notifications\BadgeCreatedNotification::class);
});

test('user cannot create badge when preorder has not started', function () {
    travelTo(\Carbon\Carbon::parse('2024-04-15'));
    actingAs($this->user);
    $response = post(route('badges.store'), [
        'species' => 'Wolf',
        'name' => 'Test Badge',
        'image' => UploadedFile::fake()->image('test.png', 400, 400),
        'catchEmAll' => true,
        'publish' => true,
        'tos' => true,
        'upgrades' => [
            'doubleSided' => true,
            'spareCopy' => true,
        ],
    ])->assertForbidden();
});

test('user cannot create badge when event has ended', function () {
    Notification::fake();
    travelTo(\Carbon\Carbon::parse('2024-07-01'));
    actingAs($this->user);
    post(route('badges.store'), [
        'species' => 'Wolf',
        'name' => 'Test Badge',
        'image' => UploadedFile::fake()->image('test.png', 400, 400),
        'catchEmAll' => true,
        'publish' => true,
        'tos' => true,
        'upgrades' => [
            'doubleSided' => true,
            'spareCopy' => true,
        ],
    ])->assertForbidden();
});

test('user cannot update badge when event has ended', function () {
    $badge = Badge::factory()
        ->recycle(\App\Models\Event::first())
        ->recycle($this->user)
        ->create();
    travelTo(\Carbon\Carbon::parse('2024-07-01'));
    actingAs($this->user);
    putJson(route('badges.update', $badge->id), [
        'species' => 'Wolf',
        'name' => 'Updated Badge Name',
        'catchEmAll' => true,
        'publish' => true,
    ])->assertForbidden();
});
