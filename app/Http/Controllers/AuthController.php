<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\EventUser;
use App\Models\User;
use App\Services\TokenRefreshService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Inertia\Inertia;
use Laravel\Socialite\Facades\Socialite;

class AuthController extends Controller
{
    public function show()
    {
        return $this->login();
        // return Inertia::render('Auth/Login');
    }

    public function login()
    {
        $url = Socialite::driver('identity')
            ->scopes(['openid', 'profile', 'email', 'groups', 'offline', 'offline_access'])
            ->redirect();

        // Use Interia location instead
        return Inertia::location($url->getTargetUrl());
    }

    public function loginCallback()
    {
        try {
            $socialLiteUser = Socialite::driver('identity')->user();
        } catch (\Exception $e) {
            return redirect()->route('auth.login');
        }

        $attendeeListResponse = \Illuminate\Support\Facades\Http::attsrv()
            ->withToken($socialLiteUser->token)
            ->get('/attendees')
            ->json();

        $regId = $attendeeListResponse['ids'][0] ?? null;

        if (isset($attendeeListResponse['ids'][0]) === false) {
            return redirect()->route('welcome')->with('message',
                'Please register for the Convention first before trying to obtain a fursuit badge.');
        }

        $isNewUser = User::where('remote_id', $socialLiteUser->getId())->doesntExist();

        $user = User::updateOrCreate([
            'remote_id' => $socialLiteUser->getId(),
        ], [
            'remote_id' => $socialLiteUser->getId(),
            'name' => $socialLiteUser->getName(),
            'email' => $socialLiteUser->getEmail(),
            'avatar' => $socialLiteUser->getAvatar(),
        ]);

        $user->wallet->balance;


        $activeEvent = Event::getActiveEvent();
        $eventUser = null;
        if ($activeEvent) {
            $statusResponse = \Illuminate\Support\Facades\Http::attsrv()
                ->withToken($socialLiteUser->token)
                ->get('/attendees/'.$regId.'/status');

            // Create or update EventUser relationship
            $eventUser = EventUser::updateOrCreate([
                'user_id' => $user->id,
                'event_id' => $activeEvent->id,
            ], [
                'attendee_id' => $regId,
                'valid_registration' => in_array($statusResponse->json()['status'], ['paid', 'checked in']),
            ]);
        }

        try {
            (new TokenRefreshService($user))->save(
                accessToken: $socialLiteUser->token,
                refreshToken: $socialLiteUser->refreshToken,
                expiresIn: 3500
            );
        } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
            // Handle MAC invalid error - clear corrupted token data and retry
            $user->update([
                'token' => null,
                'refresh_token' => null,
                'token_expires_at' => null,
                'refresh_token_expires_at' => null,
            ]);

            // Retry saving the tokens
            (new TokenRefreshService($user))->save(
                accessToken: $socialLiteUser->token,
                refreshToken: $socialLiteUser->refreshToken,
                expiresIn: 3500
            );
        }

        // Check for prepaid badges for new users OR existing users who need prepaid badge check
        $needsBadgeCheck = $isNewUser || ($eventUser && $eventUser->prepaid_badges === 0 && !cache()->has("prepaid_check_{$user->id}_{$activeEvent?->id}"));
        if ($activeEvent && $eventUser && $needsBadgeCheck) {
            $fursuit = \Illuminate\Support\Facades\Http::attsrv()
                ->withToken($socialLiteUser->token)
                ->get('/attendees/'.$regId.'/packages/fursuit')
                ->json();
            if ($fursuit['present'] && $fursuit['count'] > 0) {

                $fursuitAdditional = \Illuminate\Support\Facades\Http::attsrv()
                    ->withToken($socialLiteUser->token)
                    ->get('/attendees/'.$regId.'/packages/fursuitadd')
                    ->json();

                $additionalCopies = $fursuitAdditional['present'] ? $fursuitAdditional['count'] : 0;
                $totalPrepaidBadges = $fursuit['count'] + $additionalCopies;

                $eventUser->update([
                    'prepaid_badges' => $totalPrepaidBadges,
                ]);

                \Illuminate\Support\Facades\Http::attsrv()
                    ->withToken($socialLiteUser->token)
                    ->post('/attendees/'.$regId.'/additional-info/fursuitbadge', [
                        'created' => false,
                    ]);
            }
            
            // Mark that we've checked this user's prepaid badges for this event
            cache()->put("prepaid_check_{$user->id}_{$activeEvent->id}", true, now()->addHours(24));

        }

        Auth::login($user, true);
        if (Session::exists('catch-em-all-redirect')) {
            Session::forget('catch-em-all-redirect');

            return redirect()->route('fcea.dashboard');
        }

        return redirect()->route('dashboard');
    }

    public function logout()
    {
        return Inertia::location('https://identity.eurofurence.org/oauth2/sessions/logout');
    }

    // Frontchannel Logout
    public function logoutCallback()
    {
        Auth::logout();
        Session::flush();
    }
}
