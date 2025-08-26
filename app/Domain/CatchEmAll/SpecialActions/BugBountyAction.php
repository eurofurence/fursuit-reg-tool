<?php

namespace App\Domain\CatchEmAll\SpecialActions;


use App\Domain\CatchEmAll\Enums\SpecialCodeTypes;
use App\Domain\CatchEmAll\Models\SpecialCode;
use App\Models\User;

class BugBountyAction extends AbstractSpecialCodeAction
{
    /**
     * Execute the bug bounty special code action for the given user.
     * Returns the BUG_BOUNTY enum and deletes the code from the database.
     *
     * @param User $user The user who used the special code
     * @return SpecialCodeTypes The BUG_BOUNTY enum value
     */
    public function use(User $user): SpecialCodeTypes
    {
        // Delete the special code from the database
        SpecialCode::where('event_id', $this->eventId)
            ->where('code', $this->code)
            ->delete();

        // Return the BUG_BOUNTY enum
        return SpecialCodeTypes::BUG_BOUNTY;
    }
}
