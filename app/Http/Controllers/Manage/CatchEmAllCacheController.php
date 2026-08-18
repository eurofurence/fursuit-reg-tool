<?php

namespace App\Http\Controllers\Manage;

use App\Domain\CatchEmAll\Achievements\Utils\AchievementRegister;
use App\Domain\CatchEmAll\Interface\HasGlobalCache;
use App\Http\Controllers\Controller;
use App\Support\Manage\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Response;

class CatchEmAllCacheController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('manage-admin');

        return inertia('Manage/Tools/CatchEmAllCache', $this->props());
    }

    public function forget(string $key): RedirectResponse
    {
        Gate::authorize('manage-admin');

        Cache::forget($key);

        Toast::flashSuccess('Cache key removed', $key);

        return redirect()->route('admin.tools.catch-em-all-cache');
    }

    public function forgetAll(): RedirectResponse
    {
        Gate::authorize('manage-admin');

        $deleted = 0;

        foreach ($this->buildRows() as $row) {
            if (Cache::forget((string) $row['key'])) {
                $deleted++;
            }
        }

        Toast::flashSuccess('Listed cache keys removed', "Deleted {$deleted} key(s).");

        return redirect()->route('admin.tools.catch-em-all-cache');
    }

    /**
     * @return array<string, mixed>
     */
    private function props(): array
    {
        $cacheDriver = (string) config('cache.default');
        $warning = '';

        if ($cacheDriver !== 'database') {
            $rows = $this->buildFallbackRowsWithoutTtl();
            $warning = 'The active cache driver is not database. Remaining lifetime and creation time are only available with the database cache store.';
        } else {
            $rows = $this->buildDatabaseRows();
        }

        return [
            'rows' => $rows,
            'cacheDriver' => $cacheDriver,
            'warning' => $warning,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildRows(): array
    {
        return $this->props()['rows'];
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
}
