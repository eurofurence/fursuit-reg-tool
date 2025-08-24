<?php

namespace App\Services;

use App\Models\User;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\Token;

class TokenRefreshService
{
    public function __construct(public User $user) {}

    public function getValidAccessToken()
    {
        try {
            if ($this->user->token_expires_at && $this->user->token_expires_at->isPast()) {
                $this->refreshToken();
            }

            return $this->user->token;
        } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
            // Token is corrupted, return null to force re-authentication
            return null;
        }
    }

    public function renewRefreshTokenIfExpired()
    {
        try {
            if ($this->user->refresh_token_expires_at && $this->user->refresh_token_expires_at->isPast()) {
                $this->refreshToken();
            }
        } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
            // Clear corrupted token data
            $this->clearTokenData();
        }
    }

    public function refreshToken()
    {
        // CRITICAL: Never attempt to use refresh tokens on non-production environments
        if (! app()->isProduction()) {
            throw new \Exception('Refresh tokens are not allowed in non-production environments');
        }

        try {
            /** @var Token $token */
            $token = Socialite::driver('identity')
                ->scopes(['openid', 'profile', 'email', 'groups', 'offline', 'offline_access'])
                ->refreshToken($this->user->refresh_token);

            $this->save($token->token, $token->refreshToken, $token->expiresIn);
        } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
            // Clear corrupted token data and throw exception to force re-authentication
            $this->clearTokenData();
            throw new \Exception('Token data corrupted, please re-authenticate');
        }
    }

    private function clearTokenData()
    {
        $this->user->update([
            'token' => null,
            'refresh_token' => null,
            'token_expires_at' => null,
            'refresh_token_expires_at' => null,
        ]);
    }

    public function save(string $accessToken, string $refreshToken, int $expiresIn)
    {
        $this->user->update([
            'token' => $accessToken,
            'token_expires_at' => now()->addSeconds($expiresIn),
            'refresh_token' => $refreshToken,
            'refresh_token_expires_at' => now()->addDays(12),
        ]);
    }
}
