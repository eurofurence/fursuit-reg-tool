<?php

namespace Tests\Feature;

use App\Models\Badge\Badge;
use App\Models\Badge\State_Fulfillment\PickedUp;
use App\Models\Badge\State_Fulfillment\Processing;
use App\Models\Badge\State_Fulfillment\ReadyForPickup;
use App\Models\Event;
use App\Models\EventUser;
use App\Models\Fursuit\Fursuit;
use App\Models\Species;
use App\Models\User;
use App\Notifications\BadgePrintedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ReadyForPickupNotificationTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_sends_notification_when_badge_transitions_to_ready_for_pickup_during_event()
    {
        Notification::fake();

        // Create an event that is currently happening
        $event = Event::factory()->create([
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
            'order_starts_at' => now()->subDays(10),
            'order_ends_at' => now()->subDays(5),
        ]);

        // Verify we're during the event
        $this->assertTrue($event->isDuringEvent());

        // Create user with event registration
        $user = User::factory()->create();
        $eventUser = EventUser::factory()->create([
            'user_id' => $user->id,
            'event_id' => $event->id,
            'attendee_id' => 'TEST-123',
            'prepaid_badges' => 1,
            'valid_registration' => true,
        ]);

        // Create species
        $species = Species::factory()->create();

        // Create fursuit and badge in Processing state
        $fursuit = Fursuit::factory()->create([
            'user_id' => $user->id,
            'event_id' => $event->id,
            'species_id' => $species->id,
            'status' => 'approved',
        ]);

        $badge = Badge::factory()->create([
            'fursuit_id' => $fursuit->id,
            'status_fulfillment' => Processing::$name,
            'status_payment' => 'paid',
            'is_free_badge' => true,
            'total' => 0,
        ]);

        // Transition badge from Processing to ReadyForPickup
        $this->assertTrue($badge->status_fulfillment->canTransitionTo(ReadyForPickup::class));
        $badge->status_fulfillment->transitionTo(ReadyForPickup::class);

        // Assert notification was sent
        Notification::assertSentTo(
            [$user],
            BadgePrintedNotification::class,
            function ($notification) use ($badge) {
                return $notification->badge->id === $badge->id;
            }
        );
    }

    /** @test */
    public function it_does_not_send_notification_when_transitioning_to_ready_for_pickup_outside_event()
    {
        Notification::fake();

        // Create an event that hasn't started yet
        $event = Event::factory()->create([
            'starts_at' => now()->addDays(5),
            'ends_at' => now()->addDays(8),
            'order_starts_at' => now()->subDays(10),
            'order_ends_at' => now()->subDays(5),
        ]);

        // Verify we're NOT during the event
        $this->assertFalse($event->isDuringEvent());

        // Create user with event registration
        $user = User::factory()->create();
        $eventUser = EventUser::factory()->create([
            'user_id' => $user->id,
            'event_id' => $event->id,
            'attendee_id' => 'TEST-456',
            'prepaid_badges' => 1,
            'valid_registration' => true,
        ]);

        // Create species
        $species = Species::factory()->create();

        // Create fursuit and badge in Processing state
        $fursuit = Fursuit::factory()->create([
            'user_id' => $user->id,
            'event_id' => $event->id,
            'species_id' => $species->id,
            'status' => 'approved',
        ]);

        $badge = Badge::factory()->create([
            'fursuit_id' => $fursuit->id,
            'status_fulfillment' => Processing::$name,
            'status_payment' => 'paid',
            'is_free_badge' => true,
            'total' => 0,
            'created_at' => now()->subDays(2), // Not recently created
        ]);

        // Transition badge from Processing to ReadyForPickup
        $this->assertTrue($badge->status_fulfillment->canTransitionTo(ReadyForPickup::class));
        $badge->status_fulfillment->transitionTo(ReadyForPickup::class);

        // Assert notification was NOT sent (because we're not during the event and badge is not recently created)
        Notification::assertNotSentTo([$user], BadgePrintedNotification::class);
    }

    /** @test */
    public function it_sends_notification_when_reverting_from_picked_up_to_ready_during_event()
    {
        Notification::fake();

        // Create an event that is currently happening
        $event = Event::factory()->create([
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
            'order_starts_at' => now()->subDays(10),
            'order_ends_at' => now()->subDays(5),
        ]);

        // Verify we're during the event
        $this->assertTrue($event->isDuringEvent());

        // Create user with event registration
        $user = User::factory()->create();
        $eventUser = EventUser::factory()->create([
            'user_id' => $user->id,
            'event_id' => $event->id,
            'attendee_id' => 'TEST-789',
            'prepaid_badges' => 1,
            'valid_registration' => true,
        ]);

        // Create species
        $species = Species::factory()->create();

        // Create fursuit and badge in PickedUp state
        $fursuit = Fursuit::factory()->create([
            'user_id' => $user->id,
            'event_id' => $event->id,
            'species_id' => $species->id,
            'status' => 'approved',
        ]);

        $badge = Badge::factory()->create([
            'fursuit_id' => $fursuit->id,
            'status_fulfillment' => PickedUp::$name,
            'status_payment' => 'paid',
            'is_free_badge' => true,
            'total' => 0,
            'custom_id' => 'TEST-789-1',
        ]);

        // Transition badge from PickedUp back to ReadyForPickup (undo scenario)
        $this->assertTrue($badge->status_fulfillment->canTransitionTo(ReadyForPickup::class));
        $badge->status_fulfillment->transitionTo(ReadyForPickup::class);

        // Assert notification was sent (useful when undoing a pickup by mistake)
        Notification::assertSentTo(
            [$user],
            BadgePrintedNotification::class,
            function ($notification) use ($badge) {
                return $notification->badge->id === $badge->id;
            }
        );
    }

    /** @test */
    public function it_correctly_sets_paid_at_timestamp_when_transitioning_to_ready_for_pickup()
    {
        Notification::fake();

        // Create an event
        $event = Event::factory()->create([
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
        ]);

        // Create user with event registration
        $user = User::factory()->create();
        $eventUser = EventUser::factory()->create([
            'user_id' => $user->id,
            'event_id' => $event->id,
            'attendee_id' => 'TEST-999',
            'prepaid_badges' => 1,
            'valid_registration' => true,
        ]);

        // Create species
        $species = Species::factory()->create();

        // Create fursuit and badge in Processing state with no paid_at
        $fursuit = Fursuit::factory()->create([
            'user_id' => $user->id,
            'event_id' => $event->id,
            'species_id' => $species->id,
            'status' => 'approved',
        ]);

        $badge = Badge::factory()->create([
            'fursuit_id' => $fursuit->id,
            'status_fulfillment' => Processing::$name,
            'status_payment' => 'unpaid',
            'is_free_badge' => false,
            'total' => 300,
            'paid_at' => null,
        ]);

        // Verify paid_at is null initially
        $this->assertNull($badge->paid_at);

        // Transition to ReadyForPickup
        $badge->status_fulfillment->transitionTo(ReadyForPickup::class);
        $badge->refresh();

        // Verify paid_at was set
        $this->assertNotNull($badge->paid_at);
        $this->assertEquals(ReadyForPickup::$name, $badge->status_fulfillment);
    }

    /** @test */
    public function notification_email_contains_correct_badge_information()
    {
        Notification::fake();

        // Create an event that is currently happening
        $event = Event::factory()->create([
            'name' => 'EF29',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
        ]);

        // Create user
        $user = User::factory()->create(['name' => 'John Doe']);
        $eventUser = EventUser::factory()->create([
            'user_id' => $user->id,
            'event_id' => $event->id,
            'attendee_id' => 'EF29-100',
            'prepaid_badges' => 1,
            'valid_registration' => true,
        ]);

        // Create species
        $species = Species::factory()->create(['name' => 'Wolf']);

        // Create fursuit and badge
        $fursuit = Fursuit::factory()->create([
            'user_id' => $user->id,
            'event_id' => $event->id,
            'species_id' => $species->id,
            'name' => 'Fluffy Wolf',
            'status' => 'approved',
        ]);

        $badge = Badge::factory()->create([
            'fursuit_id' => $fursuit->id,
            'status_fulfillment' => Processing::$name,
            'status_payment' => 'paid',
            'custom_id' => 'EF29-100-1',
            'is_free_badge' => true,
            'total' => 0,
        ]);

        // Transition to ReadyForPickup
        $badge->status_fulfillment->transitionTo(ReadyForPickup::class);

        // Capture the notification
        Notification::assertSentTo(
            [$user],
            BadgePrintedNotification::class,
            function (BadgePrintedNotification $notification, $channels) use ($badge, $user) {
                // Test that the notification has the correct badge
                $this->assertEquals($badge->id, $notification->badge->id);

                // Test the mail content
                $mailMessage = $notification->toMail($user);

                /*
                 * The fursuit name belongs in the subject, and quoted: a name is attendee-supplied
                 * and routinely carries punctuation, which derails a sentence but survives quotes.
                 * The body deliberately never uses it.
                 */
                $this->assertStringContainsString('"Fluffy Wolf"', $mailMessage->subject);
                $this->assertStringContainsString('ready to collect', $mailMessage->subject);

                // Every badge mail renders one shared view, so the assertions are on its data.
                $this->assertEquals('mail.badge', $mailMessage->markdown);

                // One greeting across every attendee mail. The seven notifications used to disagree
                // four ways: no greeting at all (so Laravel's bare "Hello!"), "Hello {name},",
                // "Hello {name}!" and a greeting used as a headline.
                $this->assertEquals('Hi John Doe!', $mailMessage->viewData['greeting']);
                $this->assertEquals('Ready for pickup', $mailMessage->viewData['band']);

                /*
                 * Rendered rather than inspected line by line: this is what catches a broken blade,
                 * and it is the only assertion that proves the band, the answers and the button all
                 * survive into the message the attendee opens.
                 */
                $html = $mailMessage->render();

                $this->assertStringContainsString('Ready for pickup', $html);
                $this->assertStringContainsString('Fursuit Lounge', $html);
                $this->assertStringContainsString('Pickup information', $html);

                // No booth or queue detail: that split exists on day one only, so it reads as
                // nonsense in a mail opened on day three.
                $this->assertStringNotContainsString('booth', strtolower($html));

                return true;
            }
        );
    }
}
