<?php

namespace App\Domain\CatchEmAll\Achievements\Special;

use App\Domain\CatchEmAll\Achievements\Abstract\SimpleAchievement;
use App\Domain\CatchEmAll\Enums\SpecialCodeType;
use App\Domain\CatchEmAll\Interface\HasGlobalCache;
use App\Domain\CatchEmAll\Interface\ProgressInfo;
use App\Domain\CatchEmAll\Interface\SpecialAchievement;
use App\Domain\CatchEmAll\Models\AchievementUpdateContext;
use App\Domain\CatchEmAll\Models\SpecialCode;
use App\Domain\CatchEmAll\Models\UserSpecialCatch;
use App\Models\Event;
use App\Models\EventUser;
use Cache;

class Explorer extends SimpleAchievement implements HasGlobalCache, ProgressInfo, SpecialAchievement
{
    private const CACHE_KEY = 'explorer_locations';

    protected const INFO_CACHE_KEY = 'info_explorer_locations';

    private function locationName(mixed $constructorData): string
    {
        if (\is_object($constructorData)) {
            return $constructorData->location ?? 'Unknown';
        }

        if (\is_array($constructorData)) {
            return $constructorData['location'] ?? 'Unknown';
        }

        return 'Unknown';
    }

    /**
     * Get the info cache key for a specific event user.
     */
    protected function getInfoCacheKey(EventUser $eventUser): string
    {
        return self::INFO_CACHE_KEY.'_'.$eventUser->id;
    }

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

    private function getLocations(): array
    {
        return Cache::remember(self::CACHE_KEY, now()->addHours(1), function () {
            $currentEvent = Event::latest('starts_at')->first();

            $locations = SpecialCode::where('type', SpecialCodeType::EXPLORER)
                ->where('event_id', $currentEvent->id)
                ->get()
                ->map(function (SpecialCode $code) {
                    return $this->locationName($code->constructor_data);
                })
                ->toArray();
            sort($locations);

            return $locations;
        });
    }

    public function getMaxProgress(): int
    {
        return \count($this->getLocations());
    }

    public function updateAchievementProgress(AchievementUpdateContext $context): int
    {
        // This achievement can only be triggered by special code, not by catches
        if (! $context->isSpecialCodeTrigger() || $context->specialCodeType !== SpecialCodeType::EXPLORER) {
            return -1; // Ignore this update
        }

        // Forget the user cache for this achievement to ensure it reflects the latest state
        Cache::forget($this->getInfoCacheKey($context->eventUser));

        // Return completion progress - achievement granting is handled by AchievementService
        return min($context->locationsExplored, $this->getMaxProgress());
    }

    public function getSpecialCode(): SpecialCodeType
    {
        return SpecialCodeType::EXPLORER;
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
    public function getCurrentProgress(EventUser $eventUser): array
    {
        $currentProgress = Cache::remember($this->getInfoCacheKey($eventUser), now()->addYear(), function () use ($eventUser) {
            $caughtCodes = UserSpecialCatch::query()
                ->with('specialCode')
                ->where('event_user_id', $eventUser->id)
                ->where('user_special_catches.type', SpecialCodeType::EXPLORER)
                ->get()
                ->map(function (UserSpecialCatch $catch): string {
                    return $this->locationName($catch->specialCode?->constructor_data);
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
        return $this->getLocations();
    }
}
