<?php

namespace App\Http\Controllers\POS\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class MachineUserAuthController extends Controller
{
    /**
     * Login of new user
     */
    public function login()
    {
        $user = User::first();
        // Get session
        auth()->guard('machine-user')->login($user);
        return redirect()->route('pos.dashboard');
    }

    /**
     * Switch User - In cases where you switch between users but keep the old users "logged in" state
     */
    public function switch()
    {

    }

    /**
     * Hard Logout - Will logout a user and force pin re-entry
     */
    public function logout()
    {

    }
}
