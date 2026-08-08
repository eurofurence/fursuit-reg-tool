<?php

namespace App\Notifications;

use App\Models\Badge\Badge;
use App\Models\Fursuit\Fursuit;
use App\Notifications\Concerns\BuildsBadgeMail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "We cannot print your badge as it is."
 *
 * Deliberately not about photos: what a reviewer refuses can be the image, the name or the species,
 * so the mail names none of them and the instruction is simply to fix the badge. The reviewer's own
 * sentence is the explanation, which is why - unlike the publication block - this mail carries the
 * specific finding inline. The attendee is being asked to change something, so they need to know
 * what.
 *
 * It opens by saying the badge is not lost, because that is the first thing anybody assumes.
 */
class FursuitRejectedNotification extends Notification
{
    use BuildsBadgeMail;

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
        return $this->badgeMail(
            notifiable: $notifiable,
            subject: $this->subjectFor($this->fursuit, 'your badge needs a change before we can print it'),
            band: 'Needs a change',
            tone: 'stop',
            headline: 'We cannot print your badge as it is.',
            answers: [
                [
                    'q' => 'Did I lose my badge?',
                    'a' => 'No. Your order and your place are kept, the badge is simply on hold until this is sorted out.',
                ],
                [
                    'q' => 'What is the problem?',
                    'a' => $this->reason,
                ],
                [
                    'q' => 'What do I do?',
                    'a' => 'Please resolve the issue by editing your badge. We will review it again as soon as possible and see to it that it gets printed.',
                ],
            ],
            action: $this->badge === null ? null : [
                'label' => 'Edit my badge',
                'url' => route('badges.edit', ['badge' => $this->badge->id]),
            ],
            fursuit: $this->fursuit,
        );
    }

    public function toArray($notifiable): array
    {
        return [];
    }
}
