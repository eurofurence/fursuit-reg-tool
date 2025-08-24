<?php

use App\Console\Commands\BadgeAttendeeStatusReportCommand;
use App\Models\Badge\Badge;
use App\Models\Event;
use App\Models\EventUser;
use App\Models\Fursuit\Fursuit;
use App\Models\User;
use App\Services\RegistrationApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;

uses(RefreshDatabase::class);

test('badge attendee status report command runs successfully with no badges', function () {
    $this->artisan(BadgeAttendeeStatusReportCommand::class)
        ->expectsOutput('🎭 Badge Attendee Status Report')
        ->expectsOutput('No badges found with attendee IDs.')
        ->assertSuccessful();
});

test('badge attendee status report command runs with badges but no attendee ids', function () {
    // Create badges without attendee IDs
    Badge::factory()->create();
    
    $this->artisan(BadgeAttendeeStatusReportCommand::class)
        ->expectsOutput('🎭 Badge Attendee Status Report')
        ->expectsOutput('No badges found with attendee IDs.')
        ->assertSuccessful();
});

test('badge attendee status report command runs with badges and attendee ids', function () {
    // Create test data
    $event = Event::factory()->create();
    $user = User::factory()->create();
    
    $eventUser = EventUser::create([
        'user_id' => $user->id,
        'event_id' => $event->id,
        'attendee_id' => '12345',
        'valid_registration' => true,
        'prepaid_badges' => 1,
    ]);
    
    $fursuit = Fursuit::factory()
        ->for($user)
        ->for($event, 'event')
        ->create();
        
    $badge = Badge::factory()
        ->for($fursuit)
        ->create([
            'custom_id' => '12345-1'
        ]);

    // Mock the RegistrationApiService
    $apiServiceMock = Mockery::mock(RegistrationApiService::class);
    $apiServiceMock->shouldReceive('getAttendeeStatuses')
        ->once()
        ->with([12345])
        ->andReturn([12345 => 'approved']);
    
    $this->app->instance(RegistrationApiService::class, $apiServiceMock);

    $this->artisan(BadgeAttendeeStatusReportCommand::class)
        ->expectsOutput('🎭 Badge Attendee Status Report')
        ->expectsOutput('Found 1 badges with attendee IDs')
        ->expectsOutput('Checking status for 1 unique attendee IDs')
        ->expectsOutput('✅ Approved: 1')
        ->assertSuccessful();
});

test('badge attendee status report command handles api errors gracefully', function () {
    // Create test data
    $event = Event::factory()->create();
    $user = User::factory()->create();
    
    $eventUser = EventUser::create([
        'user_id' => $user->id,
        'event_id' => $event->id,
        'attendee_id' => '99999',
        'valid_registration' => true,
        'prepaid_badges' => 1,
    ]);
    
    $fursuit = Fursuit::factory()
        ->for($user)
        ->for($event, 'event')
        ->create();
        
    $badge = Badge::factory()
        ->for($fursuit)
        ->create([
            'custom_id' => '99999-1'
        ]);

    // Mock the RegistrationApiService to throw an exception
    $apiServiceMock = Mockery::mock(RegistrationApiService::class);
    $apiServiceMock->shouldReceive('getAttendeeStatuses')
        ->once()
        ->with([99999])
        ->andThrow(new \Exception('API connection failed'));
    
    $this->app->instance(RegistrationApiService::class, $apiServiceMock);

    $this->artisan(BadgeAttendeeStatusReportCommand::class)
        ->expectsOutput('🎭 Badge Attendee Status Report')
        ->expectsOutput('Found 1 badges with attendee IDs')
        ->expectsOutput('Failed to fetch attendee statuses: API connection failed')
        ->expectsOutput('⚠️  API Errors: 1 attendees could not be checked')
        ->assertSuccessful();
});

afterEach(function () {
    Mockery::close();
});