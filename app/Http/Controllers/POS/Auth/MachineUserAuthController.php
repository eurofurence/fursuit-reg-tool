<?php

namespace App\Http\Controllers\POS\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class MachineUserAuthController extends Controller
{
    public function selectUser()
    {
        return Inertia::render('POS/Auth/SelectUser', [
            'users' => User::where('is_cashier', true)->get(),
        ]);
    }

    public function showLogin(User $user)
    {
        $salt = bin2hex(random_bytes(32));
        session(['user_login_salt' => $salt]);

        return Inertia::render('POS/Auth/EnterPinCode', [
            'salt' => $salt,
            'user' => $user->only(['id', 'name']),
        ]);
    }

    public function submitLogin(User $user, Request $request)
    {
        // check if user is cashier
        if (! $user->is_cashier) {
            return redirect()->back()->withErrors(['code' => 'User is not a cashier']);
        }

        $data = $request->validate([
            'code' => 'required|string',
        ]);

        // Load salt
        $salt = session('user_login_salt');
        if (! hash_equals(hash('sha256', $user->pin_code.$salt), $data['code'])) {
            return redirect()->back()->withErrors(['code' => 'Invalid code']);
        }

        // Clear salt
        session()->forget('user_login_salt');

        // Authenticate user
        Auth::guard('machine-user')->login($user);

        // To Dashboard
        return redirect()->route('pos.dashboard');
    }

    public function logout()
    {
        Auth::guard('machine-user')->logout();

        return redirect()->route('pos.auth.user.select');
    }
}
