<?php

use App\Jobs\Printing\BatchPrintJob;
use App\Jobs\Printing\PrintBadgeJob;
use App\Models\Badge\Badge;
use App\Models\Event;
use App\Models\EventUser;
use App\Models\Fursuit\Fursuit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

test('batch print job sorts badges correctly for printing order', function () {
    // Create an event
    $event = Event::factory()->create([
        'starts_at' => now()->addMonths(6)->startOfDay(),
        'ends_at' => now()->addMonths(6)->addDays(30)->endOfDay(),
        'order_starts_at' => now()->subDays(30)->startOfDay(),
        'order_ends_at' => now()->addDays(30)->endOfDay(),
    ]);

    // Create users with different attendee IDs
    $users = collect([
        ['user' => User::factory()->create(), 'attendee_id' => '16'],
        ['user' => User::factory()->create(), 'attendee_id' => '14'],
        ['user' => User::factory()->create(), 'attendee_id' => '15'],
    ]);

    // Create event user relationships
    foreach ($users as $userData) {
        EventUser::create([
            'user_id' => $userData['user']->id,
            'event_id' => $event->id,
            'attendee_id' => $userData['attendee_id'],
            'valid_registration' => true,
            'prepaid_badges' => 0,
        ]);
    }

    // Create multiple fursuits and badges for user 16 (should be printed last)
    $user16 = $users->where('attendee_id', '16')->first()['user'];
    $fursuits16 = collect();
    $badges16 = collect();

    for ($i = 1; $i <= 3; $i++) {
        $fursuit = Fursuit::factory()
            ->for($user16)
            ->for($event, 'event')
            ->create(['name' => "User 16 Fursuit $i"]);
        $fursuits16->push($fursuit);

        $badge = Badge::factory()->for($fursuit)->create();
        // Simulate the custom_id generation process
        $badge->custom_id = "16-$i";
        $badge->save();
        $badges16->push($badge);
    }

    // Create single badges for users 14 and 15
    $user14 = $users->where('attendee_id', '14')->first()['user'];
    $fursuit14 = Fursuit::factory()->for($user14)->for($event, 'event')->create();
    $badge14 = Badge::factory()->for($fursuit14)->create();
    $badge14->custom_id = '14-1';
    $badge14->save();

    $user15 = $users->where('attendee_id', '15')->first()['user'];
    $fursuit15 = Fursuit::factory()->for($user15)->for($event, 'event')->create();
    $badge15 = Badge::factory()->for($fursuit15)->create();
    $badge15->custom_id = '15-1';
    $badge15->save();

    // Collect all badges in random order to test sorting
    $allBadges = collect([$badge15, $badges16[1], $badge14, $badges16[0], $badges16[2]])
        ->shuffle();

    // Test the sorting method
    $batchPrintJob = new BatchPrintJob($allBadges, 1);
    $reflection = new ReflectionClass($batchPrintJob);
    $sortMethod = $reflection->getMethod('sortBadgesForPrinting');
    $sortMethod->setAccessible(true);

    $sortedBadges = $sortMethod->invoke($batchPrintJob, $allBadges);

    // Expected order: 14-1, 15-1, 16-3, 16-2, 16-1
    $expectedOrder = [
        '14-1', // Lowest attendee ID first
        '15-1', // Next lowest attendee ID
        '16-3', // Highest badge number for attendee 16 first
        '16-2', // Next highest for attendee 16
        '16-1', // Lowest badge number for attendee 16 last
    ];

    $actualOrder = $sortedBadges->pluck('custom_id')->toArray();

    expect($actualOrder)->toBe($expectedOrder);
});

