<?php

namespace App\Console\Commands;

use App\Models\Badge\Badge;
use App\Services\RegistrationApiService;
use Illuminate\Console\Command;

class BadgeAttendeeStatusReportCommand extends Command
{
    protected $signature = 'badge:attendee-status-report';

    protected $description = 'Report badge attendee statuses by checking against the registration service API using encrypted bearer tokens';

    public function handle(RegistrationApiService $apiService): void
    {
        $this->info('🎭 Badge Attendee Status Report');
        $this->info('====================================');

        // Get all badges that have attendee IDs
        $badges = Badge::whereNotNull('custom_id')
            ->whereHas('fursuit.user.eventUsers', function ($query) {
                $query->whereNotNull('attendee_id');
            })
            ->with(['fursuit.user.eventUsers'])
            ->get();

        if ($badges->isEmpty()) {
            $this->warn('No badges found with attendee IDs.');
            return;
        }

        $this->info("Found {$badges->count()} badges with attendee IDs");
        $this->newLine();

        // Group attendee IDs and get their statuses from registration service
        $attendeeIds = [];
        $badgesByAttendeeId = [];

        foreach ($badges as $badge) {
            foreach ($badge->fursuit->user->eventUsers as $eventUser) {
                if ($eventUser->attendee_id) {
                    $attendeeIds[] = (int) $eventUser->attendee_id;
                    $badgesByAttendeeId[$eventUser->attendee_id][] = $badge;
                }
            }
        }

        $uniqueAttendeeIds = array_unique($attendeeIds);
        $this->info("Checking status for " . count($uniqueAttendeeIds) . " unique attendee IDs");
        $this->newLine();

        // Initialize counters
        $statusCounts = [
            'approved' => 0,
            'cancelled' => 0, 
            'deleted' => 0,
            'new' => 0,
            'partially paid' => 0,
            'paid' => 0,
            'checked in' => 0,
            'waiting' => 0,
            'api_error' => 0,
            'not_found' => 0
        ];

        // Use batch operation for better performance
        $this->info('Fetching attendee statuses from registration service...');
        
        try {
            $attendeeStatuses = $apiService->getAttendeeStatuses($uniqueAttendeeIds);
            
            foreach ($uniqueAttendeeIds as $attendeeId) {
                $status = $attendeeStatuses[$attendeeId] ?? 'not_found';
                
                if (isset($statusCounts[$status])) {
                    $statusCounts[$status]++;
                } else if ($status === 'not_found') {
                    $statusCounts['not_found']++;
                } else if ($status === 'api_error') {
                    $statusCounts['api_error']++;
                } else {
                    $this->warn("Unknown status: {$status} for attendee {$attendeeId}");
                    $statusCounts['api_error']++;
                }
            }
            
        } catch (\Exception $e) {
            $this->error("Failed to fetch attendee statuses: " . $e->getMessage());
            $statusCounts['api_error'] = count($uniqueAttendeeIds);
        }
        $this->newLine(2);

        // Display results
        $this->info('📊 Badge Attendee Status Report');
        $this->info('================================');
        
        $this->table(
            ['Status', 'Count', 'Percentage'],
            collect($statusCounts)->map(function ($count, $status) use ($uniqueAttendeeIds) {
                $percentage = count($uniqueAttendeeIds) > 0 
                    ? number_format(($count / count($uniqueAttendeeIds)) * 100, 1) 
                    : '0.0';
                
                return [
                    ucfirst(str_replace('_', ' ', $status)),
                    $count,
                    $percentage . '%'
                ];
            })->toArray()
        );

        $this->newLine();
        $this->info("Total attendee IDs checked: " . count($uniqueAttendeeIds));
        $this->info("Total badges affected: {$badges->count()}");
        
        // Summary for key statuses
        $this->newLine();
        $this->info('🎯 Key Status Summary:');
        $this->line("✅ Approved: {$statusCounts['approved']}");
        $this->line("❌ Cancelled: {$statusCounts['cancelled']}");
        $this->line("🗑️ Deleted: {$statusCounts['deleted']}");
        
        if ($statusCounts['api_error'] > 0) {
            $this->newLine();
            $this->warn("⚠️  API Errors: {$statusCounts['api_error']} attendees could not be checked");
        }
        
        if ($statusCounts['not_found'] > 0) {
            $this->newLine();
            $this->warn("❓ Not Found: {$statusCounts['not_found']} attendee IDs were not found in registration system");
        }
    }
}