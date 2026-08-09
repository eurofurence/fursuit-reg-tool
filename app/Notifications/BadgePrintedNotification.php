<?php

namespace App\Notifications;

use App\Models\Badge\Badge;
use App\Notifications\Concerns\BuildsBadgeMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Your badge is printed and waiting for you."
 *
 * Sent when a badge reaches Ready for Pickup, during the convention only.
 *
 * No booth or queue information: the booth split exists on day one only, so it reads as nonsense in a
 * mail opened on day three, and the pickup page carries whatever is current. The payment answer is
 * present only when something is owed, and it never names an amount.
 */
class BadgePrintedNotification extends Notification implements ShouldQueue
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
            subject: $this->subjectFor($fursuit, 'your badge is ready to collect'),
            band: 'Ready for pickup',
            tone: 'ok',
            headline: 'Your badge is printed and waiting for you.',
            answers: [
                [
                    'q' => 'Where?',
                    'a' => 'The badge desk in the Fursuit Lounge, during its opening hours.',
                ],
                [
                    'q' => 'What do I bring?',
                    'a' => $this->hasSomethingToPay($this->badge)
                        ? 'A card, we do not accept cash. You do not need your fursuit with you.'
                        : 'Nothing to pay for this one, and you do not need your fursuit with you.',
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
