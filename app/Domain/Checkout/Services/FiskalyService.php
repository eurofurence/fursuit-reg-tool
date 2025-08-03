<?php

namespace App\Domain\Checkout\Services;

use App\Domain\Checkout\Models\Checkout\Checkout;
use App\Domain\Checkout\Models\TseClient;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;

class FiskalyService
{
    public function __construct()
    {
        $this->ensureAuth();
    }

    private $accessToken;

    private $refreshToken;

    private function ensureAuth()
    {
        $authDataCacheKey = 'fiskaly_auth_data';

        if (cache()->has($authDataCacheKey)) {
            $authData = decrypt(cache()->get($authDataCacheKey));
            $this->accessToken = $authData['access_token'];
            $this->refreshToken = $authData['refresh_token'];

            if (Carbon::parse($authData['access_token_expires_at'], 'UTC')->isFuture()) {
                return;
            }

            $this->refreshAuthToken();

            return;
        }

        $this->getNewAuthToken();
    }

    private function getNewAuthToken()
    {
        $authResponse = Http::fiskaly()->post('auth', [
            'api_key' => config('services.fiskaly.api_key'),
            'api_secret' => config('services.fiskaly.api_secret'),
        ])->throw();

        $authData = $authResponse->json();
        $this->accessToken = $authData['access_token'];
        $this->refreshToken = $authData['refresh_token'];
        // Set the auth data in the cache for 24 hours
        cache()->put('fiskaly_auth_data', encrypt($authData), $authData['refresh_token_expires_in'] - 60);
    }

    private function refreshAuthToken()
    {
        $authResponse = Http::fiskaly()->post('auth', [
            'refresh_token' => $this->refreshToken,
        ])->throw();

        $authData = $authResponse->json();
        $this->accessToken = $authData['access_token'];
        $this->refreshToken = $authData['refresh_token'];
        // Update the auth data in the cache
        cache()->put('fiskaly_auth_data', encrypt($authData), $authData['refresh_token_expires_in'] - 60);
    }

    private function request()
    {
        return Http::fiskaly()->withToken($this->getAccessToken());
    }

    private function getAccessToken()
    {
        $this->ensureAuth();

        return $this->accessToken;
    }

    public function changeAdminPin(string $oldPin, string $newPin): bool
    {
        $response = $this->request()->patch('tss/'.config('services.fiskaly.tss_id').'/admin', [
            'admin_puk' => $oldPin,
            'new_admin_pin' => $newPin,
        ])->throw();

        return true;
    }

    public function adminLogin()
    {
        $response = $this->request()->post('tss/'.config('services.fiskaly.tss_id').'/admin/auth', [
            'admin_pin' => config('services.fiskaly.puk'),
        ])->throw();

        return $response->json();
    }

    // admin logout
    public function adminLogout()
    {
        $response = $this->request()->post('tss/'.config('services.fiskaly.tss_id').'/admin/logout', [
            'admin_pin' => config('services.fiskaly.puk'),
        ])->throw();

        return true;
    }

    // update TSS State
    public function updateTssState(string $state): bool
    {
        if ($state === 'INITIALIZED') {
            $this->adminLogin();
        }
        $response = $this->request()->patch('tss/'.config('services.fiskaly.tss_id'), [
            'state' => $state,
        ])->throw();

        if ($state === 'INITIALIZED') {
            $this->adminLogout();
        }

        return true;
    }

    public function createClient(TseClient $tseClient): array
    {
        $this->adminLogin();
        $response = $this->request()
            ->put('tss/'.config('services.fiskaly.tss_id').'/client/'.$tseClient->remote_id, [
                'serial_number' => $tseClient->remote_id,
            ])
            ->throw();
        $this->adminLogout();

        return $response->json();
    }

    // update client
    public function updateClient(TseClient $tseClient): array
    {
        $this->adminLogin();
        $response = $this->request()
            ->patch('tss/'.config('services.fiskaly.tss_id').'/client/'.$tseClient->remote_id, [
                'state' => $tseClient->state->value,
            ])
            ->throw();
        $this->adminLogout();

        return $response->json();
    }

    public function updateOrCreateTransaction(Checkout $checkout)
    {
        $response = $this->request()
            ->put('tss/'.config('services.fiskaly.tss_id').'/tx/'.$checkout->remote_id.'?tx_revision='.$checkout->remote_rev_count, [
                'client_id' => $checkout->machine->tseClient->remote_id,
                'type' => 'RECEIPT',
                'state' => $checkout->status,
                'schema' => [
                    'standard_v1' => [
                        'receipt' => [
                            'receipt_type' => 'RECEIPT',
                            'amounts_per_vat_rate' => [
                                [
                                    'vat_rate' => 'NORMAL',
                                    'amount' => number_format(round($checkout->total / 100, 2), 2),
                                ],
                            ],
                            'amounts_per_payment_type' => [
                                [
                                    'payment_type' => 'CASH',
                                    'amount' => number_format(round($checkout->total / 100, 2), 2),
                                    'currency_code' => 'EUR',
                                ],
                            ],
                        ],
                    ],
                ],
            ])
            ->throw();
        $checkout->increment('remote_rev_count');

        $checkout->fiskaly_data = $response->json();
        $checkout->fiskaly_id = $response->json()['_id'];
        $checkout->save();
    }
}
