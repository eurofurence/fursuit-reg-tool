<?php

namespace App\Console\Commands;

use App\Http\Controllers\FCEA\DashboardController;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class RefreshFceaRankingsCommand extends Command
{
    protected $signature = 'fcea:refresh-rankings';

    protected $description = 'Refresh FCEA leaderboard rankings for users and fursuits';

    public function handle(): int
    {
        $this->info('Starting FCEA rankings refresh...');

        try {
            // Clear all FCEA-related caches
            $this->clearFceaCaches();

            // Refresh rankings
            DashboardController::refreshRanking();

            $this->info('FCEA rankings refresh completed successfully.');
            Log::info('FCEA rankings refresh completed successfully via console command');

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error('FCEA rankings refresh failed: ' . $e->getMessage());
            Log::error('FCEA rankings refresh failed via console command', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return Command::FAILURE;
        }
    }

    private function clearFceaCaches(): void
    {
        $this->info('Clearing FCEA caches...');

        // Clear ranking caches for all possible event combinations
        $cachePatterns = [
            'user_ranking_*',
            'fursuit_ranking_*',
            'total_fursuiters_*',
        ];

        foreach ($cachePatterns as $pattern) {
            // Laravel doesn't have native pattern-based cache clearing
            // So we'll clear specific known cache keys
            $keys = [
                'user_ranking_global_10',
                'fursuit_ranking_global_10',
                'user_ranking_1_10',    // EF28
                'user_ranking_2_10',    // EF29
                'fursuit_ranking_1_10', // EF28
                'fursuit_ranking_2_10', // EF29
                'total_fursuiters_1',   // EF28
                'total_fursuiters_2',   // EF29
            ];

            foreach ($keys as $key) {
                Cache::forget($key);
            }
        }

        $this->info('FCEA caches cleared.');
    }
}