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
        // CRITICAL: Never attempt to use refresh tokens on non-production environments
        if (! app()->isProduction()) {
            $this->error('Refresh tokens are not allowed in non-production environments');
            $this->error('This command can only be run in production');
            return;
        }

        $this->info('Starting token refresh for expired tokens...');
        
        User::where('refresh_token_expires_at', '<', now())
            ->chunk(100,
                fn ($chunk) => $chunk->each(fn (User $user) => (new TokenRefreshService($user))->refreshToken()));
                
        $this->info('Token refresh completed');
    }
}
