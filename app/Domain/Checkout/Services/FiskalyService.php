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
        // Skip authentication if API credentials are not configured (e.g., in tests)
        if (! config('services.fiskaly.api_key') || ! config('services.fiskaly.api_secret')) {
            return;
        }

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
        if (! config('services.fiskaly.api_key') || ! config('services.fiskaly.api_secret')) {
            return [];
        }

        $response = $this->request()->post('tss/'.config('services.fiskaly.tss_id').'/admin/auth', [
            'admin_pin' => config('services.fiskaly.puk'),
        ])->throw();

        return $response->json();
    }

    // admin logout
    public function adminLogout()
    {
        if (! config('services.fiskaly.api_key') || ! config('services.fiskaly.api_secret')) {
            return true;
        }

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
        if (! config('services.fiskaly.api_key') || ! config('services.fiskaly.api_secret')) {
            return [];
        }

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
        if (! config('services.fiskaly.api_key') || ! config('services.fiskaly.api_secret')) {
            return [];
        }

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
        // Skip Fiskaly calls if API credentials are not configured (e.g., in tests)
        if (! config('services.fiskaly.api_key') || ! config('services.fiskaly.api_secret')) {
            return;
        }

        // Validate required data before making the API call
        if (! $checkout->remote_id) {
            throw new \InvalidArgumentException('Checkout must have a remote_id to update Fiskaly transaction');
        }

        if (! $checkout->machine?->tseClient?->remote_id) {
            throw new \InvalidArgumentException('Checkout must have a valid TSE client with remote_id');
        }

        // Define variables needed for the API request
        $paymentType = $this->mapPaymentMethodToFiskalyType($checkout->payment_method);
        $amount = number_format(round($checkout->total / 100, 2), 2);

        \Log::debug('Updating/Creating Fiskaly transaction', [
            'checkout_id' => $checkout->id,
            'remote_id' => $checkout->remote_id,
            'status' => $checkout->status,
            'payment_type' => $paymentType,
            'amount' => $amount,
            'revision' => $checkout->remote_rev_count,
        ]);

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
                                    'amount' => $amount,
                                ],
                            ],
                            'amounts_per_payment_type' => [
                                [
                                    'payment_type' => $paymentType,
                                    'amount' => $amount,
                                    'currency_code' => 'EUR',
                                ],
                            ],
                        ],
                    ],
                ],
            ]);

        if (! $response->successful()) {
            \Log::error('Fiskaly API request failed in updateOrCreateTransaction', [
                'checkout_id' => $checkout->id,
                'remote_id' => $checkout->remote_id,
                'status' => $response->status(),
                'body' => $response->body(),
                'payment_type' => $paymentType,
                'amount' => $amount,
            ]);
            $response->throw();
        }

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
     * Finish a transaction and get the end signature
     */
    public function finishTransaction(Checkout $checkout): void
    {
        // Skip Fiskaly calls if API credentials are not configured
        if (! config('services.fiskaly.api_key') || ! config('services.fiskaly.api_secret')) {
            return;
        }

        // Call Fiskaly to update transaction state to FINISHED
        $this->updateTransactionState($checkout, 'FINISHED');
    }

    /**
     * Cancel a transaction and get the end signature
     */
    public function cancelTransaction(Checkout $checkout): void
    {
        // Skip Fiskaly calls if API credentials are not configured
        if (! config('services.fiskaly.api_key') || ! config('services.fiskaly.api_secret')) {
            return;
        }

        // Call Fiskaly to update transaction state to CANCELLED
        $this->updateTransactionState($checkout, 'CANCELLED');
    }

    /**
     * Map internal payment methods to Fiskaly payment types
     */
    private function mapPaymentMethodToFiskalyType(?string $paymentMethod): string
    {
        return match (strtolower($paymentMethod ?? 'cash')) {
            'cash' => 'CASH',
            'card' => 'NON_CASH',
            'electronic_cash', 'ec' => 'ELECTRONIC_CASH',
            'credit_card' => 'CREDIT_CARD',
            default => 'CASH',
        };
    }

    /**
     * Update transaction state in Fiskaly with proper revision handling
     */
    private function updateTransactionState(Checkout $checkout, string $newState): void
    {
        // Validate required data before making the API call
        if (! $checkout->remote_id) {
            throw new \InvalidArgumentException('Checkout must have a remote_id to update Fiskaly transaction state');
        }

        if (! $checkout->machine?->tseClient?->remote_id) {
            throw new \InvalidArgumentException('Checkout must have a valid TSE client with remote_id');
        }

        $paymentType = $this->mapPaymentMethodToFiskalyType($checkout->payment_method);
        $amount = number_format(round($checkout->total / 100, 2), 2);

        \Log::debug('Updating Fiskaly transaction state', [
            'checkout_id' => $checkout->id,
            'remote_id' => $checkout->remote_id,
            'new_state' => $newState,
            'payment_type' => $paymentType,
            'amount' => $amount,
            'revision' => $checkout->remote_rev_count,
        ]);

        $response = $this->request()
            ->put('tss/'.config('services.fiskaly.tss_id').'/tx/'.$checkout->remote_id.'?tx_revision='.$checkout->remote_rev_count, [
                'client_id' => $checkout->machine->tseClient->remote_id,
                'type' => 'RECEIPT',
                'state' => $newState,
                'schema' => [
                    'standard_v1' => [
                        'receipt' => [
                            'receipt_type' => 'RECEIPT',
                            'amounts_per_vat_rate' => [
                                [
                                    'vat_rate' => 'NORMAL',
                                    'amount' => $amount,
                                ],
                            ],
                            'amounts_per_payment_type' => [
                                [
                                    'payment_type' => $paymentType,
                                    'amount' => $amount,
                                    'currency_code' => 'EUR',
                                ],
                            ],
                        ],
                    ],
                ],
            ]);

        if (! $response->successful()) {
            \Log::error('Fiskaly API request failed', [
                'checkout_id' => $checkout->id,
                'remote_id' => $checkout->remote_id,
                'new_state' => $newState,
                'status' => $response->status(),
                'body' => $response->body(),
                'payment_type' => $paymentType,
                'amount' => $amount,
            ]);
            $response->throw();
        }

        // Process the response and extract TSE data
        $fiskalyData = $response->json();

        // Update checkout with revision count and Fiskaly data
        // Don't update the status here - let the controller handle state transitions
        $checkout->remote_rev_count = $fiskalyData['revision'] ?? ($checkout->remote_rev_count + 1);
        $checkout->fiskaly_data = $fiskalyData;

        // Extract TSE compliance data (including end signature)
        $this->extractTseComplianceData($checkout, $fiskalyData);

        $checkout->save();
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

            // For completed transactions, extract end signature
            if (isset($signature['end_signature'])) {
                $checkout->tse_end_signature = $signature['end_signature'];
            } elseif (isset($fiskalyData['state']) && in_array($fiskalyData['state'], ['FINISHED', 'CANCELLED'])) {
                // Sometimes the end signature is in the main signature field for finished transactions
                $checkout->tse_end_signature = $signature['value'] ?? null;
            }
        }

        // Extract TSE timestamps (convert from Fiskaly format)
        // For KassenSichV §6 compliance, we need both start and end timestamps
        if (isset($fiskalyData['time_start'])) {
            $checkout->tse_timestamp = Carbon::parse($fiskalyData['time_start'])->toDateTimeString();
            // Store start time separately for explicit Vorgangsbeginn display
            $checkout->tse_start_timestamp = Carbon::parse($fiskalyData['time_start'])->toDateTimeString();
        }

        if (isset($fiskalyData['time_end'])) {
            $checkout->tse_end_timestamp = Carbon::parse($fiskalyData['time_end'])->toDateTimeString();
        }

        // Set process type for KassenSichV compliance
        $checkout->tse_process_type = 'Kassenbeleg-V1';

        // Store process data for audit trail (simplified)
        $checkout->tse_process_data = json_encode([
            'receipt_id' => "FSB-{$checkout->created_at->year}-{$checkout->id}",
            'total_amount' => $checkout->total,
            'payment_method' => $checkout->payment_method,
            'items_count' => $checkout->items()->count(),
            'transaction_state' => $fiskalyData['state'] ?? $checkout->status,
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
