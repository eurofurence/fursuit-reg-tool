<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;

class CatchEmAllAuthMiddleware extends Authenticate
{
    protected function redirectTo(Request $request)
    {
        Session::put('catch-em-all-redirect', true);

        return route('auth.login.redirect');
    }
}
