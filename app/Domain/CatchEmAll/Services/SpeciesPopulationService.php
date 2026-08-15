<?php

namespace App\Domain\CatchEmAll\Services;

use App\Models\Event;
use App\Models\Fursuit\Fursuit;
use Illuminate\Support\Facades\Cache;

/**
 * How many fursuits of a species are registered for an event.
 *
 * This used to drive a Common-to-Legendary rarity tier. Tiers now come from
 * FursuitRanking, which counts catches and calls the result what it is: how
 * sought after a suiter is. The population survived that change because it is
 * still the interesting fact about a species, and it is the honest one: at EF30,
 * 738 species are registered and 538 of them are one-of-a-kind, so telling
 * somebody their catch is the only Kugsha Dog at the convention says more than
 * any tier could.
 *
 * Loaded once per event and cached, rather than counted per catch.
 */
class SpeciesPopulationService
{
    private const CACHE_TTL = 900;

    /** @var array<int, array<int, int>> event id => [species id => fursuits at the event] */
    private array $memo = [];

    /**
     * Fursuits per species at an event, keyed by species id.
     *
     * @return array<int, int>
     */
    public function populations(?Event $event): array
    {
        if (! $event) {
            return [];
        }

        return $this->memo[$event->id] ??= Cache::remember(
            "cea:species_population:{$event->id}",
            self::CACHE_TTL,
            fn () => Fursuit::query()
                ->where('event_id', $event->id)
                ->where('catch_em_all', true)
                ->selectRaw('species_id, count(*) as total')
                ->groupBy('species_id')
                ->pluck('total', 'species_id')
                ->map(fn ($total) => (int) $total)
                ->all(),
        );
    }

    /** How many fursuits of this species are at the event. Unknown species count as one. */
    public function population(?Event $event, ?int $speciesId): int
    {
        return $this->populations($event)[$speciesId] ?? 1;
    }

    public function forget(?Event $event): void
    {
        if (! $event) {
            return;
        }

        unset($this->memo[$event->id]);
        Cache::forget("cea:species_population:{$event->id}");
    }
}
