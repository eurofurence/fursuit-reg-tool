<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class InactivityLogoutMiddleware
{
    /**
     * Per-machine inactivity timeout, in seconds, or null when the desk turned
     * auto logout off. null is a real setting here, so only a missing machine
     * falls back to the 5 minute default the UI shows.
     */
    private function timeoutSeconds(Request $request): ?int
    {
        $machine = $request->user('machine');

        return $machine ? $machine->auto_logout_timeout : 60 * 5;
    }

    public function handle(Request $request, Closure $next)
    {
        $timeoutSeconds = $this->timeoutSeconds($request);

        if ($timeoutSeconds === null) {
            $request->session()->forget('lastActivityTime');

            return $next($request);
        }

        if ($request->session()->has('lastActivityTime') && time() - $request->session()->get('lastActivityTime') > $timeoutSeconds) {
            $request->session()->forget('lastActivityTime');
            $request->session()->flush();
            \Auth::guard('machine-user')->logout();

            return redirect()->route('pos.auth.user.select');
        }
        $request->session()->put('lastActivityTime', time());

        return $next($request);
    }
}
