<?php

namespace App\Domain\CatchEmAll\Interface;

use App\Models\EventUser;

interface HasUserCache extends Achievement
{
    /**
     * Get the list of cache keys connected to the user
     *
     * @return array<string> An array of cache keys that can be cleared by the main process
     */
    public function getUserCacheKeys(EventUser $eventUser): array;
}
