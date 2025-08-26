<?php

namespace App\Domain\CatchEmAll\Interface;

use App\Domain\CatchEmAll\Enums\SpecialCodeTypes;
use App\Models\User;

interface SpecialCodeAction
{
    /**
     * Constructor for the special code action.
     *
     * @param int $eventId The event ID from the special_codes table
     * @param string $code The special code from the special_codes table
     * @param object|null $constructorData Optional data from the constructor_data JSON field
     */
    public function __construct(int $eventId, string $code, ?object $constructorData = null);

    /**
     * Execute the special code action for the given user.
     *
     * @param User $user The user who used the special code
     * @return SpecialCodeTypes The result of the action
     */
    public function use(User $user): SpecialCodeTypes;
}
