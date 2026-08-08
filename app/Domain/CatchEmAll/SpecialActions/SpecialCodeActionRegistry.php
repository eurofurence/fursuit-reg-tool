<?php

namespace App\Domain\CatchEmAll\SpecialActions;

use App\Domain\CatchEmAll\Interface\ConfigurableSpecialCodeAction;
use App\Domain\CatchEmAll\Interface\SpecialCodeAction;

/**
 * The action classes a special code may name, and the shape of the data each one takes.
 *
 * `SpecialCode::createActionInstance()` does `new $className(...)` on whatever the column
 * holds, so the list of classes the panel offers and the list it will accept on a write
 * have to be the same list. It lives here, once, and the form options, the `Rule::in` on
 * `class_name`, the Class column's label and the per-field validation all read it.
 *
 * The data shape is not declared here: each action class declares its own fields
 * (ConfigurableSpecialCodeAction), because the class that reads the data is the only place
 * that knows what it needs. This registry only knows which classes exist and how to fit a
 * stored document to a declaration.
 *
 * **Stored documents that do not match the declaration.** `constructor_data` predates the
 * form, and the column takes any JSON object, so the panel has to survive three cases
 * without losing data and without crashing the edit page:
 *
 *  - a key the current schema does not declare (an old key, or a key of a different
 *    action): kept. residue() returns it, the form shows it read-only, and the request
 *    writes it back unchanged as long as the class is not changed. Nothing the operator
 *    cannot see is silently dropped.
 *  - a declared key that is missing, or holds a value of the wrong type: the field shows
 *    its declared default (ActionField::cast()), and saving writes that default. The raw
 *    document is displayed next to the fields so the difference is visible before saving.
 *  - a class that is no longer registered: the edit page still renders, with the stored
 *    class shown in the Select as an unavailable option and its whole document treated as
 *    residue. `Rule::in` still refuses it on save, so the operator has to pick a class the
 *    redeem path can actually instantiate. That is change 49 and it is deliberate: a code
 *    naming a class that no longer exists throws in `createActionInstance()`.
 *
 * A stored value that is not an object at all (a list or a scalar, which the pre-fix
 * `json` rule let through) is the one thing not preserved: writing it back would recreate
 * the shape that raises a TypeError in AbstractSpecialCodeAction::__construct. It is shown
 * read-only and replaced by a real object on save.
 */
final class SpecialCodeActionRegistry
{
    /**
     * The classes shipped with the application.
     *
     * @var array<class-string<SpecialCodeAction>, string>
     */
    private const BUILT_IN = [
        BugBountyAction::class => 'Bug Hunter Bounty',
        CatchEmAllTeamAction::class => 'Catch \'Em All Team',
        ExplorerAction::class => 'Explorer',
    ];

    /**
     * Classes registered at runtime, e.g. by a service provider of a module that adds its
     * own action. Merged over the built-in list.
     *
     * @var array<class-string<SpecialCodeAction>, string>
     */
    private static array $registered = [];

    /**
     * @param  class-string<SpecialCodeAction>  $class
     */
    public static function register(string $class, string $label): void
    {
        if (! is_a($class, SpecialCodeAction::class, true)) {
            throw new \InvalidArgumentException("{$class} does not implement ".SpecialCodeAction::class.'.');
        }

        self::$registered[$class] = $label;
    }

    public static function flushRegistered(): void
    {
        self::$registered = [];
    }

    /**
     * class => label, as the Select offers them.
     *
     * @return array<class-string<SpecialCodeAction>, string>
     */
    public static function options(): array
    {
        return [...self::BUILT_IN, ...self::$registered];
    }

    /**
     * An empty class name is "no action", which is a real stored value: the column is
     * NOT NULL and the field is optional.
     */
    public static function has(?string $class): bool
    {
        return $class !== null && $class !== '' && array_key_exists($class, self::options());
    }

    public static function labelFor(?string $class): ?string
    {
        if ($class === null || $class === '') {
            return null;
        }

        return self::options()[$class] ?? $class;
    }

