<?php

/*
 * The pickup reminder: `badges:remind-pickup`.
 *
 * This command mails thousands of real attendees, so what is asserted here is mostly what it refuses
 * to do. Each filter exists because sending without it would be worse than not sending at all, and
 * the once-per-badge stamp is the one that turns a helpful nudge into a nightly nag if it breaks.
 *
 * It is deliberately not scheduled: the slot is the badge team's call, so the command is run by hand
 * until they pick one, and `--dry-run` is how they see who would hear from us.
 */

use App\Models\Badge\Badge;
use App\Models\Badge\State_Fulfillment\PickedUp;
use App\Models\Badge\State_Fulfillment\ReadyForPickup;
use App\Models\Event;
use App\Models\EventUser;
use App\Models\Fursuit\Fursuit;
use App\Models\Fursuit\States\Approved;
use App\Models\Species;
use App\Models\User;
use App\Notifications\BadgePickupReminderNotification;

use function Pest\Laravel\artisan;

beforeEach(function () {
    Notification::fake();

    // Running right now: the command refuses to mail outside the convention.
    $this->event = Event::factory()->create([
        'name' => 'Eurofurence 30',
        'starts_at' => now()->subDays(2),
        'ends_at' => now()->addDays(2),
    ]);

    $this->species = Species::create(['name' => 'Wolf']);

    $this->badge = function (array $attributes = [], bool $checkedIn = true, ?string $name = 'Tally') {
        $user = User::factory()->create();

        $fursuit = Fursuit::factory()->create([
            'event_id' => $this->event->id,
            'species_id' => $this->species->id,
            'user_id' => $user->id,
            'status' => Approved::$name,
            'name' => $name,
        ]);

        if ($checkedIn) {
            EventUser::factory()->create([
                'user_id' => $user->id,
                'event_id' => $this->event->id,
                'valid_registration' => true,
            ]);
        }

        $badge = Badge::factory()->create([
            'fursuit_id' => $fursuit->id,
            'extra_copy_of' => null,
            'status_fulfillment' => ReadyForPickup::$name,
            'pickup_reminded_at' => null,
            ...$attributes,
        ]);

        return [$user, $badge];
    };
});

test('it reminds the attendee whose printed badge is still at the desk', function () {
    [$user, $badge] = ($this->badge)();

    artisan('badges:remind-pickup')->assertSuccessful();

    Notification::assertSentTo($user, BadgePickupReminderNotification::class,
        function (BadgePickupReminderNotification $mail) use ($user) {
            $message = $mail->toMail($user);
            $html = $message->render();

            expect($message->subject)->toBe('"Tally" - your badge is still waiting for you!')
                // Amber, not green: it sits in the inbox next to the "ready for pickup" mail and has
                // to read as a follow-up rather than a repeat.
                ->and($message->viewData['tone'])->toBe('warn');

            return str_contains($html, 'still waiting at our desk in the Fursuit Lounge')
                && str_contains($html, 'until the next Eurofurence')
                && str_contains($html, 'Pickup information');
        });

    expect($badge->fresh()->pickup_reminded_at)->not->toBeNull();
});

test('it never reminds the same badge twice', function () {
    // The stamp is the whole safety mechanism. Without it a command on a timer mails the same person
    // on every run, and one stuck scheduler turns a nudge into a nightly nag.
    [$user] = ($this->badge)();

    artisan('badges:remind-pickup')->assertSuccessful();
    Notification::assertSentToTimes($user, BadgePickupReminderNotification::class, 1);

    artisan('badges:remind-pickup')->assertSuccessful();
    Notification::assertSentToTimes($user, BadgePickupReminderNotification::class, 1);
});

test('it skips badges that are collected, and attendees who never checked in', function () {
    [$collected, $collectedBadge] = ($this->badge)([], true, 'Collected');
    $collectedBadge->status_fulfillment->transitionTo(PickedUp::class);

    [$absent] = ($this->badge)([], false, 'Never came');

    [$here] = ($this->badge)([], true, 'Waiting');

    artisan('badges:remind-pickup')->assertSuccessful();

    Notification::assertSentTo($here, BadgePickupReminderNotification::class);
    Notification::assertNotSentTo($collected, BadgePickupReminderNotification::class);
    // `valid_registration` is the closest thing to "this person is actually here"; a badge reminder is
    // noise for somebody who never arrived.
    Notification::assertNotSentTo($absent, BadgePickupReminderNotification::class);
});

test('it refuses to send outside the convention unless forced', function () {
    [$user] = ($this->badge)();

    $this->event->update(['starts_at' => now()->addMonth(), 'ends_at' => now()->addMonth()->addDays(4)]);

    artisan('badges:remind-pickup')->assertSuccessful();
    Notification::assertNothingSent();

    artisan('badges:remind-pickup --force')->assertSuccessful();
    Notification::assertSentTo($user, BadgePickupReminderNotification::class);
});

test('a dry run names everybody it would mail and changes nothing', function () {
    [$user, $badge] = ($this->badge)();

    artisan('badges:remind-pickup --dry-run')
        ->expectsOutputToContain('would remind')
        ->assertSuccessful();

    Notification::assertNothingSent();
    expect($badge->fresh()->pickup_reminded_at)->toBeNull();
});
