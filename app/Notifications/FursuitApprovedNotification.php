<?php

namespace App\Notifications;

use App\Models\Badge\Badge;
use App\Models\Fursuit\Fursuit;
use App\Notifications\Concerns\BuildsBadgeMail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Your badge is approved."
 *
 * Approval says nothing about printing, so neither does this mail. What it can say usefully is when
 * to walk over, and that has an operational rule behind it: day one hands out badges from the
 * pre-print run only, because printing on day one would stall the queue. See
 * BuildsBadgeMail::collectionAnswer().
 *
 * Two answers are conditional. The payment question is absent, not answered "no", when nothing is
 * owed; and there is deliberately no question about the gallery, because an approval changes nothing
 * about it.
 */
class FursuitApprovedNotification extends Notification
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
        $answers = [
            [
                'q' => 'When can I collect it?',
                'a' => $this->collectionAnswer($this->fursuit->event),
            ],
            [
                'q' => 'Where?',
                'a' => 'The badge desk in the Fursuit Lounge. Opening hours are on the pickup page.',
            ],
        ];

        if ($this->hasSomethingToPay($this->badge)) {
            $answers[] = [
                'q' => 'Anything to pay?',
                'a' => 'Yes. Please bring a card, we do not accept cash.',
            ];
        }

        if ($this->canStillChange($this->badge)) {
            $answers[] = [
                'q' => 'Can I still change it?',
                'a' => 'Until we print it, yes.',
            ];
        }

        return $this->badgeMail(
            notifiable: $notifiable,
            subject: $this->subjectFor($this->fursuit, 'badge approved'),
            band: 'Approved',
            tone: 'ok',
            headline: 'Your badge is approved.',
            answers: $answers,
            // The badge page, not the pickup page: Ready for Pickup is the only thing that is true
            // per badge and per minute, and a card can come off the run early or be printed the
            // moment somebody asks at the desk. Pickup times stay a footer link.
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
