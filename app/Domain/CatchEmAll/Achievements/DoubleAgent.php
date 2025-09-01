<?php

namespace App\Domain\CatchEmAll\Achievements;

use App\Domain\CatchEmAll\Interface\Achievement;
use App\Domain\CatchEmAll\Models\AchievementUpdateContext;

class DoubleAgent implements Achievement
{
    public function getId(): string
    {
        return 'double_agent';
    }

    public function getTile(): string
    {
        return 'Double Agent';
    }

    public function getDescription(): string
    {
        return 'Playing both sides of the field, are we?';
    }

    public function getIcon(): string
    {
        return '';
    }

    public function getMaxProgress(): int
    {
        return 1;
    }

    public function isSecret(): bool
    {
        return false;
    }

    public function isOptional(): bool
    {
        return false;
    }

    public function isHidden(): bool
    {
        return false;
    }

    public function updateAchievementProgress(AchievementUpdateContext $context): int
    {
        $currentProgress = $context->userOwnedFursuits > 0 ? 1 : -1;

        return $currentProgress;
    }
}
