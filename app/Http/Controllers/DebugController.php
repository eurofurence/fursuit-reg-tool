<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\EventUser;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;

class DebugController extends Controller
{
    public function debugLogin(Request $request)
    {
        // Only allow when app environment is local
        if (!app()->environment('local')) {
            abort(404);
        }

        $userId = $request->query('user_id');
        
        if (!$userId) {
            return response()->json(['error' => 'user_id parameter required'], 400);
        }

        Log::info('Debug login attempt', ['user_id' => $userId]);

        $user = User::find($userId);
        
        if (!$user) {
            return response()->json(['error' => 'User not found'], 404);
        }

        // Replicate the callback actions without external API calls
        $activeEvent = Event::getActiveEvent();
        $eventUser = null;
        
        if ($activeEvent) {
            // Create or update EventUser relationship with default values
            $eventUser = EventUser::updateOrCreate([
                'user_id' => $user->id,
                'event_id' => $activeEvent->id,
            ], [
                'attendee_id' => 'debug-' . $user->id,
                'valid_registration' => true, // Set to true for debug
                'prepaid_badges' => 1, // Default to 1 prepaid badge
            ]);
        }

        // Log the user in
        Auth::login($user, true);
        
        Log::info('Debug login successful', [
            'user_id' => $user->id,
            'event_id' => $activeEvent?->id,
            'event_user_id' => $eventUser?->id,
        ]);

        // Check for catch-em-all redirect session
        if (Session::exists('catch-em-all-redirect')) {
            Session::forget('catch-em-all-redirect');
            return redirect()->route('catch-em-all.catch');
        }

        return redirect()->route('dashboard');
    }
}