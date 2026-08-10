<?php

namespace App\Domain\CatchEmAll\Achievements;

use App\Domain\CatchEmAll\Achievements\Abstract\SimpleAchievement;
use App\Domain\CatchEmAll\Models\AchievementUpdateContext;
use App\Models\Event;

class TheCompletionist extends SimpleAchievement
{
    public function __construct()
    {
        parent::__construct(
            id: 'the_completionist',
            title: 'The Completionist',
            description: 'You’re dedicated to the cause.',
            task: 'Catch at least one fursuiter on every official day of the convention.',
            icon: '⚡',
            isSecret: false,
            isOptional: false,
            isHidden: false
        );
    }

    public function getMaxProgress(): int
    {
        $currentEvent = Event::latest('starts_at')->first();

        return $currentEvent->dayCount;
    }

    public function updateAchievementProgress(AchievementUpdateContext $context): int
    {
        // Only trigger on actual catches, not special codes
        if (! $context->hasCatch()) {
            return -1; // Ignore this update
        }

        // Return current progress based on user's unique fursuits caught
        $currentProgress = min($context->userTotalDaysCaught, $this->getMaxProgress());

        return $currentProgress;
    }
}
