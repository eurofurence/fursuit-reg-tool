<?php

namespace App\Notifications;

use App\Models\Badge\Badge;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BadgePrintedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public Badge $badge
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $fursuitName = $this->badge->fursuit->name;
        $badgeId = $this->badge->custom_id;
        $eventName = $this->badge->fursuit->event->name;
        
        return (new MailMessage)
                    ->subject("Your {$fursuitName} badge is ready for pickup!")
                    ->greeting("Hello {$notifiable->name},")
                    ->line("Great news! Your fursuit badge for **{$fursuitName}** (Badge ID: {$badgeId}) has been printed and is ready for pickup.")
                    ->line("📍 **Pickup Location:** Fursuit Lounge")
                    ->line("🕐 **Opening Hours:** Available on the {$eventName} Schedule")
                    ->line("💳 **Payment Preference:** Card payments are highly preferred for faster processing!")
                    ->line("Please bring your attendee badge or ID when collecting your fursuit badge.")
                    ->line("Thank you for participating in {$eventName}!");
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'badge_id' => $this->badge->id,
            'fursuit_name' => $this->badge->fursuit->name,
            'badge_custom_id' => $this->badge->custom_id,
        ];
    }
}