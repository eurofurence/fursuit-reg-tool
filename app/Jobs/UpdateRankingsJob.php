<?php

namespace App\Jobs;

use App\Http\Controllers\FCEA\DashboardController;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class UpdateRankingsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $maxExceptions = 1;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public ?int $userId = null,
        public ?int $fursuitId = null
    ) {
        // Add delay to batch multiple ranking updates
        $this->delay(now()->addSeconds(30));
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            Log::info('Starting ranking refresh job', [
                'user_id' => $this->userId,
                'fursuit_id' => $this->fursuitId,
            ]);

            // Clear cached rankings - use specific cache keys for better performance
            $cacheKeys = [
                'user_ranking_global_10',
                'fursuit_ranking_global_10',
                'user_ranking_1_10',    // EF28
                'user_ranking_2_10',    // EF29
                'fursuit_ranking_1_10', // EF28
                'fursuit_ranking_2_10', // EF29
            ];

            foreach ($cacheKeys as $key) {
                Cache::forget($key);
            }

            // Perform the full ranking refresh
            DashboardController::refreshRanking();

            Log::info('Ranking refresh completed successfully');

        } catch (\Exception $e) {
            Log::error('Ranking refresh job failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Get the unique ID for this job to prevent duplicate ranking updates.
     */
    public function uniqueId(): string
    {
        return 'ranking_update';
    }
}
