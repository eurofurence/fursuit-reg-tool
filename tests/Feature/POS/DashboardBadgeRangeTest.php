<?php

namespace Tests\Feature\POS;

use App\Models\Badge\Badge;
use App\Models\Event;
use App\Models\EventUser;
use App\Models\Fursuit\Fursuit;
use App\Models\Machine;
use App\Models\Species;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * On the first day the badges are split into crates by attendee ID and one
 * crate goes to one desk, so a desk's "left to pick up" counter has to count
 * its own crate and nothing else.
 */
class DashboardBadgeRangeTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();

        $this->event = Event::factory()->create([
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
            'order_starts_at' => now()->subDays(10),
            'order_ends_at' => now()->subDays(5),
        ]);
    }

    private function readyBadgeForAttendee(string $attendeeId): Badge
    {
        $user = User::factory()->create();

        EventUser::factory()->create([
            'user_id' => $user->id,
            'event_id' => $this->event->id,
            'attendee_id' => $attendeeId,
        ]);

        $fursuit = Fursuit::factory()->create([
            'user_id' => $user->id,
            'event_id' => $this->event->id,
            'species_id' => Species::factory()->create()->id,
            'status' => 'approved',
        ]);

        return Badge::factory()->create([
            'fursuit_id' => $fursuit->id,
            'status_fulfillment' => 'ready_for_pickup',
            'status_payment' => 'paid',
        ]);
    }

    private function dashboardAs(Machine $machine): TestResponse
    {
        $staff = Staff::factory()->create();

        return $this->actingAs($machine, 'machine')
            ->actingAs($staff, 'machine-user')
            ->get(route('pos.dashboard'));
    }

    #[Test]
    public function it_counts_every_ready_badge_when_no_range_is_set()
    {
        $this->readyBadgeForAttendee('500');
        $this->readyBadgeForAttendee('1500');

        $machine = Machine::factory()->create();

        $this->dashboardAs($machine)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('stats.ready_for_pickup', 2));
    }

    #[Test]
    public function it_counts_only_the_crate_this_desk_holds()
    {
        $this->readyBadgeForAttendee('500');
        $this->readyBadgeForAttendee('1500');
        $this->readyBadgeForAttendee('2500');

        $machine = Machine::factory()->create([
            'badge_range_min' => 1000,
            'badge_range_max' => 1999,
        ]);

        $this->dashboardAs($machine)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('stats.ready_for_pickup', 1)
                ->where('badgeRange.min', 1000)
                ->where('badgeRange.max', 1999)
            );
    }

    /**
     * attendee_id is a string column: compared as text, "1000" sorts below
     * "999" and the crate boundary lands in the wrong place.
     */
    #[Test]
    public function it_compares_attendee_ids_numerically()
    {
        $this->readyBadgeForAttendee('999');
        $this->readyBadgeForAttendee('1000');

        $machine = Machine::factory()->create([
            'badge_range_min' => 1000,
            'badge_range_max' => null,
        ]);

        $this->dashboardAs($machine)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('stats.ready_for_pickup', 1));
    }

    #[Test]
    public function a_desk_can_store_and_clear_its_range()
    {
        $machine = Machine::factory()->create();
        $staff = Staff::factory()->create();

        $this->actingAs($machine, 'machine')
            ->actingAs($staff, 'machine-user')
            ->put(route('pos.machine.badge-range', ['machine' => $machine->id]), [
                'badge_range_min' => 0,
                'badge_range_max' => 999,
            ])->assertRedirect();

        $machine->refresh();
        $this->assertSame(0, $machine->badge_range_min);
        $this->assertSame(999, $machine->badge_range_max);
        $this->assertTrue($machine->hasBadgeRange());

        $this->actingAs($machine, 'machine')
            ->actingAs($staff, 'machine-user')
            ->put(route('pos.machine.badge-range', ['machine' => $machine->id]), [
                'badge_range_min' => null,
                'badge_range_max' => null,
            ])->assertRedirect();

        $machine->refresh();
        $this->assertNull($machine->badge_range_min);
        $this->assertNull($machine->badge_range_max);
        $this->assertFalse($machine->hasBadgeRange());
    }

    #[Test]
    public function it_rejects_a_range_that_ends_before_it_starts()
    {
        $machine = Machine::factory()->create();
        $staff = Staff::factory()->create();

        $this->actingAs($machine, 'machine')
            ->actingAs($staff, 'machine-user')
            ->put(route('pos.machine.badge-range', ['machine' => $machine->id]), [
                'badge_range_min' => 2000,
                'badge_range_max' => 1000,
            ])->assertSessionHasErrors('badge_range_max');

        $this->assertNull($machine->refresh()->badge_range_min);
    }
}
