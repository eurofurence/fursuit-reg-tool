<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class InactivityLogoutMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->session()->has('lastActivityTime') && time() - $request->session()->get('lastActivityTime') > (60 * 30)) {
            $request->session()->forget('lastActivityTime');
            $request->session()->flush();
            $user = auth()->user();
            \Auth::guard('machine-user')->logout();

            return redirect()->route('pos.auth.user.login.show', $user->id);
        }
        $request->session()->put('lastActivityTime', time());

        return $next($request);
    }
}
