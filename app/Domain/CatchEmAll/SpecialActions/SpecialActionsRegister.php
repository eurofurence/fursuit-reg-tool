<?php

namespace App\Domain\CatchEmAll\SpecialActions;

use App\Domain\CatchEmAll\Enums\SpecialCodeType;
use App\Domain\CatchEmAll\Interface\SpecialCodeAction;

class SpecialActionsRegister
{
    /**
     * Registry of all available special action classes.
     * Add new special action classes here to register them.
     *
     * @var array<class-string<SpecialCodeAction>>
     */
    private static array $specialCodeActionClasses = [
        BugBountyAction::class,
        CatchEmAllTeamAction::class,
    ];

    /**
     * Instances of all registered special action classes.
     * This array is populated during the init() method.
     *
     * @var array<class-string<SpecialCodeAction>, string> Display names of all registered special action classes.
     */
    protected static array $specialCodeDisplayName = [];

    /**
     * Mapping of SpecialCodeType to their corresponding action classes.
     * This array is populated during the init() method.
     *
     * @var array<class-string<SpecialCodeAction>, SpecialCodeType>
     */
    protected static array $specialCodeTypeMapping = [];

    /**
     * Initial Checks of all classes and generation of all arrays
     */
    public static function init(): void
    {
        self::checkSpecialCodeActionClasses();

        self::generateSpecialCodeTypeMapping();
    }

    /**
     * Check that all registered special action classes implement the SpecialCodeAction interface.
     * Throws an exception if any class does not implement the interface.
     */
    private static function checkSpecialCodeActionClasses(): void
    {
        foreach (self::$specialCodeActionClasses as $class) {
            if (! is_subclass_of($class, SpecialCodeAction::class)) {
                throw new \Exception("Class {$class} must implement the SpecialCodeAction interface.");
            }
        }
    }

    /**
     * Generate a mapping of SpecialCodeType to their corresponding action classes.
     */
    private static function generateSpecialCodeTypeMapping(): void
    {
        foreach (self::$specialCodeActionClasses as $class) {
            self::$specialCodeDisplayName[$class] = $class::getDisplayName();
            self::$specialCodeTypeMapping[$class] = $class::getSpecialCodeType();
        }
    }

    /**
     * Get the display name for a given special action class.
     *
     * @param  class-string<SpecialCodeAction>  $class
     */
    public static function getDisplayNameForClass(string $class): ?string
    {
        return self::$specialCodeDisplayName[$class] ?? null;
    }

    /**
     * Get the SpecialCodeType for a given special action class.
     *
     * @param  class-string<SpecialCodeAction>  $class
     */
    public static function getSpecialCodeTypeForClass(string $class): ?SpecialCodeType
    {
        return self::$specialCodeTypeMapping[$class] ?? null;
    }

    /**
     * Get the SpecialCodeType for a given special action class.
     *
     * @param  class-string<SpecialCodeAction>  $class
     */
    public static function getSpecialCodeTypeValueForClass(string $class): ?string
    {
        return self::$specialCodeTypeMapping[$class]->value ?? null;
    }

    public static function getSpecialCodeActionClassForType(SpecialCodeType $type): ?string
    {
        return array_search($type, self::$specialCodeTypeMapping, true) ?: null;
    }

    public static function getSpecialCodeActionClasses(): array
    {
        return self::$specialCodeActionClasses;
    }

    public static function getSpecialCodeDisplayNames(): array
    {
        return self::$specialCodeDisplayName;
    }

    public static function getSpecialCodeTypeMapping(): array
    {
        return self::$specialCodeTypeMapping;
    }

    public static function getSpecialCodeTypeValueMapping(): array
    {
        return array_map(fn (SpecialCodeType $type) => $type->value, self::$specialCodeTypeMapping);
    }
}
