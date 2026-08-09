<?php

namespace App\Domain\CatchEmAll\Achievements\Special;

use App\Domain\CatchEmAll\Achievements\Abstract\SimpleAchievement;
use App\Domain\CatchEmAll\Enums\SpecialCodeType;
use App\Domain\CatchEmAll\Interface\HasGlobalCache;
use App\Domain\CatchEmAll\Interface\HasUserCache;
use App\Domain\CatchEmAll\Interface\ProgressInfo;
use App\Domain\CatchEmAll\Interface\SpecialAchievement;
use App\Domain\CatchEmAll\Models\AchievementUpdateContext;
use App\Domain\CatchEmAll\Models\SpecialCode;
use App\Domain\CatchEmAll\Models\UserSpecialCatch;
use Cache;

class CEATeam extends SimpleAchievement implements HasGlobalCache, HasUserCache, ProgressInfo, SpecialAchievement //
{
    private const CACHE_KEY = 'cea_team';

    private const INFO_CACHE_KEY = 'info_cea_team';

    private function teamMemberName(mixed $constructorData): string
    {
        if (is_object($constructorData)) {
            return $constructorData->name ?? 'Unknown';
        }

        if (is_array($constructorData)) {
            return $constructorData['name'] ?? 'Unknown';
        }

        return 'Unknown';
    }

    /**
     * Get the info cache key for a specific event user.
     */
    private function getInfoCacheKey(\App\Models\EventUser $eventUser): string
    {
        return self::INFO_CACHE_KEY.'_'.$eventUser->id;
    }

    /**
     * Get all species names that are part of the current event's catch-em-all challenge.
     *
     * @return string[]
     */
    private function getNames(): array
    {
        return Cache::remember(self::CACHE_KEY, now()->addHours(1), function () {
            $names = SpecialCode::where('type', SpecialCodeType::CATCH_EM_ALL_TEAM)->get()->map(function (SpecialCode $code) {
                return $this->teamMemberName($code->constructor_data);
            })->toArray();
            sort($names);

            return $names;
        });
    }

    public function __construct()
    {
        parent::__construct(
            id: 'cea_team',
            title: 'Catch \'Em All Team',
            description: 'You found someone that made this game!',
            task: 'Find a member of the Catch \'Em All development team.',
            icon: '👑',
            isSecret: false,
            isOptional: false,
            isHidden: false
        );
    }

    public function getMaxProgress(): int
    {
        return 1;
    }

    public function updateAchievementProgress(AchievementUpdateContext $context): int
    {
        // This achievement can only be triggered by special code, not by catches
        if (! $context->isSpecialCodeTrigger() || $context->specialCodeType !== SpecialCodeType::CATCH_EM_ALL_TEAM) {
            return -1; // Ignore this update
        }

        // Forget the user cache for this achievement to ensure it reflects the latest state
        Cache::forget($this->getInfoCacheKey($context->eventUser));

        // Return completion progress - achievement granting is handled by AchievementService
        return self::getMaxProgress(); // Return 1 (completed)
    }

    public function getSpecialCode(): SpecialCodeType
    {
        return SpecialCodeType::CATCH_EM_ALL_TEAM;
    }

    /**
     * {@inheritDoc}
     */
    public function getCurrentProgress(\App\Models\EventUser $eventUser): array
    {
        $currentProgress = Cache::remember($this->getInfoCacheKey($eventUser), now()->addYear(), function () use ($eventUser) {
            $caughtCodes = UserSpecialCatch::query()
                ->with('specialCode')
                ->where('event_user_id', $eventUser->id)
                ->where('user_special_catches.type', SpecialCodeType::CATCH_EM_ALL_TEAM)
                ->get()
                ->map(function (UserSpecialCatch $catch): string {
                    return $this->teamMemberName($catch->specialCode?->constructor_data);
                })
                ->unique()
                ->values()
                ->toArray();

            return $caughtCodes;
        });

        return $currentProgress;
    }

    /**
     * {@inheritDoc}
     */
    public function getTotalProgress(): array
    {
        $members = $this->getNames();

        return $members;
    }

    /**
     * {@inheritDoc}
     */
    public function getCacheKeys(): array
    {
        return [self::CACHE_KEY];
    }

    /**
     * {@inheritDoc}
     */
    public function getUserCacheKeys(\App\Models\EventUser $eventUser): array
    {
        return [$this->getInfoCacheKey($eventUser)];
    }
}
