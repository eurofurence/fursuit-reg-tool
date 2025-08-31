<?php

namespace App\Domain\CatchEmAll\Achievements\Utils;

use App\Domain\CatchEmAll\Achievements\BugBountyHunter;
use App\Domain\CatchEmAll\Enums\SpecialCodeType;
use App\Domain\CatchEmAll\Interface\Achievement;
use App\Domain\CatchEmAll\Interface\SpecialAchievement;
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
        BugBountyHunter::class,
        // Add new achievements here in the format:
        // AchievementClassName::class,
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
     * Index: Array of normal achievements (non-special)
     * Built during initialization.
     *
     * @var array<Achievement>
     */
    protected static array $normalAchievements = [];

    /**
     * Initialize the achievement register.
     * This method is called once during application startup.
     *
     * @return void
     */
    public static function init(): void
    {
        // Build achievement instances from classes
        self::buildAchievementInstances();

        // Validate all registered achievements
        self::validateAchievements();

        // Build all indexes
        self::buildIndexes();

        // Log initialization
        Log::info('AchievementRegister initialized with ' . count(self::$achievements) . ' achievements', [
            'total_achievements' => count(self::$achievements),
            'special_achievements' => count(self::$specialCodeIndex),
            'normal_achievements' => count(self::$normalAchievements),
        ]);
    }

    /**
     * Build achievement instances from registered classes.
     *
     * @return void
     */
    protected static function buildAchievementInstances(): void
    {
        self::$achievements = [];

        foreach (self::$achievementClasses as $className) {
            self::$achievements[$className] = new $className();
        }
    }

    /**
     * Build all indexes for fast lookups.
     * Called during initialization.
     *
     * @return void
     */
    protected static function buildIndexes(): void
    {
        self::buildIdIndex();
        self::buildSpecialCodeIndex();
        self::buildNormalAchievementsIndex();
    }

    /**
     * Build the ID => Achievement index.
     *
     * @return void
     */
    protected static function buildIdIndex(): void
    {
        self::$idIndex = [];

        foreach (self::$achievements as $achievement) {
            $id = $achievement->getId();
            self::$idIndex[$id] = $achievement;
        }
    }

    /**
     * Build the SpecialCodeType => SpecialAchievement index.
     *
     * @return void
     */
    protected static function buildSpecialCodeIndex(): void
    {
        self::$specialCodeIndex = [];

        foreach (self::$achievements as $achievement) {
            if ($achievement instanceof SpecialAchievement) {
                $specialCode = $achievement->getSpecialCode();
                $codeValue = $specialCode->name;

                if (!isset(self::$specialCodeIndex[$codeValue])) {
                    self::$specialCodeIndex[$codeValue] = [];
                }

                self::$specialCodeIndex[$codeValue][] = $achievement;
            }
        }
    }

    /**
     * Build the normal achievements index (non-special achievements).
     *
     * @return void
     */
    protected static function buildNormalAchievementsIndex(): void
    {
        self::$normalAchievements = [];

        foreach (self::$achievements as $achievement) {
            if (!($achievement instanceof SpecialAchievement)) {
                self::$normalAchievements[] = $achievement;
            }
        }
    }

    /**
     * Get an achievement by its ID using the index for fast lookup.
     *
     * @param string $achievementId
     * @return Achievement|null
     */
    public static function getAchievementById(string $achievementId): ?Achievement
    {
        return self::$idIndex[$achievementId] ?? null;
    }

    /**
     * Get all special achievements that can be triggered by a specific SpecialCodeType.
     *
     * @param SpecialCodeType $specialCode
     * @return array<SpecialAchievement>
     */
    public static function getAchievementsBySpecialCode(SpecialCodeType $specialCode): array
    {
        return self::$specialCodeIndex[$specialCode->name] ?? [];
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
     * @param class-string<Achievement> $className
     * @return Achievement|null
     */
    public static function getAchievement(string $className): ?Achievement
    {
        return self::$achievements[$className] ?? null;
    }

    /**
     * Check if an achievement is registered by ID.
     *
     * @param string $achievementId
     * @return bool
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
     * @return void
     * @throws \InvalidArgumentException
     */
    private static function validateAchievements(): void
    {
        foreach (self::$achievements as $className => $instance) {
            // Check if the class actually implements Achievement interface
            if (!($instance instanceof Achievement)) {
                throw new \InvalidArgumentException("Class {$className} must implement Achievement interface");
            }

            // Check for duplicate IDs
            $id = $instance->getId();
            $duplicates = array_filter(self::$achievements, fn($other) => $other->getId() === $id);

            if (count($duplicates) > 1) {
                throw new \InvalidArgumentException("Duplicate achievement ID found: {$id}");
            }
        }

        // Validate SpecialCodeType duplicates
        self::validateSpecialCodeTypes();
    }

    /**
     * Validate that no SpecialCodeType is used by multiple SpecialAchievements.
     *
     * @return void
     * @throws \InvalidArgumentException
     */
    private static function validateSpecialCodeTypes(): void
    {
        $specialCodeUsage = [];

        foreach (self::$achievements as $className => $instance) {
            if ($instance instanceof SpecialAchievement) {
                $specialCode = $instance->getSpecialCode();
                $codeValue = $specialCode->name;

                if (isset($specialCodeUsage[$codeValue])) {
                    $firstClass = $specialCodeUsage[$codeValue]['className'];
                    $firstId = $specialCodeUsage[$codeValue]['achievementId'];

                    throw new \InvalidArgumentException(
                        "Duplicate SpecialCodeType '{$codeValue}' found: " .
                        "Used by both '{$firstClass}' (ID: {$firstId}) and '{$className}' (ID: {$instance->getId()}). " .
                        "Each SpecialCodeType must be unique across all SpecialAchievements."
                    );
                }

                $specialCodeUsage[$codeValue] = [
                    'className' => $className,
                    'achievementId' => $instance->getId(),
                    'instance' => $instance
                ];
            }
        }

        // Log successful validation
        if (!empty($specialCodeUsage)) {
            Log::debug('SpecialCodeType validation passed', [
                'special_code_types_found' => count($specialCodeUsage),
                'codes' => array_keys($specialCodeUsage)
            ]);
        }
    }

    /**
     * Get total count of registered achievements.
     *
     * @return int
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
}
