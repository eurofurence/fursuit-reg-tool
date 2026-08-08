<?php

namespace App\Http\Controllers\Manage;

use App\Domain\Printing\Services\BadgePrintVerification;
use App\Http\Controllers\Controller;
use App\Models\Badge\Badge;
use App\Support\Manage\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The badge list's inline check-off column.
 *
 * The same gesture as the POS numpad, from the other side of the desk: somebody reads
 * badge numbers out of the crate one after another and the operator ticks them off the
 * list on screen. That is why this is a column and not a form - the whole point is that a
 * tick is one click with no page to open and no save button to find.
 *
 * Split off BadgeController the way PrinterStateController is split off PrinterController:
 * this writes one column, it is not the record form, and it must never be confused with a
 * full round trip of a badge.
 *
 * The write itself is BadgePrintVerification, shared with
 * POS\BadgeVerificationController, so a crate reconciled here and a crate reconciled at
 * the numpad leave the same stamp on the badge, the same stamp on its print job and the
 * same batch counters behind them.
 */
class BadgeVerificationController extends Controller
{
    /**
     * Tick one badge off, or put it back.
     *
     * The request carries the state it wants, not "flip it", so a click always means what
     * the row showed when it was clicked - the list polls every five seconds, and a row
     * that refreshed under the cursor rewrites the URL with it. A request asking for the
     * state the badge is already in writes nothing and says so, which is what a double
     * click or a stale tab produces.
     */
    public function update(Request $request, Badge $badge): RedirectResponse
    {
        Gate::authorize('update', $badge);

        $verified = (bool) $request->validate([
            'verified' => ['required', 'boolean'],
        ])['verified'];

        $label = $badge->custom_id ?? 'That badge';

        if ($verified === ($badge->verified_print_at !== null)) {
            Toast::flashWarning(
                'Nothing changed',
                $verified
                    ? $label.' was already checked off.'
                    : $label.' was not checked off.',
            );

            return back();
        }

        if ($verified) {
            BadgePrintVerification::verify($badge, 'in the admin panel', $request->user());

            Toast::flashSuccess($label.' checked off');
        } else {
            BadgePrintVerification::revert($badge, 'in the admin panel');

            Toast::flashSuccess($label.' put back on the missing list');
        }

        return back();
    }
}
