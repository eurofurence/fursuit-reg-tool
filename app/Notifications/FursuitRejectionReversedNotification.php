<?php

namespace App\Notifications;

use App\Models\Fursuit\Fursuit;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class FursuitRejectionReversedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Fursuit $fursuit)
    {
        //
    }

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your Fursuit Badge Has Been Approved - Rejection Reversed')
            ->greeting('Hello '.$notifiable->name.'!')
            ->line('You have received an email that your badge was rejected and we have determined that this was in error.')
            ->line('We apologize and your badge "'.$this->fursuit->name.'" is now considered approved.')
            ->line('We see you to see at Eurofurence!')
            ->action('View Your Badge', route('badges.index'))
            ->line('Thank you for your patience and understanding.')
            ->salutation('Best regards, The Eurofurence Team');
    }

    public function toArray($notifiable): array
    {
        return [
            'fursuit_id' => $this->fursuit->id,
            'fursuit_name' => $this->fursuit->name,
            'message' => 'Your previously rejected fursuit badge has been approved after review.',
        ];
    }
}
