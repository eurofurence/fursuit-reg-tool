<?php

/*
 * "Send Pickup Reminder" on the badge list.
 *
 * The unattended command decides for itself who hears from us; this endpoint hands that decision to
 * whoever is standing at the desk, so what is asserted here is the small set of things it still
 * refuses to do on their behalf, plus the one it must never skip: the stamp, which is what keeps
 * `badges:remind-pickup` from mailing the same person again on its next run.
 *
 * The mail itself - the hours, and the sentence the last day of the desk adds to them - is asserted
 * here too, because that copy is the reason the button exists.
 */

use App\Models\Badge\Badge;
use App\Models\Badge\State_Fulfillment\PickedUp;
use App\Models\BadgePickupReminderRun;
use App\Models\Event;
use App\Models\EventUser;
use App\Models\Fursuit\Fursuit;
use App\Models\Species;
use App\Models\User;
use App\Notifications\BadgePickupReminderNotification;
use App\Support\Manage\EventScope;
use Illuminate\Support\Facades\Notification;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;
use function Pest\Laravel\post;

beforeEach(function () {
    Notification::fake();

    $this->event = Event::factory()->create([
        'name' => 'Eurofurence 29',
        'starts_at' => now()->subDays(2),
        'ends_at' => now()->addDays(2),
        'desk_opening_hours' => [
            ['date' => now()->subDay()->format('Y-m-d'), 'opens' => '10:00', 'closes' => '18:00'],
            ['date' => now()->format('Y-m-d'), 'opens' => '10:00', 'closes' => '16:00'],
            ['date' => now()->addDay()->format('Y-m-d'), 'opens' => '10:00', 'closes' => '14:00'],
        ],
    ]);

    $this->admin = User::factory()->create(['is_admin' => true, 'is_reviewer' => false]);
    $this->reviewer = User::factory()->create(['is_admin' => false, 'is_reviewer' => true]);

    $this->badge = function (string $state = 'ready_for_pickup', array $attributes = []) {
        $owner = User::factory()->create();

        EventUser::factory()->create([
            'user_id' => $owner->id,
            'event_id' => $this->event->id,
            'valid_registration' => true,
        ]);

        return Badge::factory()->create([
            'status_fulfillment' => $state,
            'status_payment' => 'paid',
            'extra_copy_of' => null,
            'pickup_reminded_at' => null,
            'fursuit_id' => Fursuit::factory()->create([
                'event_id' => $this->event->id,
                'user_id' => $owner->id,
                'species_id' => Species::firstOrCreate(['name' => 'Blue Fox'], ['type' => 'canine', 'checked' => true])->id,
                'name' => 'Nibbles',
            ])->id,
            ...$attributes,
        ]);
    };

    $this->scoped = fn (?User $actor = null) => actingAs($actor ?? $this->admin)->withSession([
        EventScope::SESSION_ID => null,
        EventScope::SESSION_CHOSEN => true,
    ]);
});

test('it mails the selected attendees and stamps each badge', function () {
    $first = ($this->badge)();
    $second = ($this->badge)('printed');

    ($this->scoped)()
        ->post(route('admin.badges.bulk.remind'), ['ids' => [$first->id, $second->id]])
        ->assertRedirect();

    Notification::assertCount(2);
    expect($first->fresh()->pickup_reminded_at)->not->toBeNull()
        ->and($second->fresh()->pickup_reminded_at)->not->toBeNull();
});

test('it never mails somebody who already has their badge', function () {
    $collected = ($this->badge)(PickedUp::$name);

    ($this->scoped)()
        ->post(route('admin.badges.bulk.remind'), ['ids' => [$collected->id]])
        ->assertRedirect();

    Notification::assertNothingSent();
    expect($collected->fresh()->pickup_reminded_at)->toBeNull();
});

test('it sends for a badge that is not printed yet, since the operator picked it', function () {
    $pending = ($this->badge)('pending');

    ($this->scoped)()
        ->post(route('admin.badges.bulk.remind'), ['ids' => [$pending->id]])
        ->assertRedirect();

    Notification::assertSentTo($pending->fresh()->fursuit->user, BadgePickupReminderNotification::class);
});

test('a second nudge is allowed, unlike the unattended command', function () {
    $reminded = ($this->badge)('ready_for_pickup', ['pickup_reminded_at' => now()->subDay()]);

    ($this->scoped)()
        ->post(route('admin.badges.bulk.remind'), ['ids' => [$reminded->id]])
        ->assertRedirect();

    Notification::assertCount(1);
});

test('a reviewer may not mail attendees', function () {
    $badge = ($this->badge)();

    ($this->scoped)($this->reviewer)
        ->post(route('admin.badges.bulk.remind'), ['ids' => [$badge->id]])
        ->assertForbidden();

    Notification::assertNothingSent();
    expect($badge->fresh()->pickup_reminded_at)->toBeNull();
});

test('a guest may not mail attendees', function () {
    $badge = ($this->badge)();

    post(route('admin.badges.bulk.remind'), ['ids' => [$badge->id]])->assertRedirect(route('login'));

    Notification::assertNothingSent();
});

