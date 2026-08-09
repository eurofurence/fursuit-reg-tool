<?php

namespace Tests\Fixtures;

use App\Domain\CatchEmAll\Enums\SpecialCodeType;
use App\Domain\CatchEmAll\SpecialActions\AbstractSpecialCodeAction;
use App\Domain\CatchEmAll\SpecialActions\ActionField;
use App\Models\EventUser;

/**
 * An action class with a field of every declarable type, registered through
 * SpecialCodeActionRegistry::register() by the tests that exercise the data form.
 *
 * The one action the application ships, BugBountyAction, reads nothing out of
 * `constructor_data` and therefore declares no fields, so the form machinery - per-field
 * rules, casts, defaults, the schema the client renders - has nothing real to run
 * against. This is that something, and it exercises the same registration path a module
 * adding its own action would use.
 */
class ConfiguredCodeAction extends AbstractSpecialCodeAction
{
    public static function constructorFields(): array
    {
        return [
            ActionField::integer('amount', 'Amount')
                ->required()
                ->rules(['min:1'])
                ->help('Points awarded on redeem'),
            ActionField::text('reason', 'Reason')
                ->default('Because'),
            ActionField::select('tier', 'Tier', ['bronze' => 'Bronze', 'gold' => 'Gold'])
                ->default('bronze'),
            ActionField::toggle('single_use', 'Single use'),
        ];
    }

    public static function constructorDescription(): ?string
    {
        return 'A test action with one field of each type.';
    }

    public function use(EventUser $eventUser): SpecialCodeType
    {
        return SpecialCodeType::BUG_BOUNTY;
    }
}
