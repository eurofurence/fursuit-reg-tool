<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Models\Fursuit\Fursuit;
use App\Notifications\FursuitApprovedNotification;
use App\Notifications\FursuitRejectedNotification;
use App\Support\Manage\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The `Send Notification` header action of ViewFursuit (audit 4.3.1, action 6).
 *
 * It re-sends an approval or rejection mail without changing state and without checking
 * the current state, which is exactly what it is for: an attendee who never received the
 * first one. That deliberate looseness is parity, so nothing here consults the state
 * machine.
 *
 * One behaviour changes and it is a client change: the Filament Select was not ->live(),
 * so the reason field only appeared after the next form round-trip (audit 73). The page
 * renders this form itself and the field reacts immediately.
 */
class FursuitNotificationController extends Controller
{
    /**
     * The two options the Select offers, verbatim.
     *
     * @var array<string, string>
     */
    public const TYPES = [
        'approved' => 'Approval Notification',
        'rejected' => 'Rejection Notification',
    ];

    /**
     * The fallback the action falls back to when a rejection carries no reason.
     *
     * Unreachable while `rejection_reason` is required for a rejection, which it is in
     * the Filament form too. Kept because it is the string the attendee would read if it
     * ever were reachable, and because dropping a defensive default during a rewrite is
     * how it becomes reachable.
     */
    public const NO_REASON = 'No reason provided';

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function typeOptions(): array
    {
        return collect(self::TYPES)
            ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
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
            'rejection_reason' => ['required_if:notification_type,rejected', 'nullable', 'string'],
        ]);

        if ($validated['notification_type'] === 'approved') {
            $fursuit->user->notify(new FursuitApprovedNotification($fursuit));

            Toast::flashSuccess('Approval notification sent successfully');

            return back();
        }

        $reason = $validated['rejection_reason'] ?? self::NO_REASON;

        $fursuit->user->notify(new FursuitRejectedNotification($fursuit, $reason));

        Toast::flashSuccess('Rejection notification sent successfully');

        return back();
    }
}
