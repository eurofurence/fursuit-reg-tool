<?php

namespace App\Domain\CatchEmAll\Interface;

use App\Domain\CatchEmAll\SpecialActions\ActionField;

/**
 * An action class that declares which keys its `constructor_data` may hold.
 *
 * The admin panel builds the special-code form, its validation rules and the stored JSON
 * from this declaration, so the class that reads the data is the one that says what the
 * data is. AbstractSpecialCodeAction implements it with an empty list, which is the honest
 * answer for an action that reads nothing.
 */
interface ConfigurableSpecialCodeAction
{
    /**
     * The keys this action reads, in the order the form shows them.
     *
     * @return array<int, ActionField>
     */
    public static function constructorFields(): array;

    /**
     * One line under the form section: what this action does with its data, including
     * "nothing" when it declares no fields.
     */
    public static function constructorDescription(): ?string;
}
