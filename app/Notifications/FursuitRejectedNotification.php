<?php

namespace App\Notifications;

use App\Models\Badge\Badge;
use App\Models\Fursuit\Fursuit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class FursuitRejectedNotification extends Notification
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
        return (new MailMessage)
            ->salutation('')
            ->subject('[ACTION REQUIRED] Fursuit Badge Rejected')
            ->line('We are sorry to inform you that your badge has been rejected. Please review the feedback provided and make the necessary changes.')
            ->error()
            ->line('Reason for rejection:')
            ->line($this->reason)
            ->line('Please click the button below to edit your badge and resubmit it for review.')
            ->action('Edit Badge', route('badges.edit', [
                'badge' => $this->badge->id,
            ]))
            ->line('Please do not reply to this email. If you have any questions, please contact us at conops@eurofurence.org');
    }

    public function toArray($notifiable): array
    {
        return [];
    }
}
