<?php

namespace App\Domain\CatchEmAll\Achievements;

use App\Domain\CatchEmAll\Achievements\Abstract\SimpleAchievement;
use App\Domain\CatchEmAll\Interface\HasGlobalCache;
use App\Domain\CatchEmAll\Models\AchievementUpdateContext;
use App\Models\Event;
use App\Models\Fursuit\Fursuit;
use Cache;

class FuredexComplete extends SimpleAchievement implements HasGlobalCache
{
    private const CACHE_KEY = 'furedex_complete';

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
        $maxProgress = Cache::remember(self::CACHE_KEY, now()->addHours(1), function () {

            $currentEvent = Event::latest('starts_at')->first();

            return Fursuit::where('event_id', $currentEvent->id)
                ->where('catch_em_all', true)
                ->whereNull('deleted_at')
                ->join('species', 'fursuits.species_id', '=', 'species.id')
                ->where('species.checked', true)
                ->distinct('species.name')
                ->count();
        });

        return $maxProgress;
    }

    public function updateAchievementProgress(AchievementUpdateContext $context): int
    {
        // Only trigger on actual catches, not special codes
        if (! $context->hasCatch()) {
            return -1; // Ignore this update
        }

        // Return current progress based on user's unique fursuits caught
        $currentProgress = min($context->userUniqueSpecies, $this->getMaxProgress());

        // Always override default behavior
        return $currentProgress;
    }
}
