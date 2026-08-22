<?php

/*
 * The sent-mail log, and the account page that reads it.
 *
 * The log exists so the desk can answer "did they hear from us" without reading a mail server. It
 * is written from the framework's NotificationSent event, which is the part worth asserting: a
 * notification added next year has to be logged without anybody touching the listener.
 *
 * It is a log and never a guard. Nothing decides whether to send by reading it - the pickup
 * reminder keeps `badges.pickup_reminded_at` for that - because a failure to write paperwork must
 * never turn into a second mail.
 */

use App\Models\Badge\Badge;
use App\Models\Event;
use App\Models\EventUser;
use App\Models\Fursuit\Fursuit;
use App\Models\Fursuit\States\Approved;
use App\Models\SentNotification;
use App\Models\Species;
use App\Models\User;
use App\Notifications\BadgePickupReminderNotification;
use Illuminate\Support\Facades\Mail;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    Mail::fake();

    $this->event = Event::factory()->create([
        'name' => 'Eurofurence 30',
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDays(2),
        'desk_opening_hours' => [
            ['date' => now()->format('Y-m-d'), 'opens' => '10:00', 'closes' => '18:00', 'reminds_at' => null],
        ],
    ]);

    $this->attendee = User::factory()->create(['name' => 'Tally Fox']);

    EventUser::factory()->create([
        'user_id' => $this->attendee->id,
        'event_id' => $this->event->id,
        'valid_registration' => true,
    ]);

    $this->badge = Badge::factory()->create([
        'status_fulfillment' => 'ready_for_pickup',
        'extra_copy_of' => null,
        'fursuit_id' => Fursuit::factory()->create([
            'event_id' => $this->event->id,
            'user_id' => $this->attendee->id,
            'species_id' => Species::firstOrCreate(['name' => 'Wolf'], ['type' => 'canine', 'checked' => true])->id,
            'status' => Approved::$name,
            'name' => 'Tally',
        ])->id,
    ]);

    // Setup can send mail of its own, so every test picks out the row it is about rather than
    // assuming the log holds exactly one.
    $this->reminder = fn () => SentNotification::where('notification', BadgePickupReminderNotification::class)->sole();
});

test('a delivered notification is logged against its recipient', function () {
    $this->attendee->notify(new BadgePickupReminderNotification($this->badge));

    $logged = ($this->reminder)();

    expect($logged->notifiable_id)->toBe($this->attendee->id)
        ->and($logged->notification)->toBe(BadgePickupReminderNotification::class)
        ->and($logged->channel)->toBe('mail')
        ->and($logged->sent_at)->not->toBeNull();
});

test('the log records the subject the recipient saw', function () {
    $this->attendee->notify(new BadgePickupReminderNotification($this->badge));

    expect(($this->reminder)()->subject)->toBe('"Tally" - your badge is still waiting for you!');
});

test('the log points back at the record the mail was about', function () {
    $this->attendee->notify(new BadgePickupReminderNotification($this->badge));

    $logged = ($this->reminder)();

    expect($logged->subject_model_id)->toBe($this->badge->id)
        ->and($logged->subjectModel)->toBeInstanceOf(Badge::class);
});

test('the class becomes a label without anybody storing one', function () {
    $this->attendee->notify(new BadgePickupReminderNotification($this->badge));

    expect(($this->reminder)()->label())->toBe('Badge Pickup Reminder');
});

test('the account page shows what we have sent, newest first', function () {
    $this->attendee->notify(new BadgePickupReminderNotification($this->badge));

    // A second, older row, to prove the ordering rather than the query happening to return one.
    SentNotification::create([
        'notifiable_type' => $this->attendee->getMorphClass(),
        'notifiable_id' => $this->attendee->id,
        'notification' => 'App\\Notifications\\BadgeCreatedNotification',
        'channel' => 'mail',
        'subject' => 'Older mail',
        'sent_at' => now()->subDays(2),
    ]);

    $admin = User::factory()->create(['is_admin' => true]);

    $page = actingAs($admin)
        ->get(route('admin.settings.users.edit', $this->attendee))
        ->assertSuccessful()
        ->viewData('page')['props'];

    expect($page['sentNotifications'])->toHaveCount(2)
        ->and($page['sentNotifications'][0]['label'])->toBe('Badge Pickup Reminder')
        ->and(end($page['sentNotifications'])['subject'])->toBe('Older mail');
});

test('an account nobody has mailed shows an empty history rather than nothing', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    $untouched = User::factory()->create();

    $page = actingAs($admin)
        ->get(route('admin.settings.users.edit', $untouched))
        ->assertSuccessful()
        ->viewData('page')['props'];

    expect($page['sentNotifications'])->toBe([]);
});
