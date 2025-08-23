<?php

namespace Tests\Feature\Printing;

use App\Domain\Printing\Models\Printer;
use App\Domain\Printing\Models\PrinterStatus;
use App\Enum\PrinterStatusEnum;
use App\Enum\PrinterStatusSeverityEnum;
use App\Models\Machine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrinterStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_printer_status_creates_new_record()
    {
        $machine = Machine::factory()->create();
        $printer = Printer::factory()->create();

        $status = PrinterStatus::updateOrCreateForPrinter(
            $printer,
            $machine,
            PrinterStatusEnum::Online,
            null,
            PrinterStatusSeverityEnum::Info,
            'Printer ready'
        );

        $this->assertDatabaseHas('printer_statuses', [
            'printer_id' => $printer->id,
            'machine_id' => $machine->id,
            'status' => 'online',
            'severity' => 'INFO',
            'message' => 'Printer ready',
        ]);

        $this->assertEquals(PrinterStatusEnum::Online, $status->status);
        $this->assertEquals(PrinterStatusSeverityEnum::Info, $status->severity);
        $this->assertEquals('Printer ready', $status->message);
    }

    public function test_printer_status_updates_existing_record()
    {
        $machine = Machine::factory()->create();
        $printer = Printer::factory()->create();

        // Create initial status
        $status = PrinterStatus::updateOrCreateForPrinter(
            $printer,
            $machine,
            PrinterStatusEnum::Online,
            null,
            PrinterStatusSeverityEnum::Info,
            'Printer ready'
        );

        $this->assertEquals('Printer ready', $status->message);

        // Update existing status
        $updatedStatus = PrinterStatus::updateOrCreateForPrinter(
            $printer,
            $machine,
            PrinterStatusEnum::MediaEmpty,
            'media-empty',
            PrinterStatusSeverityEnum::Error,
            'Out of paper'
        );

        // Should be the same record
        $this->assertEquals($status->id, $updatedStatus->id);
        $this->assertEquals(PrinterStatusEnum::MediaEmpty, $updatedStatus->status);
        $this->assertEquals('media-empty', $updatedStatus->status_code);
        $this->assertEquals(PrinterStatusSeverityEnum::Error, $updatedStatus->severity);
        $this->assertEquals('Out of paper', $updatedStatus->message);

        // Should only have one status per printer-machine pair
        $this->assertEquals(1, PrinterStatus::where('printer_id', $printer->id)
            ->where('machine_id', $machine->id)
            ->count());
    }

    public function test_printer_status_with_metadata()
    {
        $machine = Machine::factory()->create();
        $printer = Printer::factory()->create();

        $metadata = [
            'ink_level' => 25,
            'paper_count' => 0,
            'temperature' => 45
        ];

        $status = PrinterStatus::updateOrCreateForPrinter(
            $printer,
            $machine,
            PrinterStatusEnum::MediaEmpty,
            'media-empty',
            PrinterStatusSeverityEnum::Warning,
            'Low ink and out of paper',
            $metadata
        );

        $this->assertEquals($metadata, $status->metadata);
        $this->assertEquals(25, $status->metadata['ink_level']);
        $this->assertEquals(0, $status->metadata['paper_count']);
    }

    public function test_printer_status_relationships()
    {
        $machine = Machine::factory()->create();
        $printer = Printer::factory()->create();

        $status = PrinterStatus::updateOrCreateForPrinter(
            $printer,
            $machine,
            PrinterStatusEnum::Online
        );

        $this->assertEquals($printer->id, $status->printer->id);
        $this->assertEquals($machine->id, $status->machine->id);
        $this->assertTrue($machine->printerStatuses->contains($status));
    }

    public function test_multiple_machines_can_have_status_for_same_printer()
    {
        $machine1 = Machine::factory()->create();
        $machine2 = Machine::factory()->create();
        $printer = Printer::factory()->create();

        $status1 = PrinterStatus::updateOrCreateForPrinter(
            $printer,
            $machine1,
            PrinterStatusEnum::Online,
            null,
            PrinterStatusSeverityEnum::Info,
            'Online from machine 1'
        );

        $status2 = PrinterStatus::updateOrCreateForPrinter(
            $printer,
            $machine2,
            PrinterStatusEnum::Offline,
            'offline',
            PrinterStatusSeverityEnum::Error,
            'Offline from machine 2'
        );

        $this->assertNotEquals($status1->id, $status2->id);
        $this->assertEquals(PrinterStatusEnum::Online, $status1->status);
        $this->assertEquals(PrinterStatusEnum::Offline, $status2->status);
        $this->assertEquals('Online from machine 1', $status1->message);
        $this->assertEquals('Offline from machine 2', $status2->message);

        // Should have 2 different status records
        $this->assertEquals(2, PrinterStatus::where('printer_id', $printer->id)->count());
    }

    public function test_printer_status_enum_casting()
    {
        $machine = Machine::factory()->create();
        $printer = Printer::factory()->create();

        $status = PrinterStatus::create([
            'printer_id' => $printer->id,
            'machine_id' => $machine->id,
            'status' => 'media-jam',
            'severity' => 'ERROR',
        ]);

        $this->assertInstanceOf(PrinterStatusEnum::class, $status->status);
        $this->assertInstanceOf(PrinterStatusSeverityEnum::class, $status->severity);
        $this->assertEquals(PrinterStatusEnum::MediaJam, $status->status);
        $this->assertEquals(PrinterStatusSeverityEnum::Error, $status->severity);
    }
}