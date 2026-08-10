<?php

namespace App\Domain\CatchEmAll\SpecialActions;

use App\Domain\CatchEmAll\Interface\ConfigurableSpecialCodeAction;
use App\Domain\CatchEmAll\Interface\SpecialCodeAction;

abstract class AbstractSpecialCodeAction implements ConfigurableSpecialCodeAction, SpecialCodeAction
{
    protected int $eventId;

    protected string $code;

    protected ?array $constructorData;

    /**
     * Constructor for the special code action.
     *
     * @param  int  $eventId  The event ID from the special_codes table
     * @param  string  $code  The special code from the special_codes table
     * @param  array|null  $constructorData  Optional data from the constructor_data JSON field
     */
    public function __construct(int $eventId, string $code, ?array $constructorData = null)
    {
        $this->eventId = $eventId;
        $this->code = $code;
        $this->constructorData = $constructorData;
    }

    /**
     * No configurable keys unless the action overrides this.
     *
     * The admin form is built from this list, so an action that reads nothing out of
     * `$constructorData` shows no data fields rather than a JSON box nobody can fill in
     * correctly.
     *
     * @return array<int, ActionField>
     */
    public static function constructorFields(): array
    {
        return [];
    }

    public static function constructorDescription(): ?string
    {
        return null;
    }
}
