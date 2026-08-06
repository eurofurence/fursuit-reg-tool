<?php

namespace App\Domain\CatchEmAll\SpecialActions;

use App\Domain\CatchEmAll\Enums\SpecialCodeType;
use App\Models\EventUser;

class CatchEmAllTeamAction extends AbstractSpecialCodeAction
{
    /**
     * Execute the Catch 'Em All Team special code action for the given user.
     * Returns the CATCH_EM_ALL_TEAM enum value.
     *
     * @param  EventUser  $eventUser  The user who used the special code
     * @return SpecialCodeType The CATCH_EM_ALL_TEAM enum value
     */
    public function use(EventUser $eventUser): SpecialCodeType
    {
        // Return the CATCH_EM_ALL_TEAM enum
        return SpecialCodeType::CATCH_EM_ALL_TEAM;
    }

    public static function getSpecialCodeType(): SpecialCodeType
    {
        return SpecialCodeType::CATCH_EM_ALL_TEAM;
    }

    public static function getDisplayName(): string
    {
        return 'Catch \'Em All Team';
    }

    public static function getConfigData(): ?array
    {
        return ['name' => 'Hunter'];
    }
}
