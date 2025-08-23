<?php

namespace App\Http\Controllers\POS\Auth;

use App\Http\Controllers\Controller;
use App\Models\RfidTag;
use App\Models\Staff;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class MachineUserAuthController extends Controller
{
    public function selectUser()
    {
        return Inertia::render('POS/Auth/PinLogin');
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


    public function logout()
    {
        Auth::guard('machine-user')->logout();

        return redirect()->route('pos.auth.user.select');
    }
}
