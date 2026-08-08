<?php

namespace App\Services;

use App\Models\Fursuit\Fursuit;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Who is looking at a fursuit right now.
 *
 * Advisory, never blocking. The Filament panel used a five-minute cache lock that
 * *refused* approvals: a reviewer who opened a record by link could not act on it, and a
 * reviewer whose browser died froze the record for five minutes. Presence answers the
 * question the lock was really for - "is somebody else already working this one?" - and
 * answers it in two places:
 *
 *  - the queue skips records somebody is on, so two reviewers walking `next` do not land
 *    on the same fursuit;
 *  - the review page says who else is here, and lets the reviewer decide.
 *
 * An entry lives for TTL_SECONDS and the page refreshes it every HEARTBEAT_SECONDS, so a
 * closed tab drops out by itself within one TTL. Leaving is best-effort on top of that.
 *
 * The whole map is one cache entry, so two viewers arriving in the same instant can lose
 * one write. The cost of that is a queue handing out a record somebody just opened, which
 * the banner then shows - the same outcome as arriving by link, which is allowed anyway.
 * A row per viewer would trade that for a table write on every heartbeat of every
 * reviewer, which is not worth it for advisory data.
 */
class FursuitPresence
{
    /** How long a viewer stays "here" without a heartbeat. */
    public const TTL_SECONDS = 45;

    /** How often the page is expected to check in. Sent to the client. */
    public const HEARTBEAT_SECONDS = 15;

    /**
     * Record that a reviewer is on this fursuit.
     */
    public static function touch(Fursuit $fursuit, User $user): void
    {
        $viewers = self::fresh($fursuit);
        $viewers[$user->id] = now()->getTimestamp();

        self::put($fursuit, $viewers);
    }

    /**
     * Drop a reviewer, e.g. because the page was closed or a decision moved them on.
     */
    public static function leave(Fursuit $fursuit, User $user): void
    {
        $viewers = self::fresh($fursuit);

        if (! array_key_exists($user->id, $viewers)) {
            return;
        }

        unset($viewers[$user->id]);

        self::put($fursuit, $viewers);
    }

    /**
     * The other reviewers on this fursuit, named, newest arrival last.
     *
     * @return array<int, array{id: int, name: string}>
     */
    public static function others(Fursuit $fursuit, ?User $except): array
    {
        $viewers = self::fresh($fursuit);

        if ($except !== null) {
            unset($viewers[$except->id]);
        }

        if ($viewers === []) {
            return [];
        }

        $names = User::query()->whereKey(array_keys($viewers))->pluck('name', 'id');

        return collect($viewers)
            ->map(fn (int $at, int $id) => [
                'id' => $id,
                // A viewer whose account has since gone is still a viewer; the banner
                // needs something to print.
                'name' => $names[$id] ?? 'Another reviewer',
                'at' => $at,
            ])
            ->sortBy('at')
            ->map(fn (array $viewer) => ['id' => $viewer['id'], 'name' => $viewer['name']])
            ->values()
            ->all();
    }

    /**
     * Whether somebody other than `$except` is on this fursuit.
     *
     * This is the queue's skip test, so it does not resolve names.
     */
    public static function isBusy(Fursuit $fursuit, ?User $except = null): bool
    {
        $viewers = self::fresh($fursuit);

        if ($except !== null) {
            unset($viewers[$except->id]);
        }

        return $viewers !== [];
    }

    /**
     * The stored map with expired viewers dropped.
     *
     * Entries are pruned on read rather than by expiring per viewer, because the map is
     * one cache key: a reviewer who stops sending heartbeats has to disappear even though
     * a still-active colleague keeps rewriting the entry they share.
     *
     * @return array<int, int> viewer id => unix timestamp of last heartbeat
     */
    private static function fresh(Fursuit $fursuit): array
    {
        $viewers = Cache::get(self::key($fursuit), []);

        if (! is_array($viewers)) {
            return [];
        }

        $cutoff = now()->getTimestamp() - self::TTL_SECONDS;

        return collect($viewers)
            ->filter(fn ($at) => is_int($at) && $at >= $cutoff)
            ->mapWithKeys(fn (int $at, $id) => [(int) $id => $at])
            ->all();
    }

    /**
     * @param  array<int, int>  $viewers
     */
    private static function put(Fursuit $fursuit, array $viewers): void
    {
        if ($viewers === []) {
            Cache::forget(self::key($fursuit));

            return;
        }

        // One TTL past the newest heartbeat: the map itself never outlives its last live
        // entry, so an abandoned record needs no cleanup pass.
        Cache::put(self::key($fursuit), $viewers, now()->addSeconds(self::TTL_SECONDS));
    }

    private static function key(Fursuit $fursuit): string
    {
        return 'fursuit:'.$fursuit->getKey().':presence';
    }
}
