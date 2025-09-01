<?php

namespace App\Domain\CatchEmAll\Models;

use App\Domain\CatchEmAll\Enums\SpecialCodeType;
use App\Models\Fursuit\Fursuit;
use App\Models\User;

/**
 * Readonly context object that contains the essential data for achievement updates.
 * This provides a clean, minimal interface for achievement processing.
 */
readonly class AchievementUpdateContext
{
    public function __construct(
        public User $user,                          // User
        public ?UserCatch $userCatch,               // Caught Fursuit
        public ?SpecialCodeType $specialCodeType,   // Special Code Type
        public int $userTotalCatches,               // Total Catches by User
        public int $totalCatchableFursuits,         // Total Catchable Fursuits
        public int $userUniqueFursuits,             // Unique Fursuits Caught by User
        public int $userOwnedFursuits,              // Count of Fursuits Owned by User
    ) {
    }

    /**
     * Create an AchievementUpdateContext from a new catch.
     *
     * @param User $user
     * @param UserCatch $userCatch
     * @param SpecialCodeType|null $specialCodeType Optional special code that triggered this catch
     * @return self
     */
    public static function fromCatch(User $user, ?UserCatch $userCatch = null, ?SpecialCodeType $specialCodeType = null): self
    {
        if ($userCatch === null && $specialCodeType === null) {
            throw new \InvalidArgumentException('Either userCatch or specialCodeType must be provided');
        }

        // Calculate user statistics
        $userTotalCatches = UserCatch::where('user_id', $user->id)->count();
        $totalCatchableFursuits = Fursuit::where('event_id', $userCatch->event_id)->where('catch_em_all', true)->count();
        $userUniqueFursuits = UserCatch::where('user_id', $user->id)
            ->distinct('fursuit_id')
            ->count();
        $userOwnedFursuits = Fursuit::where('user_id', $user->id)->count();

        return new self(
            user: $user,
            userCatch: $userCatch,
            specialCodeType: $specialCodeType,
            userTotalCatches: $userTotalCatches,
            totalCatchableFursuits: $totalCatchableFursuits,
            userUniqueFursuits: $userUniqueFursuits,
            userOwnedFursuits: $userOwnedFursuits,
        );
    }

    /**
     * Check if this context contains a catch (vs. special code trigger).
     *
     * @return bool
     */
    public function hasCatch(): bool
    {
        return $this->userCatch !== null;
    }

    /**
     * Check if this context is for a special code trigger.
     *
     * @return bool
     */
    public function isSpecialCodeTrigger(): bool
    {
        return $this->specialCodeType !== null;
    }
}
