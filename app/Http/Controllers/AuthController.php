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
        return Inertia::render('Auth/Login');
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
            ->throw();
        $regId = $attendeeListResponse->json()['ids'][0] ?? null;

        // If $regId is null then the user is not registered
        if ($regId === null) {
            return redirect()->route('dashboard')->with('message',
                'Please register for the Convention first before trying to obtain a fursuit badge.');
        }

        $user = User::updateOrCreate([
            'remote_id' => $socialLiteUser->getId(),
        ], [
            'remote_id' => $socialLiteUser->getId(),
            'name' => $socialLiteUser->getName(),
            'email' => $socialLiteUser->getEmail(),
            'avatar' => $socialLiteUser->getAvatar(),
            'is_admin' => in_array('N9OY0K8OJVXR1P7L', $socialLiteUser->user['groups'], true),
        ]);


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

        Auth::login($user);
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
