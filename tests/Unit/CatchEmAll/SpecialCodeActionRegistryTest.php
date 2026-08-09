<?php

use App\Domain\CatchEmAll\SpecialActions\CatchEmAllTeamAction;
use App\Domain\CatchEmAll\SpecialActions\ExplorerAction;
use App\Domain\CatchEmAll\SpecialActions\SpecialCodeActionRegistry;

test('registry exposes action fields for every registered special action type', function () {
    expect(SpecialCodeActionRegistry::fieldsFor(CatchEmAllTeamAction::class))->toHaveCount(1)
        ->and(SpecialCodeActionRegistry::fieldsFor(CatchEmAllTeamAction::class)[0]->name)->toBe('name')
        ->and(SpecialCodeActionRegistry::fieldsFor(ExplorerAction::class))->toHaveCount(1)
        ->and(SpecialCodeActionRegistry::fieldsFor(ExplorerAction::class)[0]->name)->toBe('location');
});
