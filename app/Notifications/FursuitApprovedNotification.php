<?php

namespace App\Notifications;

use App\Models\Badge\Badge;
use App\Models\Fursuit\Fursuit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class FursuitApprovedNotification extends Notification
{
    private Badge $badge;

    public function __construct(public Fursuit $fursuit,public string $reason)
    {
        $this->badge = $this->fursuit->badges()->whereNull('extra_copy_of')->first();
    }

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)->salutation('')
            ->subject('[NO ACTION REQUIRED] Fursuit Badge Approved')
            ->line('We are happy to inform you that your badge has been approved.')
            ->line('We will print the badge and have it ready for you at the convention.')
            ->lineIf($this->badge->total > 0, 'We will ask you to pay the Badge fee when you pickup the Badge.')
            ->lineIf($this->badge->total > 0, 'We accept Cash (Preferred), Credit Card and EC Card. Please note that we do not accept American Express.')
            ->line('Please do not reply to this email. If you have any questions, please contact us at conops@eurofurence.org');
    }

    public function toArray($notifiable): array
    {
        return [];
    }
}
