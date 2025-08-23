<?php

namespace Tests\Feature\Printing;

use App\Domain\Printing\Models\PrintJob;
use App\Enum\PrintJobStatusEnum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrintJobTransitionTest extends TestCase
{
    use RefreshDatabase;

    public function test_print_job_transitions_through_lifecycle()
    {
        $job = PrintJob::factory()->create([
            'status' => PrintJobStatusEnum::Pending,
        ]);

        // Transition to queued
        $this->assertTrue($job->transitionTo(PrintJobStatusEnum::Queued));
        $job->refresh();
        $this->assertEquals(PrintJobStatusEnum::Queued, $job->status);
        $this->assertNotNull($job->queued_at);

        // Transition to printing
        $this->assertTrue($job->transitionTo(PrintJobStatusEnum::Printing));
        $job->refresh();
        $this->assertEquals(PrintJobStatusEnum::Printing, $job->status);
        $this->assertNotNull($job->started_at);

        // Transition to printed
        $this->assertTrue($job->transitionTo(PrintJobStatusEnum::Printed));
        $job->refresh();
        $this->assertEquals(PrintJobStatusEnum::Printed, $job->status);
        $this->assertNotNull($job->printed_at);

        // Cannot transition from terminal state
        $this->assertFalse($job->transitionTo(PrintJobStatusEnum::Queued));
    }

    public function test_print_job_handles_failure_and_retry()
    {
        $job = PrintJob::factory()->create([
            'status' => PrintJobStatusEnum::Printing,
            'retry_count' => 0,
        ]);

        // Fail the job
        $this->assertTrue($job->transitionTo(PrintJobStatusEnum::Failed, 'Printer offline'));
        $job->refresh();
        $this->assertEquals(PrintJobStatusEnum::Failed, $job->status);
        $this->assertNotNull($job->failed_at);
        $this->assertEquals('Printer offline', $job->error_message);

        // Can retry
        $this->assertTrue($job->canRetry());

        // Retry the job
        $this->assertTrue($job->transitionTo(PrintJobStatusEnum::Retrying));
        $job->refresh();
        $this->assertEquals(PrintJobStatusEnum::Retrying, $job->status);
        $this->assertEquals(1, $job->retry_count);

        // Queue again
        $this->assertTrue($job->transitionTo(PrintJobStatusEnum::Queued));
        $job->refresh();
        $this->assertEquals(PrintJobStatusEnum::Queued, $job->status);
    }

    public function test_print_job_max_retries()
    {
        $job = PrintJob::factory()->create([
            'status' => PrintJobStatusEnum::Failed,
            'retry_count' => 3,
        ]);

        $this->assertFalse($job->canRetry());
    }

    public function test_print_job_should_retry_timing()
    {
        $job = PrintJob::factory()->create([
            'status' => PrintJobStatusEnum::Failed,
            'retry_count' => 1,
            'failed_at' => now()->subMinutes(6),
        ]);

        $this->assertTrue($job->canRetry());
        $this->assertTrue($job->shouldRetry());

        // Job that failed too recently
        $recentJob = PrintJob::factory()->create([
            'status' => PrintJobStatusEnum::Failed,
            'retry_count' => 1,
            'failed_at' => now()->subMinutes(2),
        ]);

        $this->assertTrue($recentJob->canRetry());
        $this->assertFalse($recentJob->shouldRetry());
    }

    public function test_invalid_transition_fails()
    {
        $job = PrintJob::factory()->create([
            'status' => PrintJobStatusEnum::Pending,
        ]);

        // Cannot go directly from pending to printing
        $this->assertFalse($job->transitionTo(PrintJobStatusEnum::Printing));
        $job->refresh();
        $this->assertEquals(PrintJobStatusEnum::Pending, $job->status);

        // No timestamps should be updated
        $this->assertNull($job->queued_at);
        $this->assertNull($job->started_at);
        $this->assertNull($job->printed_at);
        $this->assertNull($job->failed_at);
    }

    public function test_transition_preserves_existing_timestamps()
    {
        $queuedAt = now()->subMinutes(10);
        $startedAt = now()->subMinutes(5);

        $job = PrintJob::factory()->create([
            'status' => PrintJobStatusEnum::Printing,
            'queued_at' => $queuedAt,
            'started_at' => $startedAt,
        ]);

        // Transition to printed
        $this->assertTrue($job->transitionTo(PrintJobStatusEnum::Printed));
        $job->refresh();

        // Previous timestamps should be preserved
        $this->assertEquals($queuedAt->format('Y-m-d H:i:s'), $job->queued_at->format('Y-m-d H:i:s'));
        $this->assertEquals($startedAt->format('Y-m-d H:i:s'), $job->started_at->format('Y-m-d H:i:s'));
        $this->assertNotNull($job->printed_at);
    }
}
