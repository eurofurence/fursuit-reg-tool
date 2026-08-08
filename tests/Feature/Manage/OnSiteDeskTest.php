<?php

/*
 * Settings > On-Site Desk.
 *
 * The two things the badge desk publishes about itself - when it is open, and which booth
 * an attendee queues at - both stored per event and both read back by attendee-facing
 * pages. Four things this locks in:
 *
 *  - a booth row that is wrong is refused, not repaired: overlaps, gaps, backwards ranges
 *    and a stray open end all come back as errors on the row that caused them, because
 *    normalize() would silently drop them and nobody would see it until the queue formed;
 *  - opening hours round-trip, and clearing them is a legitimate save rather than a way to
 *    get stuck with yesterday's times;
 *  - reviewers may look but not write, like every other configuration surface;
 *  - the counts shown next to the editor are the real per-booth attendee and badge counts
 *    for the selected event, which is what makes a lopsided split visible.
 */

use App\Models\Badge\Badge;
use App\Models\Event;
use App\Models\EventUser;
use App\Models\Fursuit\Fursuit;
use App\Models\Species;
use App\Models\User;
use App\Support\DeskOpeningHours;
use App\Support\Manage\EventScope;
use App\Support\PickupBooths;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withSession;

beforeEach(function () {
    $this->admin = User::factory()->create(['is_admin' => true, 'is_reviewer' => false]);
    $this->reviewer = User::factory()->create(['is_admin' => false, 'is_reviewer' => true]);

    $this->event = Event::factory()->create(['name' => 'Eurofurence 30']);

    $this->session = [
        EventScope::SESSION_ID => $this->event->id,
        EventScope::SESSION_CHOSEN => true,
    ];

    // A badge for an attendee with a known id, so the counts have something to count.
    $this->badgeFor = function (int $attendeeId) {
        $owner = User::factory()->create();
        EventUser::factory()->create([
            'user_id' => $owner->id,
            'event_id' => $this->event->id,
            'attendee_id' => (string) $attendeeId,
            // EnsureEventUserMiddleware re-fetches registrations that are not valid,
            // which would send the badge list through the SSO flow instead of rendering.
            'valid_registration' => true,
        ]);

        $fursuit = Fursuit::factory()->create([
            'user_id' => $owner->id,
            'event_id' => $this->event->id,
            'species_id' => Species::factory()->create()->id,
        ]);

        return Badge::factory()->create(['fursuit_id' => $fursuit->id]);
    };

    $this->rows = fn (array $booths) => ['booths' => $booths];
});

test('the pane lives under Settings, not Tools', function () {
    expect(route('manage.settings.on-site-desk', absolute: false))->toBe('/admin/settings/on-site-desk')
        ->and(Route::has('manage.tools.pickup-booths'))->toBeFalse();
});

test('an event without its own split falls back to the defaults', function () {
    expect(PickupBooths::forEvent($this->event))->toBe(PickupBooths::DEFAULTS);
});

