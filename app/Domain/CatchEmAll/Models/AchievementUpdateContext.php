<?php

namespace App\Domain\CatchEmAll\Models;

use App\Domain\CatchEmAll\Enums\SpecialCodeType;
use App\Models\Fursuit\Fursuit;
use App\Models\Event;
use App\Models\EventUser;
use App\Models\User;

/**
 * Readonly context object that contains the essential data for achievement updates.
 * This provides a clean, minimal interface for achievement processing.
 */
readonly class AchievementUpdateContext
{
    public function __construct(
        public EventUser $eventUser,
        public ?UserCatch $userCatch,
        public ?SpecialCodeType $specialCodeType,
        public int $userTotalCatches,
        public int $totalCatchableFursuits,
        public int $userUniqueFursuits,
    ) {}

    /**
     * Create an AchievementUpdateContext from a new catch.
     *
     * @param  SpecialCodeType|null  $specialCodeType  Optional special code that triggered this catch
     */
    public static function fromCatch(EventUser $eventUser, ?UserCatch $userCatch = null, ?SpecialCodeType $specialCodeType = null): self
    {
        if ($userCatch === null && $specialCodeType === null) {
            throw new \InvalidArgumentException('Either userCatch or specialCodeType must be provided');
        }

        $currentEvent = Event::latest('starts_at')->first();

        // Calculate user statistics
        $userTotalCatches = UserCatch::where('user_id', $eventUser->id)
            ->where('event_id', operator: $currentEvent->id)
            ->count();
        $totalCatchableFursuits = \App\Models\Fursuit\Fursuit::where('event_id', operator: $currentEvent->id)
            ->where('catch_em_all', true)
            ->count();
        $userUniqueFursuits = UserCatch::where('user_id', $eventUser->user()->id)
            ->where('event_id', operator: $currentEvent->id)
            ->distinct('fursuit_id')
            ->count();
        $userOwnedFursuits = Fursuit::where('user_id', $user->id)->count();

        return new self(
            eventUser: $eventUser,
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
     */
    public function hasCatch(): bool
    {
        return $this->userCatch !== null;
    }

    /**
     * Check if this context is for a special code trigger.
     */
    public function isSpecialCodeTrigger(): bool
    {
        return $this->specialCodeType !== null;
    }
}
