<?php

use App\Domain\CatchEmAll\Models\UserCatch;
use App\Models\Event;
use App\Models\EventUser;
use App\Models\Fursuit\Fursuit;
use App\Models\Species;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\get;
use function Pest\Laravel\travelTo;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    Storage::put('fursuits/ranked.jpg', UploadedFile::fake()->image('ranked.jpg', 120, 160)->get());
});

function rankedEvent(string $name, string $startsAt, bool $catchEmAll = true): Event
{
    return Event::factory()->create([
        'name' => $name,
        'starts_at' => Carbon::parse($startsAt),
        'ends_at' => Carbon::parse($startsAt)->addDays(5),
        'catch_em_all_enabled' => $catchEmAll,
    ]);
}

function publishedFursuit(Event $event, string $name): Fursuit
{
    return Fursuit::create([
        'user_id' => User::factory()->create()->id,
        'event_id' => $event->id,
        'species_id' => Species::firstOrCreate(['name' => 'Wolf'], ['checked' => true])->id,
        'name' => $name,
        'image' => 'fursuits/ranked.jpg',
        'status' => 'approved',
        'published' => true,
        'catch_em_all' => false,
    ]);
}

/**
 * Give `$catcher` that many catches at `$event`. Catches hang off event_users, which is
 * what scopes a leaderboard to one convention.
 */
function catchesFor(Event $event, string $catcher, int $count): void
{
    $eventUser = EventUser::create([
        'user_id' => User::factory()->create(['name' => $catcher])->id,
        'event_id' => $event->id,
        'attendee_id' => (string) random_int(10000, 99999),
        'valid_registration' => true,
        'prepaid_badges' => 0,
    ]);

    foreach (range(1, $count) as $i) {
        UserCatch::create([
            'event_user_id' => $eventUser->id,
            'fursuit_id' => publishedFursuit($event, $catcher.' target '.$i)->id,
        ]);
    }
}

test('the overview shows the running convention leaderboard, counted from its own catches', function () {
    travelTo(Carbon::parse('2026-09-05'));

    $current = rankedEvent('EF30', '2026-09-01');
    $lastYear = rankedEvent('EF29', '2025-09-01');

    catchesFor($current, 'Nightpaw', 3);
    catchesFor($current, 'Kiro', 2);
    catchesFor($lastYear, 'Old Champion', 50);

    get(route('gallery.index'))
        ->assertInertia(fn ($page) => $page
            ->where('ranking_event', 'EF30')
            ->where('ranking.0.user', 'Nightpaw')
            ->where('ranking.0.catches', 3)
            ->where('ranking.1.user', 'Kiro')
            ->has('ranking', 2)
        );
});

test('a catch-em-all event with no catches yet shows nothing, not last year\'s scores', function () {
    travelTo(Carbon::parse('2026-08-08'));

    rankedEvent('EF30', '2026-08-19');            // enabled, not played yet
    $lastYear = rankedEvent('EF29', '2025-09-01');
    catchesFor($lastYear, 'Old Champion', 50);

    get(route('gallery.index'))
        ->assertInertia(fn ($page) => $page
            ->where('ranking_event', null)
            ->has('ranking', 0)
        );
});

test('the overview drops the leaderboard once the year turns', function () {
    travelTo(Carbon::parse('2027-01-02'));

    $event = rankedEvent('EF30', '2026-09-01');
    catchesFor($event, 'Nightpaw', 3);

    get(route('gallery.index'))
        ->assertInertia(fn ($page) => $page->where('ranking_event', null)->has('ranking', 0));

    get(route('gallery.all'))
        ->assertInertia(fn ($page) => $page->where('ranking_event', null)->has('ranking', 0));
});

test("an event's own folder keeps its own leaderboard after the year turns", function () {
    travelTo(Carbon::parse('2027-01-02'));

    $event = rankedEvent('EF30', '2026-09-01');
    $older = rankedEvent('EF29', '2025-09-01');

    catchesFor($event, 'Nightpaw', 3);
    catchesFor($older, 'Old Champion', 50);

    get(route('gallery.event', $event))
        ->assertInertia(fn ($page) => $page
            ->where('ranking_event', 'EF30')
            ->where('ranking.0.user', 'Nightpaw')
            ->has('ranking', 1)
        );

    get(route('gallery.event', $older))
        ->assertInertia(fn ($page) => $page
            ->where('ranking_event', 'EF29')
            ->where('ranking.0.user', 'Old Champion')
            ->where('ranking.0.catches', 50)
        );
});

test('a convention that never ran catch-em-all shows no leaderboard', function () {
    travelTo(Carbon::parse('2026-09-05'));

    $historical = rankedEvent('EF20', '2026-09-01', catchEmAll: false);
    publishedFursuit($historical, 'Archive suit');

    get(route('gallery.event', $historical))
        ->assertInertia(fn ($page) => $page->where('ranking_event', null)->has('ranking', 0));
});

test('players on the same score share a rank', function () {
    travelTo(Carbon::parse('2026-09-05'));

    $event = rankedEvent('EF30', '2026-09-01');
    catchesFor($event, 'First', 2);
    catchesFor($event, 'Second', 2);
    catchesFor($event, 'Third', 1);

    get(route('gallery.event', $event))
        ->assertInertia(fn ($page) => $page
            ->where('ranking.0.rank', 1)
            ->where('ranking.1.rank', 1)
            ->where('ranking.2.rank', 3)
        );
});
