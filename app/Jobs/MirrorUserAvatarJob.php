<?php

namespace App\Jobs;

use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

/**
 * Mirrors the user's identity-provider avatar onto the app's storage disk.
 *
 * The IdP URL stored in users.avatar is refreshed on every login; this job
 * downloads it, normalizes it to a 512x512 webp and stores the path in
 * users.avatar_path. When the mirrored image actually changes (new, different
 * or removed), the user's Catch-Em-All profile is sent back for review, since
 * the avatar is shown on the public profile.
 */
class MirrorUserAvatarJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public User $user) {}

    public function handle(): void
    {
        $user = $this->user->fresh();

        if ($user === null) {
            return;
        }

        if (! $user->avatar) {
            $this->clearMirroredAvatar($user);

            return;
        }

        $response = Http::get($user->avatar);

        // Some IdP avatar URLs may require authentication.
        if (in_array($response->status(), [401, 403], true) && $user->token) {
            $response = Http::withToken($user->token)->get($user->avatar);
        }

        $response->throw();

        $manager = new ImageManager(new Driver);
        $webp = (string) $manager->read($response->body())->cover(512, 512)->toWebp();

        $path = 'avatars/'.$user->id.'.webp';
        $changed = ! Storage::exists($path) || Storage::get($path) !== $webp;

        if ($changed) {
            Storage::put($path, $webp);
        }

        if ($user->avatar_path !== $path) {
            $user->avatar_path = $path;
            $user->save();
        }

        if ($changed) {
            $user->userProfile?->requiresReapproval();
        }
    }

    private function clearMirroredAvatar(User $user): void
    {
        if (! $user->avatar_path) {
            return;
        }

        Storage::delete($user->avatar_path);
        $user->avatar_path = null;
        $user->save();

        $user->userProfile?->requiresReapproval();
    }
}
