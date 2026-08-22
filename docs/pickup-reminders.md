# Pickup reminders, and the sent-mail log

Two things that grew out of one question at the desk: "did we tell them their badge is still here,
and when?"

## The mail

`App\Notifications\BadgePickupReminderNotification`, rendered through `BuildsBadgeMail` and
`resources/views/mail/badge.blade.php` like every other badge mail.

Decisions that were argued once and should not be re-argued silently:

- **No status band.** Every other badge mail reports a change and gets a coloured band. This one
  reports nothing new, and the headline already carries the whole message.
- **The headline names the room.** "Your badge is printed and still waiting at our desk in the
  Fursuit Lounge." A phone preview shows the first line and little else. The "Where do I go?" answer
  repeats the room on purpose: the two readers - preview skimmer and question scanner - are
  different people.
- **State-aware headline.** A badge that is not printed yet is not "at our desk"; it says "has not
  been collected yet" instead and gains a "When can I collect it?" answer.
- **Opening hours are printed, not linked.** Today onward only, today marked `(today)`. On the
  desk's last day, and only then, a sentence above the list says so: that is the deadline, and no
  list of times conveys that the next one is a year away.
- **No "anything to bring" answer.** The mail exists to get somebody to walk to the desk; payment is
  the desk's conversation.

## When it sends

`badges:remind-pickup --scheduled`, from `routes/console.php`, every minute. Almost every run does
nothing.

The schedule lives in the panel, not in the code: **Settings > On-Site Desk**, the "Remind at"
column on the opening-hours rows. The badge team retunes it between two convention days without a
deploy, which is the whole point.

`DeskOpeningHours::dueReminder()` decides, and all three conditions must hold:

1. today's row has a `reminds_at`, and today is **not** the first published desk day
   (`remindableRows()` drops it - the desk opens that day, everybody is collecting for the first
   time, and nobody is late yet);
2. that time has passed, by no more than 15 minutes, so a scheduler that was down all afternoon
   does not fire the day's mail at nine in the evening;
3. the desk is still open.

`OnSiteDeskController::validateReminders()` refuses a time on the first day and a time outside that
day's own hours, with the error addressed to the row the operator typed rather than the row it
sorted into.

## Sending it by hand

**Send Today's Reminders**, the page action at the top of the badge list
(`BadgePickupReminderController::pageAction()`, `manage-admin` only). Same run the scheduler makes:
everybody owed a reminder, not a selection. For the morning the schedule was wrong, or a day the
desk chose not to schedule at all.

It cannot go out twice in a day. Both callers claim the day first, by inserting into
`badge_pickup_reminder_runs`, whose unique key on `(event_id, ran_on)` lets exactly one of them
through - a database constraint rather than check-then-insert, because two workers can run the same
minute. The loser is told who has it: "Today's reminders were already sent at 15:39 by Rusty."

The claim and the per-attendee stamp are different guards at different scopes, and both are needed:

| Guard | Scope | Stops |
|---|---|---|
| `badge_pickup_reminder_runs` | one event-day | a second **run** mailing everyone who became a candidate since the first |
| `badges.pickup_reminded_at` | the whole convention | a second **mail** to the same person, ever |

`badges:remind-pickup --force` skips the day claim, for a desk that genuinely wants a second sweep;
the per-attendee stamp still holds, so nobody hears from us twice. `--dry-run` claims nothing.

The **bulk** action on selected rows is deliberately outside all of this: it is the operator aiming
at rows they picked, so it stays available after the day's sweep has gone out.

## Who gets it

`BadgePickupReminderService::pending()`: any badge that is not `PickedUp`, has no
`pickup_reminded_at`, belongs to this event, and whose owner has `event_users.valid_registration`.

Two dedupes, and they are not the same one:

- **Once per badge** - `pickup_reminded_at`, stamped as the run goes, so tomorrow's run is not a
  second mail.
- **Once per person** - `candidateIds()` groups in SQL and mails about the oldest uncollected
  badge; `stamp()` then marks *every* uncollected badge that person holds. Somebody with three
  badges gets one mail, not three.

Who gets a mail lives in `App\Services\BadgePickupReminderService` and nowhere else: three callers -
the scheduler, the command, the button - and a difference between any two of them would be an
attendee mailed twice or not at all.

The stamp and the day claim are the guards. The sent-mail log below is never consulted to decide
whether to send: failing to write paperwork must not turn into a second mail.

By hand, without `--scheduled`, it is the old command: `--dry-run` names everybody it would mail,
`--force` sends outside the convention, `--event=` picks the event.

## The desk's own selection button

**Send Pickup Reminder** on the badge list (`BadgePickupReminderController`, `manage-admin` only)
sends the same mail to a selection. It is the operator's decision, so it asks the record almost
nothing: it skips collected badges and badges with no attendee, and deliberately **does** re-send to
somebody already reminded - a second nudge on the last morning is a thing the desk wants, and the
person pressing the button knows more than a timestamp does.

## The sent-mail log

`sent_notifications`, written by `App\Listeners\LogSentNotification` from the framework's
`NotificationSent`. That means every notification the app has, and every one it grows, without
anybody remembering to log it.

**Laravel auto-discovers listeners in `app/Listeners`.** Do not also register this one with
`Event::listen()`: it was, once, and every mail was logged twice.

Each row carries the notifiable, the notification class, the channel, the subject the recipient saw,
and - by reflecting over the notification's public properties - the record it was about, so a row
can point back at a badge or a fursuit. The subject comes off the message the mailer actually sent;
only when there is no such message (a faked mailer, a non-mail channel) is the notification asked to
render its subject again.

Nothing here may break a send: every failure is swallowed into `Log::warning`. The mail is the
product, this is the paperwork.

Read back on **Settings > Users > (account)**, newest 25, read-only.
