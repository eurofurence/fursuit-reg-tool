<?php

namespace App\Domain\CatchEmAll\SpecialActions;

use App\Domain\CatchEmAll\Interface\SpecialCodeAction;

abstract class AbstractSpecialCodeAction implements SpecialCodeAction
{
    protected int $eventId;
    protected string $code;
    protected ?object $constructorData;

    /**
     * Constructor for the special code action.
     *
     * @param int $eventId The event ID from the special_codes table
     * @param string $code The special code from the special_codes table
     * @param object|null $constructorData Optional data from the constructor_data JSON field
     */
    public function __construct(int $eventId, string $code, ?object $constructorData = null)
    {
        $this->eventId = $eventId;
        $this->code = $code;
        $this->constructorData = $constructorData;
    }
}
