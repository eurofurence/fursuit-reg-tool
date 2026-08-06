<?php

namespace App\Domain\CatchEmAll\Achievements;

use App\Domain\CatchEmAll\Achievements\Abstract\SimpleAchievement;
use App\Domain\CatchEmAll\Interface\HasGlobalCache;
use App\Domain\CatchEmAll\Interface\ProgressInfo;
use App\Domain\CatchEmAll\Models\AchievementUpdateContext;
use App\Domain\CatchEmAll\Models\UserCatch;
use App\Models\Event;
use App\Models\Fursuit\Fursuit;
use Cache;

use function count;

class FuredexComplete extends SimpleAchievement implements HasGlobalCache, ProgressInfo
{
    private const CACHE_KEY = 'furedex_complete';

    private const INFO_CACHE_KEY = 'info_furedex_complete';

    /**
     * Get all species names that are part of the current event's catch-em-all challenge.
     *
     * @return string[]
     */
    private function getSpecies(): array
    {
        return Cache::remember(self::CACHE_KEY, now()->addHours(1), function () {
            $currentEvent = Event::latest('starts_at')->first();

            return Fursuit::where('event_id', $currentEvent->id)
                ->where('catch_em_all', true)
                ->whereNull('deleted_at')
                ->join('species', 'fursuits.species_id', '=', 'species.id')
                ->where('species.checked', true)
                ->distinct('species.name')
                ->pluck('species.name')
                ->toArray();
        });
    }

    public function __construct()
    {
        parent::__construct(
            id: 'furedex_complete',
            title: 'Your Furédex is complete!',
            description: 'You caught all species at least once. Congratulations!',
            task: 'Catch all species at least once.',
            icon: '👑',
            isSecret: false,
            isOptional: false,
            isHidden: false
        );
    }

    public function getCacheKeys(): array
    {
        return [self::CACHE_KEY];
    }

    public function getMaxProgress(): int
    {
        return count($this->getSpecies());
    }

    public function updateAchievementProgress(AchievementUpdateContext $context): int
    {
        // Only trigger on actual catches, not special codes
        if (! $context->hasCatch()) {
            return -1; // Ignore this update
        }

        // Return current progress based on user's unique fursuits caught
        $currentProgress = min($context->userUniqueSpecies, $this->getMaxProgress());

        // Forget cached progress info for this achievement to ensure it reflects the latest state
        Cache::forget(self::INFO_CACHE_KEY);

        // Always override default behavior
        return $currentProgress;
    }

    /**
     * {@inheritDoc}
     */
    public function getCurrentProgress(\App\Models\EventUser $eventUser): array
    {
        // Get all unique species names caught by the user in the current event
        $caughtSpecies = Cache::remember(self::INFO_CACHE_KEY, now()->addYear(),
            UserCatch::where('event_user_id', $eventUser->id)
                ->join('fursuits', 'user_catches.fursuit_id', '=', 'fursuits.id')
                ->join('species', 'fursuits.species_id', '=', 'species.id')
                ->where('species.checked', true)
                ->distinct('fursuits.species_id')
                ->pluck('species.name')
                ->toArray(...)
        );

        return $caughtSpecies;
    }

    /**
     * {@inheritDoc}
     */
    public function getTotalProgress(): array
    {
        return $this->getSpecies();
    }
}
