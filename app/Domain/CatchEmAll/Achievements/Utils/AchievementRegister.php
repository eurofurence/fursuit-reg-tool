<?php

namespace App\Domain\CatchEmAll\Achievements\Utils;

use App\Domain\CatchEmAll\Achievements\Archivist;
use App\Domain\CatchEmAll\Achievements\Collector;
use App\Domain\CatchEmAll\Achievements\Curator;
use App\Domain\CatchEmAll\Achievements\FirstCatch;
use App\Domain\CatchEmAll\Achievements\Furedex;
use App\Domain\CatchEmAll\Achievements\GottaCatchEmAll;
use App\Domain\CatchEmAll\Achievements\Nice;
use App\Domain\CatchEmAll\Achievements\NightOwl;
use App\Domain\CatchEmAll\Achievements\Special\BugBountyHunter;
use App\Domain\CatchEmAll\Achievements\Special\CEATeam;
use App\Domain\CatchEmAll\Achievements\Special\Explorer;
use App\Domain\CatchEmAll\Achievements\TheCompletionist;
use App\Domain\CatchEmAll\Achievements\TheLegendary151;
use App\Domain\CatchEmAll\Enums\SpecialCodeType;
use App\Domain\CatchEmAll\Interface\Achievement;
use App\Domain\CatchEmAll\Interface\AchievementSeries;
use App\Domain\CatchEmAll\Interface\HasGlobalCache;
use App\Domain\CatchEmAll\Interface\HasUserCache;
use App\Domain\CatchEmAll\Interface\LockedBy;
use App\Domain\CatchEmAll\Interface\SpecialAchievement;
use App\Domain\CatchEmAll\Interface\StacksOn;
use App\Models\EventUser;
use Illuminate\Support\Facades\Log;

class AchievementRegister
{
    /**
     * Registry of all available achievement classes.
     * Add new achievement classes here to register them.
     *
     * @var array<class-string<Achievement>>
     */
    private static array $achievementClasses = [
        FirstCatch::class,
        Collector::class,
        Curator::class,
        Archivist::class,
        GottaCatchEmAll::class,
        Nice::class,
        NightOwl::class,
        TheLegendary151::class,
        TheCompletionist::class,
        // Special achievements
        BugBountyHunter::class,
        Explorer::class,
        // Add new achievements here in the format:
        // AchievementClassName::class,
    ];

    /**
     * Registry of all available achievement series classes.
     * Add new achievement series classes here to register them.
     *
     * @var array<class-string<AchievementSeries>>
     */
    private static array $achievementSeries = [
        Furedex::class,
        CEATeam::class,
    ];

    /**
     * Registry of all instantiated achievements.
     * Built during initialization.
     *
     * @var array<class-string<Achievement>, Achievement>
     */
    protected static array $achievements = [];

    /**
     * Index: Achievement ID => Achievement Instance
     * Built during initialization for fast lookups.
     *
     * @var array<string, Achievement>
     */
    protected static array $idIndex = [];

    /**
     * Index: SpecialCodeType => Array of SpecialAchievement Instances
     * Built during initialization for fast special code lookups.
     *
     * @var array<string, array<SpecialAchievement>>
     */
    protected static array $specialCodeIndex = [];

    /**
     * Index: StacksOn Achievement ID => Achievement ID of StacksOn Achievement
     * Built during initialization for fast lookups of achievements that stack on others.
     *
     * @var array<string, string>
     */
    protected static array $stacksOnIndex = [];

    /**
     * Index: Array of normal achievements (non-special)
     * Built during initialization.
     *
     * @var array<Achievement>
     */
    protected static array $normalAchievements = [];

    /**
     * Array of achievments having HasUserCache interface implemented
     * Built during initialization.
     *
     * @var array<HasUserCache>
     */
    protected static array $hasUserCacheAchievements = [];

    /**
     * Array of achievments having HasGlobalCache interface implemented
     * Built during initialization.
     *
     * @var array<HasGlobalCache>
     */
    protected static array $hasGlobalCacheAchievements = [];

    /**
     * Count of required (non-optional) achievements for 100% completion.
     * Built during initialization.
     */
    protected static int $requiredAchievementCount = 0;

    /**
     * Count of optional achievements.
     * Built during initialization.
     */
    protected static int $optionalAchievementCount = 0;

    /**
     * Initialize the achievement register.
     * This method is called once during application startup.
     */
    public static function init(): void
    {
        self::resetState();

        // Build achievement instances from classes
        self::buildAchievementInstances();

        // Validate all registered achievements
        self::validateAchievements();

        // Build all indexes
        self::buildIndexes();

        // Calculate achievement counts
        self::calculateAchievementCounts();

        // Check if lockedBy dependencies are valid
        self::validateLockedByDependencies();

        // Log initialization
        Log::info('AchievementRegister initialized with '.count(self::$achievements).' achievements', [
            'total_achievements' => count(self::$achievements),
            'special_achievements' => count(self::$specialCodeIndex),
            'normal_achievements' => count(self::$normalAchievements),
            'required_achievements' => self::$requiredAchievementCount,
            'optional_achievements' => self::$optionalAchievementCount,
        ]);
    }

