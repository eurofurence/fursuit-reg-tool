<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;

class CatchEmAllAuthMiddleware extends Authenticate
{
    protected function redirectTo(Request $request)
    {
        // Redirect to the Catch-Em-All domain login
        return route('catch-em-all.auth.login');
    }
}
