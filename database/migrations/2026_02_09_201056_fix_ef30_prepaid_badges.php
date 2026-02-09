<?php

use App\Models\Badge\Badge;
use App\Models\EventUser;
use App\Models\Badge\State_Payment\Paid;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $ef30UsersWithPrepaidBadges = EventUser::where('event_id', "=", 30)->where('prepaid_badges', '>', 0)->get();
        /** @var EventUser $eventUser */
        foreach ($ef30UsersWithPrepaidBadges as $eventUser) {
            // Check if the user is affected by the issue
            // eventUser.prepaid_badges - SUM(IF(badges.is_free_badge = 1, 1, 0)) > 0 AND SUM(IF(badges.is_free_badge = 0, 1, 0)) > 0
            $allowedPrepaidBadgesCount = $eventUser->prepaid_badges;

            $eventBadges = $eventUser->user()->badges()->where('event_id', "=", 30);
            $freeBadges = $eventBadges->where('is_free_badge', true);
            $paidBadges = $eventBadges->where('is_free_badge', false);

            if (!($freeBadges->count() < $allowedPrepaidBadgesCount && $paidBadges->count() > 0)) {
                continue; // Not affected
            }

            $badgesToConvert = min($allowedPrepaidBadgesCount - $freeBadges->count(), $paidBadges->count());
            for ($i = 0; $i < $badgesToConvert; $i++) {
                /** @var Badge $badge  */
                $badge = $paidBadges->skip($i)->first();
                if (!$badge) {
                    break;
                }

                $eventUser->user()->forceRefund($badge);

                $badge->is_free_badge = true;
                $badge->subtotal = 0;
                $badge->total = 0;
                $badge->tax = 0;
                $badge->status_payment = Paid::class;
                $badge->paid_at = now();
                $badge->save();

                $eventUser->user()->forcePay($badge);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
