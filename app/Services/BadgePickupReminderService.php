<?php

namespace App\Services;

use App\Models\Badge\Badge;
use App\Models\Badge\State_Fulfillment\PickedUp;
use App\Models\BadgePickupReminderRun;
use App\Models\Event;
use App\Notifications\BadgePickupReminderNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The one place a day's pickup reminders are decided and sent.
 *
 * Three callers - the scheduler, the artisan command run by hand, and the button on the badge list -
 * and they must not answer "who gets a mail" differently, because the difference would be an
 * attendee mailed twice or not at all. Everything about who and how often lives here; the callers
 * only decide whether now is the moment.
 *
 * Two guards, at two different scopes, and both are needed:
 *
 *  - **The day**, `claimToday()`. Stops a second *run*: the button pressed after the schedule has
 *    already fired would otherwise mail everybody who became a candidate in the meantime.
 *  - **The person**, `badges.pickup_reminded_at`, stamped in `send()`. Stops a second *mail* to the
 *    same attendee for the rest of the convention, across every day and every caller.
 */
class BadgePickupReminderService
{
    /**
     * Take today for this event, or return null because somebody already has it.
     *
     * The insert is the claim. Two workers in the same minute both reach this line, the unique key
     * on (event_id, ran_on) lets exactly one of them through, and the loser is told the day is
     * gone - which is the answer it wanted anyway. Checking first and inserting after would leave
     * the gap this exists to close.
     */
    public function claimToday(Event $event, string $source, ?int $actorId = null): ?BadgePickupReminderRun
    {
        try {
            return BadgePickupReminderRun::create([
                'event_id' => $event->id,
                'ran_on' => now()->toDateString(),
                'source' => $source,
                'triggered_by' => $actorId,
                'attendees_notified' => 0,
                'ran_at' => now(),
            ]);
        } catch (QueryException $exception) {
            // Anything other than the day already being taken is a real fault and stays one.
            if (! $this->isDuplicate($exception)) {
                throw $exception;
            }

            return null;
        }
    }

    /** Today's run for this event, if it has happened. */
    public function ranToday(Event $event): ?BadgePickupReminderRun
    {
        return BadgePickupReminderRun::where('event_id', $event->id)
            ->whereDate('ran_on', now()->toDateString())
            ->with('triggeredBy')
            ->first();
    }

    /**
     * Mail everybody this event still owes a reminder, one mail each.
     *
     * @return array{sent: int, stamped: int}
     */
    public function send(Event $event, ?BadgePickupReminderRun $run = null): array
    {
        $sent = 0;
        $stamped = 0;

        foreach (array_chunk($this->candidateIds($event), 200) as $chunk) {
            $badges = Badge::whereIn('id', $chunk)
                ->with(['fursuit.user', 'fursuit.event'])
                ->orderBy('id')
                ->get();

            foreach ($badges as $badge) {
                $user = $badge->fursuit?->user;

                if ($user === null) {
                    continue;
                }

                $user->notify(new BadgePickupReminderNotification($badge));

                // Stamped per attendee as we go rather than in one update at the end: a run that
                // dies halfway must not re-mail the people it already reached. Every uncollected
                // badge this person holds is stamped, not just the one named in the mail, which is
                // what keeps the next day's run from mailing them again about the other one.
                $stamped += $this->stamp($event, (int) $badge->fursuit->user_id);
                $sent++;
            }
        }

        $run?->update(['attendees_notified' => $sent]);

        return ['sent' => $sent, 'stamped' => $stamped];
    }

    /**
     * One badge id per attendee: the oldest uncollected badge each of them is still owed.
     *
     * Grouped in SQL rather than by loading every candidate and deduplicating in PHP, because this
     * runs against the whole convention and the answer is one row per person either way. The oldest
     * badge is the one the mail names, which is the one they have been waiting on longest.
     *
     * @return list<int>
     */
    public function candidateIds(Event $event): array
    {
        return $this->pending($event)
            ->join('fursuits as reminder_fursuits', 'reminder_fursuits.id', '=', 'badges.fursuit_id')
            ->groupBy('reminder_fursuits.user_id')
            ->select(DB::raw('MIN(badges.id) as badge_id'))
            ->orderBy('badge_id')
            ->pluck('badge_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Uncollected, not yet reminded, and belonging to somebody who checked in.
     *
     * A picked-up badge needs no reminder. Everything short of that is worth a nudge, printed or
     * not: a card that has not been made yet is made at the desk on demand, and the mail says so
     * rather than claiming it is waiting on the counter. `event_users.valid_registration` is the
     * closest thing we have to "this person is actually here"; without it we would chase people who
     * never came.
     */
    public function pending(Event $event): Builder
    {
        return Badge::query()
            ->whereNotState('status_fulfillment', PickedUp::class)
            ->whereNull('pickup_reminded_at')
            ->whereHas('fursuit', fn (Builder $fursuit) => $fursuit
                ->where('event_id', $event->id)
                ->whereHas('user.eventUsers', fn (Builder $eventUser) => $eventUser
                    ->where('event_id', $event->id)
                    ->where('valid_registration', true)));
    }

    /**
     * Mark every uncollected, unreminded badge this attendee holds at this event as reminded.
     *
     * A plain update rather than a save per model: nothing here is a state change, the column is
     * not part of either state machine, and the row count is one person's badges.
     */
    private function stamp(Event $event, int $userId): int
    {
        return $this->pending($event)
            ->whereHas('fursuit', fn (Builder $fursuit) => $fursuit->where('user_id', $userId))
            ->update(['pickup_reminded_at' => now()]);
    }

    /**
     * Whether this is the unique key refusing a second claim on the same day.
     *
     * Read off the driver's own code rather than the message, which is worded differently by MySQL,
     * MariaDB and SQLite and is what the test suite runs on.
     */
    private function isDuplicate(QueryException $exception): bool
    {
        return in_array((string) ($exception->errorInfo[1] ?? ''), ['1062', '19', '2067'], true)
            || str_contains(strtolower($exception->getMessage()), 'unique');
    }
}
