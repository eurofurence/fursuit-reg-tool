<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RegistrationApiService
{
    private string $baseUrl;
    
    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.attsrv.url'), '/');
    }

    /**
     * Get the status of a single attendee
     */
    public function getAttendeeStatus(int $attendeeId): ?string
    {
        $cacheKey = "attendee_status_{$attendeeId}";
        
        // Cache results for 5 minutes to avoid hammering the API
        return Cache::remember($cacheKey, 300, function () use ($attendeeId) {
            try {
                $token = $this->getValidBearerToken();
                
                if (!$token) {
                    throw new \Exception('No valid bearer token available');
                }

                $response = Http::withHeaders([
                    'Authorization' => "Bearer {$token}",
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json'
                ])
                ->timeout(30)
                ->get("{$this->baseUrl}/attendees/{$attendeeId}/status");

                if ($response->successful()) {
                    $data = $response->json();
                    return $data['status'] ?? null;
                }

                if ($response->status() === 404) {
                    return null; // Attendee not found
                }

                Log::warning("Registration API error for attendee {$attendeeId}", [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);

                return null;
            } catch (\Exception $e) {
                Log::error("Failed to check attendee {$attendeeId} status", [
                    'error' => $e->getMessage()
                ]);
                throw $e;
            }
        });
    }

    /**
     * Search for attendees by criteria and get their statuses
     */
    public function searchAttendees(array $criteria): array
    {
        try {
            $token = $this->getValidBearerToken();
            
            if (!$token) {
                throw new \Exception('No valid bearer token available');
            }

            $response = Http::withHeaders([
                'Authorization' => "Bearer {$token}",
                'Accept' => 'application/json',
                'Content-Type' => 'application/json'
            ])
            ->timeout(60) // Longer timeout for search
            ->post("{$this->baseUrl}/attendees/find", [
                'match_any' => $criteria,
                'fill_fields' => ['id', 'status']
            ]);

            if ($response->successful()) {
                $data = $response->json();
                return $data['attendees'] ?? [];
            }

            Log::warning("Registration API search error", [
                'status' => $response->status(),
                'body' => $response->body(),
                'criteria' => $criteria
            ]);

            return [];
        } catch (\Exception $e) {
            Log::error("Failed to search attendees", [
                'error' => $e->getMessage(),
                'criteria' => $criteria
            ]);
            throw $e;
        }
    }

    /**
     * Bulk get attendee statuses by IDs
     */
    public function getAttendeeStatuses(array $attendeeIds): array
    {
        $batchSize = 50; // API limit or reasonable batch size
        $results = [];

        $batches = array_chunk($attendeeIds, $batchSize);

        foreach ($batches as $batch) {
            try {
                $batchResults = $this->searchAttendees([
                    [
                        'ids' => $batch
                    ]
                ]);

                foreach ($batchResults as $attendee) {
                    $results[$attendee['id']] = $attendee['status'] ?? 'unknown';
                }
            } catch (\Exception $e) {
                Log::error("Failed to get batch attendee statuses", [
                    'error' => $e->getMessage(),
                    'batch' => $batch
                ]);
                
                // Mark all in this batch as error
                foreach ($batch as $attendeeId) {
                    $results[$attendeeId] = 'api_error';
                }
            }
        }

        return $results;
    }

    /**
     * Get a valid bearer token from any user
     * We need admin permissions to search attendees
     */
    private function getValidBearerToken(): ?string
    {
        // Try to find users with tokens (encrypted tokens are automatically decrypted by Laravel)
        $usersWithTokens = User::whereNotNull('token')
            ->whereNotNull('refresh_token')
            ->get();

        foreach ($usersWithTokens as $user) {
            try {
                $tokenService = new TokenRefreshService($user);
                $token = $tokenService->getValidAccessToken();
                
                if ($token) {
                    // Test if this token has admin permissions by trying a simple API call
                    if ($this->testTokenPermissions($token)) {
                        return $token;
                    }
                }
            } catch (\Exception $e) {
                Log::warning("Failed to get valid token for user {$user->id}: " . $e->getMessage());
                continue;
            }
        }

        return null;
    }

    /**
     * Test if a token has the required permissions for attendee searches
     */
    private function testTokenPermissions(string $token): bool
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$token}",
                'Accept' => 'application/json'
            ])
            ->timeout(10)
            ->get("{$this->baseUrl}/countdown");

            return $response->successful();
        } catch (\Exception $e) {
            return false;
        }
    }
}