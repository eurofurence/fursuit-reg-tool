<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Http\Request;

class PosAuthMiddleware extends Authenticate
{
    public function handle($request, \Closure $next, ...$guards)
    {
        // Check if machine is authenticated and not archived
        $machine = $request->user('machine');
        if ($machine && $machine->isArchived()) {
            auth()->guard('machine')->logout();
            abort(403, 'This machine has been archived and cannot be used.');
        }
        
        return parent::handle($request, $next, ...$guards);
    }
    
    protected function redirectTo(Request $request)
    {
        if ($request->user('machine') === null) {
            return route('welcome');
        }
        if ($request->user('machine-user') === null) {
            return route('pos.auth.user.select');
        }
    }
}
