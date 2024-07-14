<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\TokenRefreshService;
use Illuminate\Console\Command;

class RefreshTokensCommand extends Command
{
    protected $signature = 'refresh:tokens';

    protected $description = 'Command description';

    public function handle(): void
    {
        User::where('refresh_token_expires_at', '<', now())
            ->chunk(100,
                fn($chunk) => $chunk->each(fn(User $user) => (new TokenRefreshService($user))->refreshToken()));
    }
}