    /**
     * Clear all cached registry state before rebuilding it.
     */
    private static function resetState(): void
    {
        self::$achievements = [];
        self::$idIndex = [];
        self::$specialCodeIndex = [];
        self::$normalAchievements = [];
        self::$hasUserCacheAchievements = [];
        self::$hasGlobalCacheAchievements = [];
        self::$requiredAchievementCount = 0;
        self::$optionalAchievementCount = 0;
    }

    /**
     * Build achievement instances from registered classes.
     */
    protected static function buildAchievementInstances(): void
    {
        foreach (self::$achievementClasses as $className) {
            self::$achievements[$className] = new $className;
        }
        foreach (self::$achievementSeries as $seriesClass) {
            foreach ($seriesClass::getAchievements() as $instance) {
                self::$achievements[$instance->getId()] = $instance;
            }
        }
    }

    /**
     * Build all indexes for fast lookups.
     * Called during initialization.
     */
    protected static function buildIndexes(): void
    {
        self::buildIdIndex();
        self::buildSpecialCodeIndex();
        self::buildNormalAchievementsIndex();
        self::buildHasCacheAchievements();
        self::buildStacksOnIndex();
    }

    /**
     * Build the ID => Achievement index.
     */
    protected static function buildIdIndex(): void
    {
        foreach (self::$achievements as $achievement) {
            $id = $achievement->getId();
            self::$idIndex[$id] = $achievement;
        }
    }

    /**
     * Build the SpecialCodeType => SpecialAchievement index.
     */
    protected static function buildSpecialCodeIndex(): void
    {
        foreach (self::$achievements as $achievement) {
            if ($achievement instanceof SpecialAchievement) {
                $specialCode = $achievement->getSpecialCode();
                $codeValue = $specialCode->value;

                if (! isset(self::$specialCodeIndex[$codeValue])) {
                    self::$specialCodeIndex[$codeValue] = [];
                }

                self::$specialCodeIndex[$codeValue][] = $achievement;
            }
        }
    }

    /**
     * Build the normal achievements index (non-special achievements).
     */
    protected static function buildNormalAchievementsIndex(): void
    {
        foreach (self::$achievements as $achievement) {
            if (! ($achievement instanceof SpecialAchievement)) {
                self::$normalAchievements[] = $achievement;
            }
        }
    }

    /**
     * Build the list of achievements that implement HasCache interface.
     */
    protected static function buildHasCacheAchievements(): void
    {
        foreach (self::$achievements as $achievement) {
            if ($achievement instanceof HasUserCache) {
                self::$hasUserCacheAchievements[] = $achievement;
            }
            if ($achievement instanceof HasGlobalCache) {
                self::$hasGlobalCacheAchievements[] = $achievement;
            }
        }
    }

    /**
     * Build the stacks-on index for achievements that implement the StacksOn interface.
     */
    protected static function buildStacksOnIndex(): void
    {
        foreach (self::$achievements as $achievement) {
            if ($achievement instanceof StacksOn) {
                $stacksOnId = $achievement->stacksOn();
                if ($stacksOnId === '' || !self::hasAchievementId($stacksOnId)) {
                    continue; // Skip if stacksOnId is empty
                }
                if(isset(self::$stacksOnIndex[$stacksOnId])) {
                    throw new \InvalidArgumentException("Multiple achievements are trying to stack on the same achievement ID '{$stacksOnId}'.");
                }
                self::$stacksOnIndex[$stacksOnId] = $achievement->getId();
            }
        }
    }

    /**
     * Calculate achievement counts for required and optional achievements.
     * Called during initialization.
     */
    protected static function calculateAchievementCounts(): void
    {
        foreach (self::$achievements as $achievement) {
            if ($achievement->isOptional()) {
                self::$optionalAchievementCount++;
            } else {
                self::$requiredAchievementCount++;
            }
        }
    }

    /**
     * Validate that all lockedBy dependencies are valid.
     *
     * @throws \InvalidArgumentException
     */
    protected static function validateLockedByDependencies(): void
    {
        foreach (self::$achievements as $achievement) {
            if ($achievement instanceof LockedBy) {
                $lockedByIds = $achievement->lockedBy();

                foreach ($lockedByIds as $lockedById) {
                    if (! isset(self::$idIndex[$lockedById])) {
                        throw new \InvalidArgumentException(
                            "Achievement '{$achievement->getId()}' is locked by unknown achievement ID '{$lockedById}'."
                        );
                    }
                }
            }
        }
    }