test('the mail prints the desk hours from today onward, and the last day says so', function () {
    $badge = ($this->badge)();
    $user = $badge->fursuit->user;

    $mail = (new BadgePickupReminderNotification($badge))->toMail($user);
    $hours = collect($mail->data()['answers'])->firstWhere('q', 'When is the desk open?');

    // Yesterday's row is gone; today and tomorrow stay, today marked as such. There is no
    // sentence over the list: mid-convention the list is the whole answer.
    expect($hours['hours'])->toHaveCount(2)
        ->and($hours['hours'][0]['date'])->toBe(now()->format('Y-m-d'))
        ->and($hours['hours'][0]['today'])->toBeTrue()
        ->and($hours['hours'][1]['today'])->toBeFalse()
        ->and($hours['a'])->toBeNull();

    // Same event, but today is now the last day the desk publishes.
    $this->event->update([
        'desk_opening_hours' => [
            ['date' => now()->format('Y-m-d'), 'opens' => '10:00', 'closes' => '16:00'],
        ],
    ]);

    $lastDay = (new BadgePickupReminderNotification($badge->fresh()))->toMail($user);
    $lastDayHours = collect($lastDay->data()['answers'])->firstWhere('q', 'When is the desk open?');

    expect($lastDayHours['a'])->toContain('last day of the desk')
        ->and($lastDayHours['a'])->toContain('close at 16:00');
});

test('an event with no published hours gets no hours question', function () {
    $this->event->update(['desk_opening_hours' => null]);

    $badge = ($this->badge)();

    $mail = (new BadgePickupReminderNotification($badge))->toMail($badge->fursuit->user);
    $questions = collect($mail->data()['answers'])->pluck('q');

    expect($questions)->not->toContain('When is the desk open?')
        // And the mail asks nothing about what to bring: this one is about walking to the desk.
        ->and($questions)->not->toContain('Anything to bring?');
});

test('an uncollected badge that is not printed says so instead of claiming it is at the desk', function () {
    $printed = ($this->badge)('ready_for_pickup');
    $pending = ($this->badge)('pending');

    $printedMail = (new BadgePickupReminderNotification($printed))->toMail($printed->fursuit->user);
    $pendingMail = (new BadgePickupReminderNotification($pending))->toMail($pending->fursuit->user);

    expect($printedMail->data()['headline'])->toBe('Your badge is printed and still waiting at our desk in the Fursuit Lounge.')
        ->and($pendingMail->data()['headline'])->toBe('Your badge has not been collected yet. It waits for you at our desk in the Fursuit Lounge.')
        ->and(collect($pendingMail->data()['answers'])->pluck('q'))->toContain('When can I collect it?');
});

/*
 * "Send today's reminders", the page action.
 *
 * Not a selection: the whole day's send, the same one the scheduler makes. Which is why it claims
 * the day - the two must never both go out - and why that claim is the thing most worth asserting.
 */

test('the button sends the day and claims it', function () {
    [$first, $second] = [($this->badge)(), ($this->badge)('pending')];

    ($this->scoped)()
        ->post(route('admin.badges.remind-today'))
        ->assertRedirect();

    Notification::assertCount(2);

    $run = BadgePickupReminderRun::sole();

    expect($run->source)->toBe(BadgePickupReminderRun::SOURCE_MANUAL)
        ->and($run->triggered_by)->toBe($this->admin->id)
        ->and($run->attendees_notified)->toBe(2)
        ->and($first->fresh()->pickup_reminded_at)->not->toBeNull()
        ->and($second->fresh()->pickup_reminded_at)->not->toBeNull();
});

test('pressing it twice in one day sends once', function () {
    ($this->badge)();

    ($this->scoped)()->post(route('admin.badges.remind-today'))->assertRedirect();

    // Somebody new turns up between the two presses, and must not be mailed by the second.
    $late = ($this->badge)();

    ($this->scoped)()->post(route('admin.badges.remind-today'))->assertRedirect();

    Notification::assertCount(1);
    expect($late->fresh()->pickup_reminded_at)->toBeNull()
        ->and(BadgePickupReminderRun::count())->toBe(1);
});

test('the schedule stays quiet on a day the button already sent', function () {
    ($this->badge)();

    ($this->scoped)()->post(route('admin.badges.remind-today'))->assertRedirect();

    $late = ($this->badge)();

    // The desk's own reminder time, on a day the button has already taken.
    $this->event->update(['desk_opening_hours' => [
        ['date' => now()->subDay()->format('Y-m-d'), 'opens' => '10:00', 'closes' => '18:00'],
        ['date' => now()->format('Y-m-d'), 'opens' => '00:00', 'closes' => '23:59', 'reminds_at' => now()->format('H:i')],
    ]]);

    artisan('badges:remind-pickup --scheduled')->assertSuccessful();

    Notification::assertCount(1);
    expect($late->fresh()->pickup_reminded_at)->toBeNull();
});

test('the selection action is unaffected by the day claim', function () {
    // The bulk action is the operator aiming at rows they picked, so it stays available even once
    // the day's sweep has gone out. Only the day-wide send is once a day.
    $badge = ($this->badge)();

    ($this->scoped)()->post(route('admin.badges.remind-today'))->assertRedirect();

    Notification::assertCount(1);

    ($this->scoped)()
        ->post(route('admin.badges.bulk.remind'), ['ids' => [$badge->id]])
        ->assertRedirect();

    Notification::assertCount(2);
});

test('a reviewer may not send the day', function () {
    ($this->badge)();

    ($this->scoped)($this->reviewer)
        ->post(route('admin.badges.remind-today'))
        ->assertForbidden();

    Notification::assertNothingSent();
    expect(BadgePickupReminderRun::count())->toBe(0);
});

test('the list offers the button to an admin and not to a reviewer', function () {
    ($this->badge)();

    $names = fn ($actor) => collect(
        ($this->scoped)($actor)->get(route('admin.badges.index'))->viewData('page')['props']['pageActions']
    )->pluck('name');

    expect($names($this->admin))->toContain('remindPickupToday')
        ->and($names($this->reviewer))->not->toContain('remindPickupToday');
});
