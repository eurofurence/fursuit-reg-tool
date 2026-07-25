<?php

namespace App\Domain\CatchEmAll\Interface;

use App\Domain\CatchEmAll\Enums\SpecialCodeType;
use App\Models\EventUser;
use App\Models\User;

interface SpecialCodeAction
{
    /**
     * Constructor for the special code action.
     *
     * @param  int  $eventId  The event ID from the special_codes table
     * @param  string  $code  The special code from the special_codes table
     * @param  array|null  $constructorData  Optional data from the constructor_data JSON field
     */
    public function __construct(int $eventId, string $code, ?array $constructorData = null);

    /**
     * Execute the special code action for the given user.
     *
     * @param  EventUser  $eventUser  The user who used the special code
     * @return SpecialCodeType The result of the action
     */
    public function use(EventUser $eventUser): SpecialCodeType;

    /**
     * Get the associated SpecialCodeType for this action.
     */
    public static function getSpecialCodeType(): SpecialCodeType;

    /**
     * Get the displayed name for the admin panel for this action.
     */
    public static function getDisplayName(): string;

    /**
     * Get the config data for this action, if any.
     */
    public static function getConfigData(): ?array;
}
