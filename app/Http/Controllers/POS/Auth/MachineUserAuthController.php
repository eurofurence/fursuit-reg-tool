<?php

namespace App\Http\Controllers\POS\Auth;

use App\Http\Controllers\Controller;
use App\Models\RfidTag;
use App\Models\Staff;
use App\Rules\SecurePinRule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
            // PIN or setup code authentication
            $staff = Staff::active()
                ->where('pin_code', $data['code'])
                ->first();

            // If not found by PIN, try setup code
            if (! $staff) {
                $staff = Staff::active()
                    ->where('setup_code', $data['code'])
                    ->first();

                if ($staff) {
                    // Setup code found - redirect to setup flow
                    session(['staff_setup_id' => $staff->id]);

                    return redirect()->route('pos.auth.setup');
                }
            }

            if (! $staff) {
                return redirect()->back()->withErrors(['code' => 'Invalid PIN or setup code']);
            }
        }

        // Update staff last login
        $staff->updateLastLogin();

        // Authenticate staff
        Auth::guard('machine-user')->login($staff);

        // Check if there's a saved return URL in the session
        $returnUrl = session('pos_return_url');
        session()->forget('pos_return_url'); // Clear it after retrieving

        // Redirect to saved URL or dashboard
        if ($returnUrl) {
            return redirect($returnUrl);
        }

        return redirect()->route('pos.dashboard');
    }

    public function logout()
    {
        Auth::guard('machine-user')->logout();

        return redirect()->route('pos.auth.user.select');
    }

    /**
     * Lock the screen and save return URL for re-authentication
     */
    public function lock(Request $request)
    {
        $returnUrl = $request->input('return_url');

        // Only save GET routes to prevent issues with POST/PUT/DELETE
        if ($returnUrl && $request->isMethod('post')) {
            // Parse the URL to check if it's a valid internal route
            $parsedUrl = parse_url($returnUrl);
            $path = $parsedUrl['path'] ?? '/pos/dashboard';

            // Store the return URL in the session for this machine
            session(['pos_return_url' => $path.($parsedUrl['query'] ?? '' ? '?'.$parsedUrl['query'] : '')]);
        }

        // Log out the user
        Auth::guard('machine-user')->logout();

        // Clear the lastActivityTime to prevent immediate re-lock
        $request->session()->forget('lastActivityTime');

        return redirect()->route('pos.auth.user.select');
    }

    /**
     * Show staff setup page for new account configuration
     */
    public function showSetup()
    {
        $staffId = session('staff_setup_id');
        if (! $staffId) {
            return redirect()->route('pos.auth.user.select')
                ->withErrors(['code' => 'Setup session expired']);
        }

        $staff = Staff::find($staffId);
        if (! $staff || ! $staff->hasSetupCode()) {
            return redirect()->route('pos.auth.user.select')
                ->withErrors(['code' => 'Invalid setup session']);
        }

        return Inertia::render('POS/Auth/StaffSetup', [
            'staff_name' => $staff->name,
        ]);
    }

    /**
     * Complete staff setup with PIN and RFID registration
     */
    public function completeSetup(Request $request)
    {
        $staffId = session('staff_setup_id');
        if (! $staffId) {
            return redirect()->route('pos.auth.user.select')
                ->withErrors(['code' => 'Setup session expired']);
        }

        $staff = Staff::find($staffId);
        if (! $staff || ! $staff->hasSetupCode()) {
            return redirect()->route('pos.auth.user.select')
                ->withErrors(['code' => 'Invalid setup session']);
        }

        $data = $request->validate([
            'pin_code' => ['required', 'string', 'size:6', new SecurePinRule],
            'pin_code_confirmation' => 'required|string|same:pin_code',
            'rfid_tag' => 'required|string|min:8|max:20|regex:/^[0-9]+$/|unique:rfid_tags,content',
        ], [
            'rfid_tag.min' => 'RFID tag must be at least 8 digits long.',
            'rfid_tag.max' => 'RFID tag must not exceed 20 digits.',
            'rfid_tag.regex' => 'RFID tag must contain only numbers.',
            'rfid_tag.unique' => 'This RFID tag is already registered to another staff member.',
        ]);

        DB::transaction(function () use ($staff, $data) {
            // Update staff with new PIN
            $staff->update([
                'pin_code' => $data['pin_code'],
            ]);

            // Clear setup code
            $staff->clearSetupCode();

            // Create RFID tag
            $staff->rfidTags()->create([
                'content' => $data['rfid_tag'],
                'is_active' => true,
                'last_login_at' => now(),
            ]);
        });

        // Clear setup session
        session()->forget('staff_setup_id');

        // Authenticate staff
        Auth::guard('machine-user')->login($staff);

        return redirect()->route('pos.dashboard')
            ->with('success', 'Account setup completed successfully!');
    }
}