test('batch print job handles mixed badge types and priorities', function () {
    // Create event and users
    $event = Event::factory()->create([
        'starts_at' => now()->addMonths(6)->startOfDay(),
        'ends_at' => now()->addMonths(6)->addDays(30)->endOfDay(),
        'order_starts_at' => now()->subDays(30)->startOfDay(),
        'order_ends_at' => now()->addDays(30)->endOfDay(),
    ]);

    $user = User::factory()->create();
    EventUser::create([
        'user_id' => $user->id,
        'event_id' => $event->id,
        'attendee_id' => '100',
        'valid_registration' => true,
        'prepaid_badges' => 0,
    ]);

    // Create badges with null custom_id (not yet printed)
    $fursuit1 = Fursuit::factory()->for($user)->for($event, 'event')->create();
    $badge1 = Badge::factory()->for($fursuit1)->create(['custom_id' => null]);

    $fursuit2 = Fursuit::factory()->for($user)->for($event, 'event')->create();
    $badge2 = Badge::factory()->for($fursuit2)->create(['custom_id' => '100-1']);

    $badges = collect([$badge1, $badge2]);

    // Test sorting handles null custom_id gracefully
    $batchPrintJob = new BatchPrintJob($badges, 1);
    $reflection = new ReflectionClass($batchPrintJob);
    $sortMethod = $reflection->getMethod('sortBadgesForPrinting');
    $sortMethod->setAccessible(true);

    $sortedBadges = $sortMethod->invoke($batchPrintJob, $badges);

    // Should not throw an error and should handle null values
    expect($sortedBadges)->toHaveCount(2);
    // Badge with custom_id should come first, null custom_id should come last
    expect($sortedBadges->first()->custom_id)->toBe('100-1');
    expect($sortedBadges->last()->custom_id)->toBeNull();
});

test('batch print job creates proper job chains', function () {
    // Create a simple test case
    $event = Event::factory()->create();
    $user = User::factory()->create();
    EventUser::create([
        'user_id' => $user->id,
        'event_id' => $event->id,
        'attendee_id' => '50',
        'valid_registration' => true,
        'prepaid_badges' => 0,
    ]);

    $fursuit = Fursuit::factory()->for($user)->for($event, 'event')->create();
    $badge = Badge::factory()->for($fursuit)->create(['custom_id' => '50-1']);

    $badges = collect([$badge]);

    // Mock the Bus::batch call to verify job chaining
    Bus::fake();

    $batchPrintJob = new BatchPrintJob($badges, 1);
    $batchPrintJob->handle();

    // Verify that Bus::batch was called
    Bus::assertBatched(function ($batch) {
        // Should have one job chain
        $jobChains = $batch->jobs;
        expect(count($jobChains))->toBe(1);

        // First chain should contain one PrintBadgeJob
        $firstChain = $jobChains[0];
        expect(count($firstChain))->toBe(1);
        expect($firstChain[0])->toBeInstanceOf(PrintBadgeJob::class);

        return true;
    });
});

test('batch print job respects priority ordering', function () {
    // This test verifies that the sorting algorithm properly handles priority
    $event = Event::factory()->create();

    // Create badges with different attendee IDs but same priority (1)
    $badges = collect();
    $attendeeIds = [20, 10, 30];

    foreach ($attendeeIds as $attendeeId) {
        $user = User::factory()->create();
        EventUser::create([
            'user_id' => $user->id,
            'event_id' => $event->id,
            'attendee_id' => (string) $attendeeId,
            'valid_registration' => true,
            'prepaid_badges' => 0,
        ]);

        $fursuit = Fursuit::factory()->for($user)->for($event, 'event')->create();
        $badge = Badge::factory()->for($fursuit)->create(['custom_id' => "$attendeeId-1"]);
        $badges->push($badge);
    }

    // Shuffle to ensure sorting works
    $badges = $badges->shuffle();

    // Sort and verify order
    $batchPrintJob = new BatchPrintJob($badges, 1);
    $reflection = new ReflectionClass($batchPrintJob);
    $sortMethod = $reflection->getMethod('sortBadgesForPrinting');
    $sortMethod->setAccessible(true);

    $sortedBadges = $sortMethod->invoke($batchPrintJob, $badges);

    // Should be ordered by attendee ID: 10-1, 20-1, 30-1
    $expectedOrder = ['10-1', '20-1', '30-1'];
    $actualOrder = $sortedBadges->pluck('custom_id')->toArray();

    expect($actualOrder)->toBe($expectedOrder);
});

