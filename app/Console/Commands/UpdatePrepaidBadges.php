<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Models\EventUser;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class UpdatePrepaidBadges extends Command
{
    protected $signature = 'prepaid:update {user_id?} {--event=} {--force}';

    protected $description = 'Update prepaid badges for users based on their fursuit packages';

    public function handle()
    {
        $userId = $this->argument('user_id');
        $eventId = $this->option('event');
        $force = $this->option('force');

        if ($eventId) {
            $event = Event::find($eventId);
            if (! $event) {
                $this->error("Event ID {$eventId} not found.");

                return 1;
            }
        } else {
            $event = Event::getActiveEvent();
            if (! $event) {
                $this->error('No active event found. Use --event=ID to specify an event.');

                return 1;
            }
        }

        $this->info("Using event: {$event->name} (ID: {$event->id})");

        if ($userId) {
            $this->updateUserPrepaidBadges($userId, $event, $force);
        } else {
            $this->info('Updating prepaid badges for all users with EventUser records...');
            $eventUsers = EventUser::where('event_id', $event->id)->get();

            foreach ($eventUsers as $eventUser) {
                $this->updateUserPrepaidBadges($eventUser->user_id, $event, $force);
            }
        }

        return 0;
    }

    private function updateUserPrepaidBadges($userId, $event, $force = false)
    {
        $user = User::find($userId);
        if (! $user) {
            $this->error("User ID {$userId} not found.");

            return;
        }

        $eventUser = $user->eventUser($event->id);
        if (! $eventUser) {
            $this->warn("No EventUser record found for user {$userId} in event {$event->id}");

            return;
        }

        // Skip if already has prepaid badges and not forcing
        if (! $force && $eventUser->prepaid_badges > 0) {
            $this->info("User {$userId} already has {$eventUser->prepaid_badges} prepaid badges. Use --force to override.");

            return;
        }

        // Check if user has valid tokens
        if (! $user->token) {
            $this->warn("User {$userId} has no valid token. Cannot check fursuit packages.");

            return;
        }

        try {
            $this->info("Checking fursuit packages for user {$userId} (attendee {$eventUser->attendee_id})...");

            $fursuit = Http::attsrv()
                ->withToken($user->token)
                ->get('/attendees/'.$eventUser->attendee_id.'/packages/fursuit')
                ->json();

            if ($fursuit['present'] && $fursuit['count'] > 0) {
                $fursuitAdditional = Http::attsrv()
                    ->withToken($user->token)
                    ->get('/attendees/'.$eventUser->attendee_id.'/packages/fursuitadd')
                    ->json();

                $additionalCopies = $fursuitAdditional['present'] ? $fursuitAdditional['count'] : 0;
                $totalPrepaidBadges = $fursuit['count'] + $additionalCopies;

                $eventUser->update([
                    'prepaid_badges' => $totalPrepaidBadges,
                ]);

                $this->info("✓ Updated user {$userId}: {$fursuit['count']} fursuit + {$additionalCopies} additional = {$totalPrepaidBadges} total prepaid badges");

                // Mark fursuitbadge as not created yet
                Http::attsrv()
                    ->withToken($user->token)
                    ->post('/attendees/'.$eventUser->attendee_id.'/additional-info/fursuitbadge', [
                        'created' => false,
                    ]);

            } else {
                $this->info("User {$userId} has no fursuit packages.");
                // Ensure prepaid_badges is 0 if no packages
                if ($eventUser->prepaid_badges > 0) {
                    $eventUser->update(['prepaid_badges' => 0]);
                    $this->info("✓ Reset user {$userId} prepaid badges to 0");
                }
            }

        } catch (\Exception $e) {
            $this->error("Error checking packages for user {$userId}: ".$e->getMessage());
        }
    }
}
