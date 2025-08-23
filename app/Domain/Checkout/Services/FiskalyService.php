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
                                    'payment_type' => strtoupper($checkout->payment_method ?? 'CASH'),
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

        // Store complete Fiskaly response
        $fiskalyData = $response->json();
        $checkout->fiskaly_data = $fiskalyData;
        $checkout->fiskaly_id = $fiskalyData['_id'];

        // Extract required TSE compliance fields from Fiskaly response
        $this->extractTseComplianceData($checkout, $fiskalyData);

        $checkout->save();

        return $fiskalyData;
    }

    /**
     * Extract TSE compliance data from Fiskaly response
     */
    private function extractTseComplianceData(Checkout $checkout, array $fiskalyData): void
    {
        // Extract TSE serial number from TSS info
        $checkout->tse_serial_number = $fiskalyData['tss_id'] ?? config('services.fiskaly.tss_id');

        // Extract transaction number from Fiskaly
        $checkout->tse_transaction_number = $fiskalyData['number'] ?? null;

        // Extract signature information
        if (isset($fiskalyData['signature'])) {
            $signature = $fiskalyData['signature'];
            $checkout->tse_signature_counter = $signature['counter'] ?? null;
            $checkout->tse_start_signature = $signature['value'] ?? null;

            // For completed transactions, there might be an end signature
            if (isset($signature['end_signature'])) {
                $checkout->tse_end_signature = $signature['end_signature'];
            }
        }

        // Extract TSE timestamp (convert from Fiskaly format)
        if (isset($fiskalyData['time_start'])) {
            $checkout->tse_timestamp = Carbon::parse($fiskalyData['time_start'])->toDateTimeString();
        }

        // Set process type for KassenSichV compliance
        $checkout->tse_process_type = 'Kassenbeleg-V1';

        // Store process data for audit trail (simplified)
        $checkout->tse_process_data = json_encode([
            'receipt_id' => "FSB-{$checkout->created_at->year}-{$checkout->id}",
            'total_amount' => $checkout->total,
            'payment_method' => $checkout->payment_method,
            'items_count' => $checkout->items()->count(),
        ]);
    }

    /**
     * Get TSS info for compliance reporting
     */
    public function getTssInfo(): array
    {
        $response = $this->request()
            ->get('tss/'.config('services.fiskaly.tss_id'))
            ->throw();

        return $response->json();
    }

    /**
     * Get transaction details for audit purposes
     */
    public function getTransaction(string $transactionId): array
    {
        $response = $this->request()
            ->get('tss/'.config('services.fiskaly.tss_id').'/tx/'.$transactionId)
            ->throw();

        return $response->json();
    }
}
