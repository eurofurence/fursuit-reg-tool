<?php

namespace App\Domain\CatchEmAll\Services;

use App\Domain\CatchEmAll\Enums\FursuitRarity;
use App\Models\Event;
use App\Models\Fursuit\Fursuit;
use Illuminate\Support\Facades\Cache;

/**
 * Rarity of a species at an event.
 *
 * The old rule ranked a catch by how often that fursuit had already been caught
 * (`UserCatch::getCatches()`), so rarity measured fame, not rarity: the most
 * photographed suiter at the con came out Legendary, and on the first morning,
 * when nobody had caught anything, every catch was Common. It also cost one
 * query per catch.
 *
 * Rarity is now how many fursuits of that species are registered for the event,
 * loaded once and cached. A species nobody else brought is Legendary from the
 * first minute, and a Fox among two hundred is Common no matter who photographs
 * it.
 *
 * Thresholds are counts rather than percentages because the distribution has a
 * very long tail: at EF30, 738 species are registered and 538 of them are
 * one-of-a-kind, so a share-of-population rule collapses into "almost
 * everything is Legendary".
 */
class SpeciesRarityService
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

    public function forSpecies(?Event $event, ?int $speciesId): FursuitRarity
    {
        return FursuitRarity::fromSpeciesPopulation($this->population($event, $speciesId));
    }

    public function forFursuit(?Event $event, Fursuit $fursuit): FursuitRarity
    {
        return $this->forSpecies($event, $fursuit->species_id);
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
