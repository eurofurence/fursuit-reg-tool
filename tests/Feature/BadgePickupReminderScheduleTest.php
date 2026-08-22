<?php

/*
 * The scheduled half of `badges:remind-pickup`.
 *
 * The schedule runs this command every minute for the length of the convention, so almost
 * everything asserted here is a run that sends nothing. The reminder time lives on the desk's own
 * opening hours (Settings > On-Site Desk), which means the badge team can move it between two
 * convention days - and means the guards that decide whether a minute is the minute have to hold
 * against whatever they type.
 */

use App\Models\Badge\Badge;
use App\Models\Badge\State_Fulfillment\PickedUp;
use App\Models\BadgePickupReminderRun;
use App\Models\Event;
use App\Models\EventUser;
use App\Models\Fursuit\Fursuit;
use App\Models\Fursuit\States\Approved;
use App\Models\Species;
use App\Models\User;
use App\Notifications\BadgePickupReminderNotification;
use Illuminate\Support\Facades\Notification;

use function Pest\Laravel\artisan;
use function Pest\Laravel\travelTo;

beforeEach(function () {
    Notification::fake();

    // A three day convention, the desk open 10:00 - 18:00 every day, reminding at 15:00 on the
    // two days that are allowed to remind.
    $this->today = now()->startOfDay();

    $this->hours = function (array $overrides = []) {
        $rows = [
            ['date' => $this->today->copy()->subDay()->format('Y-m-d'), 'opens' => '10:00', 'closes' => '18:00', 'reminds_at' => null],
            ['date' => $this->today->format('Y-m-d'), 'opens' => '10:00', 'closes' => '18:00', 'reminds_at' => '15:00'],
            ['date' => $this->today->copy()->addDay()->format('Y-m-d'), 'opens' => '10:00', 'closes' => '18:00', 'reminds_at' => '15:00'],
        ];

        return array_replace_recursive($rows, $overrides);
    };

    $this->event = Event::factory()->create([
        'name' => 'Eurofurence 30',
        'starts_at' => $this->today->copy()->subDay(),
        'ends_at' => $this->today->copy()->addDays(2),
        'desk_opening_hours' => ($this->hours)(),
    ]);

    $this->species = Species::firstOrCreate(['name' => 'Wolf'], ['type' => 'canine', 'checked' => true]);

    $this->badge = function (array $attributes = [], bool $checkedIn = true, ?User $owner = null) {
        $user = $owner ?? User::factory()->create();

        $fursuit = Fursuit::factory()->create([
            'event_id' => $this->event->id,
            'species_id' => $this->species->id,
            'user_id' => $user->id,
            'status' => Approved::$name,
            'name' => 'Tally',
        ]);

        if ($checkedIn && ! EventUser::where('user_id', $user->id)->where('event_id', $this->event->id)->exists()) {
            EventUser::factory()->create([
                'user_id' => $user->id,
                'event_id' => $this->event->id,
                'valid_registration' => true,
            ]);
        }

        $badge = Badge::factory()->create([
            'fursuit_id' => $fursuit->id,
            'extra_copy_of' => null,
            'status_fulfillment' => 'ready_for_pickup',
            'pickup_reminded_at' => null,
            ...$attributes,
        ]);

        return [$user, $badge];
    };
});

test('it sends at the time the desk set', function () {
    [$user, $badge] = ($this->badge)();

    travelTo($this->today->copy()->setTime(15, 0));

    artisan('badges:remind-pickup --scheduled')->assertSuccessful();

    Notification::assertSentTo($user, BadgePickupReminderNotification::class);
    expect($badge->fresh()->pickup_reminded_at)->not->toBeNull();
});

test('it stays quiet before the reminder time', function () {
    ($this->badge)();

    travelTo($this->today->copy()->setTime(14, 59));

    artisan('badges:remind-pickup --scheduled')->assertSuccessful();

    Notification::assertNothingSent();
});

test('it stays quiet once the window has passed, rather than sending the day late', function () {
    // A scheduler that was down all afternoon must not fire the day's mail in the evening: by then
    // the desk is closing and the mail sends people to a counter nobody is standing at.
    ($this->badge)();

    travelTo($this->today->copy()->setTime(15, 16));

    artisan('badges:remind-pickup --scheduled')->assertSuccessful();

    Notification::assertNothingSent();
});

test('it never sends on the first desk day, even with a time set on it', function () {
    ($this->badge)();

    $this->event->update(['desk_opening_hours' => ($this->hours)([0 => ['reminds_at' => '15:00']])]);

    travelTo($this->today->copy()->subDay()->setTime(15, 0));

    artisan('badges:remind-pickup --scheduled')->assertSuccessful();

    Notification::assertNothingSent();
});

test('a day with no reminder time sends nothing', function () {
    ($this->badge)();

    $this->event->update(['desk_opening_hours' => ($this->hours)([1 => ['reminds_at' => null]])]);

    travelTo($this->today->copy()->setTime(15, 0));

    artisan('badges:remind-pickup --scheduled')->assertSuccessful();

    Notification::assertNothingSent();
});

test('it does not send after the desk has closed', function () {
    ($this->badge)();

    // The desk shuts at 15:00 today, so the 15:00 reminder has nowhere to send anybody.
    $this->event->update(['desk_opening_hours' => ($this->hours)([1 => ['closes' => '15:00']])]);

    travelTo($this->today->copy()->setTime(15, 0));

    artisan('badges:remind-pickup --scheduled')->assertSuccessful();

    Notification::assertNothingSent();
});

