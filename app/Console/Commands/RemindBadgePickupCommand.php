<?php

namespace App\Console\Commands;

use App\Models\Badge\Badge;
use App\Models\BadgePickupReminderRun;
use App\Models\Event;
use App\Services\BadgePickupReminderService;
use App\Support\DeskOpeningHours;
use Illuminate\Console\Command;

/**
 * Nudge attendees whose badge is still sitting at the desk.
 *
 * Scheduled every minute and does nothing on almost every run: `--scheduled` fires only inside the
 * window after the "Remind at" time the desk set for today, on the On-Site Desk settings page. The
 * schedule therefore lives in the panel, next to the opening hours it has to agree with, rather
 * than in this file or in `routes/console.php` - the badge team retunes it between two convention
 * days without a deploy. See DeskOpeningHours::dueReminder() for the three conditions, and
 * DeskOpeningHours::remindableRows() for why the first desk day never sends.
 *
 * Who gets a mail is not decided here. BadgePickupReminderService owns that, because the badge
 * list's "Send today's reminders" button sends the same run, and two callers with two answers would
 * be an attendee mailed twice. This command decides only whether now is the moment.
 *
 * Both callers claim the day first, so whichever goes second sends nothing at all. `--force` skips
 * that claim, which is the escape hatch for a desk that genuinely wants a second sweep - the
 * per-attendee stamp still keeps anybody from hearing from us twice. `--dry-run` claims nothing and
 * sends nothing.
 */
class RemindBadgePickupCommand extends Command
{
    protected $signature = 'badges:remind-pickup
                            {--dry-run : List who would be mailed and change nothing}
                            {--event= : Event id, defaults to the active event}
                            {--force : Send outside the convention, and even if today already ran}
                            {--scheduled : Only send if the desk has a reminder due right now}';

    protected $description = 'Remind attendees that their badge is still waiting at the desk';

    public function handle(BadgePickupReminderService $reminders): int
    {
        $event = $this->option('event')
            ? Event::find($this->option('event'))
            : Event::getActiveEvent();

        if ($event === null) {
            // Silent on the schedule: no active event is the normal state for fifty weeks a year,
            // and an error a minute for fifty weeks is how a log stops being read.
            if ($this->option('scheduled')) {
                return self::SUCCESS;
            }

            $this->error('No event to remind for.');

            return self::FAILURE;
        }

        if ($this->option('scheduled')) {
            $due = DeskOpeningHours::dueReminder($event);

            if ($due === null) {
                return self::SUCCESS;
            }

            $this->info('Desk reminder due at '.$due['reminds_at'].' on '.$due['date'].'.');
        } elseif (! $this->running($event) && ! $this->option('force')) {
            $this->warn($event->name.' is not running right now, so nobody was reminded.');
            $this->line('Pass --force if you mean to send outside the convention.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            return $this->dryRun($reminders, $event);
        }

        $run = $this->claim($reminders, $event);

        if ($run === false) {
            return self::SUCCESS;
        }

        $result = $reminders->send($event, $run);

        $this->info($result['sent'].' attendee(s) reminded, '.$result['stamped'].' badge(s) stamped.');

        return self::SUCCESS;
    }

    /**
     * Take today, or report who already has it.
     *
     * Returns the run on success, null when `--force` deliberately skipped the claim, and false
     * when the day is taken and there is nothing to do.
     */
    private function claim(BadgePickupReminderService $reminders, Event $event): BadgePickupReminderRun|false|null
    {
        if ($this->option('force')) {
            return null;
        }

        $source = $this->option('scheduled')
            ? BadgePickupReminderRun::SOURCE_SCHEDULE
            : BadgePickupReminderRun::SOURCE_CONSOLE;

        $run = $reminders->claimToday($event, $source);

        if ($run !== null) {
            return $run;
        }

        $this->info($reminders->ranToday($event)?->describe() ?? 'Today has already been sent.');
        $this->line('Pass --force to send again anyway; nobody who has already been reminded will hear from us twice.');

        return false;
    }

    private function dryRun(BadgePickupReminderService $reminders, Event $event): int
    {
        $ran = $reminders->ranToday($event);

        if ($ran !== null) {
            $this->warn($ran->describe().' A real run would send nothing without --force.');
        }

        $badges = Badge::whereIn('id', $reminders->candidateIds($event))
            ->with(['fursuit.user'])
            ->orderBy('id')
            ->get();

        foreach ($badges as $badge) {
            $user = $badge->fursuit?->user;

            if ($user === null) {
                continue;
            }

            $this->line(sprintf(
                '  would remind %s about %s (%s)',
                $user->name,
                $badge->custom_id ?? '#'.$badge->id,
                $badge->fursuit->name,
            ));
        }

        $this->info($badges->count().' attendee(s) would be reminded.');

        return self::SUCCESS;
    }

    private function running(Event $event): bool
    {
        return $event->starts_at !== null
            && $event->ends_at !== null
            && now()->between($event->starts_at, $event->ends_at);
    }
}
