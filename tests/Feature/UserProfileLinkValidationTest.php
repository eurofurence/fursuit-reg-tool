<?php

use App\Models\User;
use App\Models\UserProfile\UserProfile;

beforeEach(function () {
    // UserObserver auto-creates a profile for every new user.
    $this->owner = User::factory()->create();
    $this->profile = $this->owner->userProfile()->firstOrFail();
});

function updateProfileLinks(User $user, UserProfile $profile, array $links)
{
    return test()->actingAs($user)->put(
        route('catch-em-all.profiles.update', $profile),
        ['links' => $links],
    );
}

test('javascript scheme links are rejected', function () {
    updateProfileLinks($this->owner, $this->profile, ['javascript://%0aalert(1)'])
        ->assertSessionHasErrors('links.0');

    expect($this->profile->links()->count())->toBe(0);
});

test('non-http schemes like ftp are rejected', function () {
    updateProfileLinks($this->owner, $this->profile, ['ftp://example.com/x'])
        ->assertSessionHasErrors('links.0');

    expect($this->profile->links()->count())->toBe(0);
});

test('scheme-less links are stored with https prepended', function () {
    updateProfileLinks($this->owner, $this->profile, ['example.com/me'])
        ->assertSessionDoesntHaveErrors()
        ->assertRedirect();

    expect($this->profile->links()->pluck('url')->all())
        ->toBe(['https://example.com/me']);
});

test('scheme and host are lowercased while the path keeps its case', function () {
    updateProfileLinks($this->owner, $this->profile, ['HTTPS://EXAMPLE.COM/Me'])
        ->assertSessionDoesntHaveErrors()
        ->assertRedirect();

    expect($this->profile->links()->pluck('url')->all())
        ->toBe(['https://example.com/Me']);
});
