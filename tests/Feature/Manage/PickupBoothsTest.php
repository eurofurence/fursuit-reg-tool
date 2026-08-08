<?php

/*
 * Pickup Booths: the two guarantees that outlived the move.
 *
 * The booth split is edited in Settings > On-Site Desk now, and OnSiteDeskTest owns the
 * screen: the row shape, the counts, the reviewer split, and the whole table of bad rows
 * (overlaps, gaps, backwards ranges, a stray open end, non-numeric and empty). None of that
 * is repeated here.
 *
 * What is here is the pair that belongs to the move itself rather than to the screen:
 *
 *  - the URL the page used to live at still lands somewhere, because it is the one panel
 *    page an operator plausibly bookmarked, and it was bookmarked for the one hour of the
 *    year when nobody has time to hunt for where a screen went;
 *  - a cross-row error names the row the operator typed. The server sorts the rows before
 *    it checks them against each other, so this is the one thing about the new validation
 *    that a table of single-fault cases cannot catch: every one of those has the submitted
 *    order and the sorted order agreeing.
 */

use App\Models\Event;
use App\Models\User;
use App\Support\Manage\EventScope;
use Illuminate\Support\Facades\Route;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withSession;

beforeEach(function () {
    $this->admin = User::factory()->create(['is_admin' => true, 'is_reviewer' => false]);

    $this->event = Event::factory()->create(['name' => 'Eurofurence 30']);

    $this->session = [
        EventScope::SESSION_ID => $this->event->id,
        EventScope::SESSION_CHOSEN => true,
    ];
});

test('the retired Tools URL redirects to the pane that replaced it', function () {
    actingAs($this->admin);

    withSession($this->session)
        ->get('/admin/tools/pickup-booths')
        ->assertRedirect(route('manage.settings.on-site-desk'));

    // Unnamed, so App\Support\Manage\Navigation cannot put the retired item back in the
    // rail pointing at a redirect.
    expect(Route::has('manage.tools.pickup-booths'))->toBeFalse();
});

test('a cross-row error names the row the operator typed, not its sorted position', function () {
    /*
     * Row 0 opens at 2000 and sorts last; row 1 covers 0 to 999 and sorts first. The gap is
     * between them, and it is row 0 that has to move. Reporting the sorted position would
     * underline row 1, which is the row that is right.
     */
    actingAs($this->admin);

    withSession($this->session)
        ->put(route('manage.settings.on-site-desk.booths'), [
            'booths' => [
                ['from' => 2000, 'to' => null],
                ['from' => 0, 'to' => 999],
            ],
        ])
        ->assertSessionHasErrors('booths.0.from')
        ->assertSessionDoesntHaveErrors('booths.1.from');

    expect($this->event->fresh()->pickup_booths)->toBeNull();
});
