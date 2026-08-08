<?php

namespace App\Notifications;

use App\Models\Badge\Badge;
use App\Notifications\Concerns\BuildsBadgeMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Your badge is still waiting for you."
 *
 * A nudge for a printed badge nobody has collected, sent by `badges:remind-pickup` during the
 * convention. The amber band rather than green is deliberate: it sits in the inbox next to the green
 * "ready for pickup" mail and has to read as a follow-up, not a repeat.
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

        return $this->badgeMail(
            notifiable: $notifiable,
            subject: $this->subjectFor($fursuit, 'your badge is still waiting for you!'),
            band: 'Still waiting for you',
            tone: 'warn',
            headline: 'Your badge is printed and still at our desk.',
            answers: [
                [
                    'q' => 'Where do I go?',
                    'a' => 'The badge desk in the Fursuit Lounge. Opening hours are on the pickup page.',
                ],
                [
                    'q' => 'Anything to bring?',
                    'a' => $this->hasSomethingToPay($this->badge)
                        ? 'A card for what is left to pay, we do not accept cash. You do not need your fursuit with you.'
                        : 'Nothing to pay, and you do not need your fursuit with you.',
                ],
                [
                    'q' => 'What if I cannot make it?',
                    'a' => 'We keep your badge until the next Eurofurence, so you can collect it there next year.',
                ],
            ],
            action: [
                'label' => 'Pickup information',
                'url' => route('info.pickup'),
            ],
            fursuit: $fursuit,
        );
    }

    public function toArray($notifiable): array
    {
        return [];
    }
}
