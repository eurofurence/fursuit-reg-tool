<?php

namespace App\Domain\CatchEmAll\Achievements;

use App\Domain\CatchEmAll\Achievements\Abstract\SimpleAchievement;
use App\Domain\CatchEmAll\Enums\AchievementsTier;
use App\Domain\CatchEmAll\Models\AchievementUpdateContext;
use Carbon\Carbon;

class EarlyBird extends SimpleAchievement
{
    public function __construct()
    {
        parent::__construct(
            id: 'early_bird',
            title: 'Early Bird',
            description: 'The early bird catches the Fursuit.',
            task: 'Catch a Fursuit between 6 AM and 10 AM CEST.',
            icon: '🌅',
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

        // Check if catch was between 6 AM and 10 AM (6:00 - 9:59)
        if ($hour >= 6 && $hour < 10) {
            return 1; // Achievement completed
        }

        return -1; // Not completed yet
    }
}
