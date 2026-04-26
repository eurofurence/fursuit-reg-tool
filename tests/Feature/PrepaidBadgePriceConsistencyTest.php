<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventUser;
use App\Models\User;
use App\Models\Species;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PrepaidBadgePriceConsistencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    /** @test */
    public function it_correctly_calculates_badge_as_paid_when_prepaid_badges_exhausted_after_order_starts()
    {
        // Create an event with order_starts_at in the past (so the -1 deduction applies)
        $event = Event::factory()->create([
            'order_starts_at' => now()->subDays(5),
            'order_ends_at' => now()->addDays(30),
            'mass_printed_at' => now()->addDays(10),
        ]);

        // Create a user with 2 prepaid badges
        $user = User::factory()->create();
        $this->actingAs($user);

        EventUser::create([
            'user_id' => $user->id,
            'event_id' => $event->id,
            'attendee_id' => 'TEST-' . $user->id,
            'prepaid_badges' => 2,
            'valid_registration' => true,
        ]);

        // Create species for the badge
        $species = Species::factory()->create(['name' => 'Wolf']);

        // User has already created 1 badge
        $existingBadge = $user->fursuits()->create([
            'event_id' => $event->id,
            'species_id' => $species->id,
            'name' => 'First Fursuit',
            'image' => 'fursuits/test1.jpg',
            'status' => 'approved',
            'published' => false,
            'catch_em_all' => false,
        ])->badges()->create([
            'status_fulfillment' => 'pending',
            'status_payment' => 'paid',
            'subtotal' => 0,
            'tax_rate' => 0.19,
            'tax' => 0,
            'total' => 0,
            'is_free_badge' => true,
            'dual_side_print' => true,
            'apply_late_fee' => false,
            'paid_at' => now(),
        ]);

        // Check what the frontend would see
        $prepaidBadgesLeft = $user->getPrepaidBadgesLeft($event->id);
        
        // After order_starts_at: 2 prepaid - 1 (deduction) - 1 (already ordered) = 0
        $this->assertEquals(0, $prepaidBadgesLeft, 'Frontend should see 0 prepaid badges left');

        // Now create a new badge via the controller
        $response = $this->post(route('badges.store'), [
            'species' => 'Wolf',
            'name' => 'Second Fursuit',
            'image' => UploadedFile::fake()->image('fursuit.jpg', 480, 680),
            'catchEmAll' => false,
            'publish' => false,
            'tos' => true,
            'upgrades' => [
                'spareCopy' => false,
            ],
        ]);

        $response->assertRedirect(route('badges.index'));

        // Get the newly created badge
        $newBadge = $user->badges()
            ->whereHas('fursuit', function ($q) {
                $q->where('name', 'Second Fursuit');
            })
            ->first();

        // Assert the badge was created as PAID (not free)
        $this->assertNotNull($newBadge, 'Badge should be created');
        $this->assertFalse($newBadge->is_free_badge, 'Badge should NOT be marked as free');
        $this->assertEquals(500, $newBadge->total, 'Badge should cost 500 cents (5€)');
        $this->assertEquals('unpaid', $newBadge->status_payment, 'Badge should be unpaid');
    }

    /** @test */
    public function it_correctly_creates_free_badge_when_prepaid_badges_available()
    {
        // Create an event with order_starts_at in the past
        $event = Event::factory()->create([
            'order_starts_at' => now()->subDays(5),
            'order_ends_at' => now()->addDays(30),
            'mass_printed_at' => now()->addDays(10),
        ]);

        // Create a user with 3 prepaid badges
        $user = User::factory()->create();
        $this->actingAs($user);

        EventUser::create([
            'user_id' => $user->id,
            'event_id' => $event->id,
            'attendee_id' => 'TEST-' . $user->id,
            'prepaid_badges' => 3,
            'valid_registration' => true,
        ]);

        // Create species for the badge
        $species = Species::factory()->create(['name' => 'Fox']);

        // User has already created 1 badge
        $existingBadge = $user->fursuits()->create([
            'event_id' => $event->id,
            'species_id' => $species->id,
            'name' => 'First Fursuit',
            'image' => 'fursuits/test1.jpg',
            'status' => 'approved',
            'published' => false,
            'catch_em_all' => false,
        ])->badges()->create([
            'status_fulfillment' => 'pending',
            'status_payment' => 'paid',
            'subtotal' => 0,
            'tax_rate' => 0.19,
            'tax' => 0,
            'total' => 0,
            'is_free_badge' => true,
            'dual_side_print' => true,
            'apply_late_fee' => false,
            'paid_at' => now(),
        ]);

        // Check what the frontend would see
        $prepaidBadgesLeft = $user->getPrepaidBadgesLeft($event->id);
        
        // After order_starts_at: 3 prepaid - 1 (deduction) - 1 (already ordered) = 1
        $this->assertEquals(1, $prepaidBadgesLeft, 'Frontend should see 1 prepaid badge left');

        // Now create a new badge via the controller
        $response = $this->post(route('badges.store'), [
            'species' => 'Fox',
            'name' => 'Second Fursuit',
            'image' => UploadedFile::fake()->image('fursuit.jpg', 480, 680),
            'catchEmAll' => false,
            'publish' => false,
            'tos' => true,
            'upgrades' => [
                'spareCopy' => false,
            ],
        ]);

        $response->assertRedirect(route('badges.index'));

        // Get the newly created badge
        $newBadge = $user->badges()
            ->whereHas('fursuit', function ($q) {
                $q->where('name', 'Second Fursuit');
            })
            ->first();

        // Assert the badge was created as FREE
        $this->assertNotNull($newBadge, 'Badge should be created');
        $this->assertTrue($newBadge->is_free_badge, 'Badge should be marked as free');
        $this->assertEquals(0, $newBadge->total, 'Badge should cost 0 cents (free)');
        $this->assertEquals('paid', $newBadge->status_payment, 'Badge should be marked as paid');
        $this->assertNotNull($newBadge->paid_at, 'Badge should have paid_at timestamp');
    }

    /** @test */
    public function it_correctly_handles_prepaid_badges_before_order_starts()
    {
        // Create an event with order_starts_at in the FUTURE (no -1 deduction)
        $event = Event::factory()->create([
            'order_starts_at' => now()->addDays(5),
            'order_ends_at' => now()->addDays(30),
            'mass_printed_at' => now()->addDays(10),
        ]);

        // Create a user with 1 prepaid badge
        $user = User::factory()->create();
        $this->actingAs($user);

        EventUser::create([
            'user_id' => $user->id,
            'event_id' => $event->id,
            'attendee_id' => 'TEST-' . $user->id,
            'prepaid_badges' => 1,
            'valid_registration' => true,
        ]);

        // Check what the frontend would see
        $prepaidBadgesLeft = $user->getPrepaidBadgesLeft($event->id);
        
        // Before order_starts_at: 1 prepaid - 0 (no deduction) - 0 (no orders) = 1
        $this->assertEquals(1, $prepaidBadgesLeft, 'Frontend should see 1 prepaid badge left before order starts');

        // Create species for the badge
        Species::factory()->create(['name' => 'Dragon']);

        // Create a badge via the controller
        $response = $this->post(route('badges.store'), [
            'species' => 'Dragon',
            'name' => 'Dragon Suit',
            'image' => UploadedFile::fake()->image('fursuit.jpg', 480, 680),
            'catchEmAll' => false,
            'publish' => false,
            'tos' => true,
            'upgrades' => [
                'spareCopy' => false,
            ],
        ]);

        $response->assertRedirect(route('badges.index'));

        // Get the newly created badge
        $newBadge = $user->badges()->first();

        // Assert the badge was created as FREE (prepaid)
        $this->assertNotNull($newBadge, 'Badge should be created');
        $this->assertTrue($newBadge->is_free_badge, 'Badge should be marked as free before order_starts_at');
        $this->assertEquals(0, $newBadge->total, 'Badge should cost 0 cents (free)');
        $this->assertEquals('paid', $newBadge->status_payment, 'Badge should be marked as paid');
    }

    /** @test */
    public function it_shows_consistent_pricing_in_frontend_and_backend()
    {
        // Create an event with order_starts_at in the past
        $event = Event::factory()->create([
            'order_starts_at' => now()->subDays(5),
            'order_ends_at' => now()->addDays(30),
            'mass_printed_at' => now()->addDays(10),
        ]);

        // Create a user with 2 prepaid badges
        $user = User::factory()->create();
        $this->actingAs($user);

        EventUser::create([
            'user_id' => $user->id,
            'event_id' => $event->id,
            'attendee_id' => 'TEST-' . $user->id,
            'prepaid_badges' => 2,
            'valid_registration' => true,
        ]);

        // Create species
        $species = Species::factory()->create(['name' => 'Cat']);

        // User has already created 1 badge
        $user->fursuits()->create([
            'event_id' => $event->id,
            'species_id' => $species->id,
            'name' => 'First Cat',
            'image' => 'fursuits/test1.jpg',
            'status' => 'approved',
            'published' => false,
            'catch_em_all' => false,
        ])->badges()->create([
            'status_fulfillment' => 'pending',
            'status_payment' => 'paid',
            'subtotal' => 0,
            'tax_rate' => 0.19,
            'tax' => 0,
            'total' => 0,
            'is_free_badge' => true,
            'dual_side_print' => true,
            'apply_late_fee' => false,
            'paid_at' => now(),
        ]);

        // Visit the create page to see what frontend would display
        $response = $this->get(route('badges.create'));
        $response->assertOk();
        
        // Check the prepaidBadgesLeft value passed to frontend
        $prepaidBadgesLeft = $response->viewData('page')['props']['prepaidBadgesLeft'];
        
        // After order_starts_at: 2 prepaid - 1 (deduction) - 1 (already ordered) = 0
        $this->assertEquals(0, $prepaidBadgesLeft, 'Frontend should receive 0 prepaid badges left');

        // Now create a badge and verify it's consistent
        $storeResponse = $this->post(route('badges.store'), [
            'species' => 'Cat',
            'name' => 'Second Cat',
            'image' => UploadedFile::fake()->image('fursuit.jpg', 480, 680),
            'catchEmAll' => false,
            'publish' => false,
            'tos' => true,
            'upgrades' => [
                'spareCopy' => false,
            ],
        ]);

        $storeResponse->assertRedirect(route('badges.index'));

        // Get the newly created badge
        $newBadge = $user->badges()
            ->whereHas('fursuit', function ($q) {
                $q->where('name', 'Second Cat');
            })
            ->first();

        // Verify consistency: if frontend shows 0 prepaid left, badge should be paid
        if ($prepaidBadgesLeft == 0) {
            $this->assertFalse($newBadge->is_free_badge, 'Badge should NOT be free when frontend shows 0 prepaid left');
            $this->assertEquals(500, $newBadge->total, 'Badge should cost 5€ when no prepaid badges left');
        } else {
            $this->assertTrue($newBadge->is_free_badge, 'Badge should be free when prepaid badges available');
            $this->assertEquals(0, $newBadge->total, 'Badge should cost 0€ when prepaid badges available');
        }
    }
}
