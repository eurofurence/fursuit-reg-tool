<?php

namespace App\Notifications;

use App\Models\Badge\Badge;
use App\Models\Badge\State_Fulfillment\Printed;
use App\Models\Badge\State_Fulfillment\ReadyForPickup;
use App\Notifications\Concerns\BuildsBadgeMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Your badge is still waiting for you."
 *
 * A nudge for a badge nobody has collected, sent by `badges:remind-pickup` or by the badge list's
 * bulk action. The only badge mail with no status band: this one is not reporting a change, and the
 * headline already carries the whole message, so a band over it just says the same thing twice.
 *
 * The headline carries the location and the "where do I go" answer repeats it, deliberately. The
 * headline is what a phone preview shows and the answer is what somebody scanning the questions
 * looks for; the desk would rather say the room twice than have either reader miss it.
 *
 * The mail is sent for any uncollected badge, not only a printed one, so the headline is read off
 * the fulfillment state. A card that has not been printed yet is not "at our desk", and telling
 * somebody it is sends them to a counter that cannot hand them anything; those badges get the
 * collection sentence the rest of the mails use instead.
 *
 * The opening hours are printed in the mail rather than linked. "When are you open" is the question
 * the desk is asked most, and a nudge read on a phone in a corridor should answer it without a tap.
 * On the desk's last day the hours carry the deadline line, which is the whole reason to send.
 *
 * There is no "anything to bring" answer. This mail exists to get somebody to walk to the desk;
 * payment and what to carry are the desk's conversation, not the reminder's.
 *
 * The last answer is a promise the desk makes in writing: an uncollected badge is kept for the next
 * convention. That matches what the app already does, since the attendee's badge page lists
 * uncollected badges from previous years.
 */
class BadgePickupReminderNotification extends Notification implements ShouldQueue
{
    use BuildsBadgeMail, Queueable;

    public function __construct(public Badge $badge) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $fursuit = $this->badge->fursuit;
        $event = $fursuit?->event;

        return $this->badgeMail(
            notifiable: $notifiable,
            subject: $this->subjectFor($fursuit, 'your badge is still waiting for you!'),
            band: null,
            tone: 'warn',
            headline: $this->waiting()
                ? 'Your badge is printed and still waiting at our desk in the Fursuit Lounge.'
                : 'Your badge has not been collected yet. It waits for you at our desk in the Fursuit Lounge.',
            answers: array_values(array_filter([
                [
                    'q' => 'Where do I go?',
                    'a' => 'The badge desk in the Fursuit Lounge.',
                ],
                $this->deskHoursAnswer($event),
                $this->waiting() ? null : [
                    'q' => 'When can I collect it?',
                    'a' => $this->collectionAnswer($event),
                ],
                [
                    'q' => 'What if I cannot make it?',
                    'a' => 'We keep your badge until the next Eurofurence, so you can collect it there next year.',
                ],
            ])),
            action: [
                'label' => 'Pickup information',
                'url' => route('info.pickup'),
            ],
            fursuit: $fursuit,
        );
    }

    /**
     * Whether the card is finished and sitting at the desk right now.
     *
     * Both printed states count. `Printed` is a card off the printer that has not been booked in
     * yet, and from the attendee's side that is the same errand as `ReadyForPickup`: the badge
     * exists, it is at the desk, walk over. Anything earlier is still being made.
     */
    private function waiting(): bool
    {
        return in_array(
            $this->badge->status_fulfillment->getValue(),
            [ReadyForPickup::$name, Printed::$name],
            true,
        );
    }

    public function toArray($notifiable): array
    {
        return [];
    }
}
