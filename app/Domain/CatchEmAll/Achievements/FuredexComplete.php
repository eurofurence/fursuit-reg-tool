<?php

namespace App\Domain\CatchEmAll\Achievements;

use App\Domain\CatchEmAll\Achievements\Abstract\SimpleAchievement;
use App\Domain\CatchEmAll\Models\AchievementUpdateContext;
use App\Models\Event;
use App\Models\Fursuit\Fursuit;

class FuredexComplete extends SimpleAchievement
{
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

    // TODO: Optimization: Cache the total number of species to avoid querying the database every time.
    public function getMaxProgress(): int
    {
        $currentEvent = Event::latest('starts_at')->first();

        return Fursuit::where('event_id', $currentEvent->id)->distinct('species_id')->count('species_id');
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
