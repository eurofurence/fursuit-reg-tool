<?php

namespace App\Console\Commands;

use App\Services\BadgeLayerCacheService;
use Illuminate\Console\Command;

class BadgeCacheClearCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'badge-cache:clear
                            {--fursuit-id=* : Clear cache for specific fursuit IDs}
                            {--all : Clear all badge caches}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clear badge layer caches';

    /**
     * Execute the console command.
     */
    public function handle(BadgeLayerCacheService $cacheService): int
    {
        $fursuitIds = $this->option('fursuit-id');
        $clearAll = $this->option('all');

        if ($clearAll) {
            if ($this->confirm('Are you sure you want to clear ALL badge caches?')) {
                $cacheService->clearAllBadgeCaches();
                $this->info('✅ All badge caches cleared successfully!');
                $this->warn('💡 Run "badge-cache:warmup" to improve performance for common layers');

                return self::SUCCESS;
            } else {
                $this->info('Cache clear operation cancelled.');

                return self::SUCCESS;
            }
        }

        if (! empty($fursuitIds)) {
            $this->info('Clearing cache for fursuit IDs: '.implode(', ', $fursuitIds));

            foreach ($fursuitIds as $fursuitId) {
                try {
                    $cacheService->clearFursuitCache((int) $fursuitId);
                    $this->info("✅ Cleared cache for fursuit ID: {$fursuitId}");
                } catch (\Exception $e) {
                    $this->error("❌ Failed to clear cache for fursuit ID {$fursuitId}: ".$e->getMessage());
                }
            }

            return self::SUCCESS;
        }

        $this->error('❌ Please specify either --all or --fursuit-id options');
        $this->line('Examples:');
        $this->line('  php artisan badge-cache:clear --all');
        $this->line('  php artisan badge-cache:clear --fursuit-id=123');
        $this->line('  php artisan badge-cache:clear --fursuit-id=123 --fursuit-id=456');

        return self::FAILURE;
    }
}
