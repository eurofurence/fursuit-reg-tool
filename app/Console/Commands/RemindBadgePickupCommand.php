<?php

namespace App\Console\Commands;

use App\Models\Badge\Badge;
use App\Models\Badge\State_Fulfillment\ReadyForPickup;
use App\Models\Event;
use App\Notifications\BadgePickupReminderNotification;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Nudge attendees whose badge is printed and still sitting at the desk.
 *
 * Deliberately **not** scheduled. The badge team has not settled when it should fire, and this is a
 * command that mails thousands of real people: it belongs in routes/console.php only once somebody has
 * decided the slot (the obvious one is the morning of the last full convention day, late enough to be
 * a real reminder and early enough to act on). Until then it is run by hand, and `--dry-run` shows
 * exactly who would hear from us.
 *
 * Four filters, and each one is here because sending without it would be worse than not sending:
 *
 *  - **Ready for Pickup only.** A badge that is not printed cannot be collected, and a picked-up badge
 *    needs no reminder.
 *  - **Once per badge.** `pickup_reminded_at` is stamped as we go, so a repeat run is a no-op rather
 *    than a second mail.
 *  - **During the convention.** A reminder to walk to a desk is noise before the doors open and after
 *    they close.
 *  - **Checked-in attendees only.** `event_users.valid_registration` is the closest thing we have to
 *    "this person is actually here"; without it we would chase people who never came.
 */
class RemindBadgePickupCommand extends Command
{
    protected $signature = 'badges:remind-pickup
                            {--dry-run : List who would be mailed and change nothing}
                            {--event= : Event id, defaults to the active event}
                            {--force : Send even when the event is not currently running}';

    protected $description = 'Remind attendees that their printed badge is still waiting at the desk';

    public function handle(): int
    {
        $event = $this->option('event')
            ? Event::find($this->option('event'))
            : Event::getActiveEvent();

        if ($event === null) {
            $this->error('No event to remind for.');

            return self::FAILURE;
        }

        $running = $event->starts_at !== null
            && $event->ends_at !== null
            && now()->between($event->starts_at, $event->ends_at);

        if (! $running && ! $this->option('force')) {
            $this->warn($event->name.' is not running right now, so nobody was reminded.');
            $this->line('Pass --force if you mean to send outside the convention.');

            return self::SUCCESS;
        }

        $query = $this->pending($event);
        $total = $query->count();

        if ($total === 0) {
            $this->info('Nothing to remind: every printed badge has been collected or already had its reminder.');

            return self::SUCCESS;
        }

        $dryRun = $this->option('dry-run');
        $sent = 0;

        $query->with(['fursuit.user', 'fursuit.event'])->chunkById(200, function ($badges) use (&$sent, $dryRun) {
            foreach ($badges as $badge) {
                $user = $badge->fursuit?->user;

                if ($user === null) {
                    continue;
                }

                if ($dryRun) {
                    $this->line(sprintf(
                        '  would remind %s about %s (%s)',
                        $user->name,
                        $badge->custom_id ?? '#'.$badge->id,
                        $badge->fursuit->name,
                    ));
                    $sent++;

                    continue;
                }

                $user->notify(new BadgePickupReminderNotification($badge));

                // Stamped per badge as we go rather than in one update at the end: a run that dies
                // halfway must not re-mail the people it already reached.
                $badge->forceFill(['pickup_reminded_at' => now()])->saveQuietly();
                $sent++;
            }
        });

        $this->info($dryRun
            ? $sent.' attendee(s) would be reminded.'
            : $sent.' attendee(s) reminded.');

        return self::SUCCESS;
    }

    /**
     * Printed, uncollected, not yet reminded, and belonging to somebody who checked in.
     */
    private function pending(Event $event): Builder
    {
        return Badge::query()
            ->whereState('status_fulfillment', ReadyForPickup::class)
            ->whereNull('pickup_reminded_at')
            ->whereHas('fursuit', fn (Builder $fursuit) => $fursuit
                ->where('event_id', $event->id)
                ->whereHas('user.eventUsers', fn (Builder $eventUser) => $eventUser
                    ->where('event_id', $event->id)
                    ->where('valid_registration', true)))
            ->orderBy('id');
    }
}
