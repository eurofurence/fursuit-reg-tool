<?php

use App\Models\Badge\Badge;
use App\Models\Badge\State_Payment\Paid;
use App\Models\EventUser;
use App\Models\Fursuit\Fursuit;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $ef30UsersWithPrepaidBadges = EventUser::where('event_id', '=', 30)->where('prepaid_badges', '>', 0)->get();
        /** @var EventUser $eventUser */
        foreach ($ef30UsersWithPrepaidBadges as $eventUser) {
            // Check if the user is affected by the issue
            // eventUser.prepaid_badges - SUM(IF(badges.is_free_badge = 1, 1, 0)) > 0 AND SUM(IF(badges.is_free_badge = 0, 1, 0)) > 0
            $allowedPrepaidBadgesCount = $eventUser->prepaid_badges;

            $user = User::where('id', '=', $eventUser->user_id)->first();

            $eventFursuits = Fursuit::where('user_id', '=', $eventUser->user_id)->where('event_id', '=', 30)->get();
            /** @ */
            $eventBadges = Collection::make();
            foreach ($eventFursuits as $fursuit) {
                $badges = $fursuit->badges()->get();
                $eventBadges = $eventBadges->concat($badges);
            }
            $freeBadges = $eventBadges->where('is_free_badge', true);
            $paidBadges = $eventBadges->where('is_free_badge', false);

            if (! ($freeBadges->count() < $allowedPrepaidBadgesCount && $paidBadges->count() > 0)) {
                continue; // Not affected
            }

            $badgesToConvert = min($allowedPrepaidBadgesCount - $freeBadges->count(), $paidBadges->count());
            for ($i = 0; $i < $badgesToConvert; $i++) {
                /** @var Badge $badge */
                $badge = $paidBadges->skip($i)->first();
                if (! $badge) {
                    break;
                }

                $badge->is_free_badge = true;
                $badge->subtotal = 0;
                $badge->total = 0;
                $badge->tax = 0;
                $badge->status_payment = Paid::class;
                $badge->paid_at = now();
                $badge->save();

                $user->deposit(500, [
                    'title' => 'Refund for badge '.$badge->id,
                    'description' => 'Refund for badge '.$badge->id.' due to prepaid badge issue',
                    'event_id' => 30,
                ]);
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
