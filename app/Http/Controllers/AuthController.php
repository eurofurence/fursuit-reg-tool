<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Inertia\Inertia;
use Laravel\Socialite\Facades\Socialite;

class AuthController extends Controller
{
    public function show()
    {
        return Inertia::render('Auth/Login');
    }

    public function login()
    {
        $url = Socialite::driver('identity')
            ->scopes(['openid', 'profile', 'email', 'groups'])
            ->redirect();

        // Use Interia location instead
        return Inertia::location($url->getTargetUrl());
    }

    public function loginCallback()
    {
        try {
            $user = Socialite::driver('identity')->user();
        } catch (\Exception $e) {
            return redirect()->route('auth.login');
        }
        $user = User::updateOrCreate([
            'remote_id' => $user->getId(),
        ], [
            'remote_id' => $user->getId(),
            'name' => $user->getName(),
            'email' => $user->getEmail(),
            'avatar' => $user->getAvatar(),
            'is_admin' => in_array('N9OY0K8OJVXR1P7L', $user->user['groups'], true),
        ]);
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
