<?php

use App\Jobs\MirrorUserAvatarJob;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Generate a tiny valid PNG so the job's Intervention Image pipeline can
 * decode the faked HTTP response body.
 */
function tinyPngBody(int $shade = 128): string
{
    $image = imagecreatetruecolor(4, 4);
    $color = imagecolorallocate($image, $shade, $shade, $shade);
    imagefilledrectangle($image, 0, 0, 3, 3, $color);

    ob_start();
    imagepng($image);
    $body = ob_get_clean();
    imagedestroy($image);

    return $body;
}

beforeEach(function () {
    Storage::fake();
});

test('no IdP avatar and no mirrored avatar leaves approval untouched', function () {
    $user = User::factory()->create();
    // UserObserver auto-created an auto-approved profile.
    $profile = $user->userProfile;
    expect($profile->approved_at)->not->toBeNull();

    (new MirrorUserAvatarJob($user))->handle();

    $user->refresh();
    $profile->refresh();

    expect($user->avatar_path)->toBeNull()
        ->and($profile->approved_at)->not->toBeNull()
        ->and($profile->rejected_at)->toBeNull();
});

test('first mirror stores the avatar and sends the profile to pending', function () {
    Http::fake(['*' => Http::response(tinyPngBody(), 200)]);

    $user = User::factory()->create();
    $user->forceFill(['avatar' => 'https://idp.example/avatar.png'])->save();

    $profile = $user->userProfile;
    expect($profile->approved_at)->not->toBeNull();

    (new MirrorUserAvatarJob($user))->handle();

    $user->refresh();
    $profile->refresh();

    expect($user->avatar_path)->toBe('avatars/'.$user->id.'.webp')
        ->and($profile->approved_at)->toBeNull()
        ->and($profile->rejected_at)->toBeNull();
    Storage::assertExists('avatars/'.$user->id.'.webp');
});

test('re-running with unchanged avatar bytes does not trigger reapproval', function () {
    Http::fake(['*' => Http::response(tinyPngBody(), 200)]);

    $user = User::factory()->create();
    $user->forceFill(['avatar' => 'https://idp.example/avatar.png'])->save();

    (new MirrorUserAvatarJob($user))->handle();

    $profile = $user->userProfile->refresh();
    expect($profile->approved_at)->toBeNull();

    // Reviewer re-approves the profile between mirror runs.
    $profile->update(['approved_at' => now()]);

    (new MirrorUserAvatarJob($user))->handle();

    $profile->refresh();

    expect($profile->approved_at)->not->toBeNull()
        ->and($profile->rejected_at)->toBeNull();
});

test('removed IdP avatar deletes the mirror and sends the profile to pending', function () {
    $user = User::factory()->create();

    $path = 'avatars/'.$user->id.'.webp';
    Storage::put($path, 'mirrored-avatar-bytes');
    $user->forceFill(['avatar' => null, 'avatar_path' => $path])->save();

    $profile = $user->userProfile;
    expect($profile->approved_at)->not->toBeNull();

    (new MirrorUserAvatarJob($user))->handle();

    $user->refresh();
    $profile->refresh();

    Storage::assertMissing($path);
    expect($user->avatar_path)->toBeNull()
        ->and($profile->approved_at)->toBeNull()
        ->and($profile->rejected_at)->toBeNull();
});
