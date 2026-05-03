<?php

use App\Models\EventUser;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $ef30Users = EventUser::where('event_id', '=', 30)->get();

        foreach ($ef30Users as $eventUser) {
            $userWallet = $eventUser->user->wallet;

            $badges = $eventUser->user->fursuits()->where('event_id', '=', 30)->get()->flatMap(function ($fursuit) {
                return $fursuit->badges()->whereNull('deleted_at')->where('status_payment', '=', 'unpaid')->get();
            });

            $balance = $badges->reduce(function ($carry, $badge) {
                return $carry + ($badge->is_free_badge ? 0 : $badge->total);
            }, 0);

            if ($userWallet->getBalanceAttribute() != -$balance) {
                $diff = -$balance - $userWallet->getBalanceAttribute();
                if ($diff > 0) {
                    $eventUser->user->deposit($diff);
                }
                if ($diff < 0) {
                    $eventUser->user->forceWithdraw(abs($diff));
                }

            }
        }

        /* DEBUGGING OUTPUT */
        /*
        echo "UserID, EventUserID, AttendeeId, Wallet Balance, Actual Balance\n";

        foreach ($ef30Users as $eventUser) {
            $userWallet = $eventUser->user->wallet;

            $badges = $eventUser->user->fursuits()->where('event_id', '=', 30)->get()->flatMap(function ($fursuit) {
                return $fursuit->badges()->whereNull('deleted_at')->where('status_payment', '=', 'unpaid')->get();
            });

            $balance = $badges->reduce(function ($carry, $badge) {
                return $carry + ($badge->is_free_badge ? 0 : $badge->total);
            }, 0);
            if ($userWallet->getBalanceAttribute() != -$balance) {
                echo "{$eventUser->user_id}, {$eventUser->id}, {$eventUser->attendee_id}, {$userWallet->getBalanceAttribute()}, -{$balance}\n";
            }
        }
        */
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
