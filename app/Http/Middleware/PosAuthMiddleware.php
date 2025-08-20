<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Http\Request;

class PosAuthMiddleware extends Authenticate
{
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
