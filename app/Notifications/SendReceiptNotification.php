<?php

namespace App\Notifications;

use App\Domain\Checkout\Models\Checkout\Checkout;
use App\Notifications\Concerns\BuildsBadgeMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Attachment;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The receipt for a paid checkout, with the PDF attached.
 *
 * No fursuit name in the subject: a checkout can cover several badges, so naming one of them would be
 * wrong more often than it is right. This is also the only badge mail with no action button - there is
 * nothing to do with a receipt.
 */
class SendReceiptNotification extends Notification implements ShouldQueue
{
    use BuildsBadgeMail, Queueable;

    public function __construct(public Checkout $checkout) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return $this->badgeMail(
            notifiable: $notifiable,
            subject: 'Your receipt for Eurofurence fursuit badges',
            band: 'Receipt attached',
            tone: 'info',
            headline: 'Thanks, that is paid.',
            answers: [
                [
                    'q' => 'What is attached?',
                    'a' => 'Your receipt as a PDF, for your records.',
                ],
                [
                    'q' => 'Something looks wrong?',
                    'a' => 'Come back to the badge desk while you are on site and we can correct it there.',
                ],
            ],
        )->attach(Attachment::fromStorage('checkouts/'.$this->checkout->id.'.pdf'));
    }

    public function toArray($notifiable): array
    {
        return [];
    }
}
