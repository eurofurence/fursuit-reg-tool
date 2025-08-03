<?php

namespace App\Notifications;

use App\Domain\Checkout\Models\Checkout\Checkout;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Attachment;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SendReceiptNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Checkout $checkout) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->salutation('')
            ->subject('Your Eurofurence e.V. Fursuit Badge Receipt')
            ->line('Attached you will find your receipt for your Fursuit Badge purchase.')
            ->attach(Attachment::fromStorage('checkouts/'.$this->checkout->id.'.pdf'));
    }

    public function toArray($notifiable): array
    {
        return [];
    }
}