test('somebody with several uncollected badges is mailed once, and all of them are stamped', function () {
    [$user, $first] = ($this->badge)();
    [, $second] = ($this->badge)([], true, $user);
    [, $third] = ($this->badge)(['status_fulfillment' => 'pending'], true, $user);

    travelTo($this->today->copy()->setTime(15, 0));

    artisan('badges:remind-pickup --scheduled')->assertSuccessful();

    Notification::assertSentToTimes($user, BadgePickupReminderNotification::class, 1);

    expect($first->fresh()->pickup_reminded_at)->not->toBeNull()
        ->and($second->fresh()->pickup_reminded_at)->not->toBeNull()
        ->and($third->fresh()->pickup_reminded_at)->not->toBeNull();
});

test('the next day does not mail the same person again', function () {
    [$user] = ($this->badge)();

    travelTo($this->today->copy()->setTime(15, 0));
    artisan('badges:remind-pickup --scheduled')->assertSuccessful();

    travelTo($this->today->copy()->addDay()->setTime(15, 0));
    artisan('badges:remind-pickup --scheduled')->assertSuccessful();

    Notification::assertSentToTimes($user, BadgePickupReminderNotification::class, 1);
});

test('a badge that is not printed yet is still worth a nudge', function () {
    [$user] = ($this->badge)(['status_fulfillment' => 'pending']);

    travelTo($this->today->copy()->setTime(15, 0));

    artisan('badges:remind-pickup --scheduled')->assertSuccessful();

    Notification::assertSentTo($user, BadgePickupReminderNotification::class);
});

test('a collected badge is never reminded', function () {
    [$user, $badge] = ($this->badge)();
    $badge->status_fulfillment->transitionTo(PickedUp::class);

    travelTo($this->today->copy()->setTime(15, 0));

    artisan('badges:remind-pickup --scheduled')->assertSuccessful();

    Notification::assertNotSentTo($user, BadgePickupReminderNotification::class);
});

test('an event with no desk hours sends nothing on the schedule', function () {
    ($this->badge)();

    $this->event->update(['desk_opening_hours' => null]);

    travelTo($this->today->copy()->setTime(15, 0));

    artisan('badges:remind-pickup --scheduled')->assertSuccessful();

    Notification::assertNothingSent();
});

/*
 * The day guard.
 *
 * `pickup_reminded_at` stops a second mail to one person; this stops a second run. The two are not
 * the same guard: without this, the badge list's button pressed after the schedule has fired would
 * mail everybody who became a candidate in between, which nobody asked for.
 */

test('the schedule does not run twice in one day', function () {
    [$user] = ($this->badge)();

    travelTo($this->today->copy()->setTime(15, 0));
    artisan('badges:remind-pickup --scheduled')->assertSuccessful();

    // A second badge appears for somebody else, and the scheduler ticks again inside the window.
    [$late] = ($this->badge)();

    travelTo($this->today->copy()->setTime(15, 5));
    artisan('badges:remind-pickup --scheduled')->assertSuccessful();

    Notification::assertSentToTimes($user, BadgePickupReminderNotification::class, 1);
    Notification::assertNotSentTo($late, BadgePickupReminderNotification::class);

    expect(BadgePickupReminderRun::count())->toBe(1);
});

test('the next day is a new day and runs again', function () {
    ($this->badge)();

    travelTo($this->today->copy()->setTime(15, 0));
    artisan('badges:remind-pickup --scheduled')->assertSuccessful();

    [$tomorrowsUser] = ($this->badge)();

    travelTo($this->today->copy()->addDay()->setTime(15, 0));
    artisan('badges:remind-pickup --scheduled')->assertSuccessful();

    Notification::assertSentTo($tomorrowsUser, BadgePickupReminderNotification::class);
    expect(BadgePickupReminderRun::count())->toBe(2);
});

test('a claimed day records who set it off', function () {
    ($this->badge)();

    travelTo($this->today->copy()->setTime(15, 0));
    artisan('badges:remind-pickup --scheduled')->assertSuccessful();

    $run = BadgePickupReminderRun::sole();

    expect($run->source)->toBe(BadgePickupReminderRun::SOURCE_SCHEDULE)
        ->and($run->ran_on->toDateString())->toBe($this->today->toDateString())
        ->and($run->attendees_notified)->toBe(1);
});

test('--force sends again on a day that already ran, and still never mails anybody twice', function () {
    [$first] = ($this->badge)();

    travelTo($this->today->copy()->setTime(15, 0));
    artisan('badges:remind-pickup --scheduled')->assertSuccessful();

    [$second] = ($this->badge)();

    artisan('badges:remind-pickup --force')->assertSuccessful();

    Notification::assertSentToTimes($first, BadgePickupReminderNotification::class, 1);
    Notification::assertSentToTimes($second, BadgePickupReminderNotification::class, 1);
});

test('a dry run neither claims the day nor sends', function () {
    ($this->badge)();

    travelTo($this->today->copy()->setTime(15, 0));

    artisan('badges:remind-pickup --dry-run --force')->assertSuccessful();

    Notification::assertNothingSent();
    expect(BadgePickupReminderRun::count())->toBe(0);
});
