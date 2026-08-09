<?php

namespace App\Notifications;

use App\Models\Badge\Badge;
use App\Models\Fursuit\Fursuit;
use App\Notifications\Concerns\BuildsBadgeMail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "We cannot print your badge."
 *
 * Deliberately not about photos: what a reviewer refuses can be the image, the name or the species,
 * so the mail names none of them and the instruction is simply to fix the badge. The reviewer's own
 * sentence carries the explanation, in the finding panel. The attendee is being asked to change
 * something, so they need to know what.
 *
 * The badge page states the same finding (BadgeController::show passes it), so the two must not
 * drift apart - change both together.
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
            headline: 'We cannot print your badge.',
            answers: [
                [
                    'q' => 'What do I do?',
                    'a' => 'Check the reason below, then update your badge. We review it again after that.',
                ],
            ],
            // The reviewer's own sentence, kept apart from the prose so it reads as the
            // finding rather than as our wording. The badge page shows the same sentence.
            finding: $this->reason,
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
