<?php

namespace App\Http\Controllers\POS\Auth;

use App\Http\Controllers\Controller;
use App\Models\RfidTag;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class MachineUserAuthController extends Controller
{
    public function selectUser()
    {
        return Inertia::render('POS/Auth/PinLogin');
    }

    private function generateSalt()
    {
        $salt = bin2hex(random_bytes(32));
        session(['user_login_salt' => $salt]);

        return $salt;
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

    public function submitPinLogin(Request $request)
    {
        $data = $request->validate([
            'code' => 'required|string',
            'is_rfid' => 'boolean',
        ]);


        $staff = null;
        $rfidTag = null;

        // Find staff by PIN or RFID
        if ($data['is_rfid'] ?? false) {
            // RFID authentication - find by RFID tag
            $rfidTag = RfidTag::active()
                ->where('content', $data['code'])
                ->with('staff')
                ->first();

            if (! $rfidTag || ! $rfidTag->staff->is_active) {
                return redirect()->back()->withErrors(['code' => 'Invalid or inactive RFID badge']);
            }

            $staff = $rfidTag->staff;

            // Update RFID tag last login
            $rfidTag->updateLastLogin();
        } else {
            // PIN authentication - find staff by direct PIN comparison
            $staff = Staff::active()
                ->where('pin_code', $data['code'])
                ->first();

            if (! $staff) {
                return redirect()->back()->withErrors(['code' => 'Invalid PIN code']);
            }
        }


        // Update staff last login
        $staff->updateLastLogin();

        // Authenticate staff
        Auth::guard('machine-user')->login($staff);

        // To Dashboard
        return redirect()->route('pos.dashboard');
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
