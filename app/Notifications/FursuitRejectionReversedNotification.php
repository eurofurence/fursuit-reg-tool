<?php

namespace App\Notifications;

use App\Models\Badge\Badge;
use App\Models\Fursuit\Fursuit;
use App\Notifications\Concerns\BuildsBadgeMail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "We turned your badge down in error."
 *
 * Sent when a reviewer approves a record that was rejected, and sent even after the convention has
 * ended: an apology has no expiry, which is why this one is not held behind the review undo window
 * either.
 *
 * It says plainly which of the two mails they now hold is true, because the attendee is looking at a
 * rejection in their inbox and needs to know it no longer counts.
 */
class FursuitRejectionReversedNotification extends Notification
{
    use BuildsBadgeMail;

    private ?Badge $badge;

    public function __construct(public Fursuit $fursuit)
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
            subject: $this->subjectFor($this->fursuit, 'our mistake, your badge is approved'),
            band: 'Approved after a second look',
            tone: 'ok',
            headline: 'We turned your badge down in error. Sorry about that.',
            answers: [
                [
                    'q' => 'So what is true now?',
                    'a' => 'Your badge is approved. Please ignore the earlier email asking you to change it.',
                ],
                [
                    'q' => 'Do I need to resubmit?',
                    'a' => 'No. What you sent is fine.',
                ],
                [
                    'q' => 'When can I collect it?',
                    'a' => $this->collectionAnswer($this->fursuit->event),
                ],
                [
                    'q' => 'Where?',
                    'a' => 'The badge desk in the Fursuit Lounge. Opening hours are on the pickup page.',
                ],
            ],
            action: $this->badge === null ? null : [
                'label' => 'Check my badge',
                'url' => route('badges.show', ['badge' => $this->badge->id]),
            ],
            fursuit: $this->fursuit,
        );
    }

    public function toArray($notifiable): array
    {
        return [];
    }
}
