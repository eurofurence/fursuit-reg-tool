<?php

namespace App\Http\Controllers\Manage;

use App\Domain\Printing\Models\Printer;
use App\Enum\PrinterStatusEnum;
use App\Http\Controllers\Controller;
use App\Support\Manage\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The two writes the printer list makes that are not the record form: the inline
 * `is_active` toggle and Clear error.
 *
 * Split off PrinterController the way FursuitModerationController is split off
 * FursuitController: these are operational gestures against live hardware config, not
 * CRUD, and they are the endpoints worth reading on their own.
 *
 * Both are explicit. Nothing here runs on page load and nothing here is reachable from
 * the 15s poll, which is a GET that reloads `rows` and `meta` only. `is_active` was a
 * the old panel CheckboxColumn that wrote straight to the database on a single click with no
 * confirm, no notification and no audit trail; it now goes through a real
 * authorized endpoint that says what it did.
 */
class PrinterStateController extends Controller
{
    /**
     * Whether Printer::clearPrinterError() would do anything for this record.
     *
     * The same two conditions the model method checks, asked here so the list can offer
     * the action disabled with a reason rather than silently doing nothing.
     */
    public static function hasClearableError(Printer $printer): bool
    {
        return (bool) $printer->is_active
            && in_array($printer->status, [PrinterStatusEnum::PAUSED, PrinterStatusEnum::OFFLINE], true);
    }

    /**
     * Take a printer in or out of service.
     *
     * The request carries the state it wants, not "flip it", so a click always means what
     * the row showed when it was clicked. A request that asks for the state the printer is
     * already in writes nothing and says so, which is what a double click or a stale tab
     * produces.
     *
     * No PrinterStatusUpdated broadcast. The event carries a status - a
     * PrinterStatusEnum or a PrinterConditionEnum - and `is_active` is neither: the
     * hardware has not changed state, its availability has. Broadcasting the printer's
     * current status would tell every POS screen something it already knows, and
     * broadcasting OFFLINE would claim a fault that does not exist and be overwritten by
     * the agent's next reading anyway. POS reads `is_active` through
     * Printer::getPrinterStates() on load instead.
     */
    public function setActive(Request $request, Printer $printer): RedirectResponse
    {
        Gate::authorize('update', $printer);

        $validated = $request->validate([
            'active' => ['required', 'boolean'],
        ]);

        $active = (bool) $validated['active'];

        if ((bool) $printer->is_active === $active) {
            Toast::flashWarning(
                'Nothing changed',
                $printer->name.' is already '.($active ? 'active' : 'inactive').'.'
            );

            return back();
        }

        $printer->update(['is_active' => $active]);

        Toast::flashSuccess(
            $active ? 'Printer activated' : 'Printer deactivated',
            $printer->name.($active
                ? ' is back in service.'
                : ' will not be offered for new print jobs.')
        );

        return back();
    }

    /**
     * Put a paused or offline printer back to Ready, once whatever stopped it is fixed.
     *
     * Built on Printer::clearPrinterError(), which has existed since the state columns
     * landed and which the old panel never called. It
     * clears the held job and the error message, stamps last_state_update and broadcasts
     * PrinterStatusUpdated, so the POS learns immediately - which is why it is used here
     * rather than a local update().
     *
     * It looks the printer up by name, so a second active printer sharing this one's name
     * could be the one it writes to. That is refused rather than risked: this is live
     * hardware and the wrong record is worse than no action.
     */
    public function clearError(Printer $printer): RedirectResponse
    {
        Gate::authorize('update', $printer);

        if (! self::hasClearableError($printer)) {
            Toast::flashDanger(
                'Nothing was cleared',
                'Only an active printer that is paused or offline has an error to clear.'
            );

            return back();
        }

        $ambiguous = Printer::query()
            ->where('name', $printer->name)
            ->where('is_active', true)
            ->whereKeyNot($printer->getKey())
            ->exists();

        if ($ambiguous) {
            Toast::flashDanger(
                'Nothing was cleared',
                'Another active printer has the same name, so this cannot be cleared safely. Rename one of them first.'
            );

            return back();
        }

        if (! Printer::clearPrinterError($printer->name)) {
            Toast::flashDanger(
                'Nothing was cleared',
                'The printer state changed while the request was in flight. Reload and try again.'
            );

            return back();
        }

        Toast::flashSuccess(
            'Printer error cleared',
            $printer->name.' is back to Ready.'
        );

        return back();
    }
}
