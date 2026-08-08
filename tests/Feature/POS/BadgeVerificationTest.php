<?php

namespace Tests\Feature\POS;

use App\Domain\Printing\Models\PrintJob;
use App\Enum\PrintJobStatusEnum;
use App\Enum\PrintJobTypeEnum;
use App\Enum\PrintVerificationSourceEnum;
use App\Models\Badge\Badge;
use App\Models\Event;
use App\Models\EventUser;
use App\Models\Fursuit\Fursuit;
use App\Models\Machine;
use App\Models\Species;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Checking a crate of printed cards off, card by card.
 *
 * The screen exists to answer one question at the end of the crate: which cards
 * were printed and never turned up. Everything here is a way of getting that
 * answer wrong - a copy checked off in place of its sibling, a number typed
 * twice, a card checked off that is not in your hand.
 */
class BadgeVerificationTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    private Machine $machine;

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

        $this->actingAs($this->machine, 'machine')
            ->actingAs(Staff::factory()->create(['name' => 'Desk staff']), 'machine-user');
    }

    /**
     * A printed badge belonging to one attendee, with its custom_id already
     * allocated - which is what the fulfillment transition does, and what the
     * number on the physical card is.
     */
    private function printedBadge(string $attendeeId, int $copy = 1): Badge
    {
        $user = User::factory()->create();

        EventUser::factory()->create([
            'user_id' => $user->id,
            'event_id' => $this->event->id,
            'attendee_id' => $attendeeId,
        ]);

        return $this->copyFor($user, $attendeeId, $copy);
    }

    private function copyFor(User $user, string $attendeeId, int $copy): Badge
    {
        $fursuit = Fursuit::factory()->create([
            'user_id' => $user->id,
            'event_id' => $this->event->id,
            'species_id' => Species::factory()->create()->id,
            'status' => 'approved',
        ]);

        return Badge::factory()->create([
            'fursuit_id' => $fursuit->id,
            'custom_id' => $attendeeId.'-'.$copy,
            'status_fulfillment' => 'ready_for_pickup',
            'status_payment' => 'paid',
            'printed_at' => now()->subHour(),
        ]);
    }

    private function printJobFor(Badge $badge): PrintJob
    {
        return PrintJob::factory()->create([
            'printable_type' => $badge::class,
            'printable_id' => $badge->id,
            'type' => PrintJobTypeEnum::Badge,
            'status' => PrintJobStatusEnum::Printed,
            'printed_at' => now()->subHour(),
        ]);
    }

    public function test_a_bare_attendee_number_checks_off_the_first_copy(): void
    {
        $badge = $this->printedBadge('1234');

        $this->post(route('pos.verification.store'), ['badge_id' => '1234'])
            ->assertRedirect();

        $this->assertNotNull($badge->fresh()->verified_print_at);
    }

    public function test_a_second_copy_has_to_be_typed_in_full(): void
    {
        $first = $this->printedBadge('1234');
        $second = $this->copyFor($first->fursuit->user, '1234', 2);

        // The bare number is copy 1 and nothing else: guessing which copy is in
        // the operator's hand is the mistake this screen exists to catch.
        $this->post(route('pos.verification.store'), ['badge_id' => '1234']);

        $this->assertNotNull($first->fresh()->verified_print_at);
        $this->assertNull($second->fresh()->verified_print_at);

        $this->post(route('pos.verification.store'), ['badge_id' => '1234-2']);

        $this->assertNotNull($second->fresh()->verified_print_at);
    }

    public function test_the_stamp_goes_through_the_print_job_so_the_agent_and_the_desk_agree(): void
    {
        $badge = $this->printedBadge('1234');
        $job = $this->printJobFor($badge);

        $this->post(route('pos.verification.store'), ['badge_id' => '1234']);

        $job->refresh();

        $this->assertNotNull($job->verified_print_at);
        $this->assertSame(PrintVerificationSourceEnum::Operator, $job->verification_source);
        $this->assertNotNull($badge->fresh()->verified_print_at);
    }

    public function test_a_badge_with_no_print_job_is_still_checkable_off(): void
    {
        $badge = $this->printedBadge('1234');

        $this->post(route('pos.verification.store'), ['badge_id' => '1234']);

        $this->assertNotNull($badge->fresh()->verified_print_at);
    }

    public function test_a_number_typed_twice_says_so_and_keeps_the_first_stamp(): void
    {
        $badge = $this->printedBadge('1234');

        $this->travelTo(now()->subMinutes(10));
        $this->post(route('pos.verification.store'), ['badge_id' => '1234']);
        $this->travelBack();

        $stamped = $badge->fresh()->verified_print_at;

        $this->post(route('pos.verification.store'), ['badge_id' => '1234'])
            ->assertSessionHas('verification', fn (array $result) => $result['status'] === 'duplicate');

        $this->assertTrue($badge->fresh()->verified_print_at->equalTo($stamped));
    }

    public function test_an_unknown_attendee_and_a_missing_copy_are_told_apart(): void
    {
        $this->printedBadge('1234');

        $this->post(route('pos.verification.store'), ['badge_id' => '9999'])
            ->assertSessionHas('verification', fn (array $result) => $result['status'] === 'error'
                && str_contains($result['message'], 'No attendee 9999'));

        $this->post(route('pos.verification.store'), ['badge_id' => '1234-3'])
            ->assertSessionHas('verification', fn (array $result) => $result['status'] === 'error'
                && str_contains($result['message'], 'no copy 3'));
    }

    public function test_a_number_that_is_not_a_badge_number_is_refused(): void
    {
        $this->post(route('pos.verification.store'), ['badge_id' => 'abc'])
            ->assertSessionHas('verification', fn (array $result) => $result['status'] === 'error');
    }

    public function test_undo_puts_the_card_back_on_the_missing_list(): void
    {
        $badge = $this->printedBadge('1234');
        $job = $this->printJobFor($badge);

        $this->post(route('pos.verification.store'), ['badge_id' => '1234']);
        $this->post(route('pos.verification.revert', ['badge' => $badge->id]))
            ->assertRedirect();

        $this->assertNull($badge->fresh()->verified_print_at);
        $this->assertNull($job->fresh()->verified_print_at);
        $this->assertNull($job->fresh()->verification_source);
    }

    public function test_the_screen_counts_the_crate_and_lists_what_was_checked_off(): void
    {
        $checked = $this->printedBadge('1234');
        $this->printedBadge('1235');

        $this->post(route('pos.verification.store'), ['badge_id' => '1234']);

        $this->get(route('pos.verification.index'))
            ->assertSuccessful()
            ->assertInertia(fn ($page) => $page
                ->component('POS/Verification/Index')
                ->where('stats.printed', 2)
                ->where('stats.verified', 1)
                ->where('stats.missing', 1)
                ->where('recent.0.custom_id', $checked->custom_id)
                ->count('recent', 1)
                ->etc()
            );
    }

    public function test_the_counters_follow_the_crate_this_desk_holds(): void
    {
        $this->machine->update(['badge_range_min' => 1000, 'badge_range_max' => 1999]);

        $this->printedBadge('1234');
        $this->printedBadge('2500');

        $this->get(route('pos.verification.index'))
            ->assertInertia(fn ($page) => $page
                ->where('stats.printed', 1)
                ->where('stats.missing', 1)
                ->etc()
            );
    }

    public function test_the_screen_needs_a_signed_in_staff_member(): void
    {
        auth('machine-user')->logout();
        auth('machine')->logout();

        $this->get(route('pos.verification.index'))->assertRedirect();
        $this->post(route('pos.verification.store'), ['badge_id' => '1234'])->assertRedirect();
    }
}
