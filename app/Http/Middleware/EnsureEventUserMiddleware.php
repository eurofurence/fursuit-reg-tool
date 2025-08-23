<?php

namespace App\Http\Middleware;

use App\Models\Event;
use App\Models\EventUser;
use App\Services\TokenRefreshService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EnsureEventUserMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        $activeEvent = Event::getActiveEvent();

        if (! $activeEvent) {
            return $next($request);
        }

        // Check if user already has an EventUser entry for current event
        $eventUser = EventUser::where([
            'user_id' => $user->id,
            'event_id' => $activeEvent->id,
        ])->first();

        if ($eventUser && $eventUser->valid_registration) {
            return $next($request);
        }

        try {
            // Get fresh token or use existing one
            $tokenService = new TokenRefreshService($user);
            $accessToken = $tokenService->getValidAccessToken();

            if (! $accessToken) {
                Log::warning('User missing access token, logging out', ['user_id' => $user->id]);
                Auth::logout();

                return redirect()->route('welcome')->with('message', 'Your session has expired. Please log in again to access your registration.');
            }

            // Get attendee info
            $attendeeListResponse = Http::attsrv()
                ->withToken($accessToken)
                ->get('/attendees');

            if (! $attendeeListResponse->successful()) {
                Log::warning('Failed to get attendee list', ['user_id' => $user->id, 'status' => $attendeeListResponse->status()]);
                Auth::logout();

                return redirect()->route('welcome')->with('message', 'Unable to verify your registration. Please log in again.');
            }

            $attendeeData = $attendeeListResponse->json();
            $regId = $attendeeData['ids'][0] ?? null;

            if (! $regId) {
                Log::info('User has no active registration', ['user_id' => $user->id]);
                Auth::logout();

                return redirect()->route('welcome')->with('message', 'Please register for the convention first before trying to obtain a fursuit badge.');
            }

            // Get registration status
            $statusResponse = Http::attsrv()
                ->withToken($accessToken)
                ->get('/attendees/'.$regId.'/status');

            if (! $statusResponse->successful()) {
                Log::warning('Failed to get registration status', ['user_id' => $user->id, 'reg_id' => $regId]);
                Auth::logout();

                return redirect()->route('welcome')->with('message', 'Unable to verify your registration status. Please log in again.');
            }

            $statusData = $statusResponse->json();
            $validRegistration = in_array($statusData['status'], ['paid', 'checked in']);

            if (! $validRegistration) {
                Log::info('User has invalid registration', ['user_id' => $user->id, 'status' => $statusData['status']]);
                Auth::logout();

                return redirect()->route('welcome')->with('message', 'Please complete your convention registration payment before accessing fursuit badges.');
            }

            // Create or update EventUser relationship
            $eventUser = EventUser::updateOrCreate([
                'user_id' => $user->id,
                'event_id' => $activeEvent->id,
            ], [
                'attendee_id' => $regId,
                'valid_registration' => $validRegistration,
            ]);

            // Check for fursuit packages to set prepaid badges
            try {
                $fursuit = Http::attsrv()
                    ->withToken($accessToken)
                    ->get('/attendees/'.$regId.'/packages/fursuit')
                    ->json();

                if ($fursuit['present'] && $fursuit['count'] > 0) {
                    $fursuitAdditional = Http::attsrv()
                        ->withToken($accessToken)
                        ->get('/attendees/'.$regId.'/packages/fursuitadd')
                        ->json();

                    $additionalCopies = $fursuitAdditional['present'] ? $fursuitAdditional['count'] : 0;
                    $totalPrepaidBadges = $fursuit['count'] + $additionalCopies;

                    $eventUser->update(['prepaid_badges' => $totalPrepaidBadges]);

                    // Mark as not created in reg system
                    Http::attsrv()
                        ->withToken($accessToken)
                        ->post('/attendees/'.$regId.'/additional-info/fursuitbadge', [
                            'created' => false,
                        ]);
                }
            } catch (\Exception $e) {
                Log::warning('Failed to check fursuit packages', ['user_id' => $user->id, 'error' => $e->getMessage()]);
                // Continue without setting prepaid badges if fursuit package check fails
            }

            return $next($request);

        } catch (\Exception $e) {
            Log::error('EventUser middleware error', [
                'user_id' => $user->id,
                'event_id' => $activeEvent->id,
                'error' => $e->getMessage(),
            ]);

            Auth::logout();

            return redirect()->route('welcome')->with('message', 'An error occurred while verifying your registration. Please log in again.');
        }
    }
}
