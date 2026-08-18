<?php

namespace App\Domain\CatchEmAll\SpecialActions;

use App\Domain\CatchEmAll\Enums\SpecialCodeType;
use App\Domain\CatchEmAll\Interface\ConfigurableSpecialCodeAction;
use App\Models\EventUser;

class FursuitBadgeTeamAction extends AbstractSpecialCodeAction implements ConfigurableSpecialCodeAction
{
    /**
     * Execute the Catch 'Em All Team special code action for the given user.
     * Returns the FURSUIT_BADGE_TEAM enum value.
     *
     * @param  EventUser  $eventUser  The user who used the special code
     * @return SpecialCodeType The FURSUIT_BADGE_TEAM enum value
     */
    public function use(EventUser $eventUser): SpecialCodeType
    {
        // Return the FURSUIT_BADGE_TEAM enum
        return SpecialCodeType::FURSUIT_BADGE_TEAM;
    }

    public static function getSpecialCodeType(): SpecialCodeType
    {
        return SpecialCodeType::FURSUIT_BADGE_TEAM;
    }

    public static function getDisplayName(): string
    {
        return 'Fursuit Badge Team';
    }

    public static function constructorDescription(): ?string
    {
        return 'Fursuit Badge Team stores the team member name for this special code.';
    }

    public static function constructorFields(): array
    {
        return [
            ActionField::text('name', 'Name')
                ->default('Hunter')
                ->help('Team member name associated with this code.'),
        ];
    }
}
