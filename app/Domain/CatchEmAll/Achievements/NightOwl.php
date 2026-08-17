<?php

namespace App\Domain\CatchEmAll\Achievements;

use App\Domain\CatchEmAll\Achievements\Abstract\SimpleAchievement;
use App\Domain\CatchEmAll\Enums\AchievementsTier;
use App\Domain\CatchEmAll\Models\AchievementUpdateContext;
use Carbon\Carbon;

class NightOwl extends SimpleAchievement
{
    public function __construct()
    {
        parent::__construct(
            id: 'night_owl',
            title: 'Night Owl',
            description: 'The hunt never sleeps, and neither do you.',
            task: 'Catch a Fursuit between 1 AM and 5 AM CEST.',
            icon: '🦉',
            isSecret: false,
            isOptional: false,
            isHidden: false,
            tier: AchievementsTier::TIER_4
        );
    }

    public function getMaxProgress(): int
    {
        return 1;
    }

    public function updateAchievementProgress(AchievementUpdateContext $context): int
    {
        // Only trigger on actual catches, not special codes
        if (! $context->hasCatch()) {
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
