<?php

namespace App\Notifications\Concerns;

use App\Models\Badge\Badge;
use App\Models\Event;
use App\Models\Fursuit\Fursuit;
use App\Support\DeskOpeningHours;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * The one place a badge mail is assembled.
 *
 * Every attendee-facing badge notification renders `resources/views/mail/badge.blade.php` through
 * this trait, so the decisions the badge team made in review hold for all of them instead of being
 * re-litigated per class: a status band first, the questions an attendee actually asks, the fursuit
 * name in the subject only and quoted, one button at most, no amounts, and no claim about printers.
 *
 * The three helpers below exist because each answers a question three or more notifications ask,
 * and each was previously answered differently in different classes.
 */
trait BuildsBadgeMail
{
    /**
     * @param  array<int, array{q: string, a: string}>  $answers
     * @param  array{label: string, url: string}|null  $action
     */
    protected function badgeMail(
        object $notifiable,
        string $subject,
        ?string $band,
        string $tone,
        string $headline,
        array $answers,
        ?array $action = null,
        ?string $finding = null,
        ?string $note = null,
        ?Fursuit $fursuit = null,
    ): MailMessage {
        return (new MailMessage)
            ->subject($subject)
            ->markdown('mail.badge', [
                // "Hi {name}!" everywhere. The seven mails used to disagree four ways, including
                // one that put a headline in the greeting slot.
                'greeting' => 'Hi '.$notifiable->name.'!',
                'band' => $band,
                'tone' => $tone,
                'headline' => $headline,
                'answers' => $answers,
                'finding' => $finding,
                'action' => $action,
                'note' => $note,
                'pickupUrl' => route('info.pickup'),
                'eventName' => $fursuit?->event?->name,
            ]);
    }

    /**
     * The subject's leading fragment: the fursuit's name, quoted.
     *
     * Quoted because a name is attendee-supplied and routinely carries punctuation, emoji or
     * underscores; in quotes that reads as a title rather than as part of our sentence. It leads so
     * a threaded client groups one badge's mails together.
     */
    protected function subjectFor(?Fursuit $fursuit, string $rest): string
    {
        $name = trim((string) $fursuit?->name);

        return $name === '' ? ucfirst($rest) : '"'.$name.'" - '.$rest;
    }

    /**
     * Whether anything is still owed on this badge.
     *
     * Drives the presence of the payment answer, never its amount. A free badge does not get the
     * question answered with "no", it does not get the question.
     */
    protected function hasSomethingToPay(?Badge $badge): bool
    {
        return $badge !== null && $badge->total > 0 && $badge->paid_at === null;
    }

    /**
     * When the attendee can collect, as a sentence.
     *
     * Day one hands out badges from the pre-print run only, so a badge that made the run needs no
     * explanation and gets none. A badge that missed it does: without the date, "second day" reads
     * as an arbitrary rule, and the missed deadline is what support gets asked about.
     * `mass_printed_at` is the same signal the attendee's badge page reads, and the wording there
     * matches this - change both together. An unset date reads as a run that is still ahead, the
     * same as a future one: an event with no run scheduled has not missed it.
     */
    protected function collectionAnswer(?Event $event): string
    {
        $massPrintedAt = $event?->mass_printed_at;

        if ($massPrintedAt === null || now()->lt($massPrintedAt)) {
            return 'From the first convention day.';
        }

        return 'The printing deadline ('.$massPrintedAt->format('j F Y').') has passed, so you can collect it from the second convention day.';
    }

    /**
     * The desk hours a reminder prints, and the sentence that goes under them.
     *
     * Printed in the mail rather than left to a link, because the question the desk gets asked
     * is "when are you open", and an attendee reading a nudge on their phone in a corridor
     * should not have to open a page to answer it. Only today and later are listed - see
     * DeskOpeningHours::upcoming() - so the list never opens with days that have gone.
     *
     * The last day gets its own line. "We are open today until 16:00" is the only piece of
     * this mail with a deadline in it, and on the day the desk closes for good it is the whole
     * point of sending: after that the badge waits a year.
     *
     * An event that publishes no hours gets no question. The alternative is a heading over an
     * empty list, or an invented time, and both are worse than the pickup page's link in the
     * footer, which every one of these mails already carries.
     *
     * Today is marked in the list rather than repeated above it. "We are open today until 16:00"
     * over a list whose first row already says today's times is the same fact twice, and read as a
     * contradiction the moment a later row carried a different time.
     *
     * The one sentence that is not in the list is the deadline: on the desk's last day the mail has
     * to say that it is the last day, because no list of times conveys that the next one is a year
     * away.
     *
     * @return array{q: string, hours: list<array{date: string, opens: string, closes: string, note: string|null, today: bool}>, a: string|null}|null
     */
    protected function deskHoursAnswer(?Event $event): ?array
    {
        $rows = DeskOpeningHours::upcoming($event);

        if ($rows === []) {
            return null;
        }

        $today = CarbonImmutable::now()->format('Y-m-d');

        $rows = array_map(
            fn (array $row) => [...$row, 'today' => $row['date'] === $today],
            $rows,
        );

        $closesToday = DeskOpeningHours::today($event)['closes'] ?? null;

        $note = $closesToday !== null && DeskOpeningHours::isLastDay($event)
            ? 'Today is the last day of the desk and we close at '.$closesToday.'. After that your badge waits for you at the next Eurofurence.'
            : null;

        return ['q' => 'When is the desk open?', 'hours' => $rows, 'a' => $note];
    }

    /**
     * Whether the attendee can still put something different on this badge.
     *
     * Two things close it: the card being committed to a print batch, which is what stops an edit
     * from producing a card that no longer matches the stack, and the card having been printed. Both
     * are checked, because a badge printed on site is not necessarily still locked.
     */
    protected function canStillChange(?Badge $badge): bool
    {
        return $badge !== null
            && $badge->printed_at === null
            && ! $badge->isPrintingLocked();
    }
}
