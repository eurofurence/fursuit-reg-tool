<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class BadgeCacheStatusCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'badge-cache:status';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Show badge cache status and statistics';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🔍 Badge Cache Status');
        $this->newLine();

        // Note: Fursuit images are not cached locally (one-time use)

        // Show cache statistics
        $this->showCacheStatistics();
        $this->newLine();

        // Performance recommendations
        $this->showRecommendations();

        return self::SUCCESS;
    }

    private function showCacheStatistics(): void
    {
        $this->line('<options=bold>📈 Cache Statistics:</>');

        // These are example cache keys - in a real implementation you'd track these
        $badgeTypes = ['EF29_Badge', 'EF28_Badge'];
        $dimensions = ['1024x648'];

        $cachedBackgrounds = 0;
        $cachedOverlays = 0;

        foreach ($badgeTypes as $badgeType) {
            foreach ($dimensions as $dimension) {
                if (Cache::has("badge_background_{$badgeType}_{$dimension}")) {
                    $cachedBackgrounds++;
                }
                if (Cache::has("catch_overlay_{$badgeType}_{$dimension}")) {
                    $cachedOverlays++;
                }
            }
        }

        $this->table(['Cache Type', 'Cached Items', 'Status'], [
            ['Background Layers', $cachedBackgrounds, $cachedBackgrounds > 0 ? '✅ Active' : '⚠️  Empty'],
            ['Catch-Em-All Overlays', $cachedOverlays, $cachedOverlays > 0 ? '✅ Active' : '⚠️  Empty'],
            ['Fursuit Layers', 'Dynamic', 'ℹ️  Generated per badge (not cached)'],
        ]);
    }

    private function showRecommendations(): void
    {
        $this->line('<options=bold>💡 Performance Recommendations:</>');

        $recommendations = [
            'Run "badge-cache:warmup" after deployment to pre-cache common layers',
            'Clear specific fursuit caches when images are updated',
            'Background and overlay layers are cached for 24 hours',
            'Fursuit layers are generated fresh for each badge (one-time use)',
        ];

        foreach ($recommendations as $recommendation) {
            $this->line("  • {$recommendation}");
        }
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= (1 << (10 * $pow));

        return round($bytes, 2).' '.$units[$pow];
    }
}
