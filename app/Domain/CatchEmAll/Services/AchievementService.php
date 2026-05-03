<?php

namespace App\Domain\CatchEmAll\Services;

use App\Domain\CatchEmAll\Achievements\Utils\AchievementFactory;
use App\Domain\CatchEmAll\Achievements\Utils\AchievementRegister;
use App\Domain\CatchEmAll\Enums\SpecialCodeType;
use App\Domain\CatchEmAll\Interface\Achievement;
use App\Domain\CatchEmAll\Models\AchievementUpdateContext;
use App\Domain\CatchEmAll\Models\UserCatch;
use App\Models\EventUser;
use App\Models\User;

class AchievementService
{
  public function processAchievements(EventUser $eventUser, ?UserCatch $newCatch = null, ?SpecialCodeType $codeType = null): void
  {
    if ($newCatch == null && $codeType == null) {
      return;
    }

    $context = AchievementUpdateContext::fromCatch($eventUser, $newCatch, $codeType);

    // Handle normal achievements
    if ($newCatch != null) {
        foreach (AchievementRegister::getNormalAchievements() as $achievement) {
            $this->handleAchievementProgress($eventUser, $achievement, $context);
        }
    }

    // Handle special achievements
    if ($codeType != null) {
        foreach (AchievementRegister::getSpecialAchievementsByCode($codeType) as $achievement) {
            $this->handleAchievementProgress($eventUser, $achievement, $context);
        }
    }
  }

  private function handleAchievementProgress(EventUser $eventUser, Achievement $achievement, AchievementUpdateContext $context): void
  {
    $newProgress = $achievement->updateAchievementProgress($context);
    if ($newProgress >= 0) {
      AchievementFactory::updateUserAchievementProgress($eventUser, $achievement, $newProgress);
    }
  }
}