    /**
     * Get an achievement by its ID using the index for fast lookup.
     */
    public static function getAchievementById(string $achievementId): ?Achievement
    {
        return self::$idIndex[$achievementId] ?? null;
    }

    /**
     * Get all special achievements that can be triggered by a specific SpecialCodeType.
     *
     * @return array<SpecialAchievement>
     */
    public static function getAchievementsBySpecialCode(SpecialCodeType $specialCode): array
    {
        return self::$specialCodeIndex[$specialCode->value] ?? [];
    }

    /**
     * Get all normal achievements (non-special).
     *
     * @return array<Achievement>
     */
    public static function getNormalAchievements(): array
    {
        return self::$normalAchievements;
    }

    /**
     * Get all special achievements.
     *
     * @return array<SpecialAchievement>
     */
    public static function getSpecialAchievements(): array
    {
        return array_values(self::$specialCodeIndex);
    }

    /**
     * Get special achievements by their special code.
     *
     * @return array<SpecialAchievement>
     */
    public static function getSpecialAchievementsByCode(SpecialCodeType $specialCode): array
    {
        return self::$specialCodeIndex[$specialCode->value] ?? [];
    }

    /**
     * Get all achievement instances.
     *
     * @return array<Achievement>
     */
    public static function getAllAchievementInstances(): array
    {
        return array_values(self::$achievements);
    }

    /**
     * Get all registered achievement classes.
     *
     * @return array<class-string<Achievement>>
     */
    public static function getAllAchievementClasses(): array
    {
        return array_keys(self::$achievements);
    }

    /**
     * Get a specific achievement instance by its class name.
     *
     * @param  class-string<Achievement>  $className
     */
    public static function getAchievement(string $className): ?Achievement
    {
        return self::$achievements[$className] ?? null;
    }

    /**
     * Get the number of required (non-optional) achievements for 100% completion.
     */
    public static function getRequiredAchievementCount(): int
    {
        return self::$requiredAchievementCount;
    }

    /**
     * Get the number of optional achievements.
     */
    public static function getOptionalAchievementCount(): int
    {
        return self::$optionalAchievementCount;
    }

    /**
     * Check if an achievement is registered by ID.
     */
    public static function hasAchievementId(string $achievementId): bool
    {
        return isset(self::$idIndex[$achievementId]);
    }

    /**
     * Get statistics about the achievement register.
     *
     * @return array<string, mixed>
     */
    public static function getStatistics(): array
    {
        return [
            'total_achievements' => count(self::$achievements),
            'special_achievements' => count(self::getSpecialAchievements()),
            'normal_achievements' => count(self::$normalAchievements),
            'special_code_types' => count(self::$specialCodeIndex),
            'indexed_ids' => count(self::$idIndex),
        ];
    }

    /**
     * Validate all registered achievements for consistency.
     *
     * @throws \InvalidArgumentException
     */
    private static function validateAchievements(): void
    {
        foreach (self::$achievements as $className => $instance) {
            // Check if the class actually implements Achievement interface
            if (! ($instance instanceof Achievement)) {
                throw new \InvalidArgumentException("Class {$className} must implement Achievement interface");
            }

            // Check for duplicate IDs
            $id = $instance->getId();
            $duplicates = array_filter(self::$achievements, fn ($other) => $other->getId() === $id);

            if (count($duplicates) > 1) {
                throw new \InvalidArgumentException("Duplicate achievement ID found: {$id}");
            }
        }
    }

    /**
     * Get total count of registered achievements.
     */
    public static function getCount(): int
    {
        return count(self::$achievements);
    }

    /**
     * Get all registered achievement instances.
     *
     * @return array<Achievement>
     */
    public static function getAllRegisteredInstances(): array
    {
        return array_values(self::$achievements);
    }

    /**
     * Get all registered achievement IDs.
     *
     * @return array<string>
     */
    public static function getAllRegisteredIds(): array
    {
        return array_keys(self::$idIndex);
    }

    public static function getHasCacheAchievements(): array
    {
        return self::$hasUserCacheAchievements;
    }

    public static function getAllUserCachedKeys(EventUser $eventUser): array
    {
        $cacheKeys = [];

        foreach (self::$hasUserCacheAchievements as $achievement) {
            $keys = $achievement->getUserCacheKeys($eventUser);
            $cacheKeys = [...$cacheKeys, ...$keys];
        }

        return array_unique($cacheKeys);
    }

    public static function getStacksOnIndex(): array
    {
        return self::$stacksOnIndex;
    }

    public static function getStacksOnAchievementID(string $stacksOnId): ?string
    {
        return self::$stacksOnIndex[$stacksOnId] ?? null;
    }
}
