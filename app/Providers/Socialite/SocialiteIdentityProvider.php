<?php

namespace App\Providers\Socialite;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User;

class SocialiteIdentityProvider extends AbstractProvider
{
    private mixed $issuer;
    private mixed $userinfoEndpoint;
    private mixed $tokenEndpoint;
    private mixed $authorizationEndpoint;
    private mixed $jwksUri;
    private mixed $endSessionEndpoint;
    private mixed $revocationEndpoint;

    /**
     * The scopes being requested.
     *
     * @var array
     */
    protected $scopes = ['email'];

    /**
     * The separating character for the requested scopes.
     *
     * @var string
     */
    protected $scopeSeparator = ' ';

    public function getIdentityConfig()
    {
        // Get from cache if exists
        if (isset($this->issuet)) {
            return $this;
        }
        // Get from services.identity.openid_configuration url and cache it
        $config = Cache::remember('identity_config', now()->addDay(), function () {
            return Http::get(config('services.identity.openid_configuration'))->throw()->json();
        });
        $this->issuer = $config['issuer'];
        $this->userinfoEndpoint = $config['userinfo_endpoint'];
        $this->authorizationEndpoint = $config['authorization_endpoint'];
        $this->tokenEndpoint = $config['token_endpoint'];
        $this->jwksUri = $config['jwks_uri'];
        $this->endSessionEndpoint = $config['end_session_endpoint'];
        $this->revocationEndpoint = $config['revocation_endpoint'];
        return $this;
    }

    protected function getAuthUrl($state)
    {
        return $this->buildAuthUrlFromBase($this->getIdentityConfig()->authorizationEndpoint, $state);
    }

    protected function getTokenUrl()
    {
        return $this->getIdentityConfig()->tokenEndpoint;
    }

    protected function getUserByToken($token)
    {
        $response = $this->getHttpClient()->get($this->getIdentityConfig()->userinfoEndpoint, [
            'headers' => [
                'cache-control' => 'no-cache',
                'Authorization' => 'Bearer '.$token,
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
        ]);

        return json_decode($response->getBody()->getContents(), true);
    }

    protected function mapUserToObject(array $user)
    {
        return (new User())->setRaw($user)->map([
            'id' => $user['sub'],
            'email' => $user['email'],
            'email_verified' => $user['email_verified'],
            'name' => $user['name'],
            'groups' => $user['groups'],
        ]);
    }
}
