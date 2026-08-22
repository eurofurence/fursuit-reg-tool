<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Http\Requests\Manage\BadgePickupReminderRequest;
use App\Models\Badge\Badge;
use App\Models\Badge\State_Fulfillment\PickedUp;
use App\Models\BadgePickupReminderRun;
use App\Models\Event;
use App\Notifications\BadgePickupReminderNotification;
use App\Services\BadgePickupReminderService;
use App\Support\Manage\Action;
use App\Support\Manage\Status;
use App\Support\Manage\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

/**
 * "Send pickup reminder" on the badge list.
 *
 * The same mail `badges:remind-pickup` sends, aimed by hand. The command decides for itself who
 * hears from us - printed, unreminded, checked in, during the convention - and that is right for an
 * unattended run over the whole event. At the desk the operator is the filter: they have the list
 * in front of them, they have set it up with the panel's own filters, and the selection is the
 * decision. So this endpoint asks the record almost nothing.
 *
 * The one refusal it keeps is a collected badge. Telling somebody their badge is waiting when they
 * are holding it is the single mistake that costs the desk a support conversation, and it is the
 * one an operator makes by ticking the header checkbox on an unfiltered list.
 *
 * The page action beside it, `today()`, is the other half: not a selection, but the whole day's
 * send, claimed against the scheduler so the two cannot both go out.
 *
 * Everything else is sent, including a badge that has already been reminded. A second nudge on the
 * last morning is a thing the desk deliberately wants, and the operator pressing the button knows
 * more about whether it is warranted than a timestamp does. `pickup_reminded_at` is still stamped,
 * so the unattended command never doubles up on what was sent here, and the toast names how many of
 * the selection had heard from us before.
 */
class BadgePickupReminderController extends Controller
{
    private const BULK_LABEL = 'Send Pickup Reminder';

    private const BULK_HEADING = 'Send pickup reminder';

    private const BULK_DESCRIPTION = 'Emails the selected attendees that their badge is still waiting at the desk, with the desk opening hours. Badges that have already been picked up are skipped. This sends real mail immediately.';

    private const TODAY_LABEL = 'Send Today\'s Reminders';

    private const TODAY_HEADING = 'Send today\'s pickup reminders';

    private const TODAY_DESCRIPTION = 'Runs the day\'s reminder now, for everybody with an uncollected badge who has not already been reminded - the same send the desk hours schedule makes. It can only happen once a day: if the schedule has already sent today, this does nothing.';

    /**
     * The bulk action, or null when this operator may not mail attendees.
     */
    public static function bulkAction(): ?Action
    {
        if (! Gate::allows('manage-admin')) {
            return null;
        }

        return Action::post('remindPickupBulk', self::BULK_LABEL, route('admin.badges.bulk.remind'))
            ->icon('mail')
            ->tone(Status::WARN)
            ->confirm(self::BULK_HEADING, self::BULK_DESCRIPTION, 'Send');
    }

    /**
     * "Send today's reminders", the page action beside the list.
     *
     * The same run the scheduler makes, set off by hand - for the morning the schedule was wrong,
     * or the day somebody wants it out before the desk fills up. It is a page action rather than a
     * bulk one because it is not about a selection: it is today's send, for everybody who is owed
     * one, which is exactly what the scheduler would have done.
     *
     * Offered whether or not today has a "Remind at" time. A day the desk chose not to schedule is
     * still a day somebody may decide to sweep, and the day claim below is what keeps that decision
     * from turning into a second send.
     */
    public static function pageAction(): ?Action
    {
        if (! Gate::allows('manage-admin')) {
            return null;
        }

        return Action::post('remindPickupToday', self::TODAY_LABEL, route('admin.badges.remind-today'))
            ->icon('mail')
            ->tone(Status::WARN)
            ->confirm(self::TODAY_HEADING, self::TODAY_DESCRIPTION, 'Send');
    }

