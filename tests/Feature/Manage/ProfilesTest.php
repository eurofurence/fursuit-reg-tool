<?php

/*
 * The Catch-Em-All profile queue: three verdicts and a claim that expires.
 *
 * What this locks in:
 *
 *  - **A reviewer may decide.** The verdicts authorize `view`, not `update`, because
 *    UserProfilePolicy::update is admin-only and means editing the row. Wiring them to
 *    `update` would leave the module reachable for a reviewer and every button in it
 *    refused, which is how the old panel's badge screens went wrong.
 *  - **The claim is enforced.** Two reviewers working the queue must not decide the same
 *    profile a second apart, so a verdict without the claim is refused rather than
 *    silently applied.
 *  - **A verdict publishes or hides everything at once.** Approval is what makes the
 *    description, the links and the mirrored avatar visible; the public page is what the
 *    attendee sees, and it is asserted here rather than only in the panel.
 *  - **Any later edit sends it back.** The whole reason the queue exists is that an
 *    approved profile can be rewritten the moment it is approved.
 */

use App\Models\User;
use App\Models\UserProfile\States\Approved;
use App\Models\UserProfile\States\Pending;
use App\Models\UserProfile\States\Rejected;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->reviewer = User::factory()->create(['is_admin' => false, 'is_reviewer' => true]);
    $this->second = User::factory()->create(['is_admin' => false, 'is_reviewer' => true]);

    // UserObserver creates the profile with the account, and a fresh profile is approved:
    // there is nothing in it to review yet.
    $this->owner = User::factory()->create(['name' => 'Attendee']);
    $this->profile = $this->owner->userProfile()->firstOrFail();

    // What makes it review work.
    $this->profile->update(['description' => 'Ask me about my ears']);
    $this->profile->links()->create(['url' => 'https://example.com/me']);
    $this->profile->refresh();
});

it('shows a reviewer the pending profile with its links', function () {
    actingAs($this->reviewer)
        ->get(route('admin.profiles.show', $this->profile))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Manage/Profiles/Show')
            ->where('profile.user', 'Attendee')
            ->where('profile.description', 'Ask me about my ears')
            ->where('profile.links.0', 'https://example.com/me')
            ->where('profile.status.label', 'Pending')
            ->where('profile.claim.held', true)
        );
});

it('lists pending profiles first', function () {
    actingAs($this->reviewer)
        ->get(route('admin.profiles.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Manage/Profiles/Index')
            ->where('rows.0.cells.user_name', 'Attendee')
            ->has('rows', 1)
        );
});

it('hands the queue entry point the next waiting profile', function () {
    actingAs($this->reviewer)
        ->get(route('admin.profiles.review'))
        ->assertRedirect(route('admin.profiles.show', $this->profile));
});

it('sends the reviewer to the list when nothing is waiting', function () {
    $this->profile->status->transitionTo(Approved::class, $this->reviewer);

    actingAs($this->reviewer)
        ->get(route('admin.profiles.review'))
        ->assertRedirect(route('admin.profiles.index'));
});

it('approves a claimed profile and publishes it', function () {
    // The claim is taken by opening the record, which is what a reviewer does.
    actingAs($this->reviewer)->get(route('admin.profiles.show', $this->profile));

    actingAs($this->reviewer)
        ->post(route('admin.profiles.approve', $this->profile))
        ->assertRedirect();

    expect($this->profile->refresh()->status)->toBeInstanceOf(Approved::class);

    // Approved is what the public page checks, so the verdict is asserted where it lands.
    actingAs($this->owner)
        ->get(route('catch-em-all.profiles.show', $this->profile->uuid))
        ->assertOk();
});

it('rejects with a reason the owner can read', function () {
    actingAs($this->reviewer)->get(route('admin.profiles.show', $this->profile));

    actingAs($this->reviewer)
        ->post(route('admin.profiles.reject', $this->profile), ['reason' => 'Links to a shop.'])
        ->assertRedirect();

    $this->profile->refresh();

    expect($this->profile->status)->toBeInstanceOf(Rejected::class)
        ->and($this->profile->rejection_reason)->toBe('Links to a shop.');
});

it('refuses a rejection with no reason', function () {
    actingAs($this->reviewer)->get(route('admin.profiles.show', $this->profile));

    actingAs($this->reviewer)
        ->post(route('admin.profiles.reject', $this->profile), ['reason' => ''])
        ->assertSessionHasErrors('reason');

    expect($this->profile->refresh()->status)->toBeInstanceOf(Pending::class);
});

it('refuses a verdict from a reviewer who does not hold the claim', function () {
    actingAs($this->reviewer)->get(route('admin.profiles.show', $this->profile));

    actingAs($this->second)
        ->post(route('admin.profiles.approve', $this->profile))
        ->assertRedirect();

    expect($this->profile->refresh()->status)->toBeInstanceOf(Pending::class);
});

it('releases the claim so somebody else can decide', function () {
    actingAs($this->reviewer)->get(route('admin.profiles.show', $this->profile));

    actingAs($this->reviewer)->delete(route('admin.profiles.unclaim', $this->profile));

    actingAs($this->second)->get(route('admin.profiles.show', $this->profile));

    actingAs($this->second)
        ->post(route('admin.profiles.approve', $this->profile))
        ->assertRedirect();

    expect($this->profile->refresh()->status)->toBeInstanceOf(Approved::class);
});

it('moves a rejected profile back to pending', function () {
    actingAs($this->reviewer)->get(route('admin.profiles.show', $this->profile));
    actingAs($this->reviewer)->post(route('admin.profiles.reject', $this->profile), ['reason' => 'Nope.']);

    actingAs($this->reviewer)->get(route('admin.profiles.show', $this->profile));
    actingAs($this->reviewer)
        ->post(route('admin.profiles.reopen', $this->profile))
        ->assertRedirect();

    $this->profile->refresh();

    expect($this->profile->status)->toBeInstanceOf(Pending::class)
        ->and($this->profile->rejection_reason)->toBeNull();
});

it('sends an approved profile back to pending when the attendee edits it', function () {
    $this->profile->status->transitionTo(Approved::class, $this->reviewer);

    actingAs($this->owner)
        ->put(route('catch-em-all.profiles.update', $this->profile), [
            'description' => 'Rewritten after approval',
            'links' => ['https://example.com/me'],
        ])
        ->assertRedirect();

    expect($this->profile->refresh()->status)->toBeInstanceOf(Pending::class);
});

it('keeps a profile nobody has approved off the public page', function () {
    $stranger = User::factory()->create();

    actingAs($stranger)
        ->get(route('catch-em-all.profiles.show', $this->profile->uuid))
        ->assertRedirect(route('catch-em-all.catch'));
});

it('is closed to a user who is neither reviewer nor admin', function () {
    actingAs(User::factory()->create(['is_admin' => false, 'is_reviewer' => false]))
        ->get(route('admin.profiles.index'))
        ->assertForbidden();
});

it('leaves the profile untouched when the reviewer only skips it', function () {
    actingAs($this->reviewer)->get(route('admin.profiles.show', $this->profile));

    actingAs($this->reviewer)
        ->get(route('admin.profiles.next', $this->profile))
        ->assertRedirect(route('admin.profiles.index'));

    expect($this->profile->refresh()->status)->toBeInstanceOf(Pending::class)
        ->and($this->profile->isClaimed())->toBeFalse();
});
