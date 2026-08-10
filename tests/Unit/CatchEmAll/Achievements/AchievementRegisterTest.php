<?php

use App\Domain\CatchEmAll\Achievements\Utils\AchievementRegister;

uses(Tests\TestCase::class);

test('achievement register can be initialized more than once', function () {
    AchievementRegister::init();
    $firstStatistics = AchievementRegister::getStatistics();

    AchievementRegister::init();

    expect(AchievementRegister::getStatistics())->toBe($firstStatistics);
});