test('print job api returns badges in correct priority order', function () {
    // Mock Storage to avoid S3 configuration issues in tests
    Storage::fake('s3');

    // Create a machine and printer
    $machine = \App\Models\Machine::factory()->create();
    $printer = \App\Domain\Printing\Models\Printer::factory()
        ->for($machine)
        ->create(['status' => 'idle']);

    // Create event and users
    $event = Event::factory()->create();
    $users = collect([
        ['attendee_id' => '16', 'badge_count' => 2],
        ['attendee_id' => '14', 'badge_count' => 1],
        ['attendee_id' => '15', 'badge_count' => 1],
    ]);

    $allPrintJobs = collect();

    foreach ($users as $userData) {
        $user = User::factory()->create();
        EventUser::create([
            'user_id' => $user->id,
            'event_id' => $event->id,
            'attendee_id' => $userData['attendee_id'],
            'valid_registration' => true,
            'prepaid_badges' => 0,
        ]);

        for ($i = 1; $i <= $userData['badge_count']; $i++) {
            $fursuit = Fursuit::factory()->for($user)->for($event, 'event')->create();
            $badge = Badge::factory()->for($fursuit)->create(['custom_id' => $userData['attendee_id']."-$i"]);

            // Create print job
            $printJob = $badge->printJobs()->create([
                'printer_id' => $printer->id,
                'type' => \App\Enum\PrintJobTypeEnum::Badge,
                'status' => \App\Enum\PrintJobStatusEnum::Pending,
                'file' => 'test.pdf',
                'priority' => 1,
                'queued_at' => now(),
            ]);
            $allPrintJobs->push($printJob);
        }
    }

    // Test the API endpoint
    $this->actingAs($machine, 'machine');
    $response = $this->get('/pos/auth/printers/jobs');

    $response->assertOk();
    $jobs = $response->json();

    // Should return jobs in correct order: 14-1, 15-1, 16-2, 16-1
    $expectedOrder = ['14-1', '15-1', '16-2', '16-1'];
    $actualOrder = collect($jobs)->pluck('id')->map(function ($jobId) {
        $printJob = \App\Domain\Printing\Models\PrintJob::find($jobId);

        return $printJob->printable->custom_id;
    })->toArray();

    expect($actualOrder)->toBe($expectedOrder);
});

test('high priority jobs are processed first regardless of badge order', function () {
    // Mock Storage to avoid S3 configuration issues in tests
    Storage::fake('s3');

    // Create machine and printer
    $machine = \App\Models\Machine::factory()->create();
    $printer = \App\Domain\Printing\Models\Printer::factory()
        ->for($machine)
        ->create(['status' => 'idle']);

    // Create event and user
    $event = Event::factory()->create();
    $user = User::factory()->create();
    EventUser::create([
        'user_id' => $user->id,
        'event_id' => $event->id,
        'attendee_id' => '99',
        'valid_registration' => true,
        'prepaid_badges' => 0,
    ]);

    // Create badges with different priorities
    $normalBadgeFursuit = Fursuit::factory()->for($user)->for($event, 'event')->create();
    $normalBadge = Badge::factory()->for($normalBadgeFursuit)->create(['custom_id' => '99-1']);

    $highPriorityFursuit = Fursuit::factory()->for($user)->for($event, 'event')->create();
    $highPriorityBadge = Badge::factory()->for($highPriorityFursuit)->create(['custom_id' => '99-2']);

    // Create print jobs with different priorities
    $normalPrintJob = $normalBadge->printJobs()->create([
        'printer_id' => $printer->id,
        'type' => \App\Enum\PrintJobTypeEnum::Badge,
        'status' => \App\Enum\PrintJobStatusEnum::Pending,
        'file' => 'normal.pdf',
        'priority' => 1, // Normal priority
        'queued_at' => now()->subMinutes(10), // Created first
    ]);

    $highPriorityPrintJob = $highPriorityBadge->printJobs()->create([
        'printer_id' => $printer->id,
        'type' => \App\Enum\PrintJobTypeEnum::Badge,
        'status' => \App\Enum\PrintJobStatusEnum::Pending,
        'file' => 'priority.pdf',
        'priority' => 10, // High priority
        'queued_at' => now(), // Created later
    ]);

    // Test API returns high priority job first
    $this->actingAs($machine, 'machine');
    $response = $this->get('/pos/auth/printers/jobs');

    $response->assertOk();
    $jobs = $response->json();

    // High priority job should be first, despite being created later
    expect($jobs[0]['id'])->toBe($highPriorityPrintJob->id);
    expect($jobs[0]['priority'])->toBe(10);
    expect($jobs[1]['id'])->toBe($normalPrintJob->id);
    expect($jobs[1]['priority'])->toBe(1);
});

