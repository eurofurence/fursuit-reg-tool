<?php

namespace App\Domain\CatchEmAll\Achievements;

use App\Domain\CatchEmAll\Interface\Achievement;
use App\Domain\CatchEmAll\Models\UserCatch;
use App\Models\User;

class BugBountyHunter implements Achievement
{
    /**
     * @inheritDoc
     */
    public function getId(): string
    {
        return 'bug_bounty_hunter';
    }

    /**
     * @inheritDoc
     */
    public function getTile(): string
    {
        return 'Bug Bounty Hunter';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Thanks for the QA! Your contribution is noted.';
    }

    /**
     * @inheritDoc
     */
    public function getIcon(): string
    {
        return '🐛';
    }

    /**
     * @inheritDoc
     */
    public function getMaxProgress(): int
    {
        return 1;
    }

    /**
     * @inheritDoc
     */
    public function isSecret(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function isOptional(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function isHidden(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public function updateAchievementProgress(User $user, UserCatch $newCatch): bool
    {
        \App\Domain\CatchEmAll\Services\AchievementService::grantAchievement($user, $this);
        return true;
    }
}
