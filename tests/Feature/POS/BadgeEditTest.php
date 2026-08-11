<?php

namespace Tests\Feature\POS;

use App\Domain\Checkout\Models\Checkout\Checkout;
use App\Domain\Checkout\Models\Checkout\States\Active;
use App\Domain\Checkout\Models\Checkout\States\Cancelled;
use App\Domain\Checkout\Services\CheckoutService;
use App\Models\Badge\Badge;
use App\Models\Event;
use App\Models\Fursuit\Fursuit;
use App\Models\Machine;
use App\Models\RfidTag;
use App\Models\Species;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Desk corrections: editing a badge, and the manager gate on its price.
 */
class BadgeEditTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    private Machine $machine;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->event = Event::factory()->create([
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
            'order_starts_at' => now()->subDays(10),
            'order_ends_at' => now()->subDays(5),
        ]);

        $this->machine = Machine::factory()->create();
        $this->customer = User::factory()->create();
    }

    private function badge(array $attributes = []): Badge
    {
        $fursuit = Fursuit::factory()->create([
            'user_id' => $this->customer->id,
            'event_id' => $this->event->id,
            'species_id' => Species::factory()->create(['name' => 'Wolf'])->id,
            'status' => 'approved',
            'name' => 'Fluffy',
            'published' => false,
            'catch_em_all' => false,
        ]);

        return Badge::factory()->create(array_merge([
            'fursuit_id' => $fursuit->id,
            'status_fulfillment' => 'ready_for_pickup',
            'status_payment' => 'unpaid',
            'subtotal' => 420,
            'tax' => 80,
            'total' => 500,
            'tax_rate' => 0.19,
        ], $attributes));
    }

    private function actingAsStaff(Staff $staff): self
    {
        $this->actingAs($this->machine, 'machine')->actingAs($staff, 'machine-user');

        return $this;
    }

    private function cashier(): Staff
    {
        return Staff::factory()->create(['is_manager' => false, 'pin_code' => '111111']);
    }

    private function manager(string $pin = '654321'): Staff
    {
        return Staff::factory()->create(['is_manager' => true, 'pin_code' => $pin]);
    }

    private function overrideAs(Staff $staff, Badge $badge, int $cents, ?string $code = null): TestResponse
    {
        return $this->actingAsStaff($staff)->post(route('pos.badges.prices'), [
            'prices' => [$badge->id => $cents],
            'manager_code' => $code,
        ]);
    }

    #[Test]
    public function a_cashier_can_edit_badge_details_without_a_manager()
    {
        $badge = $this->badge();

        $this->actingAsStaff($this->cashier())
            ->put(route('pos.badges.update', ['badge' => $badge->id]), [
                'name' => 'Rex',
                'species' => 'Dragon',
                'dual_side_print' => true,
                'published' => true,
                'catch_em_all' => true,
            ])
            ->assertRedirect();

        $badge->refresh();
        $fursuit = $badge->fursuit->refresh();

        $this->assertSame('Rex', $fursuit->name);
        $this->assertSame('Dragon', $fursuit->species->name);
        $this->assertTrue((bool) $badge->dual_side_print);
        $this->assertTrue((bool) $fursuit->published);
        $this->assertTrue((bool) $fursuit->catch_em_all);
    }

    /**
     * The attendee-facing edit sends the fursuit back for review. This one must
     * not: the badge is being handed over now, and a pending fursuit would drop
     * out of the desk's own lists.
     */
    #[Test]
    public function editing_at_the_desk_leaves_the_fursuit_approved()
    {
        $badge = $this->badge();

        $this->actingAsStaff($this->cashier())
            ->put(route('pos.badges.update', ['badge' => $badge->id]), [
                'name' => 'Rex',
                'species' => 'Dragon',
                'dual_side_print' => false,
                'published' => false,
                'catch_em_all' => false,
            ]);

        $this->assertSame('approved', $badge->fursuit->refresh()->status->getValue());
    }

    #[Test]
    public function a_cashier_cannot_override_a_price_without_approval()
    {
        $badge = $this->badge();

        $this->overrideAs($this->cashier(), $badge, 0)
            ->assertSessionHasErrors('manager_code');

        $this->assertSame(500, (int) $badge->refresh()->total);
    }

    #[Test]
    public function a_non_manager_pin_does_not_approve()
    {
        $badge = $this->badge();
        Staff::factory()->create(['is_manager' => false, 'pin_code' => '222222']);

        $this->overrideAs($this->cashier(), $badge, 0, '222222')
            ->assertSessionHasErrors('manager_code');

        $this->assertSame(500, (int) $badge->refresh()->total);
    }

    #[Test]
    public function a_manager_pin_approves_an_override_for_a_cashier()
    {
        $badge = $this->badge();
        $this->manager('654321');

        $this->overrideAs($this->cashier(), $badge, 0, '654321')
            ->assertSessionHasNoErrors();

        $badge->refresh();

        $this->assertSame(0, (int) $badge->total);
        $this->assertSame(0, (int) $badge->subtotal);
        $this->assertSame(0, (int) $badge->tax);
    }

    #[Test]
    public function a_manager_rfid_tag_approves_an_override()
    {
        $badge = $this->badge();
        $manager = $this->manager();

        RfidTag::create([
            'staff_id' => $manager->id,
            'content' => '12345678',
            'is_active' => true,
        ]);

        $this->overrideAs($this->cashier(), $badge, 250, '12345678')
            ->assertSessionHasNoErrors();

        $this->assertSame(250, (int) $badge->refresh()->total);
    }

    #[Test]
    public function an_inactive_manager_tag_does_not_approve()
    {
        $badge = $this->badge();
        $manager = $this->manager();

        RfidTag::create([
            'staff_id' => $manager->id,
            'content' => '87654321',
            'is_active' => false,
        ]);

        $this->overrideAs($this->cashier(), $badge, 250, '87654321')
            ->assertSessionHasErrors('manager_code');
    }

    #[Test]
    public function a_manager_signed_in_at_the_till_needs_no_code()
    {
        $badge = $this->badge();

        $this->overrideAs($this->manager(), $badge, 750)
            ->assertSessionHasNoErrors();

        $this->assertSame(750, (int) $badge->refresh()->total);
    }

    #[Test]
    public function an_override_recomputes_the_tax_split()
    {
        $badge = $this->badge();

        $this->overrideAs($this->manager(), $badge, 1000);

        $badge->refresh();

        // 10.00 gross at 19%: 8.40 net, 1.60 tax, and the two must add back up.
        $this->assertSame(1000, (int) $badge->total);
        $this->assertSame(840, (int) $badge->subtotal);
        $this->assertSame(160, (int) $badge->tax);
        $this->assertSame((int) $badge->total, (int) $badge->subtotal + (int) $badge->tax);
    }

    #[Test]
    public function a_paid_badge_cannot_be_repriced()
    {
        $badge = $this->badge(['status_payment' => 'paid']);

        $this->overrideAs($this->manager(), $badge, 0)
            ->assertSessionHasErrors('prices');

        $this->assertSame(500, (int) $badge->refresh()->total);
    }

    #[Test]
    public function an_override_is_recorded_with_who_approved_it()
    {
        $badge = $this->badge();
        $manager = $this->manager();

        $this->overrideAs($this->cashier(), $badge, 0, $manager->pin_code);

        $activity = $badge->activities()->where('description', 'Badge price overridden')->latest('id')->first();

        $this->assertNotNull($activity);
        $this->assertSame(500, $activity->properties['from']);
        $this->assertSame(0, $activity->properties['to']);
        $this->assertSame($manager->id, $activity->properties['approved_by_staff_id']);
    }

    /**
     * The Fiskaly receipt is signed against a total, so a repriced transaction
     * cannot be edited in place: the open one is cancelled and a new one opens
     * at the new price, holding the same badges.
     */
    #[Test]
    public function overriding_a_price_reopens_the_active_checkout()
    {
        $badge = $this->badge();
        $manager = $this->manager();

        $this->actingAsStaff($manager);

        $checkout = (new CheckoutService)->create(collect([$badge]), $this->customer->id);

        $this->assertSame(500, (int) $checkout->total);

        $response = $this->post(route('pos.badges.prices'), [
            'prices' => [$badge->id => 0],
        ]);

        $rebuilt = Checkout::where('machine_id', $this->machine->id)
            ->where('status', Active::$name)
            ->latest('id')
            ->first();

        $this->assertNotNull($rebuilt);
        $this->assertNotSame($checkout->id, $rebuilt->id);
        $this->assertSame(0, (int) $rebuilt->total);
        $this->assertSame([$badge->id], $rebuilt->items->pluck('payable_id')->all());

        $response->assertRedirect(route('pos.checkout.show', ['checkout' => $rebuilt->id]));

        $this->assertTrue($checkout->refresh()->status->equals(Cancelled::class));
    }

    #[Test]
    public function an_override_does_not_touch_a_checkout_on_another_till()
    {
        $badge = $this->badge();
        $manager = $this->manager();

        $otherMachine = Machine::factory()->create();

        $otherCheckout = Checkout::create([
            'remote_id' => 'other-till',
            'remote_rev_count' => 1,
            'status' => 'ACTIVE',
            'user_id' => $this->customer->id,
            'cashier_id' => $manager->id,
            'machine_id' => $otherMachine->id,
            'total' => 500,
            'tax' => 80,
            'subtotal' => 420,
            'fiskaly_data' => [],
        ]);
        $otherCheckout->items()->create([
            'payable_type' => Badge::class,
            'payable_id' => $badge->id,
            'name' => 'Fursuit Badge',
            'description' => [],
            'total' => 500,
            'tax' => 80,
            'subtotal' => 420,
        ]);

        $this->overrideAs($manager, $badge, 0)->assertSessionHasNoErrors();

        $this->assertTrue($otherCheckout->refresh()->status->equals(Active::class));
    }
}