    /**
     * @return array<int, ActionField>
     */
    public static function fieldsFor(?string $class): array
    {
        if (! self::has($class) || ! is_a($class, ConfigurableSpecialCodeAction::class, true)) {
            return [];
        }

        return $class::constructorFields();
    }

    public static function descriptionFor(?string $class): ?string
    {
        if (! self::has($class) || ! is_a($class, ConfigurableSpecialCodeAction::class, true)) {
            return null;
        }

        return $class::constructorDescription();
    }

    /**
     * Every schema the form may need, keyed by class name, including the empty key for
     * "no class". The client renders whichever one `class_name` currently points at, so
     * switching the Select swaps the fields without another request.
     *
     * @return array<string, array{label: ?string, description: ?string, fields: array<int, array<string, mixed>>}>
     */
    public static function schemas(): array
    {
        $schemas = [
            '' => [
                'label' => null,
                'description' => 'No action class: the code exists but redeeming it does nothing.',
                'fields' => [],
            ],
        ];

        foreach (self::options() as $class => $label) {
            $schemas[$class] = [
                'label' => $label,
                'description' => self::descriptionFor($class),
                'fields' => array_map(
                    fn (ActionField $field) => $field->toArray(),
                    self::fieldsFor($class),
                ),
            ];
        }

        return $schemas;
    }

    /**
     * The declared keys of a stored document, cast to the types the fields declare and
     * filled in from the defaults where the document is silent.
     *
     * @return array<string, mixed>
     */
    public static function declaredValues(?string $class, mixed $stored): array
    {
        $document = self::asDocument($stored);
        $values = [];

        foreach (self::fieldsFor($class) as $field) {
            $values[$field->name] = array_key_exists($field->name, $document)
                ? $field->cast($document[$field->name])
                : $field->defaultValue();
        }

        return $values;
    }

    /**
     * The keys of a stored object that the current schema does not declare.
     *
     * This is the set a save writes back untouched, so it is deliberately empty for a
     * stored value that is not an object: there are no keys to keep there, and writing the
     * value back would restore the shape the redeem path raises a TypeError on.
     *
     * @return array<string, mixed>
     */
    public static function undeclaredKeys(?string $class, mixed $stored): array
    {
        if (! self::isObjectShaped($stored)) {
            return [];
        }

        $declared = array_map(fn (ActionField $field) => $field->name, self::fieldsFor($class));

        return array_diff_key(self::asDocument($stored), array_flip($declared));
    }

    /**
     * Whatever the current schema does not account for, for display.
     *
     * The undeclared keys, or the raw stored value when that value is not a JSON object
     * at all. Null when the document is fully described by the schema.
     */
    public static function residue(?string $class, mixed $stored): mixed
    {
        if ($stored === null) {
            return null;
        }

        // Not an object: a list or a scalar, which no set of keys can describe.
        if (! self::isObjectShaped($stored)) {
            return $stored;
        }

        $rest = self::undeclaredKeys($class, $stored);

        return $rest === [] ? null : $rest;
    }

    /**
     * The stored value as a flat assoc array of its top-level keys, or [] when it is not
     * an object.
     *
     * @return array<string, mixed>
     */
    private static function asDocument(mixed $stored): array
    {
        if (! self::isObjectShaped($stored)) {
            return [];
        }

        return is_object($stored) ? get_object_vars($stored) : $stored;
    }

    /**
     * The model casts the column to `object`, which decodes `{"a":1}` to a stdClass but
     * leaves a JSON list as a PHP list, so both shapes reach this code.
     */
    private static function isObjectShaped(mixed $stored): bool
    {
        if (is_object($stored)) {
            return true;
        }

        if (! is_array($stored)) {
            return false;
        }

        // An empty array is ambiguous ({} and [] both decode to []). It carries no keys
        // either way, so treating it as an object is the harmless reading.
        return $stored === [] || ! array_is_list($stored);
    }
}