    public function today(BadgePickupReminderService $reminders): RedirectResponse
    {
        Gate::authorize('manage-admin');

        $event = Event::getActiveEvent();

        if (! $event instanceof Event) {
            Toast::flashDanger('Nothing sent', 'There is no active event to remind for.');

            return back();
        }

        // The claim is the guard, and it is shared with the scheduler: whichever of the two gets
        // here first has today, and the other one is told so rather than sending again.
        $run = $reminders->claimToday($event, BadgePickupReminderRun::SOURCE_MANUAL, auth()->id());

        if ($run === null) {
            $already = $reminders->ranToday($event);

            Toast::flashDanger(
                'Already sent today',
                ($already?->describe() ?? 'Today has already been sent.')
                    .' Nobody was mailed twice.',
            );

            return back();
        }

        $result = $reminders->send($event, $run);

        Log::info('Pickup reminders sent for the day from the badge list', [
            'event_id' => $event->id,
            'sent' => $result['sent'],
            'actor_id' => auth()->id(),
        ]);

        if ($result['sent'] === 0) {
            Toast::flashSuccess(
                'Nothing to send',
                'Everybody with an uncollected badge has already had their reminder.',
            );

            return back();
        }

        Toast::flashSuccess(
            'Reminders sent',
            $result['sent'] === 1
                ? '1 attendee reminded. Today is now done, so the schedule will not send again.'
                : $result['sent'].' attendees reminded. Today is now done, so the schedule will not send again.',
        );

        return back();
    }

    public function bulk(BadgePickupReminderRequest $request): RedirectResponse
    {
        $badges = Badge::whereIn('id', $request->validated('ids'))
            ->with(['fursuit.user', 'fursuit.event'])
            ->orderBy('id')
            ->get();

        if ($badges->isEmpty()) {
            Toast::flashDanger('Nothing sent', 'None of the selected badges still exist.');

            return back();
        }

        $collected = $badges->filter(
            fn (Badge $badge) => $badge->status_fulfillment->getValue() === PickedUp::$name
        );

        $sendable = $badges->reject(
            fn (Badge $badge) => $badge->status_fulfillment->getValue() === PickedUp::$name
                || $badge->fursuit?->user === null
        );

        $repeats = $sendable->filter(fn (Badge $badge) => $badge->pickup_reminded_at !== null);

        foreach ($sendable as $badge) {
            $badge->fursuit->user->notify(new BadgePickupReminderNotification($badge));

            // Stamped per badge as we go, exactly as the command does: a run that dies halfway
            // must not re-mail the people it already reached, and the unattended command reads
            // this column to decide who it still owes a nudge.
            $badge->forceFill(['pickup_reminded_at' => now()])->saveQuietly();
        }

        Log::info('Pickup reminders sent from the badge list', [
            'badge_ids' => $sendable->pluck('id')->all(),
            'actor_id' => auth()->id(),
        ]);

        if ($sendable->isEmpty()) {
            Toast::flashDanger('Nothing sent', $this->skippedBody($badges->count(), $collected->count()));

            return back();
        }

        Toast::flashSuccess(
            $sendable->count() === 1 ? 'Reminder sent' : 'Reminders sent',
            $this->sentBody($badges->count(), $sendable->count(), $collected->count(), $repeats->count()),
        );

        return back();
    }

    /**
     * What the send reports back, including what it left alone.
     *
     * The skipped and repeated counts are named rather than folded into the total, for the reason
     * the bulk status write names its own: being told fifty mails went out when forty were skipped
     * reads as a much bigger send than it was, and a repeat is the one an operator wants to know
     * about after the fact.
     */
    private function sentBody(int $selected, int $sent, int $collected, int $repeats): string
    {
        $body = $sent === 1 ? '1 reminder sent.' : $sent.' reminders sent.';

        if ($collected > 0) {
            $body .= ' '.$collected.' already picked up, so '.($collected === 1 ? 'it was' : 'they were').' skipped.';
        }

        $missing = $selected - $sent - $collected;

        if ($missing > 0) {
            $body .= ' '.$missing.' had no attendee to mail.';
        }

        if ($repeats > 0) {
            $body .= ' '.$repeats.' had already been reminded before.';
        }

        return $body;
    }

    private function skippedBody(int $selected, int $collected): string
    {
        if ($collected === $selected) {
            return $selected === 1
                ? 'That badge has already been picked up.'
                : 'All '.$selected.' selected badges have already been picked up.';
        }

        return 'None of the selected badges have an attendee to mail.';
    }
}
