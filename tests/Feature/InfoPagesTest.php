<?php

use App\Http\Controllers\InfoController;
use App\Models\Badge\Badge;
use App\Models\Badge\State_Fulfillment\Pending;
use App\Models\Badge\State_Fulfillment\ReadyForPickup;
use App\Models\Event;
use App\Models\EventUser;
use App\Models\Fursuit\Fursuit;
use App\Models\User;
use App\Services\BadgeCalculationService;
use App\Support\PickupBooths;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

describe('Public information pages', function () {
    test('the FAQ renders without an event', function () {
        get(route('info.faq'))
            ->assertSuccessful()
            ->assertInertia(fn ($page) => $page->component('Info/Faq')->where('event', null));
    });

    test('pickup falls back to the default booth split when the event configures none', function () {
        Event::factory()->create([
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(3),
            'pickup_booths' => null,
        ]);

        get(route('info.pickup'))
            ->assertSuccessful()
            ->assertInertia(fn ($page) => $page->component('Info/Pickup')
                ->where('booths', PickupBooths::DEFAULTS)
                ->where('attendeeId', null)
                ->where('myBoothIndex', null)
            );
    });

    test('pickup publishes the desk hours only once the desk has any', function () {
        // No invented fallback: an opening time nobody staffed sends attendees to a closed
        // hall, so an unconfigured event publishes an empty list and the page says nothing.
        $event = Event::factory()->create([
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(3),
            'desk_opening_hours' => null,
        ]);

        get(route('info.pickup'))
            ->assertSuccessful()
            ->assertInertia(fn ($page) => $page->where('openingHours', []));

        $event->update(['desk_opening_hours' => [
            ['date' => '2026-09-02', 'opens' => '10:00', 'closes' => '18:00', 'note' => null],
        ]]);

        get(route('info.pickup'))
            ->assertSuccessful()
            ->assertInertia(fn ($page) => $page
                ->where('openingHours.0.date', '2026-09-02')
                ->where('openingHours.0.opens', '10:00')
                ->where('openingHours.0.closes', '18:00')
            );
    });

    test('pickup marks the booth serving the signed-in attendee', function () {
        $event = Event::factory()->create([
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(3),
            'pickup_booths' => [
                ['label' => 'A', 'from' => 0, 'to' => 999],
                ['label' => 'B', 'from' => 1000, 'to' => null],
            ],
        ]);

        $user = User::factory()->create();
        EventUser::factory()->create([
            'event_id' => $event->id,
            'user_id' => $user->id,
            'attendee_id' => 1234,
        ]);

        actingAs($user)
            ->get(route('info.pickup'))
            ->assertSuccessful()
            ->assertInertia(fn ($page) => $page
                ->where('attendeeId', 1234)
                ->where('myBoothIndex', 1)
            );
    });

    test('the Catch-Em-All page explains the game instead of redirecting to it', function () {
        Event::factory()->create([
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(3),
            'catch_em_all_enabled' => true,
            'catch_em_all_start' => now()->subHour(),
            'catch_em_all_end' => now()->addDays(2),
        ]);

        get(route('info.catch-em-all'))
            ->assertSuccessful()
            ->assertInertia(fn ($page) => $page->component('Info/CatchEmAll')
                ->where('isActive', true)
                ->where('gameUrl', InfoController::gameUrl())
            );
    });

    test('the Catch-Em-All page reports the game as closed outside its window', function () {
        Event::factory()->create([
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(3),
            'catch_em_all_enabled' => false,
        ]);

        get(route('info.catch-em-all'))
            ->assertSuccessful()
            ->assertInertia(fn ($page) => $page->where('isActive', false));
    });
});

describe('Navigation gating', function () {
    // The site nav hides the Catch-Em-All entry off this flag rather than linking to a
    // game nobody can play. See HandleInertiaRequests::share().
    test('catchEmAllActive is shared with every page', function () {
        Event::factory()->create([
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(3),
            'catch_em_all_enabled' => true,
            'catch_em_all_start' => now()->subHour(),
            'catch_em_all_end' => now()->addDays(2),
        ]);

        get(route('welcome'))
            ->assertInertia(fn ($page) => $page->where('catchEmAllActive', true));
    });

    test('catchEmAllActive is false when no event exists', function () {
        get(route('welcome'))
            ->assertInertia(fn ($page) => $page->where('catchEmAllActive', false));
    });
});

describe('Free badge deadline', function () {
    // It has its own column rather than borrowing mass_printed_at, which records when the
    // print run happened and rendered "28 July" where the site says 1 August.
    test('the FAQ quotes the event field and the live badge price', function () {
        Event::factory()->create([
            'starts_at' => now()->addMonth(),
            'ends_at' => now()->addMonth()->addDays(4),
            'free_badge_deadline' => now()->addDays(10),
            'mass_printed_at' => now()->addDays(3),
        ]);

        get(route('info.faq'))
            ->assertSuccessful()
            ->assertInertia(fn ($page) => $page->component('Info/Faq')
                ->where('freeBadgeDeadline', Event::getActiveEvent()->free_badge_deadline->toISOString())
                ->where('badgePrice', BadgeCalculationService::calculate())
            );
    });

    test('an event without a deadline sends null rather than a made-up date', function () {
        Event::factory()->create([
            'starts_at' => now()->addMonth(),
            'ends_at' => now()->addMonth()->addDays(4),
            'free_badge_deadline' => null,
        ]);

        get(route('info.faq'))
            ->assertInertia(fn ($page) => $page->where('freeBadgeDeadline', null));
    });
});

describe('Welcome page badge summary', function () {
    // The landing page could not answer "is my badge ready", which is the main reason
    // somebody opens it on site. It now rolls the fulfillment states up into one line.
    test('it rolls fulfillment states up for the signed-in user', function () {
        $event = Event::factory()->create([
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(3),
            'order_starts_at' => now()->subDays(2),
            'order_ends_at' => now()->addDays(2),
        ]);

        $user = User::factory()->create();
        EventUser::factory()->create([
            'event_id' => $event->id,
            'user_id' => $user->id,
            'valid_registration' => true,
        ]);

        $fursuit = Fursuit::factory()->create([
            'user_id' => $user->id,
            'event_id' => $event->id,
        ]);

        Badge::factory()->create([
            'fursuit_id' => $fursuit->id,
            'status_fulfillment' => ReadyForPickup::$name,
        ]);
        Badge::factory()->create([
            'fursuit_id' => $fursuit->id,
            'status_fulfillment' => Pending::$name,
        ]);

        actingAs($user)
            ->get(route('welcome'))
            ->assertSuccessful()
            ->assertInertia(fn ($page) => $page
                ->where('badgeSummary.total', 2)
                ->where('badgeSummary.ready', 1)
                ->where('badgeSummary.inProgress', 1)
                ->where('badgeSummary.pickedUp', 0)
            );
    });

    test('a signed-out visitor gets no summary but still gets the price', function () {
        Event::factory()->create([
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(3),
        ]);

        get(route('welcome'))
            ->assertInertia(fn ($page) => $page
                ->where('badgeSummary', null)
                ->where('badgePrice', BadgeCalculationService::calculate())
            );
    });
});
