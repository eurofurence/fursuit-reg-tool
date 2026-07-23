<?php

namespace App\Domain\CatchEmAll\Interface;

/**
 * Hides Achievements if they are locked by LockedBy Achievements. This is useful for Achievements that are part of a series, where the user should not see the next Achievement until they have earned the previous one.
 */
interface HiddenIfLocked extends LockedBy {}