test('the page renders both editors and the per-booth counts', function () {
    ($this->badgeFor)(12);      // booth 1
    ($this->badgeFor)(1500);    // booth 2
    ($this->badgeFor)(9000);    // last booth

    $this->event->update(['desk_opening_hours' => [
        ['date' => '2026-09-02', 'opens' => '10:00', 'closes' => '18:00', 'note' => null],
    ]]);

    withSession($this->session)
        ->actingAs($this->admin)
        ->get(route('manage.settings.on-site-desk'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Manage/Settings/OnSiteDesk')
            ->where('isConfigured', false)
            ->where('openingHours.0.date', '2026-09-02')
            ->where('openingHours.0.opens', '10:00')
            // Rows, not a JSON blob, and a purely derived label comes back blank so the
            // editor keeps deriving it.
            ->where('booths.0.from', 0)
            ->where('booths.0.to', 999)
            ->where('booths.0.label', null)
            ->where('counts.totals.badges', 3)
            ->where('counts.totals.attendees', 3)
            ->where('counts.totals.unassigned', 0)
            ->where('counts.booths.0.attendees', 1)
            ->where('counts.booths.1.attendees', 1)
            ->where('counts.booths.5.attendees', 1)
        );
});

test('an admin can save a custom split', function () {
    actingAs($this->admin);

    withSession($this->session)->put(route('manage.settings.on-site-desk.booths'), [
        'booths' => [
            ['from' => 0, 'to' => 4999],
            ['from' => 5000, 'to' => null],
        ],
    ])->assertRedirect(route('manage.settings.on-site-desk'));

    expect($this->event->fresh()->pickup_booths)->toBe([
        ['label' => '0 – 4999', 'from' => 0, 'to' => 4999],
        ['label' => '5000 and up', 'from' => 5000, 'to' => null],
    ]);
});

test('rows are stored in range order however they were typed', function () {
    actingAs($this->admin);

    withSession($this->session)->put(route('manage.settings.on-site-desk.booths'), [
        'booths' => [
            ['from' => 5000, 'to' => null],
            ['from' => 0, 'to' => 4999],
        ],
    ])->assertSessionHasNoErrors();

    expect(array_column($this->event->fresh()->pickup_booths, 'from'))->toBe([0, 5000]);
});

test('a typed label is kept and a blank one is derived', function () {
    actingAs($this->admin);

    withSession($this->session)->put(route('manage.settings.on-site-desk.booths'), [
        'booths' => [
            ['label' => 'Lounge counter', 'from' => 0, 'to' => 999],
            ['label' => '', 'from' => 1000, 'to' => null],
        ],
    ])->assertSessionHasNoErrors();

    expect(array_column($this->event->fresh()->pickup_booths, 'label'))
        ->toBe(['Lounge counter', '1000 and up']);
});

test('resetting clears the column so the event follows the defaults again', function () {
    $this->event->update(['pickup_booths' => [['label' => 'All', 'from' => 0, 'to' => null]]]);

    actingAs($this->admin);

    withSession($this->session)
        ->post(route('manage.settings.on-site-desk.booths.reset'))
        ->assertRedirect(route('manage.settings.on-site-desk'));

    expect($this->event->fresh()->pickup_booths)->toBeNull();
});

test('a bad booth row is refused, and the error names the field that caused it', function (array $booths, string $field, string $reason) {
    actingAs($this->admin);

    withSession($this->session)
        ->put(route('manage.settings.on-site-desk.booths'), ['booths' => $booths])
        ->assertSessionHasErrors($field);

    expect($this->event->fresh()->pickup_booths)->toBeNull($reason);
})->with([
    'no booths at all' => [[], 'booths', 'an empty split'],
    'missing from' => [[['to' => 999]], 'booths.0.from', 'a booth with no start'],
    'non-numeric from' => [[['from' => 'zero', 'to' => 999]], 'booths.0.from', 'a start that is not a number'],
    'negative from' => [[['from' => -1, 'to' => 999]], 'booths.0.from', 'a negative attendee id'],
    'backwards range' => [[['from' => 900, 'to' => 100]], 'booths.0.to', 'a booth ending before it starts'],
    'overlapping' => [
        [['from' => 0, 'to' => 1500], ['from' => 1000, 'to' => null]],
        'booths.1.from',
        'two booths claiming one attendee',
    ],
    'gap between booths' => [
        [['from' => 0, 'to' => 999], ['from' => 2000, 'to' => null]],
        'booths.1.from',
        'attendee ids belonging to no booth',
    ],
    'open end that is not last' => [
        [['from' => 0, 'to' => null], ['from' => 1000, 'to' => 1999]],
        'booths.0.to',
        'an open-ended booth swallowing the ones above it',
    ],
]);

test('an admin can publish, edit and clear the opening hours', function () {
    actingAs($this->admin);

    // Typed out of order on purpose: days have one correct order and it is not the order
    // somebody happened to add the rows in.
    withSession($this->session)->put(route('manage.settings.on-site-desk.hours'), [
        'hours' => [
            ['date' => '2026-09-03', 'opens' => '09:00', 'closes' => '17:00', 'note' => ''],
            ['date' => '2026-09-02', 'opens' => '10:00', 'closes' => '18:00', 'note' => 'Busiest day'],
        ],
    ])->assertRedirect(route('manage.settings.on-site-desk'));

    expect($this->event->fresh()->desk_opening_hours)->toBe([
        ['date' => '2026-09-02', 'opens' => '10:00', 'closes' => '18:00', 'note' => 'Busiest day'],
        ['date' => '2026-09-03', 'opens' => '09:00', 'closes' => '17:00', 'note' => null],
    ]);

    // Clearing every row is a save, not a no-op: the column goes back to null so it reads
    // the same as an event that never published hours.
    withSession($this->session)
        ->put(route('manage.settings.on-site-desk.hours'), ['hours' => []])
        ->assertSessionHasNoErrors();

    expect($this->event->fresh()->desk_opening_hours)->toBeNull()
        ->and(DeskOpeningHours::forEvent($this->event->fresh()))->toBe([]);
});

test('a bad opening-hours row is refused', function (array $hours, string $field) {
    actingAs($this->admin);

    withSession($this->session)
        ->put(route('manage.settings.on-site-desk.hours'), ['hours' => $hours])
        ->assertSessionHasErrors($field);

    expect($this->event->fresh()->desk_opening_hours)->toBeNull();
})->with([
    'no date' => [[['date' => '', 'opens' => '10:00', 'closes' => '18:00']], 'hours.0.date'],
    'a weekday name' => [[['date' => 'Wednesday', 'opens' => '10:00', 'closes' => '18:00']], 'hours.0.date'],
    'a day that does not exist' => [[['date' => '2026-09-31', 'opens' => '10:00', 'closes' => '18:00']], 'hours.0.date'],
    'the same day twice' => [
        [
            ['date' => '2026-09-02', 'opens' => '10:00', 'closes' => '13:00'],
            ['date' => '2026-09-02', 'opens' => '14:00', 'closes' => '18:00'],
        ],
        'hours.1.date',
    ],
    'not a time' => [[['date' => '2026-09-02', 'opens' => 'morning', 'closes' => '18:00']], 'hours.0.opens'],
    'missing close' => [[['date' => '2026-09-02', 'opens' => '10:00']], 'hours.0.closes'],
]);

test('a reviewer can read the page but neither write', function () {
    actingAs($this->reviewer);

    withSession($this->session)
        ->get(route('manage.settings.on-site-desk'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('canEdit', false));

    withSession($this->session)
        ->put(route('manage.settings.on-site-desk.booths'), ['booths' => [['from' => 0, 'to' => null]]])
        ->assertForbidden();

    withSession($this->session)
        ->put(route('manage.settings.on-site-desk.hours'), [
            'hours' => [['date' => '2026-09-02', 'opens' => '10:00', 'closes' => '18:00']],
        ])
        ->assertForbidden();

    expect($this->event->fresh()->pickup_booths)->toBeNull()
        ->and($this->event->fresh()->desk_opening_hours)->toBeNull();
});

test('badges whose owner has no attendee id are reported as unassigned', function () {
    $owner = User::factory()->create();
    $fursuit = Fursuit::factory()->create([
        'user_id' => $owner->id,
        'event_id' => $this->event->id,
        'species_id' => Species::factory()->create()->id,
    ]);
    Badge::factory()->create(['fursuit_id' => $fursuit->id]);

    $counts = PickupBooths::counts($this->event, PickupBooths::forEvent($this->event));

    expect($counts['totals']['unassigned'])->toBe(1)
        ->and($counts['totals']['badges'])->toBe(0);
});

test('the attendee badge list is served the configured split and the desk hours', function () {
    $this->event->update([
        'pickup_booths' => [['label' => 'Only booth', 'from' => 0, 'to' => null]],
        'desk_opening_hours' => [['date' => '2026-09-02', 'opens' => '10:00', 'closes' => '18:00', 'note' => null]],
    ]);

    $badge = ($this->badgeFor)(42);
    $owner = $badge->fursuit->user;

    actingAs($owner)
        ->get(route('badges.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('attendeeId', '42')
            ->where('pickupBooths', [['label' => 'Only booth', 'from' => 0, 'to' => null]])
            ->where('deskOpeningHours.0.date', '2026-09-02')
        );
});
