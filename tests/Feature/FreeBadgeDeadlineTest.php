<?php

namespace Tests\Feature;

use App\Models\Badge\Badge;
use App\Models\Event;
use App\Models\EventUser;
use App\Models\Species;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The badge that comes with the fursuit package is free only until
 * `events.free_badge_deadline`. Order after it and it costs the badge fee like
 * any other.
 *
 * `prepaid_badges` sums two things AuthController reads out of the registration
 * system: the one included badge, and extra copies bought as `fursuitadd`. Only
 * the included one expires - the extras were paid for and stay free whenever
 * they are claimed.
 *
 * The column existed for a year and was only ever rendered on the Welcome page
 * and the FAQ, never enforced, so every attendee kept their free badge for the
 * whole convention. See docs/prepaid-badges.md for why this is not the `- 1`
 * that was removed as bugfix-03.
 */
class FreeBadgeDeadlineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function event(?string $deadline): Event
    {
        return Event::factory()->create([
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->addDays(5),
            'order_starts_at' => now()->subDays(20),
            'order_ends_at' => now()->addDays(3),
            'free_badge_deadline' => $deadline,
            'badge_price_cents' => 500,
        ]);
    }

    private function attendee(Event $event, int $prepaid): User
    {
        $user = User::factory()->create();

        EventUser::create([
            'user_id' => $user->id,
            'event_id' => $event->id,
            'attendee_id' => 'TEST-'.$user->id,
            'prepaid_badges' => $prepaid,
            'valid_registration' => true,
        ]);

        $this->actingAs($user);

        return $user;
    }

    private function orderBadge(string $name): void
    {
        Species::firstOrCreate(['name' => 'Wolf'], ['name' => 'Wolf', 'checked' => true]);

        $this->post(route('badges.store'), [
            'species' => 'Wolf',
            'name' => $name,
            'image' => UploadedFile::fake()->image('fursuit.jpg', 480, 680),
            'catchEmAll' => false,
            'publish' => false,
            'tos' => true,
            'upgrades' => ['spareCopy' => false],
        ])->assertRedirect(route('badges.index'));
    }

    #[Test]
    public function the_included_badge_is_free_before_the_deadline(): void
    {
        $event = $this->event(now()->addDay()->toDateTimeString());
        $user = $this->attendee($event, 1);

        $this->assertSame(1, $user->getPrepaidBadgesLeft($event->id));

        $this->orderBadge('In time');

        $badge = $user->badges()->first();
        $this->assertTrue($badge->is_free_badge);
        $this->assertSame(0, $badge->total);
        $this->assertSame('paid', (string) $badge->status_payment);
    }

    #[Test]
    public function the_included_badge_costs_the_badge_fee_after_the_deadline(): void
    {
        $event = $this->event(now()->subDay()->toDateTimeString());
        $user = $this->attendee($event, 1);

        $this->assertSame(0, $user->getPrepaidBadgesLeft($event->id));

        $this->orderBadge('Too late');

        $badge = $user->badges()->first();
        $this->assertFalse($badge->is_free_badge);
        $this->assertSame(500, $badge->total);
        $this->assertSame('unpaid', (string) $badge->status_payment);
        $this->assertNull($badge->paid_at);
    }

    /**
     * The desk case the deadline must not break: somebody who bought two extra
     * copies in the registration system still gets those two free, they just no
     * longer get the included one on top.
     */
    #[Test]
    public function extra_copies_bought_in_registration_stay_free_after_the_deadline(): void
    {
        $event = $this->event(now()->subDay()->toDateTimeString());
        $user = $this->attendee($event, 3);

        $this->assertSame(2, $user->getPrepaidBadgesLeft($event->id));

        $this->orderBadge('First extra');
        $this->assertTrue($user->badges()->first()->is_free_badge);
        $this->assertSame(1, $user->fresh()->getPrepaidBadgesLeft($event->id));

        $this->orderBadge('Second extra');
        $this->assertSame(0, $user->fresh()->getPrepaidBadgesLeft($event->id));

        $this->orderBadge('One too many');
        $paid = $user->badges()->where('is_free_badge', false)->first();
        $this->assertNotNull($paid, 'The fourth badge is past the entitlement and must be charged');
        $this->assertSame(500, $paid->total);
    }

    /**
     * Nullable on purpose: an event that never set a deadline has no free-badge
     * cutoff, and the entitlement is honored in full rather than silently losing
     * one badge per attendee.
     */
    #[Test]
    public function an_event_without_a_deadline_honors_the_whole_entitlement(): void
    {
        $event = $this->event(null);
        $user = $this->attendee($event, 1);

        $this->assertSame(1, $user->getPrepaidBadgesLeft($event->id));

        $this->orderBadge('No deadline set');

        $this->assertTrue($user->badges()->first()->is_free_badge);
    }

    /**
     * The deadline decides the price, never whether the badge may be ordered at
     * all: an attendee who missed it still orders, they just pay.
     */
    #[Test]
    public function missing_the_deadline_does_not_block_ordering(): void
    {
        $event = $this->event(now()->subDay()->toDateTimeString());
        $user = $this->attendee($event, 1);

        $this->assertTrue($user->can('create', Badge::class));
    }
}
