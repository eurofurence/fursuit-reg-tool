<?php

namespace App\Domain\CatchEmAll\SpecialActions;

use App\Domain\CatchEmAll\Enums\SpecialCodeType;
use App\Domain\CatchEmAll\Interface\ConfigurableSpecialCodeAction;
use App\Models\EventUser;

class ExplorerAction extends AbstractSpecialCodeAction implements ConfigurableSpecialCodeAction
{
    /**
     * Execute the Explorer special code action for the given user.
     * Returns the EXPLORER enum value.
     *
     * @param  EventUser  $eventUser  The user who used the special code
     * @return SpecialCodeType The EXPLORER enum value
     */
    public function use(EventUser $eventUser): SpecialCodeType
    {
        // Return the EXPLORER enum
        return SpecialCodeType::EXPLORER;
    }

    public static function getSpecialCodeType(): SpecialCodeType
    {
        return SpecialCodeType::EXPLORER;
    }

    public static function getDisplayName(): string
    {
        return 'Explorer';
    }

    public static function constructorDescription(): ?string
    {
        return 'Explorer stores a location label that is shown in management tools.';
    }

    /**
     * {@inheritDoc}
     */
    public static function constructorFields(): array
    {
        return [
            ActionField::text('location', 'Location')
                ->default('ABC')
                ->help('Identifier for the explorer location this code represents.'),
        ];
    }
}
