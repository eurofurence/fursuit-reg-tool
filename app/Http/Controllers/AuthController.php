<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\TokenRefreshService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Inertia\Inertia;
use Laravel\Socialite\Facades\Socialite;
use League\Uri\Http;

class AuthController extends Controller
{
    public function show()
    {
        return $this->login();
        //return Inertia::render('Auth/Login');
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
            'attendee_id' => $regId,
        ]);

        $user->wallet->balance;

        $statusResponse = \Illuminate\Support\Facades\Http::attsrv()
            ->withToken($socialLiteUser->token)
            ->get('/attendees/'.$regId.'/status');

        // Update the user's registration status
        if (in_array($statusResponse->json()['status'], ['paid', 'checked in'])) {
            $user->valid_registration = true;
            $user->save();
        }

        (new TokenRefreshService($user))->save(
            accessToken: $socialLiteUser->token,
            refreshToken: $socialLiteUser->refreshToken,
            expiresIn: 3500
        );

        if ($isNewUser) {
            $fursuit = \Illuminate\Support\Facades\Http::attsrv()
                ->withToken($socialLiteUser->token)
                ->get('/attendees/' . $regId . '/packages/fursuit')
                ->json();
            if ($fursuit['present'] && $fursuit['count'] > 0) {

                $fursuitAdditional = \Illuminate\Support\Facades\Http::attsrv()
                    ->withToken($socialLiteUser->token)
                    ->get('/attendees/' . $regId . '/packages/fursuitadd')
                    ->json();

                $copies = $fursuitAdditional['present'] ? $fursuitAdditional['count'] : 0;

                $user->has_free_badge = true;
                $user->free_badge_copies = $copies;
                $user->save();


                \Illuminate\Support\Facades\Http::attsrv()
                    ->withToken($socialLiteUser->token)
                    ->post('/attendees/' . $regId . '/additional-info/fursuitbadge', [
                        'created' => false,
                    ]);
            }

        }

        Auth::login($user, true);
        if(Session::exists('catch-em-all-redirect')) {
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
