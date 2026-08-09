<?php

namespace App\Domain\CatchEmAll\SpecialActions;

use App\Domain\CatchEmAll\Enums\SpecialCodeType;
use App\Domain\CatchEmAll\Interface\ConfigurableSpecialCodeAction;
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
        ExplorerAction::class,
    ];

    /**
     * Indexed by ::class
     *
     * @var array<class-string<SpecialCodeAction>, array>
     */
    protected static array $specialCodeClassNameIndex = [];

    /**
     * Indexed by SpecialCodeType->name
     *
     * @var array<int, array>
     */
    protected static array $specialCodeTypeIndex = [];

    /**
     * Initial Checks of all classes and generation of all arrays
     */
    public static function init(): void
    {
        self::resetIndexes();

        self::checkSpecialCodeActionClasses();

        self::generateIndexes();
    }

    /**
     * Clear the cached register indexes before rebuilding them.
     */
    private static function resetIndexes(): void
    {
        self::$specialCodeClassNameIndex = [];
        self::$specialCodeTypeIndex = [];
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
    private static function generateIndexes(): void
    {
        foreach (self::$specialCodeActionClasses as $class) {
            if (! is_subclass_of($class, SpecialCodeAction::class)) {
                throw new \Exception("Class {$class} must implement the SpecialCodeAction interface.");
            }

            if (isset(self::$specialCodeTypeIndex[$class::getSpecialCodeType()->value])) {
                throw new \Exception("Duplicate SpecialCodeType: {$class::getSpecialCodeType()->value} by {$class} and ".self::$specialCodeTypeIndex[$class::getSpecialCodeType()->value]['class']);
            }

            self::$specialCodeClassNameIndex[$class] = [
                'display_name' => $class::getDisplayName(),
                'type' => $class::getSpecialCodeType(),
                'config' => self::legacyConfigDataForClass($class),
            ];

            self::$specialCodeTypeIndex[$class::getSpecialCodeType()->value] = [
                'class' => $class,
                'display_name' => $class::getDisplayName(),
                'config' => self::legacyConfigDataForClass($class),
            ];
        }
    }

    /**
     * Keep compatibility with old consumers that still read config arrays from this register.
     *
     * @param  class-string<SpecialCodeAction>  $class
     */
    private static function legacyConfigDataForClass(string $class): ?array
    {
        if (! is_a($class, ConfigurableSpecialCodeAction::class, true)) {
            return null;
        }

        $config = [];

        foreach ($class::constructorFields() as $field) {
            $config[$field->name] = $field->defaultValue();
        }

        return $config === [] ? null : $config;
    }

    public static function getDisplayNameForClass(string $className): ?string
    {
        return self::$specialCodeClassNameIndex[$className]['display_name'] ?? null;
    }

    public static function getSpecialCodeTypeForClass(string $className): ?SpecialCodeType
    {
        return self::$specialCodeClassNameIndex[$className]['type'] ?? null;
    }

    public static function getConfigDataForClass(string $className): ?array
    {
        return self::$specialCodeClassNameIndex[$className]['config'] ?? null;
    }

    public static function getClassForSpecialCodeType(SpecialCodeType $type): ?string
    {
        return self::$specialCodeTypeIndex[$type->value]['class'] ?? null;
    }

    public static function getDisplayNameForSpecialCodeType(SpecialCodeType $type): ?string
    {
        return self::$specialCodeTypeIndex[$type->value]['display_name'] ?? null;
    }

    public static function getConfigDataForSpecialCodeType(SpecialCodeType $type): ?array
    {
        return self::$specialCodeTypeIndex[$type->value]['config'] ?? null;
    }

    /**
     * Get all available classes::class
     *
     * @return array<class-string<SpecialCodeAction>>
     */
    public static function getAllClasses(): array
    {
        return array_keys(self::$specialCodeClassNameIndex);
    }

    /**
     * Get all available SpecialCodesTypes that are available
     *
     * @return array<SpecialCodeType>
     */
    public static function getAllSpecialCodeTypes(): array
    {
        return array_map(SpecialCodeType::from(...), array_keys(self::$specialCodeTypeIndex));
    }

    public static function getFillamentOptions(): array
    {
        $result = [];

        foreach (self::$specialCodeTypeIndex as $key => $entry) {
            $result[$key] = $entry['display_name'] ?? null;
        }

        return $result;
    }
}
