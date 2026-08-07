<?php

namespace App\Domain\CatchEmAll\SpecialActions;

use App\Domain\CatchEmAll\Enums\SpecialCodeType;
use App\Models\EventUser;

class ExplorerAction extends AbstractSpecialCodeAction
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

    public static function getConfigData(): ?array
    {
        return ['location' => 'ABC'];
    }
}
