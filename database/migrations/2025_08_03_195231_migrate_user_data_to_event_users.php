<?php

use App\Models\Event;
use App\Models\EventUser;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Migrate existing user data to event_users table for EF28
        $ef28Event = Event::where('name', 'EF28')->first();
        if (! $ef28Event) {
            return; // No EF28 event to migrate to
        }

        $users = User::whereNotNull('attendee_id')
            ->orWhere('has_free_badge', true)
            ->orWhere('valid_registration', true)
            ->get();

        foreach ($users as $user) {
            $prepaidBadges = 0;
            if ($user->has_free_badge) {
                $prepaidBadges = 1 + ($user->free_badge_copies ?? 0);
            }

            EventUser::updateOrCreate([
                'user_id' => $user->id,
                'event_id' => $ef28Event->id,
            ], [
                'attendee_id' => $user->attendee_id ?? '',
                'valid_registration' => $user->valid_registration ?? false,
                'prepaid_badges' => $prepaidBadges,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Migrate data back to users table from event_users for EF28
        $ef28Event = Event::where('name', 'EF28')->first();
        if (! $ef28Event) {
            return;
        }

        $eventUsers = EventUser::where('event_id', $ef28Event->id)->get();

        foreach ($eventUsers as $eventUser) {
            $user = $eventUser->user;
            if ($user) {
                $user->update([
                    'attendee_id' => $eventUser->attendee_id,
                    'valid_registration' => $eventUser->valid_registration,
                    'has_free_badge' => $eventUser->prepaid_badges > 0,
                    'free_badge_copies' => max(0, $eventUser->prepaid_badges - 1),
                ]);
            }
        }
    }
};
