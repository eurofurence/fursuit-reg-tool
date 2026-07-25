<?php

namespace App\Domain\CatchEmAll\Achievements\Special;

use App\Domain\CatchEmAll\Achievements\Abstract\SimpleAchievement;
use App\Domain\CatchEmAll\Enums\SpecialCodeType;
use App\Domain\CatchEmAll\Interface\SpecialAchievement;
use App\Domain\CatchEmAll\Models\AchievementUpdateContext;
use App\Domain\CatchEmAll\Models\SpecialCode;
use App\Models\Event;

class Explorer extends SimpleAchievement implements SpecialAchievement
{
    public function __construct()
    {
        parent::__construct(
            id: 'explorer',
            title: 'Explorer',
            description: 'You explored all areas!',
            task: 'Discover all hidden locations. Maybe ask some friends to help you find them all.',
            icon: '🧭',
            isSecret: false,
            isOptional: false,
            isHidden: false
        );
    }

    public function getMaxProgress(): int
    {
        $currentEvent = Event::latest('starts_at')->first();

        return SpecialCode::where('type', SpecialCodeType::EXPLORER)
            ->where('event_id', $currentEvent->id)
            ->count();
    }

    public function updateAchievementProgress(AchievementUpdateContext $context): int
    {
        // This achievement can only be triggered by special code, not by catches
        if (! $context->isSpecialCodeTrigger() || $context->specialCodeType !== SpecialCodeType::EXPLORER) {
            return -1; // Ignore this update
        }

        // Return completion progress - achievement granting is handled by AchievementService
        return min($context->locationsExplored, $this->getMaxProgress());
    }

    public function getSpecialCode(): SpecialCodeType
    {
        return SpecialCodeType::EXPLORER;
    }
}
