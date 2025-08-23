<?php

namespace Tests\Feature\Printing;

use App\Domain\Printing\Models\PrintJob;
use App\Domain\Printing\Models\Printer;
use App\Enum\PrintJobStatusEnum;
use App\Enum\QzConnectionStatusEnum;
use App\Models\Machine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MachineQzStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_machine_tracks_qz_connection_status()
    {
        $machine = Machine::factory()->create([
            'is_print_server' => true,
            'qz_connection_status' => QzConnectionStatusEnum::Disconnected,
        ]);

        // Update to connected
        $machine->updateQzStatus(QzConnectionStatusEnum::Connected);
        $machine->refresh();
        
        $this->assertEquals(QzConnectionStatusEnum::Connected, $machine->qz_connection_status);
        $this->assertNotNull($machine->qz_last_seen_at);
        $this->assertTrue($machine->isQzConnected());
    }

    public function test_qz_connection_considered_lost_after_timeout()
    {
        $machine = Machine::factory()->create([
            'qz_connection_status' => QzConnectionStatusEnum::Connected,
            'qz_last_seen_at' => now()->subMinutes(3),
        ]);

        $this->assertFalse($machine->isQzConnected());
    }

    public function test_qz_connection_valid_within_timeout()
    {
        $machine = Machine::factory()->create([
            'qz_connection_status' => QzConnectionStatusEnum::Connected,
            'qz_last_seen_at' => now()->subMinute(),
        ]);

        $this->assertTrue($machine->isQzConnected());
    }

    public function test_machine_counts_pending_print_jobs()
    {
        $machine = Machine::factory()->create(['is_print_server' => true]);
        $printer = Printer::factory()->create(['machine_id' => $machine->id]);

        PrintJob::factory()->count(3)->create([
            'printer_id' => $printer->id,
            'status' => PrintJobStatusEnum::Pending,
        ]);

        PrintJob::factory()->count(2)->create([
            'printer_id' => $printer->id,
            'status' => PrintJobStatusEnum::Queued,
        ]);

        PrintJob::factory()->count(1)->create([
            'printer_id' => $printer->id,
            'status' => PrintJobStatusEnum::Retrying,
        ]);

        // Should not count completed jobs
        PrintJob::factory()->create([
            'printer_id' => $printer->id,
            'status' => PrintJobStatusEnum::Printed,
        ]);

        PrintJob::factory()->create([
            'printer_id' => $printer->id,
            'status' => PrintJobStatusEnum::Failed,
        ]);

        $this->assertEquals(6, $machine->getPendingPrintJobsCount());
    }

    public function test_machine_counts_only_own_print_jobs()
    {
        $machine1 = Machine::factory()->create(['is_print_server' => true]);
        $machine2 = Machine::factory()->create(['is_print_server' => true]);
        
        $printer1 = Printer::factory()->create(['machine_id' => $machine1->id]);
        $printer2 = Printer::factory()->create(['machine_id' => $machine2->id]);

        PrintJob::factory()->count(3)->create([
            'printer_id' => $printer1->id,
            'status' => PrintJobStatusEnum::Pending,
        ]);

        PrintJob::factory()->count(2)->create([
            'printer_id' => $printer2->id,
            'status' => PrintJobStatusEnum::Pending,
        ]);

        $this->assertEquals(3, $machine1->getPendingPrintJobsCount());
        $this->assertEquals(2, $machine2->getPendingPrintJobsCount());
    }

    public function test_scope_filters_print_servers()
    {
        Machine::factory()->count(3)->create(['is_print_server' => false]);
        Machine::factory()->count(2)->create(['is_print_server' => true]);

        $this->assertEquals(2, Machine::printServers()->count());
    }

    public function test_scope_filters_qz_connected_machines()
    {
        Machine::factory()->create([
            'qz_connection_status' => QzConnectionStatusEnum::Connected,
            'qz_last_seen_at' => now(),
        ]);

        Machine::factory()->create([
            'qz_connection_status' => QzConnectionStatusEnum::Disconnected,
        ]);

        Machine::factory()->create([
            'qz_connection_status' => QzConnectionStatusEnum::Error,
        ]);

        $this->assertEquals(1, Machine::withQzConnected()->count());
    }

    public function test_qz_status_enum_casting()
    {
        $machine = Machine::factory()->create([
            'qz_connection_status' => 'connected',
        ]);

        $this->assertInstanceOf(QzConnectionStatusEnum::class, $machine->qz_connection_status);
        $this->assertEquals(QzConnectionStatusEnum::Connected, $machine->qz_connection_status);
    }
}