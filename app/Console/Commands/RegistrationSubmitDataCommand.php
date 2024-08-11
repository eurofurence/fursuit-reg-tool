<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class RegistrationSubmitDataCommand extends Command
{
    protected $signature = 'registration:submit-data';

    protected $description = 'Submit data to the registration service';

    public function handle(): void
    {
        $this->info('Submitting data to the registration service...');
        // Go trough all users that have a regid
        // and submit the data to the registration service
        User::lazy(100)->each(function (User $user) {
            $this->info("Submitting data for user {$user->id}");
            // Submit data to the registration service
            Http::attsrv()
                ->asJson()
                ->withCookies([
                    'AUTH' => 'ory_at_UkbfzhqYABBzgaonHUbjtU6ocGcOZERoidQkRtS6Qbs.XlapU0HTf5q-ZqAN6Ty9KJua3npo-YuVtI_ZDYP1YB4',
                    'JWT' => 'eyJhbGciOiJSUzI1NiIsImtpZCI6ImUwODE3MmQzLTBkODktNGYzZS1iMWZjLTA1ZDQ1MGNkODM5YSIsInR5cCI6IkpXVCJ9.eyJhdF9oYXNoIjoielR5VmJlRjBzd2NXZ0VnbU1aV3JidyIsImF1ZCI6WyJiOWExNTc5ZS02YjY2LTQ3NGYtOWRkNi00MWU2MThkZjBkNmQiXSwiYXV0aF90aW1lIjoxNzIzMzE4MTIxLCJhdmF0YXIiOiI2dnhETjh5c1MzMjFsYUlkaWlZSTNXdTcxazJIdEVLd0dLNlJiQnRWLndlYnAiLCJlbWFpbCI6Im1lQHRoaXJpdGluLmNvbSIsImVtYWlsX3ZlcmlmaWVkIjp0cnVlLCJleHAiOjE3MjMzMjk2MzksImdyb3VwcyI6WyJRUDROSkc4MzdYUjVWS1kxIiwiUFdFSksxWEtZOE82R1ZENCIsIjZNWllMNVhXRVhOT1JQSksiLCJQVjlNNEVYRTU4N0dSNTZLIiwiTjlPWTBLOE8wVjJSMVA3TCIsIjVKS1k5MzI1N1JYTDREN1AiLCJPM0RXTUxYWjA0WEo5NjU3IiwiUUUzVk1SMkxLOVgxUFcwNyIsIkVOM0dMNDJRMDcySktaUU8iLCJPRTdRWk4yUjdRMjlLV01MIiwiTjlPWTBLOE9KVlhSMVA3TCIsIjU0WllPRFgxNUcySzFNNzYiLCIwUlYzOVkyUDFNWDFKNE42IiwiNTBXWVBOWFZLTDJRN0dEWiIsIlFFM1ZNUjJMUTlYMVBXMDciXSwiaWF0IjoxNzIzMzI2MDM5LCJpc3MiOiJodHRwczovL2lkZW50aXR5LmV1cm9mdXJlbmNlLm9yZy8iLCJqdGkiOiJiNjQ1ZTc0My02OTkxLTRiNTUtOWVlNi02MzA1MDllZTJhYjQiLCJuYW1lIjoiVGluIiwicmF0IjoxNzIzMzI2MDM4LCJzaWQiOiIyZDg1YWYyNi1lZTlhLTQ3MDQtOGViNy05Y2NkNjdkMzFhYTIiLCJzdWIiOiJRTDg5UjY1ODNLTkRHM1dKIn0.mYF24uL3_KSpWbUogZ5vTUGWYpnZ74kQouD-Up_mXf3A0dhQw0Pnuea4v1093byau3hCW10upHX61Jfcpw-1mj7NxA0zoSjG6Dolptrn8k39d-sXA-1ngYQhZdNJMzTLmV9jJs9jAhdw_cKcS5Q7Y80osCb8pKjnCvCTAXI5rnEpqRSqGpCCJI9AivV47v_cYu55nRKptZ4g1ym0oA_EDZFAw5z79L1grrV5_SVqjhKs04p7Ptw57IEM9mdKVE3r7infud4bEAPzqwqJr99Q1IJa3eVPrSe3o42bfzmzaJEs1y1XnaZ-UFw7jMw3T906_Ff8HLowZyGLpGOxEegwbHwY6gE9NWkJvr6m86N9ovioFJ5b1zhanvfkekH2KAZ4Y3Hoxasb6RloTDUKCE6iwxY1JwWGFYj0gv44ngG-9Cjn-XByOLMUtqEpCIMxSYYVhXblivr5D8zuWH5CkviF10O4_5JvkOT_mY3DGPPJSUOVSvRy9cYA1iVeT_wAx1WxEY8fawINCXTGOVtdgpaCVnPSMLWxRHmswV_ffENpuWekjBz84hHnp0CuezA9RxRJ87vPh9KUGKnvyBaOYEeNrtN_hUTI67kngTNxqEEmQZrbickLpMauVPK66QH8xpZL0T4FRVQ34oORS3a8TRUbYYbOsQvNKwiotdhVTu1799M',
                ],'.regtest.eurofurence.org')
                ->post('/attendees/'.$user->attendee_id.'/additional-info/fursuitbadge',[
                    'has_fursuit_badge' => $user->badges()->exists(),
                ]);
            // Get and dd
            $response = Http::attsrv()
                ->asJson()
                ->withCookies([
                    'AUTH' => 'ory_at_UkbfzhqYABBzgaonHUbjtU6ocGcOZERoidQkRtS6Qbs.XlapU0HTf5q-ZqAN6Ty9KJua3npo-YuVtI_ZDYP1YB4',
                    'JWT' => 'eyJhbGciOiJSUzI1NiIsImtpZCI6ImUwODE3MmQzLTBkODktNGYzZS1iMWZjLTA1ZDQ1MGNkODM5YSIsInR5cCI6IkpXVCJ9.eyJhdF9oYXNoIjoielR5VmJlRjBzd2NXZ0VnbU1aV3JidyIsImF1ZCI6WyJiOWExNTc5ZS02YjY2LTQ3NGYtOWRkNi00MWU2MThkZjBkNmQiXSwiYXV0aF90aW1lIjoxNzIzMzE4MTIxLCJhdmF0YXIiOiI2dnhETjh5c1MzMjFsYUlkaWlZSTNXdTcxazJIdEVLd0dLNlJiQnRWLndlYnAiLCJlbWFpbCI6Im1lQHRoaXJpdGluLmNvbSIsImVtYWlsX3ZlcmlmaWVkIjp0cnVlLCJleHAiOjE3MjMzMjk2MzksImdyb3VwcyI6WyJRUDROSkc4MzdYUjVWS1kxIiwiUFdFSksxWEtZOE82R1ZENCIsIjZNWllMNVhXRVhOT1JQSksiLCJQVjlNNEVYRTU4N0dSNTZLIiwiTjlPWTBLOE8wVjJSMVA3TCIsIjVKS1k5MzI1N1JYTDREN1AiLCJPM0RXTUxYWjA0WEo5NjU3IiwiUUUzVk1SMkxLOVgxUFcwNyIsIkVOM0dMNDJRMDcySktaUU8iLCJPRTdRWk4yUjdRMjlLV01MIiwiTjlPWTBLOE9KVlhSMVA3TCIsIjU0WllPRFgxNUcySzFNNzYiLCIwUlYzOVkyUDFNWDFKNE42IiwiNTBXWVBOWFZLTDJRN0dEWiIsIlFFM1ZNUjJMUTlYMVBXMDciXSwiaWF0IjoxNzIzMzI2MDM5LCJpc3MiOiJodHRwczovL2lkZW50aXR5LmV1cm9mdXJlbmNlLm9yZy8iLCJqdGkiOiJiNjQ1ZTc0My02OTkxLTRiNTUtOWVlNi02MzA1MDllZTJhYjQiLCJuYW1lIjoiVGluIiwicmF0IjoxNzIzMzI2MDM4LCJzaWQiOiIyZDg1YWYyNi1lZTlhLTQ3MDQtOGViNy05Y2NkNjdkMzFhYTIiLCJzdWIiOiJRTDg5UjY1ODNLTkRHM1dKIn0.mYF24uL3_KSpWbUogZ5vTUGWYpnZ74kQouD-Up_mXf3A0dhQw0Pnuea4v1093byau3hCW10upHX61Jfcpw-1mj7NxA0zoSjG6Dolptrn8k39d-sXA-1ngYQhZdNJMzTLmV9jJs9jAhdw_cKcS5Q7Y80osCb8pKjnCvCTAXI5rnEpqRSqGpCCJI9AivV47v_cYu55nRKptZ4g1ym0oA_EDZFAw5z79L1grrV5_SVqjhKs04p7Ptw57IEM9mdKVE3r7infud4bEAPzqwqJr99Q1IJa3eVPrSe3o42bfzmzaJEs1y1XnaZ-UFw7jMw3T906_Ff8HLowZyGLpGOxEegwbHwY6gE9NWkJvr6m86N9ovioFJ5b1zhanvfkekH2KAZ4Y3Hoxasb6RloTDUKCE6iwxY1JwWGFYj0gv44ngG-9Cjn-XByOLMUtqEpCIMxSYYVhXblivr5D8zuWH5CkviF10O4_5JvkOT_mY3DGPPJSUOVSvRy9cYA1iVeT_wAx1WxEY8fawINCXTGOVtdgpaCVnPSMLWxRHmswV_ffENpuWekjBz84hHnp0CuezA9RxRJ87vPh9KUGKnvyBaOYEeNrtN_hUTI67kngTNxqEEmQZrbickLpMauVPK66QH8xpZL0T4FRVQ34oORS3a8TRUbYYbOsQvNKwiotdhVTu1799M',
                ],'.regtest.eurofurence.org')
                ->get('/attendees/'.$user->attendee_id.'/additional-info/fursuitbadge');

            dd($response->json());
        });
    }
}
