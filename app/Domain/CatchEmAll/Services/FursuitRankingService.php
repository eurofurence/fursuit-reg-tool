<?php

namespace App\Domain\CatchEmAll\Services;

use App\Domain\CatchEmAll\Enums\FursuitRanking;
use App\Domain\CatchEmAll\Models\UserCatch;
use App\Models\Event;
use App\Models\Fursuit\Fursuit;
use Illuminate\Support\Facades\Cache;

/**
 * How often each fursuit at an event has been caught, and the ranking that
 * follows from it.
 *
 * Counted once per event and cached, rather than per catch: the screens that
 * show a ranking show thirty of them at a time, and `UserCatch::getCatches()`
 * runs a query each. The cache is short because a ranking climbs during the
 * event and a badge that never moves is worse than one that lags a minute.
 *
 * Species population lives in SpeciesPopulationService and is a different figure:
 * how many of a species are registered, shown as a count rather than a tier.
 */
class FursuitRankingService
{
    private const CACHE_TTL = 300;

    /** @var array<int, array<int, int>> event id => [fursuit id => times caught] */
    private array $memo = [];

    /**
     * Times caught per fursuit at an event, keyed by fursuit id.
     *
     * @return array<int, int>
     */
    public function counts(?Event $event): array
    {
        if (! $event) {
            return [];
        }

        return $this->memo[$event->id] ??= Cache::remember(
            "cea:fursuit_catches:{$event->id}",
            self::CACHE_TTL,
            fn () => UserCatch::query()
                ->join('fursuits', 'fursuits.id', '=', 'user_catches.fursuit_id')
                ->where('fursuits.event_id', $event->id)
                ->selectRaw('user_catches.fursuit_id, count(*) as total')
                ->groupBy('user_catches.fursuit_id')
                ->pluck('total', 'fursuit_id')
                ->map(fn ($total) => (int) $total)
                ->all(),
        );
    }

    public function catches(?Event $event, ?int $fursuitId): int
    {
        return $this->counts($event)[$fursuitId] ?? 0;
    }

    public function forFursuit(?Event $event, Fursuit $fursuit): FursuitRanking
    {
        return FursuitRanking::fromCatchCount($this->catches($event, $fursuit->id));
    }

    public function forget(?Event $event): void
    {
        if (! $event) {
            return;
        }

        unset($this->memo[$event->id]);
        Cache::forget("cea:fursuit_catches:{$event->id}");
    }
}
