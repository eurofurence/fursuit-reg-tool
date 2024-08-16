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
                    'AUTH' => config('services.attsrv.cookies.AUTH'),
                    'JWT' => config('services.attsrv.cookies.JWT'),
                ],config('services.attsrv.cookies.domain'))
                ->post('/attendees/'.$user->attendee_id.'/additional-info/fursuitbadge',[
                    'has_fursuit_badge' => $user->badges()->exists(),
                ]);
            // Get and dd
            $response = Http::attsrv()
                ->asJson()
                ->withCookies([
                    'AUTH' => config('services.attsrv.cookies.AUTH'),
                    'JWT' => config('services.attsrv.cookies.JWT'),
                ],config('services.attsrv.cookies.domain'))
                ->get('/attendees/'.$user->attendee_id.'/additional-info/fursuitbadge');

            dd($response->json());
        });
    }
}
