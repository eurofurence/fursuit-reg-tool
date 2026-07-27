<?php

namespace App\Domain\CatchEmAll\Interface;

interface HasGlobalCache extends Achievement
{
    /**
     * Get the list of cache keys used globally
     *
     * @return array<string> An array of cache keys that can be cleared by the main process
     */
    public function getCacheKeys(): array;
}
