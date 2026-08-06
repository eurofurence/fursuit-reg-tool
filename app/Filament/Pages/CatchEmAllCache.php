<?php

namespace App\Filament\Pages;

use App\Domain\CatchEmAll\Achievements\Utils\AchievementRegister;
use App\Domain\CatchEmAll\Interface\HasGlobalCache;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CatchEmAllCache extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-circle-stack';

    protected static ?string $navigationGroup = 'Catch Em All';

    protected static ?string $navigationLabel = 'Cache';

    protected static ?string $title = 'Catch Em All Cache';

    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.pages.catch-em-all-cache';

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $rows = [];

    public string $cacheDriver = '';

    public string $warning = '';

    public function mount(): void
    {
        $this->reloadRows();
    }

    public function reloadRows(): void
    {
        $this->cacheDriver = (string) config('cache.default');
        $this->warning = '';

        if ($this->cacheDriver !== 'database') {
            $this->rows = $this->buildFallbackRowsWithoutTtl();
            $this->warning = 'The active cache driver is not database. Remaining lifetime and creation time are only available with the database cache store.';

            return;
        }

        $this->rows = $this->buildDatabaseRows();
    }

    public function forgetKey(string $key): void
    {
        Cache::forget($key);

        Notification::make()
            ->title('Cache key removed')
            ->body($key)
            ->success()
            ->send();

        $this->reloadRows();
    }

    public function forgetKeyByIndex(int $index): void
    {
        if (! isset($this->rows[$index]['key'])) {
            Notification::make()
                ->title('Cache key not found')
                ->danger()
                ->send();

            return;
        }

        $this->forgetKey((string) $this->rows[$index]['key']);
    }

    public function forgetAllListed(): void
    {
        $keys = array_values(array_unique(array_map(
            static fn (array $row): string => (string) $row['key'],
            $this->rows
        )));

        $deleted = 0;

        foreach ($keys as $key) {
            if (Cache::forget($key)) {
                $deleted++;
            }
        }

        Notification::make()
            ->title('Listed cache keys removed')
            ->body("Deleted {$deleted} key(s).")
            ->success()
            ->send();

        $this->reloadRows();
    }

    /**
     * @return array<int, string>
     */
    private function getAchievementKeys(): array
    {
        $keys = [];

        foreach (AchievementRegister::getAllAchievementInstances() as $achievement) {
            if (! $achievement instanceof HasGlobalCache) {
                continue;
            }

            $keys = array_merge($keys, $achievement->getCacheKeys());
        }

        return array_values(array_unique($keys));
    }

    /**
     * @return array<int, string>
     */
    private function getGameControllerNonEventUserKeyPrefixes(): array
    {
        return [
            'leaderboard_',
            'total_fursuiters_',
        ];
    }

    /**
     * @return array<string, int>
     */
    private function getKnownTtlSecondsByPrefix(): array
    {
        return [
            'leaderboard_' => 600,
            'total_fursuiters_' => 3600,
            'furedex_complete' => 3600,
            'explorer_locations' => 3600,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildDatabaseRows(): array
    {
        $table = (string) config('cache.stores.database.table', 'cache');
        $prefix = (string) config('cache.prefix', '');
        $now = now()->timestamp;

        $achievementKeys = $this->getAchievementKeys();
        $gamePrefixes = $this->getGameControllerNonEventUserKeyPrefixes();

        $query = DB::table($table)->select(['key', 'expiration'])
            ->where(function ($q) use ($achievementKeys, $gamePrefixes, $prefix) {
                foreach ($achievementKeys as $key) {
                    $q->orWhere('key', '=', $prefix.$key);
                }

                foreach ($gamePrefixes as $keyPrefix) {
                    $q->orWhere('key', 'like', $prefix.$keyPrefix.'%');
                }
            });

        $cacheEntries = $query->get();

        $rows = [];

        foreach ($cacheEntries as $entry) {
            $rawKey = (string) $entry->key;
            $logicalKey = $this->stripCachePrefix($rawKey, $prefix);
            $expiration = (int) $entry->expiration;
            $remaining = $expiration - $now;

            $ttl = $this->knownTtlForKey($logicalKey);
            $estimatedCreatedAt = null;
            if ($ttl !== null) {
                $estimatedCreatedAt = date('Y-m-d H:i:s', $expiration - $ttl);
            }

            $rows[] = [
                'key' => $logicalKey,
                'source' => $this->sourceForKey($logicalKey),
                'exists' => $remaining > 0,
                'remaining_seconds' => $remaining,
                'expires_at' => date('Y-m-d H:i:s', $expiration),
                'estimated_created_at' => $estimatedCreatedAt,
                'created_at_is_estimated' => $estimatedCreatedAt !== null,
            ];
        }

        $existingKeys = array_map(static fn (array $row): string => $row['key'], $rows);

        foreach ($achievementKeys as $achievementKey) {
            if (in_array($achievementKey, $existingKeys, true)) {
                continue;
            }

            $rows[] = [
                'key' => $achievementKey,
                'source' => 'AchievementRegister',
                'exists' => false,
                'remaining_seconds' => null,
                'expires_at' => null,
                'estimated_created_at' => null,
                'created_at_is_estimated' => false,
            ];
        }

        usort($rows, function (array $a, array $b): int {
            return strcmp((string) $a['key'], (string) $b['key']);
        });

        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildFallbackRowsWithoutTtl(): array
    {
        $rows = [];

        foreach ($this->getAchievementKeys() as $key) {
            $rows[] = [
                'key' => $key,
                'source' => 'AchievementRegister',
                'exists' => Cache::has($key),
                'remaining_seconds' => null,
                'expires_at' => null,
                'estimated_created_at' => null,
                'created_at_is_estimated' => false,
            ];
        }

        return $rows;
    }

    private function stripCachePrefix(string $storedKey, string $prefix): string
    {
        if ($prefix !== '' && str_starts_with($storedKey, $prefix)) {
            return substr($storedKey, strlen($prefix));
        }

        return $storedKey;
    }

    private function sourceForKey(string $key): string
    {
        if (in_array($key, $this->getAchievementKeys(), true)) {
            return 'AchievementRegister';
        }

        return 'GameController';
    }

    private function knownTtlForKey(string $key): ?int
    {
        foreach ($this->getKnownTtlSecondsByPrefix() as $prefix => $ttl) {
            if (str_starts_with($key, $prefix)) {
                return $ttl;
            }

            if ($key === $prefix) {
                return $ttl;
            }
        }

        return null;
    }

    public static function canAccess(): bool
    {
        return (bool) (auth()->user()?->is_admin);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return (bool) (auth()->user()?->is_admin);
    }
}
