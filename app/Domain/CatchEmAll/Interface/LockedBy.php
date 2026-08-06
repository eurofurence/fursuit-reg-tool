<?php

namespace App\Domain\CatchEmAll\Interface;

interface LockedBy extends Achievement
{
    /**
     * Get the list of achievement IDs that this achievement is locked by.
     *
     * @return array<string> An array of achievement IDs that must be earned before this achievement can be earned.
     */
    public function lockedBy(): array;
}
