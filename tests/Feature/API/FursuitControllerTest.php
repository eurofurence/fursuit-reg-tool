<?php

use App\Models\Event;
use App\Models\EventUser;
use App\Models\Fursuit\Fursuit;
use App\Models\Fursuit\States\Approved;
use App\Models\Fursuit\States\Pending;
use App\Models\Fursuit\States\Rejected;
use App\Models\Species;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Set up API authentication
    config(['api.api_middleware_bearer_token' => 'test-token']);
    $this->withHeaders(['Authorization' => 'Bearer test-token']);

    // Create active event (most recent) and an older event
    $this->oldEvent = Event::factory()->create([
        'name' => 'Old Event',
        'starts_at' => now()->subYears(1),
    ]);
    $this->activeEvent = Event::factory()->create([
        'name' => 'Active Event',
        'starts_at' => now()->subMonths(1),
    ]);
    $this->species = Species::factory()->create(['name' => 'Wolf']);

    // Create users with event users for the active event
    $this->user1 = User::factory()->create(['name' => 'User One']);
    $this->eventUser1 = EventUser::factory()->create([
        'user_id' => $this->user1->id,
        'event_id' => $this->activeEvent->id,
        'attendee_id' => 'ATT001',
        'valid_registration' => true,
    ]);

    $this->user2 = User::factory()->create(['name' => 'User Two']);
    $this->eventUser2 = EventUser::factory()->create([
        'user_id' => $this->user2->id,
        'event_id' => $this->activeEvent->id,
        'attendee_id' => 'ATT002',
        'valid_registration' => true,
    ]);

    // Create a user for the old event (should not appear in results)
    $this->oldUser = User::factory()->create(['name' => 'Old User']);
    $this->oldEventUser = EventUser::factory()->create([
        'user_id' => $this->oldUser->id,
        'event_id' => $this->oldEvent->id,
        'attendee_id' => 'ATT999',
        'valid_registration' => true,
    ]);

    // Create fursuits for the active event
    $this->approvedFursuit = Fursuit::factory()->create([
        'user_id' => $this->user1->id,
        'species_id' => $this->species->id,
        'event_id' => $this->activeEvent->id,
        'name' => 'Approved Wolf',
        'status' => Approved::class,
    ]);

    $this->pendingFursuit = Fursuit::factory()->create([
        'user_id' => $this->user2->id,
        'species_id' => $this->species->id,
        'event_id' => $this->activeEvent->id,
        'name' => 'Pending Fox',
        'status' => Pending::class,
    ]);

    $this->rejectedFursuit = Fursuit::factory()->create([
        'user_id' => $this->user1->id,
        'species_id' => $this->species->id,
        'event_id' => $this->activeEvent->id,
        'name' => 'Rejected Bear',
        'status' => Rejected::class,
    ]);

    // Create a fursuit for the old event (should not appear in results)
    $this->oldFursuit = Fursuit::factory()->create([
        'user_id' => $this->oldUser->id,
        'species_id' => $this->species->id,
        'event_id' => $this->oldEvent->id,
        'name' => 'Old Event Fursuit',
        'status' => Approved::class,
    ]);
});

test('can fetch all approved fursuits by default', function () {
    $response = $this->getJson('/api/fursuits');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'name',
                    'user',
                    'species',
                    'badges_count',
                ],
            ],
            'links',
            'meta',
        ]);

    // Should only return approved fursuit
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.name'))->toBe('Approved Wolf');
});

test('can filter fursuits by attendee id', function () {
    $response = $this->getJson('/api/fursuits?reg_id=ATT001');

    $response->assertStatus(200);

    // Should return fursuits for user1 (approved and rejected)
    expect($response->json('data'))->toHaveCount(1); // Only approved by default
    expect($response->json('data.0.name'))->toBe('Approved Wolf');
});

test('can filter fursuits by name', function () {
    $response = $this->getJson('/api/fursuits?name=Wolf');

    $response->assertStatus(200);

    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.name'))->toBe('Approved Wolf');
});

test('can filter fursuits by status', function () {
    $response = $this->getJson('/api/fursuits?status='.Pending::$name);

    $response->assertStatus(200);

    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.name'))->toBe('Pending Fox');
});

test('can get all fursuits regardless of status with any', function () {
    $response = $this->getJson('/api/fursuits?status=any');

    $response->assertStatus(200);

    // Should return all three fursuits
    expect($response->json('data'))->toHaveCount(3);
});

test('can combine filters', function () {
    $response = $this->getJson('/api/fursuits?reg_id=ATT001&status=any');

    $response->assertStatus(200);

    // Should return both fursuits for user1
    expect($response->json('data'))->toHaveCount(2);
});

test('validates reg_id exists in event_users table', function () {
    $response = $this->getJson('/api/fursuits?reg_id=NONEXISTENT');

    $response->assertStatus(422)
        ->assertJsonValidationErrors('reg_id');
});

test('validates name length', function () {
    $longName = str_repeat('a', 65); // Exceeds max length of 64

    $response = $this->getJson('/api/fursuits?name='.$longName);

    $response->assertStatus(422)
        ->assertJsonValidationErrors('name');
});

test('validates status values', function () {
    $response = $this->getJson('/api/fursuits?status=invalid');

    $response->assertStatus(422)
        ->assertJsonValidationErrors('status');
});

test('returns paginated results', function () {
    // Create more fursuits to test pagination for the active event
    Fursuit::factory(15)->create([
        'species_id' => $this->species->id,
        'event_id' => $this->activeEvent->id,
        'status' => Approved::class,
    ]);

    $response = $this->getJson('/api/fursuits');

    $response->assertStatus(200);

    // Should return 10 items per page (pageSize = 10)
    expect($response->json('data'))->toHaveCount(10);
    expect($response->json('meta.total'))->toBe(16); // 15 created + 1 from beforeEach
});

test('only returns fursuits from active event', function () {
    $response = $this->getJson('/api/fursuits?status=any');

    $response->assertStatus(200);

    // Should return 3 fursuits from active event, not the old event fursuit
    expect($response->json('data'))->toHaveCount(3);

    // Verify all returned fursuits are from the active event
    $fursuitNames = collect($response->json('data'))->pluck('name')->toArray();
    expect($fursuitNames)->toContain('Approved Wolf');
    expect($fursuitNames)->toContain('Pending Fox');
    expect($fursuitNames)->toContain('Rejected Bear');
    expect($fursuitNames)->not->toContain('Old Event Fursuit');
});

test('returns empty result when no active event exists', function () {
    // Delete all events to simulate no active event
    Event::truncate();

    $response = $this->getJson('/api/fursuits');

    $response->assertStatus(200);
    expect($response->json('data'))->toHaveCount(0);
});
