<?php

use App\Models\Badge\Badge;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use function Pest\Laravel\actingAs;
use function Pest\Laravel\post;
use function Pest\Laravel\put;
use function Pest\Laravel\putJson;
use function Pest\Laravel\travelTo;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->user->update([
        'has_free_badge' => false,
        'free_badge_copies' => 0,
    ]);
    $event = \App\Models\Event::factory()->create([
        'starts_at' => \Carbon\Carbon::parse('2024-06-01'),
        'ends_at' => \Carbon\Carbon::parse('2024-06-30'),
        'order_starts_at' => \Carbon\Carbon::parse('2024-06-01'),
        'order_ends_at' => \Carbon\Carbon::parse('2024-06-25'),
    ]);
    // Fake Storage
    Storage::fake('s3');
});

test('user can create badge', function () {
    // Fake Notification
    Notification::fake();

    // Travel to valid time
    travelTo(\Carbon\Carbon::parse('2024-05-02'));
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
    Storage::disk('s3')->assertExists($badge->image);
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
    ])->assertRedirect(route('welcome'));
});

test('user cannot update badge when event has ended', function () {
    $badge = Badge::factory()
        ->recycle(\App\Models\Event::first())
        ->recycle($this->user)
        ->create();
    travelTo(\Carbon\Carbon::parse('2024-07-01'));
    actingAs($this->user);
    putJson(route('badges.update', $badge->id), [
        'name' => 'Updated Badge Name',
    ])->assertRedirect(route('welcome'));
});
