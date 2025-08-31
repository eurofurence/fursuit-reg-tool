<?php

namespace App\Domain\CatchEmAll\Models;

use App\Domain\CatchEmAll\Enums\SpecialCodeType;
use App\Models\User;

/**
 * Readonly context object that contains the essential data for achievement updates.
 * This provides a clean, minimal interface for achievement processing.
 */
readonly class AchievementUpdateContext
{
    public function __construct(
        public User $user,
        public ?UserCatch $userCatch,
        public ?SpecialCodeType $specialCodeType,
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
    public static function fromCatch(User $user, UserCatch $userCatch, ?SpecialCodeType $specialCodeType = null): self
    {
        return new self(
            user: $user,
            userCatch: $userCatch,
            specialCodeType: $specialCodeType,
        );
    }

    /**
     * Create an AchievementUpdateContext for special code triggers (no catch involved).
     *
     * @param User $user
     * @param SpecialCodeType $specialCodeType
     * @return self
     */
    public static function fromSpecialCode(User $user, SpecialCodeType $specialCodeType): self
    {
        return new self(
            user: $user,
            userCatch: null,
            specialCodeType: $specialCodeType,
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
