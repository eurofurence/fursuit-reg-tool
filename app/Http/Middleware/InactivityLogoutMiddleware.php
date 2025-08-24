<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class InactivityLogoutMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        // POS inactivity timeout: 5 minutes (300 seconds)
        $timeoutSeconds = 60 * 5;

        if ($request->session()->has('lastActivityTime') && time() - $request->session()->get('lastActivityTime') > $timeoutSeconds) {
            $request->session()->forget('lastActivityTime');
            $request->session()->flush();
            $user = auth()->user();
            \Auth::guard('machine-user')->logout();

            return redirect()->route('pos.auth.user.select');
        }
        $request->session()->put('lastActivityTime', time());

        return $next($request);
    }
}
