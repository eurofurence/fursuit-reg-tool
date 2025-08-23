<?php

namespace App\Console\Commands;

use App\Services\BadgeLayerCacheService;
use Illuminate\Console\Command;

class BadgeCacheWarmupCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'badge-cache:warmup
                            {--force : Force warmup even if caches exist}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Warm up badge layer caches for improved performance';

    /**
     * Execute the console command.
     */
    public function handle(BadgeLayerCacheService $cacheService): int
    {
        $this->info('Starting badge cache warmup...');

        if ($this->option('force')) {
            $this->warn('Forcing cache refresh...');
            $cacheService->clearAllBadgeCaches();
        }

        try {
            $cacheService->warmupCaches();
            $this->info('✅ Badge layer caches warmed up successfully!');

            $this->table(['Cache Type', 'Status'], [
                ['Background Layers', '✅ Cached'],
                ['Catch-Em-All Overlays', '✅ Cached'],
                ['Fursuit Images', 'ℹ️  Cached on-demand'],
            ]);

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('❌ Failed to warm up caches: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
