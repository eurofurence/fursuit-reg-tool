<?php

namespace App\Domain\CatchEmAll\Achievements;

use App\Domain\CatchEmAll\Achievements\Abstract\SimpleAchievement;
use App\Domain\CatchEmAll\Interface\HiddenIfLocked;
use App\Domain\CatchEmAll\Interface\LockedBy;
use App\Domain\CatchEmAll\Models\AchievementUpdateContext;

class Curator extends SimpleAchievement implements LockedBy, HiddenIfLocked
{
    public function __construct()
    {
        parent::__construct(
            id: 'curator',
            title: 'Curator',
            description: 'Your reputation as a collector is growing.',
            task: 'Catch 20 Fursuits.',
            icon: '🏛️',
            isSecret: false,
            isOptional: false,
            isHidden: false
        );
    }

    public function getMaxProgress(): int
    {
        return 20;
    }

    public function updateAchievementProgress(AchievementUpdateContext $context): int
    {
        // Only trigger on actual catches, not special codes
        if (! $context->hasCatch()) {
            return -1; // Ignore this update
        }

        // Return current progress based on user's total catches
        $currentProgress = min($context->userTotalCatches, $this->getMaxProgress());

        return $currentProgress;
    }

    public function lockedBy(): array
    {
        return [
            'collector',
        ];
    }
}
