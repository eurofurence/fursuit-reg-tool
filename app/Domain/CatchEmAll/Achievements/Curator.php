<?php

namespace App\Domain\CatchEmAll\Achievements;

use App\Domain\CatchEmAll\Interface\Achievement;
use App\Domain\CatchEmAll\Models\AchievementUpdateContext;

class Curator implements Achievement
{
    public function getId(): string
    {
        return 'curator';
    }

    public function getTile(): string
    {
        return 'Curator';
    }

    public function getDescription(): string
    {
        return 'Your reputation as a collector is growing.';
    }

    public function getIcon(): string
    {
        return '🏛️';
    }

    public function getMaxProgress(): int
    {
        return 20;
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
        // Only trigger on actual catches, not special codes
        if (!$context->hasCatch()) {
            return -1; // Ignore this update
        }

        // Return current progress based on user's total catches
        $currentProgress = min($context->userTotalCatches, $this->getMaxProgress());

        return $currentProgress;
    }
}
