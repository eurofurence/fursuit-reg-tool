<?php

namespace App\Domain\CatchEmAll\Models;

use App\Domain\CatchEmAll\Enums\SpecialCodeType;
use App\Models\Event;
use App\Models\EventUser;
use App\Models\Fursuit\Fursuit;

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
        public int $userUniqueSpecies,
        public int $locationsExplored,
        public int $userTotalDaysCaught,
        public int $userTotalTeamCatches
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
        $userTotalCatches = UserCatch::where('event_user_id', $eventUser->id)
            ->count();
        $totalCatchableFursuits = Fursuit::where('event_id', $currentEvent->id)
            ->where('catch_em_all', true)
            ->count();
        $userUniqueFursuits = UserCatch::where('event_user_id', $eventUser->id)
            ->distinct('fursuit_id')
            ->count();
        $userUniqueSpecies = UserCatch::where('event_user_id', $eventUser->id)
            ->join('fursuits', 'user_catches.fursuit_id', '=', 'fursuits.id')
            ->join('species', 'fursuits.species_id', '=', 'species.id')
            ->where('species.checked', true)
            ->distinct('fursuits.species_id')
            ->count('fursuits.species_id');
        $locationsExplored = ($specialCodeType !== SpecialCodeType::EXPLORER) ? 0 : UserSpecialCatch::query()
            ->where('event_user_id', $eventUser->id)
            ->where('user_special_catches.type', SpecialCodeType::EXPLORER)
            ->distinct('special_code_id')
            ->count();
        $userTotalDaysCaught = UserCatch::where('event_user_id', $eventUser->id)
            ->selectRaw('DISTINCT DATE(created_at) as date')
            ->get()
            ->count();
        $userTotalTeamCatches = ($specialCodeType !== SpecialCodeType::CATCH_EM_ALL_TEAM) ? 0 : UserSpecialCatch::query()
            ->where('event_user_id', $eventUser->id)
            ->where('user_special_catches.type', SpecialCodeType::CATCH_EM_ALL_TEAM)
            ->count();

        return new self(
            eventUser: $eventUser,
            userCatch: $userCatch,
            specialCodeType: $specialCodeType,
            userTotalCatches: $userTotalCatches,
            totalCatchableFursuits: $totalCatchableFursuits,
            userUniqueFursuits: $userUniqueFursuits,
            userUniqueSpecies: $userUniqueSpecies,
            locationsExplored: $locationsExplored,
            userTotalDaysCaught: $userTotalDaysCaught,
            userTotalTeamCatches: $userTotalTeamCatches
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
