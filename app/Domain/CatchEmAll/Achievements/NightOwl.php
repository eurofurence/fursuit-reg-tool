<?php

namespace App\Domain\CatchEmAll\Achievements;

use App\Domain\CatchEmAll\Interface\Achievement;
use App\Domain\CatchEmAll\Models\AchievementUpdateContext;
use Carbon\Carbon;

class NightOwl implements Achievement
{
    public function getId(): string
    {
        return 'night_owl';
    }

    public function getTile(): string
    {
        return 'Night Owl';
    }

    public function getDescription(): string
    {
        return 'The hunt never sleeps, and neither do you.';
    }

    public function getIcon(): string
    {
        return '🦉';
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
        // Only trigger on actual catches, not special codes
        if (!$context->hasCatch()) {
            return -1; // Ignore this update
        }

        // Get the catch time in CEST timezone
        $catchTime = Carbon::parse($context->userCatch->created_at)->setTimezone('Europe/Berlin');
        $hour = $catchTime->hour;

        // Check if catch was between 1 AM and 5 AM (1:00 - 4:59)
        if ($hour >= 1 && $hour < 5) {
            return 1; // Achievement completed
        }

        return -1; // Not completed yet
    }
}
