<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\Token;

class TokenRefreshService
{
    public function __construct(public User $user)
    {
    }

    public function getValidAccessToken()
    {
        if ($this->user->token_expires_at->isPast()) {
            $this->refreshToken();
        }

        return $this->user->token;
    }

    public function renewRefreshTokenIfExpired()
    {
        if ($this->user->refresh_token_expires_at->isPast()) {
            $this->refreshToken();
        }
    }

    public function refreshToken()
    {
        /** @var Token $token */
        $token = Socialite::driver('identity')
            ->scopes(['openid', 'profile', 'email', 'groups', 'offline', 'offline_access'])
            ->refreshToken($this->user->refresh_token);

        $this->save($token->token, $token->refreshToken, $token->expiresIn);
    }

    public function save(string $accessToken,string $refreshToken,int $expiresIn)
    {
        $this->user->update([
            'token' => $accessToken,
            'token_expires_at' => now()->addSeconds($expiresIn),
            'refresh_token' => $refreshToken,
            'refresh_token_expires_at' => now()->addDays(12),
        ]);
    }
}
