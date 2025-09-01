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
        // Determine callback URL based on current domain
        $currentHost = request()->getHost();
        $isCatchEmAll = $currentHost === config('fcea.domain');

        if ($isCatchEmAll) {
            $protocol = str_contains($currentHost, 'localhost') ? 'http' : 'https';
            $callbackUrl = $protocol . '://' . $currentHost . '/auth/callback';
        } else {
            $callbackUrl = rtrim(config('app.url'), '/') . '/auth/callback';
        }

        $url = Socialite::driver('identity')
            ->scopes(['openid', 'profile', 'email', 'groups', 'offline', 'offline_access'])
            ->redirectUrl($callbackUrl)
            ->redirect();

        // Use Interia location instead
        return Inertia::location($url->getTargetUrl());
    }

    public function loginCallback()
    {
        try {
            // Ensure callback URL matches the one used during login redirect
            $currentHost = request()->getHost();
            $isCatchEmAll = $currentHost === config('fcea.domain');

            if ($isCatchEmAll) {
                $protocol = str_contains($currentHost, 'localhost') ? 'http' : 'https';
                $callbackUrl = $protocol . '://' . $currentHost . '/auth/callback';
            } else {
                $callbackUrl = rtrim(config('app.url'), '/') . '/auth/callback';
            }

            $socialLiteUser = Socialite::driver('identity')
                ->redirectUrl($callbackUrl)
                ->user();
        } catch (\Exception $e) {
            // Redirect to login on the same domain
            $currentHost = request()->getHost();
            $isCatchEmAll = $currentHost === config('fcea.domain');

            if ($isCatchEmAll) {
                $protocol = str_contains($currentHost, 'localhost') ? 'http' : 'https';
                return redirect($protocol . '://' . $currentHost . '/auth/login');
            } else {
                return redirect()->route('auth.login');
            }
        }

        $attendeeListResponse = \Illuminate\Support\Facades\Http::attsrv()
            ->withToken($socialLiteUser->token)
            ->get('/attendees')
            ->json();

        $regId = $attendeeListResponse['ids'][0] ?? null;

        if (isset($attendeeListResponse['ids'][0]) === false) {
            $currentHost = request()->getHost();
            $isCatchEmAll = $currentHost === config('fcea.domain');

            if ($isCatchEmAll) {
                return redirect()->route('catch-em-all.introduction')->with('message',
                    'Please register for the Convention first before trying to play Catch-Em-All.');
            } else {
                return redirect()->route('welcome')->with('message',
                    'Please register for the Convention first before trying to obtain a fursuit badge.');
            }
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

        if ($activeEvent && $eventUser) {
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
        }

        Auth::login($user, true);

        // Redirect based on current domain
        $currentHost = request()->getHost();
        $isCatchEmAll = $currentHost === config('fcea.domain');

        if ($isCatchEmAll) {
            // Redirect to introduction page for new users, or home for returning users
            return redirect()->route('catch-em-all.introduction');
        } else {
            return redirect()->route('dashboard');
        }
    }

    public function logout()
    {
        $currentHost = request()->getHost();
        $isCatchEmAll = $currentHost === config('fcea.domain');

        if ($isCatchEmAll) {
            // Include post logout redirect for Catch-Em-All
            $protocol = str_contains($currentHost, 'localhost') ? 'http' : 'https';
            $returnUrl = $protocol . '://' . $currentHost;
            return Inertia::location('https://identity.eurofurence.org/oauth2/sessions/logout?post_logout_redirect_uri=' . urlencode($returnUrl));
        } else {
            return Inertia::location('https://identity.eurofurence.org/oauth2/sessions/logout');
        }
    }

    // Frontchannel Logout
    public function logoutCallback()
    {
        Auth::logout();
        Session::flush();

        // Optional: redirect based on domain
        $currentHost = request()->getHost();
        $isCatchEmAll = $currentHost === config('fcea.domain');

        if ($isCatchEmAll) {
            $protocol = str_contains($currentHost, 'localhost') ? 'http' : 'https';
            return redirect($protocol . '://' . $currentHost);
        }

        // For main domain, just complete the logout (no redirect needed)
    }
}
