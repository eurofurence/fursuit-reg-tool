<?php

namespace App\Notifications;

use App\Models\Badge\Badge;
use App\Models\Fursuit\Fursuit;
use App\Notifications\Concerns\BuildsBadgeMail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Your badge is approved. It will not be shown in the gallery."
 *
 * The half-good news that used to have no message of its own: a submission that breaks no rule but is
 * wrong for the public surfaces was rejected outright, so the attendee was told to fix a badge that
 * was in fact fine and would otherwise have been printed.
 *
 * The explanation comes in two layers, deliberately. The answer points at the guidelines, which are
 * on the submission form; the quoted finding under it is the reviewer's own sentence. General rule
 * first, then the specific thing found, so an attendee who wants to resubmit knows what to change
 * instead of guessing.
 *
 * One mail, not two: whether the card has already been printed changes exactly one answer.
 */
class FursuitPublicationBlockedNotification extends Notification
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
        $canChange = $this->canStillChange($this->badge);

        return $this->badgeMail(
            notifiable: $notifiable,
            subject: $this->subjectFor($this->fursuit, 'badge approved, but not in the gallery'),
            band: 'Approved, not published',
            tone: 'warn',
            headline: 'Your badge is approved. It will not be shown in the gallery.',
            answers: [
                [
                    'q' => 'Do I still get my badge?',
                    'a' => 'Yes. It will be waiting for you at the badge desk in the Fursuit Lounge, and there is nothing you need to do for that.',
                ],
                [
                    'q' => 'Why is my fursuit badge not being published in the gallery and the Catch-Em-All game?',
                    'a' => 'We check every submission against our guidelines, and it was determined that yours did not meet the guidelines for publication. The guidelines are shown when you submit a badge.',
                ],
                [
                    'q' => 'Can I change that?',
                    'a' => $canChange
                        ? 'Yes, until we print your badge. Send us a photo of your costume and we will review it again.'
                        : 'Unfortunately your badge has already been printed, so you cannot send a new photo for it. If you would like an entry in the gallery or to take part in Catch-Em-All, you would need to order a new badge.',
                ],
            ],
            // What the reviewer actually found, kept apart from the prose so it reads as the finding
            // rather than as more policy.
            finding: $this->reason,
            action: $this->badge === null ? null : [
                'label' => $canChange ? 'Send a different photo' : 'Check my badge',
                'url' => $canChange
                    ? route('badges.edit', ['badge' => $this->badge->id])
                    : route('badges.show', ['badge' => $this->badge->id]),
            ],
            note: 'Your opt-in for the Fursuit Gallery and Fursuit Catch-Em-All has been revoked.',
            fursuit: $this->fursuit,
        );
    }

    public function toArray($notifiable): array
    {
        return [];
    }
}
