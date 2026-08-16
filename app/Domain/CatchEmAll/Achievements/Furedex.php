<?php

namespace App\Domain\CatchEmAll\Achievements;

use App\Domain\CatchEmAll\Achievements\Abstract\SimpleAchievement;
use App\Domain\CatchEmAll\Interface\AchievementSeries;
use App\Domain\CatchEmAll\Interface\HasGlobalCache;
use App\Domain\CatchEmAll\Interface\HasUserCache;
use App\Domain\CatchEmAll\Interface\HiddenIfLocked;
use App\Domain\CatchEmAll\Interface\LockedBy;
use App\Domain\CatchEmAll\Interface\ProgressInfo;
use App\Domain\CatchEmAll\Interface\StacksOn;
use App\Domain\CatchEmAll\Models\AchievementUpdateContext;
use App\Domain\CatchEmAll\Models\UserCatch;
use App\Models\Event;
use App\Models\EventUser;
use App\Models\Fursuit\Fursuit;
use Cache;

class Furedex extends SimpleAchievement implements HasGlobalCache, HasUserCache, ProgressInfo, LockedBy, AchievementSeries, HiddenIfLocked, StacksOn
{
    protected const CACHE_KEY = 'furedex_complete';

    protected const INFO_CACHE_KEY = 'info_furedex_complete';

    protected int $maxProgress;
    protected array $lockedByIds;
    protected string $stacksOnId;


    public function __construct(string $id, string $title, string $description, string $task, int $maxProgress, array $lockedByIds = [], string $stacksOnId = '')
    {
        parent::__construct($id, $title, $description, $task);
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
        return $this->maxProgress ?: \count($this->getSpecies());
    }

    public function stacksOn(): string
    {
        return $this->stacksOnId;
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
    protected function getSpecies(): array
    {
        return Cache::remember(self::CACHE_KEY, now()->addHours(1), function () {
            $currentEvent = Event::latest('starts_at')->first();

            return Fursuit::where('event_id', $currentEvent->id)
                ->where('catch_em_all', true)
                ->whereNull('deleted_at')
                ->join('species', 'fursuits.species_id', '=', 'species.id')
                ->where('species.checked', true)
                ->groupBy('species.name')
                ->havingRaw('COUNT(*) >= 10')
                ->pluck('species.name')
                ->toArray();
        });
    }

    public function getCacheKeys(): array
    {
        return [self::CACHE_KEY];
    }

    public function updateAchievementProgress(AchievementUpdateContext $context): int
    {
        // Only trigger on actual catches, not special codes
        if (! $context->hasCatch()) {
            return -1; // Ignore this update
        }
        // Forget cached progress info for this achievement to ensure it reflects the latest state
        Cache::forget($this->getInfoCacheKey($context->eventUser));

        // Return current progress based on user's unique fursuits caught
        $currentProgress = \count($this->getCurrentProgress($context->eventUser));

        // Always override default behavior
        return min($currentProgress, $this->getMaxProgress());
    }

    /**
     * {@inheritDoc}
     */
    public function getCurrentProgress(EventUser $eventUser): array
    {
        // Get all unique species names caught by the user in the current event
        $caughtSpecies = Cache::remember($this->getInfoCacheKey($eventUser), now()->addYear(),
            UserCatch::where('event_user_id', $eventUser->id)
                ->join('fursuits', 'user_catches.fursuit_id', '=', 'fursuits.id')
                ->join('species', 'fursuits.species_id', '=', 'species.id')
                ->where('species.checked', true)
                ->distinct('fursuits.species_id')
                ->pluck('species.name')
                ->toArray(...)
        );

        return $caughtSpecies;
    }

    /**
     * {@inheritDoc}
     */
    public function getTotalProgress(): array
    {

        return $this->getSpecies();
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
        $definitions = [
            ['furedex_5', 'Fill the furédex (1/4)', 5, [], ''],
            ['furedex_10', 'Fill the furédex (2/4)', 10, ['furedex_5'], 'furedex_5'],
            ['furedex_20', 'Fill the furédex (3/4)', 20, ['furedex_10'], 'furedex_10'],
        ];

        $achievements = array_map(
            fn (array $d) => new self($d[0], $d[1], "You caught {$d[2]} species at least once. Congratulations!", "Catch {$d[2]} species at least once.", $d[2], $d[3], $d[4]),
            $definitions
        );

        // Total species count is only known via the instance (cached lookup), not statically
        $complete = new self('furedex_complete', 'Fill the furédex (4/4)', 'You caught every species. Congratulations!', 'Catch every species at least once.', 0, ['furedex_20'], 'furedex_20');
        $achievements[] = $complete;

        return $achievements;
    }
}
