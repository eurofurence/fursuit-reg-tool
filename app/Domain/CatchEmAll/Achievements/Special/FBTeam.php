<?php

namespace App\Domain\CatchEmAll\Achievements\Special;

use App\Domain\CatchEmAll\Achievements\Abstract\SimpleAchievement;
use App\Domain\CatchEmAll\Enums\SpecialCodeType;
use App\Domain\CatchEmAll\Interface\AchievementSeries;
use App\Domain\CatchEmAll\Interface\HasGlobalCache;
use App\Domain\CatchEmAll\Interface\HasUserCache;
use App\Domain\CatchEmAll\Interface\HiddenIfLocked;
use App\Domain\CatchEmAll\Interface\LockedBy;
use App\Domain\CatchEmAll\Interface\ProgressInfo;
use App\Domain\CatchEmAll\Interface\SpecialAchievement;
use App\Domain\CatchEmAll\Interface\StacksOn;
use App\Domain\CatchEmAll\Models\AchievementUpdateContext;
use App\Domain\CatchEmAll\Models\SpecialCode;
use App\Domain\CatchEmAll\Models\UserSpecialCatch;
use App\Models\EventUser;
use Cache;

class FBTeam extends SimpleAchievement implements HasGlobalCache, HasUserCache, ProgressInfo, SpecialAchievement, AchievementSeries, LockedBy, HiddenIfLocked, StacksOn //
{
    protected const CACHE_KEY = 'fb_team';

    protected const INFO_CACHE_KEY = 'info_fb_team';

    protected int $maxProgress;
    protected array $lockedByIds;
    protected string $stacksOnId;


    public function __construct(string $id, string $title, string $description, string $task, int $maxProgress, array $lockedByIds = [], string $stacksOnId = '', bool $isOptional = false)
    {
        parent::__construct($id, $title, $description, $task, isOptional: $isOptional);
        $this->maxProgress = $maxProgress;
        $this->lockedByIds = $lockedByIds;
        $this->stacksOnId = $stacksOnId;
    }

    public function lockedBy(): array
    {
        return $this->lockedByIds;
    }

    public function getMaxProgress(): int
    {
        return $this->maxProgress ?: \count($this->getNames());
    }

    public function stacksOn(): string
    {
        return $this->stacksOnId;
    }


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
    protected function getInfoCacheKey(EventUser $eventUser): string
    {
        return self::INFO_CACHE_KEY.'_'.$eventUser->id;
    }

    /**
     * Get all species names that are part of the current event's catch-em-all challenge.
     *
     * @return string[]
     */
    protected function getNames(): array
    {
        return Cache::remember(self::CACHE_KEY, now()->addHours(1), function () {
            $names = SpecialCode::whereIn('type', [SpecialCodeType::CATCH_EM_ALL_TEAM, SpecialCodeType::FURSUIT_BADGE_TEAM])->get()->map(fn(SpecialCode $code) => $this->teamMemberName($code->constructor_data))->toArray();
            sort($names);

            return $names;
        });
    }

    public function updateAchievementProgress(AchievementUpdateContext $context): int
    {
        // This achievement can only be triggered by special code, not by catches
        if (! $context->isSpecialCodeTrigger() || ($context->specialCodeType !== SpecialCodeType::CATCH_EM_ALL_TEAM && $context->specialCodeType !== SpecialCodeType::FURSUIT_BADGE_TEAM)) {
            return -1; // Ignore this update
        }

        // Forget the user cache for this achievement to ensure it reflects the latest state
        Cache::forget($this->getInfoCacheKey($context->eventUser));

        // Return completion progress - achievement granting is handled by AchievementService
        return min($this->getMaxProgress(), \count($this->getCurrentProgress($context->eventUser)));
    }

    public function getSpecialCode(): array
    {
        return [SpecialCodeType::CATCH_EM_ALL_TEAM, SpecialCodeType::FURSUIT_BADGE_TEAM];
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
                ->whereIn('user_special_catches.type', [SpecialCodeType::CATCH_EM_ALL_TEAM, SpecialCodeType::FURSUIT_BADGE_TEAM])
                ->get()
                ->map(function (UserSpecialCatch $catch): string {
                    return $this->teamMemberName($catch->specialCode?->constructor_data);
                })
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
    public function getUserCacheKeys(EventUser $eventUser): array
    {
        return [$this->getInfoCacheKey($eventUser)];
    }

    public static function getAchievements(): array
    {
        $achievements = [];

        $achievements[] = new self('fb_team', 'Fursuit Badge Team  (1/3)', 'You found someone that works on this application!', 'Find a member of the Fursuit Badge team.', 1, []);

        $achievements[] = new self('fb_team_five', 'Fursuit Badge Team  (2/3)', 'You found three of us! Can you also find the remaining team?', 'You found one, but can you find two more of us?', 5, ['fb_team'], 'fb_team');

        // Total species count is only known via the instance (cached lookup), not statically
        $complete = new self('fb_team_all', 'Fursuit Badge Team  (3/3)', 'You found all of us! Congratulations!', 'Now find the rest of us!', 0, ['fb_team_five'], 'fb_team_five', isOptional: true);
        $achievements[] = $complete;

        return $achievements;
    }
}
