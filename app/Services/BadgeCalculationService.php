<?php

namespace App\Services;

use App\Models\Event;

class BadgeCalculationService
{
    /**
     * The fee to fall back on when no event answers for the price.
     *
     * Both the Welcome page and the FAQ quote a price before an event exists in a fresh
     * install, and the checkout has to name a number rather than charge nothing, so the
     * fee this class used to hardcode stays as the floor.
     */
    public const DEFAULT_FEE = 500;

    /**
     * @param  Event|null  $event  The event the badge belongs to. Defaults to the active
     *                             one, which is what every caller means; pass it in when
     *                             it is already loaded so this does not re-query.
     * @return int cents
     *
     * Returns the badge fee in cents.
     *
     * One flat fee per event: a spare copy costs the same as any other extra badge, and
     * `$isLate` decides nothing. The parameter is kept because two call sites still pass
     * it and the badge table still carries `apply_late_fee`; there is no late surcharge in
     * this system, and adding one means adding a branch here, not just a truthy argument.
     */
    public static function calculate(
        bool $isSpareCopy = false,
        bool $isFreeBadge = false,
        bool $isLate = false,
        ?Event $event = null
    ): int {
        $fee = ($event ?? Event::getActiveEvent())?->badge_price_cents ?? self::DEFAULT_FEE;

        if ($isSpareCopy) {
            return $fee;
        }

        if ($isFreeBadge) {
            return 0;
        }

        return $fee;
    }
}
