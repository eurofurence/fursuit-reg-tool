<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Models\Fursuit\Fursuit;
use App\Notifications\BadgePickupReminderNotification;
use App\Notifications\BadgePrintedNotification;
use App\Notifications\FursuitApprovedNotification;
use App\Notifications\FursuitPublicationBlockedNotification;
use App\Notifications\FursuitRejectedNotification;
use App\Notifications\FursuitRejectionReversedNotification;
use App\Support\Manage\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Send one of the badge mails again, by hand.
 *
 * The one review-adjacent thing left on the record page, and it is not a verdict: it changes no
 * state, writes no decision row and consults no state machine. It exists for the attendee who says
 * they never got the mail, and for the desk that wants to re-send a pickup notice.
 *
 * That deliberate looseness is why it is a separate screen from the queue: a verdict has an undo
 * window, a decision row and a presence banner around it, and this has none of those because it
 * decides nothing.
 *
 * The list below tracks the mails we actually send. It used to offer two - approval and rejection -
 * from a set that is now six, so the desk had no way to re-send a publication block or a pickup
 * notice at all.
 */
class FursuitNotificationController extends Controller
{
    /**
     * What can be re-sent, and what it is called on the picker.
     *
     * @var array<string, string>
     */
    public const TYPES = [
        'approved' => 'Approved',
        'rejected' => 'Needs a change (rejected)',
        'publication_blocked' => 'Approved, not published',
        'rejection_reversed' => 'Our mistake, approved after all',
        'pickup_ready' => 'Ready for pickup',
        'pickup_reminder' => 'Still waiting for you (reminder)',
    ];

    /**
     * The two mails that cannot be sent without something to say to the attendee.
     *
     * @var array<int, string>
     */
    public const NEEDS_REASON = ['rejected', 'publication_blocked'];

    /**
     * The three that are about the card rather than the review, so they need one to exist.
     *
     * @var array<int, string>
     */
    public const NEEDS_BADGE = ['pickup_ready', 'pickup_reminder'];

    /**
     * The fallback for a mail that should carry a reason and somehow does not.
     *
     * Unreachable while the reason is required for those two types, which it is. Kept because it is
     * the string the attendee would read if it ever were reachable, and because dropping a defensive
     * default during a rewrite is how it becomes reachable.
     */
    public const NO_REASON = 'No reason provided';

    /**
     * @return array<int, array{value: string, label: string, needsReason: bool}>
     */
    public static function typeOptions(): array
    {
        return collect(self::TYPES)
            ->map(fn (string $label, string $value) => [
                'value' => $value,
                'label' => $label,
                // Read by the client so the reason field appears the moment the type is picked.
                'needsReason' => in_array($value, self::NEEDS_REASON, true),
            ])
            ->values()
            ->all();
    }

    public function store(Request $request, Fursuit $fursuit): RedirectResponse
    {
        // Gated on `view` like the rest of the moderation surface: the action carried no
        // visibility predicate and no authorization of its own in Filament, so every
        // reviewer who could open the page could send it.
        Gate::authorize('view', $fursuit);

        $validated = $request->validate([
            'notification_type' => ['required', 'string', 'in:'.implode(',', array_keys(self::TYPES))],
            'rejection_reason' => [
                'required_if:notification_type,'.implode(',', self::NEEDS_REASON),
                'nullable',
                'string',
            ],
        ]);

        $type = $validated['notification_type'];
        $reason = $validated['rejection_reason'] ?? self::NO_REASON;
        $badge = $fursuit->badges()->whereNull('extra_copy_of')->first();

        if (in_array($type, self::NEEDS_BADGE, true) && $badge === null) {
            Toast::flashDanger(
                'Nothing was sent',
                'That mail is about the printed card, and this fursuit has no badge.',
            );

            return back();
        }

        $fursuit->user->notify(match ($type) {
            'approved' => new FursuitApprovedNotification($fursuit),
            'rejected' => new FursuitRejectedNotification($fursuit, $reason),
            'publication_blocked' => new FursuitPublicationBlockedNotification($fursuit, $reason),
            'rejection_reversed' => new FursuitRejectionReversedNotification($fursuit),
            'pickup_ready' => new BadgePrintedNotification($badge),
            'pickup_reminder' => new BadgePickupReminderNotification($badge),
        });

        Toast::flashSuccess(self::TYPES[$type].' notification sent');

        return back();
    }
}
