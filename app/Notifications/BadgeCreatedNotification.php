<?php

namespace App\Notifications;

use App\Models\Badge\Badge;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BadgeCreatedNotification extends Notification
{
    public function __construct(public Badge $badge) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->salutation('')
            ->subject('[NO ACTION REQUIRED] Fursuit Badge Pending Review')
            ->line('Thank you for submitting your badge for review. We will notify you once your badge has been approved or if we need any additional information.')
            ->line('Please do not reply to this email. If you have any questions, please contact us at conops@eurofurence.org')
            ->action('Edit Badge', route('badges.edit', [
                'badge' => $this->badge->id,
            ]))
            ->line('Thank you for your patience and understanding.')
            ->greeting('Badge Pending Review');
    }

    public function toArray($notifiable): array
    {
        return [];
    }
}
