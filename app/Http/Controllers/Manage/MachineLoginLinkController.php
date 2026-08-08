<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Models\Machine;
use App\Support\Manage\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;

/**
 * Mints the POS login link for one machine.
 *
 * Whoever holds this URL authenticates as the till: it lands on
 * pos.auth.machine.login, which logs the machine in on the `machine` guard with
 * remember set. The old panel rendered it with URL::signedRoute() into a copyable
 * infolist entry on the edit page, so the credential existed the moment the page was
 * opened, never expired and left no trace of who took it.
 *
 * Three things change, and nothing else does:
 *
 *  - It is minted on demand. Nothing is generated until this endpoint is called, and it
 *    is never part of the list payload, so a poll of the machine list cannot hand out
 *    credentials for every till in the hall.
 *  - It expires. temporarySignedRoute rather than signedRoute, 15 minutes, which the
 *    `signed` middleware on the POS route already enforces: an expired signature is a
 *    403 there with no change here.
 *  - It is logged, naming the operator who asked for it.
 *
 * The route it points at, its parameter and its signature are untouched, so the POS side
 * needs no change: pos.auth.machine.login still validates `machine_id` against the
 * signature it already validated.
 */
class MachineLoginLinkController extends Controller
{
    /**
     * How long a minted link stays usable. Long enough to walk a laptop over to the till
     * and paste it, short enough that a copy left in a chat log is dead by the time
     * anyone reads it.
     */
    private const TTL_MINUTES = 15;

    public function __invoke(Request $request, Machine $machine): RedirectResponse
    {
        // Its own ability, not `update`: handing out a credential is a different
        // question from editing the record, even though both answer is_admin today.
        Gate::authorize('loginLink', $machine);

        $expiresAt = now()->addMinutes(self::TTL_MINUTES);

        $url = URL::temporarySignedRoute('pos.auth.machine.login', $expiresAt, [
            'machine_id' => $machine->id,
        ]);

        activity()
            ->performedOn($machine)
            ->causedBy($request->user())
            ->withProperties(['expires_at' => $expiresAt->toIso8601String()])
            ->log('POS login link created');

        /*
         * The URL rides back in Inertia's flash bag rather than in a prop: a prop would
         * be part of the page's history state and a back navigation would put the
         * credential on screen again long after it was minted. The flash bag is the same
         * channel the upload endpoint uses for its stored path.
         */
        Toast::put('machineLoginLink', [
            'machineId' => $machine->id,
            'url' => $url,
            'expiresAt' => $expiresAt->toIso8601String(),
            'minutes' => self::TTL_MINUTES,
        ]);

        Toast::flashSuccess(
            'Login link created',
            'Valid for '.self::TTL_MINUTES.' minutes. Anyone holding it can log in as this machine.'
        );

        return back();
    }
}
