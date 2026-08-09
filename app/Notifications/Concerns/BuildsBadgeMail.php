<?php

namespace App\Notifications\Concerns;

use App\Models\Badge\Badge;
use App\Models\Event;
use App\Models\Fursuit\Fursuit;
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
        string $band,
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
