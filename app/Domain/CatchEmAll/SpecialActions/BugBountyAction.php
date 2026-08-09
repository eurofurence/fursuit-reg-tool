<?php

namespace App\Domain\CatchEmAll\SpecialActions;

use App\Domain\CatchEmAll\Enums\SpecialCodeType;
use App\Domain\CatchEmAll\Models\SpecialCode;
use App\Models\EventUser;
use App\Models\User;

class BugBountyAction extends AbstractSpecialCodeAction
{
    /**
     * This action reads nothing out of `constructor_data`: `use()` below consumes the code
     * and returns the enum, and the achievement it triggers takes no parameters either.
     * Its declared field list is therefore empty (the inherited `constructorFields()`), and
     * the admin form shows this sentence where the fields would be instead of inviting an
     * operator to type JSON that nothing will ever read.
     *
     * Rows written before the form existed may still carry keys, e.g. `{"amount": 100}`.
     * They are kept byte for byte on save and shown read-only in the form; see
     * SpecialCodeActionRegistry::residue().
     */
    public static function constructorDescription(): ?string
    {
        return 'Bug Hunter Bounty takes no data. Redeeming the code consumes it and grants the Bug Bounty Hunter achievement.';
    }

    /**
     * Execute the bug bounty special code action for the given user.
     * Returns the BUG_BOUNTY enum and deletes the code from the database.
     *
     * @param  EventUser  $eventUser  The user who used the special code
     * @return SpecialCodeType The BUG_BOUNTY enum value
     */
    public function use(EventUser $eventUser): SpecialCodeType
    {
        // Delete the special code from the database
        SpecialCode::where('event_id', $this->eventId)
            ->where('code', $this->code)
            ->delete();

        // Return the BUG_BOUNTY enum
        return SpecialCodeType::BUG_BOUNTY;
    }

    public static function getSpecialCodeType(): SpecialCodeType
    {
        return SpecialCodeType::BUG_BOUNTY;
    }

    public static function getDisplayName(): string
    {
        return 'Bug Bounty';
    }
}
