<?php

namespace App\Domain\CatchEmAll\Achievements;

use App\Domain\CatchEmAll\Interface\Achievement;
use App\Domain\CatchEmAll\Models\AchievementUpdateContext;

class Collector implements Achievement
{
    public function getId(): string
    {
        return 'collector';
    }

    public function getTile(): string
    {
        return 'Collector';
    }

    public function getDescription(): string
    {
        return 'You are an aspiring collector.';
    }

    public function getIcon(): string
    {
        return '📦';
    }

    public function getMaxProgress(): int
    {
        return 10;
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
