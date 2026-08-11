<?php

/*
 * The attendee badge editor answers to the owner rules, not to the panel override.
 *
 * `BadgePolicy::update` used to read `$user->is_admin && request()->routeIs('<old-panel>.*',
 * 'livewire.*')`, which is false on `Route::resource('badges')`, so every operator on the
 * public routes fell through to the owner rules. The panel override was made
 * request-independent, which is right for /admin and wrong here: the same ability guards
 * `GET /badges/{badge}/edit` and `PUT /badges/{badge}`, a write path that resets the
 * fursuit to pending review and recalculates the total. `updateAsOwner()` is what those
 * routes ask now.
 */

use App\Models\Badge\Badge;
use App\Models\Event;
use App\Models\EventUser;
use App\Models\Fursuit\Fursuit;
use App\Models\Fursuit\States\Approved;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\put;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    Http::fake();

    $this->event = Event::factory()->create([
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDays(10),
        'order_starts_at' => now()->subDay(),
        'order_ends_at' => now()->addDays(5),
    ]);

    $this->owner = User::factory()->create(['is_admin' => false, 'is_reviewer' => false]);
    $this->reviewer = User::factory()->create(['is_admin' => false, 'is_reviewer' => true]);
    $this->admin = User::factory()->create(['is_admin' => true, 'is_reviewer' => false]);

    foreach ([$this->owner, $this->reviewer, $this->admin] as $user) {
        EventUser::create([
            'user_id' => $user->id,
            'event_id' => $this->event->id,
            'attendee_id' => (string) (10000 + $user->id),
            'valid_registration' => true,
            'prepaid_badges' => 0,
        ]);
    }

    // A badge already committed to a print batch. The artwork was rendered when the batch
    // was built, so an edit now produces a card that no longer matches the order.
    $this->lockedBadge = fn () => Badge::factory()->create([
        'fursuit_id' => Fursuit::factory()->create([
            'user_id' => $this->owner->id,
            'event_id' => $this->event->id,
            'status' => Approved::$name,
            'name' => 'Locked Suit',
        ])->id,
        'printing_locked_at' => now(),
    ]);
});

test('a reviewer cannot edit somebody else\'s badge through the attendee editor', function () {
    $badge = ($this->lockedBadge)();

    actingAs($this->reviewer);

    get(route('badges.edit', $badge))->assertForbidden();

    put(route('badges.update', $badge), [
        'species' => 'Wolf',
        'name' => 'Rewritten',
        'catchEmAll' => true,
        'publish' => true,
    ])->assertForbidden();

    expect($badge->fursuit->fresh()->name)->toBe('Locked Suit');
});

test('an admin cannot edit somebody else\'s badge through the attendee editor either', function () {
    // The earlier behaviour: that route check was false here, so an admin got the
    // owner rules, and the owner rules refuse a badge that is not theirs.
    $badge = ($this->lockedBadge)();

    actingAs($this->admin);

    get(route('badges.edit', $badge))->assertForbidden();
    put(route('badges.update', $badge), [
        'species' => 'Wolf',
        'name' => 'Rewritten',
        'catchEmAll' => true,
        'publish' => true,
    ])->assertForbidden();

    expect($badge->fursuit->fresh()->name)->toBe('Locked Suit');
});

test('the print lock still stops the owner, so the guard is the lock and not the actor', function () {
    $badge = ($this->lockedBadge)();

    actingAs($this->owner);

    get(route('badges.edit', $badge))->assertForbidden();
});

test('the panel override survives for an admin and is gone for a reviewer', function () {
    /*
     * `update` keeps answering yes for an admin, which is what the panel needs.
     * The reviewer half of the override is gone: reviewers were narrowed to Dashboard,
     * Badges and Fursuits, and reading a badge is what they kept, not moving one between
     * states (docs/admin/roles.md). /admin/badges/{badge}/edit authorizes `view` now and
     * asks `update` only for whether the page renders as a form.
     *
     * `updateAsOwner` stays false for both. That is the point of the split: the operator
     * override was never meant to walk an operator past the attendee write path's own
     * guards, and it still does not.
     */
    $badge = ($this->lockedBadge)();

    expect(Gate::forUser($this->admin)->allows('update', $badge))->toBeTrue()
        ->and(Gate::forUser($this->reviewer)->allows('update', $badge))->toBeFalse()
        ->and(Gate::forUser($this->reviewer)->allows('view', $badge))->toBeTrue()
        ->and(Gate::forUser($this->admin)->allows('updateAsOwner', $badge))->toBeFalse()
        ->and(Gate::forUser($this->reviewer)->allows('updateAsOwner', $badge))->toBeFalse();
});
