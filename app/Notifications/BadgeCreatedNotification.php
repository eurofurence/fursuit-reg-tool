<?php

namespace App\Notifications;

use App\Models\Badge\Badge;
use App\Notifications\Concerns\BuildsBadgeMail;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "We have your badge and it is in the review queue."
 *
 * Sent the moment a badge is ordered. One version for everybody.
 */
class BadgeCreatedNotification extends Notification
{
    use BuildsBadgeMail, Queueable;

    public function __construct(public Badge $badge) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $fursuit = $this->badge->fursuit;

        return $this->badgeMail(
            notifiable: $notifiable,
            subject: $this->subjectFor($fursuit, 'badge received, waiting for review'),
            band: 'Waiting for review',
            tone: 'info',
            headline: 'We have your badge and it is in the review queue.',
            answers: [
                [
                    'q' => 'What happens now?',
                    'a' => 'Our team checks every submission before printing. You get one more email when that is done.',
                ],
                [
                    'q' => 'How long does that take?',
                    'a' => 'Usually a day or two. During the convention you can come straight to the badge desk, we review it there and you can take it with you.',
                ],
                [
                    'q' => 'Can I still change it?',
                    'a' => 'Yes, the photo, the name and the species, right up until we print it.',
                ],
            ],
            action: [
                'label' => 'Edit badge',
                'url' => route('badges.edit', ['badge' => $this->badge->id]),
            ],
            fursuit: $fursuit,
        );
    }

    public function toArray($notifiable): array
    {
        return [];
    }
}
