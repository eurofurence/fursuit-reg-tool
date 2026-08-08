<?php

namespace App\Notifications;

use App\Models\Badge\Badge;
use App\Models\Fursuit\Fursuit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Your badge is approved, but it will not be shown in the gallery or the game."
 *
 * The half-good news that used to have no message of its own: a submission that follows
 * the Code of Conduct but is not a photo of a suit was rejected outright, so the attendee
 * was told to fix a badge that was in fact fine and would otherwise have been printed.
 *
 * So the wording leads with what does not change - the card is printed and handed out -
 * and only then explains what was turned down and that resubmitting is optional. It is
 * deliberately not an error mail: nothing is required of the attendee.
 */
class FursuitPublicationBlockedNotification extends Notification
{
    private ?Badge $badge;

    public function __construct(public Fursuit $fursuit, public string $reason)
    {
        $this->badge = $this->fursuit->badges()->whereNull('extra_copy_of')->first();
    }

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->salutation('')
            ->subject('[NO ACTION REQUIRED] Fursuit Badge Approved, Not Published')
            ->line('Your badge has been approved. We will print it and have it ready for you at the convention.')
            ->line('It will not appear in our online gallery and it cannot be caught in Fursuit Catch-Em-All.')
            ->line('Why:')
            ->line($this->reason);

        if ($this->badge !== null) {
            $mail
                ->line('If you would rather be in the gallery and the game, you can submit a different photo until we print your badge. Your badge is reviewed again afterwards.')
                ->action('Edit Badge', route('badges.edit', [
                    'badge' => $this->badge->id,
                ]));
        }

        return $mail
            ->line('Please do not reply to this email. If you have any questions, please contact us at fursuit-team@eurofurence.org');
    }

    public function toArray($notifiable): array
    {
        return [];
    }
}
