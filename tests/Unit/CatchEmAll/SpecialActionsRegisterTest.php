<?php

use App\Domain\CatchEmAll\Enums\SpecialCodeType;
use App\Domain\CatchEmAll\SpecialActions\SpecialActionsRegister;

test('special actions register can be initialized more than once', function () {
    SpecialActionsRegister::init();
    SpecialActionsRegister::init();

    expect(SpecialActionsRegister::getClassForSpecialCodeType(SpecialCodeType::BUG_BOUNTY))
        ->toBe(App\Domain\CatchEmAll\SpecialActions\BugBountyAction::class);
});