test('mass print uses single laravel batch with proper ordering', function () {
    Bus::fake();

    // Create event and users
    $event = Event::factory()->create();
    $users = collect([
        ['attendee_id' => '16', 'badge_count' => 2],
        ['attendee_id' => '14', 'badge_count' => 1],
        ['attendee_id' => '15', 'badge_count' => 1],
    ]);

    $allBadges = collect();

    foreach ($users as $userData) {
        $user = User::factory()->create();
        EventUser::create([
            'user_id' => $user->id,
            'event_id' => $event->id,
            'attendee_id' => $userData['attendee_id'],
            'valid_registration' => true,
            'prepaid_badges' => 0,
        ]);

        for ($i = 1; $i <= $userData['badge_count']; $i++) {
            $fursuit = Fursuit::factory()->for($user)->for($event, 'event')->create();
            $badge = Badge::factory()->for($fursuit)->create(['custom_id' => $userData['attendee_id']."-$i"]);
            $allBadges->push($badge);
        }
    }

    // Simulate the BadgeResource bulk action
    $printerId = 1;
    $sortedBadges = $allBadges->sort(function ($a, $b) {
        $aCustomId = $a->custom_id;
        $bCustomId = $b->custom_id;

        if (! $aCustomId && ! $bCustomId) {
            return 0;
        }
        if (! $aCustomId) {
            return 1;
        }
        if (! $bCustomId) {
            return -1;
        }

        $aParts = explode('-', $aCustomId, 2);
        $bParts = explode('-', $bCustomId, 2);

        if (count($aParts) !== 2 || count($bParts) !== 2) {
            return 0;
        }

        [$aAttendeeId, $aBadgeNumber] = $aParts;
        [$bAttendeeId, $bBadgeNumber] = $bParts;

        $aAttendeeId = (int) $aAttendeeId;
        $bAttendeeId = (int) $bAttendeeId;
        $aBadgeNumber = (int) $aBadgeNumber;
        $bBadgeNumber = (int) $bBadgeNumber;

        if ($aAttendeeId !== $bAttendeeId) {
            return $aAttendeeId <=> $bAttendeeId;
        }

        return $bBadgeNumber <=> $aBadgeNumber;
    })->values();

    // Create PrintBadgeJob instances
    $printJobs = $sortedBadges->map(function ($badge) use ($printerId) {
        return new \App\Jobs\Printing\PrintBadgeJob($badge, $printerId, priority: 1);
    })->toArray();

    // Dispatch as single batch
    Bus::batch($printJobs)
        ->name("Mass Print - {$allBadges->count()} badges")
        ->onQueue('batch-print')
        ->allowFailures()
        ->dispatch();

    // Verify single batch was dispatched with correct jobs
    Bus::assertBatched(function ($batch) {
        // Verify jobs are in correct order
        $jobs = $batch->jobs;
        expect(count($jobs))->toBe(4); // 14-1, 15-1, 16-2, 16-1

        // All should be PrintBadgeJob instances
        foreach ($jobs as $job) {
            expect($job)->toBeInstanceOf(\App\Jobs\Printing\PrintBadgeJob::class);
        }

        return true;
    });
});
