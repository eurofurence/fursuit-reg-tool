# QZ.io Print System Integration Improvements - Laravel Implementation Plan

## Database Changes - Laravel Migrations

### Migration 1: Add Print Server Fields to Machines Table
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            $table->boolean('is_print_server')->default(false)->after('should_discover_printers');
            $table->string('qz_connection_status')->default('disconnected')->after('is_print_server');
            $table->timestamp('qz_last_seen_at')->nullable()->after('qz_connection_status');
            $table->unsignedInteger('pending_print_jobs_count')->default(0)->after('qz_last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            $table->dropColumn([
                'is_print_server',
                'qz_connection_status',
                'qz_last_seen_at',
                'pending_print_jobs_count'
            ]);
        });
    }
};
```

### Migration 2: Enhance Print Jobs Table
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('print_jobs', function (Blueprint $table) {
            // Add new tracking fields
            $table->timestamp('queued_at')->nullable()->after('printed_at');
            $table->timestamp('started_at')->nullable()->after('queued_at');
            $table->timestamp('failed_at')->nullable()->after('started_at');
            $table->text('error_message')->nullable()->after('failed_at');
            $table->unsignedTinyInteger('retry_count')->default(0)->after('error_message');
            $table->unsignedTinyInteger('priority')->default(5)->after('retry_count');
            $table->foreignId('processing_machine_id')->nullable()->constrained('machines')->nullOnDelete()->after('printer_id');
            
            // Add indexes for performance
            $table->index('status');
            $table->index(['status', 'priority', 'created_at']);
            $table->index('processing_machine_id');
        });
    }

    public function down(): void
    {
        Schema::table('print_jobs', function (Blueprint $table) {
            $table->dropForeign(['processing_machine_id']);
            $table->dropColumn([
                'queued_at',
                'started_at',
                'failed_at',
                'error_message',
                'retry_count',
                'priority',
                'processing_machine_id'
            ]);
            $table->dropIndex(['status']);
            $table->dropIndex(['status', 'priority', 'created_at']);
            $table->dropIndex(['processing_machine_id']);
        });
    }
};
```

### Migration 3: Create Printer Status Table
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('printer_statuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('printer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('machine_id')->constrained()->cascadeOnDelete();
            $table->string('status'); // PrinterStatusEnum
            $table->string('status_code')->nullable(); // e.g., 'media-empty', 'offline'
            $table->string('severity')->nullable(); // PrinterStatusSeverityEnum
            $table->text('message')->nullable();
            $table->json('metadata')->nullable(); // Additional status data
            $table->timestamps();
            
            $table->index(['printer_id', 'created_at']);
            $table->unique(['printer_id', 'machine_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('printer_statuses');
    }
};
```

## PHP Enums - Domain Specific

### app/Enum/QzConnectionStatusEnum.php
```php
<?php

namespace App\Enum;

enum QzConnectionStatusEnum: string
{
    case Connected = 'connected';
    case Disconnected = 'disconnected';
    case Connecting = 'connecting';
    case Error = 'error';
    case Reconnecting = 'reconnecting';

    public function getColor(): string
    {
        return match($this) {
            self::Connected => 'green',
            self::Disconnected => 'red',
            self::Connecting => 'yellow',
            self::Error => 'red',
            self::Reconnecting => 'orange',
        };
    }

    public function getLabel(): string
    {
        return match($this) {
            self::Connected => 'Connected',
            self::Disconnected => 'Disconnected',
            self::Connecting => 'Connecting...',
            self::Error => 'Connection Error',
            self::Reconnecting => 'Reconnecting...',
        };
    }
}
```

### app/Enum/PrintJobStatusEnum.php (Enhanced)
```php
<?php

namespace App\Enum;

enum PrintJobStatusEnum: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Printing = 'printing';
    case Printed = 'printed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Retrying = 'retrying';

    public function canTransitionTo(self $newStatus): bool
    {
        return match($this) {
            self::Pending => in_array($newStatus, [self::Queued, self::Cancelled]),
            self::Queued => in_array($newStatus, [self::Printing, self::Cancelled, self::Failed]),
            self::Printing => in_array($newStatus, [self::Printed, self::Failed]),
            self::Failed => in_array($newStatus, [self::Retrying, self::Cancelled]),
            self::Retrying => in_array($newStatus, [self::Queued, self::Cancelled]),
            self::Printed, self::Cancelled => false,
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Printed, self::Cancelled]);
    }

    public function isActive(): bool
    {
        return in_array($this, [self::Queued, self::Printing, self::Retrying]);
    }
}
```

### app/Enum/PrinterStatusEnum.php
```php
<?php

namespace App\Enum;

enum PrinterStatusEnum: string
{
    case Online = 'online';
    case Offline = 'offline';
    case Busy = 'busy';
    case Error = 'error';
    case MediaEmpty = 'media-empty';
    case MediaJam = 'media-jam';
    case CoverOpen = 'cover-open';
    case Paused = 'paused';
    case Unknown = 'unknown';

    public static function fromQzStatusCode(string $code): self
    {
        return match($code) {
            'online' => self::Online,
            'offline' => self::Offline,
            'media-empty' => self::MediaEmpty,
            'media-jam' => self::MediaJam,
            'cover-open' => self::CoverOpen,
            'paused' => self::Paused,
            default => self::Unknown,
        };
    }

    public function requiresAttention(): bool
    {
        return in_array($this, [
            self::Offline,
            self::Error,
            self::MediaEmpty,
            self::MediaJam,
            self::CoverOpen,
        ]);
    }
}
```

### app/Enum/PrinterStatusSeverityEnum.php
```php
<?php

namespace App\Enum;

enum PrinterStatusSeverityEnum: string
{
    case Fatal = 'FATAL';
    case Error = 'ERROR';
    case Warning = 'WARN';
    case Info = 'INFO';

    public function getLevel(): int
    {
        return match($this) {
            self::Fatal => 4,
            self::Error => 3,
            self::Warning => 2,
            self::Info => 1,
        };
    }
}
```

## Model Updates with Casts

### app/Models/Machine.php (Enhanced)
```php
<?php

namespace App\Models;

use App\Enum\QzConnectionStatusEnum;
use App\Domain\Printing\Models\Printer;
use App\Domain\Printing\Models\PrintJob;
use Bavix\Wallet\Traits\HasWalletFloat;
use Illuminate\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\Authorizable;

class Machine extends Model implements \Illuminate\Contracts\Auth\Authenticatable
{
    use Authenticatable, Authorizable, HasWalletFloat;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'should_discover_printers' => 'boolean',
        'is_print_server' => 'boolean',
        'qz_connection_status' => QzConnectionStatusEnum::class,
        'qz_last_seen_at' => 'datetime',
        'pending_print_jobs_count' => 'integer',
    ];

    // Existing relationships...

    public function processingPrintJobs()
    {
        return $this->hasMany(PrintJob::class, 'processing_machine_id');
    }

    public function printerStatuses()
    {
        return $this->hasMany(PrinterStatus::class);
    }

    public function scopePrintServers($query)
    {
        return $query->where('is_print_server', true);
    }

    public function scopeWithQzConnected($query)
    {
        return $query->where('qz_connection_status', QzConnectionStatusEnum::Connected);
    }

    public function isQzConnected(): bool
    {
        return $this->qz_connection_status === QzConnectionStatusEnum::Connected &&
               $this->qz_last_seen_at?->gt(now()->subMinutes(2));
    }

    public function updateQzStatus(QzConnectionStatusEnum $status): void
    {
        $this->update([
            'qz_connection_status' => $status,
            'qz_last_seen_at' => now(),
        ]);
    }

    public function getPendingPrintJobsCount(): int
    {
        return PrintJob::whereHas('printer', fn ($q) => $q->where('machine_id', $this->id))
            ->whereIn('status', [
                PrintJobStatusEnum::Pending,
                PrintJobStatusEnum::Queued,
                PrintJobStatusEnum::Retrying,
            ])
            ->count();
    }
}
```

### app/Domain/Printing/Models/PrintJob.php (Enhanced)
```php
<?php

namespace App\Domain\Printing\Models;

use App\Enum\PrintJobStatusEnum;
use App\Enum\PrintJobTypeEnum;
use App\Models\Machine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class PrintJob extends Model
{
    protected $guarded = [];

    protected $casts = [
        'printed_at' => 'datetime',
        'queued_at' => 'datetime',
        'started_at' => 'datetime',
        'failed_at' => 'datetime',
        'type' => PrintJobTypeEnum::class,
        'status' => PrintJobStatusEnum::class,
        'retry_count' => 'integer',
        'priority' => 'integer',
    ];

    public function printable()
    {
        return $this->morphTo();
    }

    public function printer()
    {
        return $this->belongsTo(Printer::class);
    }

    public function processingMachine()
    {
        return $this->belongsTo(Machine::class, 'processing_machine_id');
    }

    public function scopePending($query)
    {
        return $query->where('status', PrintJobStatusEnum::Pending);
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', [
            PrintJobStatusEnum::Queued,
            PrintJobStatusEnum::Printing,
            PrintJobStatusEnum::Retrying,
        ]);
    }

    public function scopePrioritized($query)
    {
        return $query->orderBy('priority', 'desc')
                     ->orderBy('created_at', 'asc');
    }

    public function transitionTo(PrintJobStatusEnum $newStatus, ?string $errorMessage = null): bool
    {
        if (!$this->status->canTransitionTo($newStatus)) {
            return false;
        }

        return DB::transaction(function () use ($newStatus, $errorMessage) {
            $updates = ['status' => $newStatus];

            switch ($newStatus) {
                case PrintJobStatusEnum::Queued:
                    $updates['queued_at'] = now();
                    break;
                case PrintJobStatusEnum::Printing:
                    $updates['started_at'] = now();
                    break;
                case PrintJobStatusEnum::Printed:
                    $updates['printed_at'] = now();
                    break;
                case PrintJobStatusEnum::Failed:
                    $updates['failed_at'] = now();
                    $updates['error_message'] = $errorMessage;
                    break;
                case PrintJobStatusEnum::Retrying:
                    $updates['retry_count'] = $this->retry_count + 1;
                    break;
            }

            return $this->update($updates);
        });
    }

    public function assignToMachine(Machine $machine): void
    {
        $this->update(['processing_machine_id' => $machine->id]);
    }

    public function canRetry(): bool
    {
        return $this->status === PrintJobStatusEnum::Failed &&
               $this->retry_count < 3;
    }

    public function shouldRetry(): bool
    {
        return $this->canRetry() &&
               $this->failed_at?->gt(now()->subMinutes(5));
    }
}
```

### app/Domain/Printing/Models/PrinterStatus.php (New)
```php
<?php

namespace App\Domain\Printing\Models;

use App\Enum\PrinterStatusEnum;
use App\Enum\PrinterStatusSeverityEnum;
use App\Models\Machine;
use Illuminate\Database\Eloquent\Model;

class PrinterStatus extends Model
{
    protected $guarded = [];

    protected $casts = [
        'status' => PrinterStatusEnum::class,
        'severity' => PrinterStatusSeverityEnum::class,
        'metadata' => 'array',
    ];

    public function printer()
    {
        return $this->belongsTo(Printer::class);
    }

    public function machine()
    {
        return $this->belongsTo(Machine::class);
    }

    public static function updateOrCreateForPrinter(
        Printer $printer, 
        Machine $machine,
        PrinterStatusEnum $status,
        ?string $statusCode = null,
        ?PrinterStatusSeverityEnum $severity = null,
        ?string $message = null,
        ?array $metadata = null
    ): self {
        return self::updateOrCreate(
            [
                'printer_id' => $printer->id,
                'machine_id' => $machine->id,
            ],
            [
                'status' => $status,
                'status_code' => $statusCode,
                'severity' => $severity,
                'message' => $message,
                'metadata' => $metadata,
            ]
        );
    }
}
```

## Comprehensive Test Suite

### tests/Unit/Enum/PrintJobStatusEnumTest.php
```php
<?php

namespace Tests\Unit\Enum;

use App\Enum\PrintJobStatusEnum;
use PHPUnit\Framework\TestCase;

class PrintJobStatusEnumTest extends TestCase
{
    public function test_can_transition_from_pending()
    {
        $status = PrintJobStatusEnum::Pending;
        
        $this->assertTrue($status->canTransitionTo(PrintJobStatusEnum::Queued));
        $this->assertTrue($status->canTransitionTo(PrintJobStatusEnum::Cancelled));
        $this->assertFalse($status->canTransitionTo(PrintJobStatusEnum::Printed));
        $this->assertFalse($status->canTransitionTo(PrintJobStatusEnum::Printing));
    }

    public function test_terminal_states_cannot_transition()
    {
        $printed = PrintJobStatusEnum::Printed;
        $cancelled = PrintJobStatusEnum::Cancelled;
        
        $this->assertTrue($printed->isTerminal());
        $this->assertTrue($cancelled->isTerminal());
        
        foreach (PrintJobStatusEnum::cases() as $status) {
            $this->assertFalse($printed->canTransitionTo($status));
            $this->assertFalse($cancelled->canTransitionTo($status));
        }
    }

    public function test_active_states()
    {
        $this->assertTrue(PrintJobStatusEnum::Queued->isActive());
        $this->assertTrue(PrintJobStatusEnum::Printing->isActive());
        $this->assertTrue(PrintJobStatusEnum::Retrying->isActive());
        
        $this->assertFalse(PrintJobStatusEnum::Pending->isActive());
        $this->assertFalse(PrintJobStatusEnum::Printed->isActive());
        $this->assertFalse(PrintJobStatusEnum::Failed->isActive());
        $this->assertFalse(PrintJobStatusEnum::Cancelled->isActive());
    }
}
```

### tests/Feature/Printing/PrintJobTransitionTest.php
```php
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
        $this->assertNotNull($job->fresh()->queued_at);
        $this->assertEquals(PrintJobStatusEnum::Queued, $job->fresh()->status);

        // Transition to printing
        $this->assertTrue($job->transitionTo(PrintJobStatusEnum::Printing));
        $this->assertNotNull($job->fresh()->started_at);

        // Transition to printed
        $this->assertTrue($job->transitionTo(PrintJobStatusEnum::Printed));
        $this->assertNotNull($job->fresh()->printed_at);

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
        $this->assertNotNull($job->fresh()->failed_at);
        $this->assertEquals('Printer offline', $job->fresh()->error_message);

        // Can retry
        $this->assertTrue($job->fresh()->canRetry());

        // Retry the job
        $this->assertTrue($job->transitionTo(PrintJobStatusEnum::Retrying));
        $this->assertEquals(1, $job->fresh()->retry_count);

        // Queue again
        $this->assertTrue($job->transitionTo(PrintJobStatusEnum::Queued));
    }

    public function test_print_job_max_retries()
    {
        $job = PrintJob::factory()->create([
            'status' => PrintJobStatusEnum::Failed,
            'retry_count' => 3,
        ]);

        $this->assertFalse($job->canRetry());
    }

    public function test_invalid_transition_fails()
    {
        $job = PrintJob::factory()->create([
            'status' => PrintJobStatusEnum::Pending,
        ]);

        // Cannot go directly from pending to printing
        $this->assertFalse($job->transitionTo(PrintJobStatusEnum::Printing));
        $this->assertEquals(PrintJobStatusEnum::Pending, $job->fresh()->status);
    }
}
```

### tests/Feature/Printing/MachineQzStatusTest.php
```php
<?php

namespace Tests\Feature\Printing;

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
        
        $this->assertEquals(QzConnectionStatusEnum::Connected, $machine->fresh()->qz_connection_status);
        $this->assertNotNull($machine->fresh()->qz_last_seen_at);
        $this->assertTrue($machine->fresh()->isQzConnected());
    }

    public function test_qz_connection_considered_lost_after_timeout()
    {
        $machine = Machine::factory()->create([
            'qz_connection_status' => QzConnectionStatusEnum::Connected,
            'qz_last_seen_at' => now()->subMinutes(3),
        ]);

        $this->assertFalse($machine->isQzConnected());
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

        PrintJob::factory()->create([
            'printer_id' => $printer->id,
            'status' => PrintJobStatusEnum::Printed,
        ]);

        $this->assertEquals(5, $machine->getPendingPrintJobsCount());
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
}
```

### tests/Feature/Printing/PrinterStatusTest.php
```php
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

    public function test_printer_status_updates_or_creates()
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

        $this->assertEquals(PrinterStatusEnum::Online, $status->status);
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

        $this->assertEquals($status->id, $updatedStatus->id);
        $this->assertEquals(PrinterStatusEnum::MediaEmpty, $updatedStatus->status);
        $this->assertEquals('Out of paper', $updatedStatus->message);
        
        // Should only have one status per printer-machine pair
        $this->assertEquals(1, PrinterStatus::where('printer_id', $printer->id)
            ->where('machine_id', $machine->id)
            ->count());
    }

    public function test_printer_status_requires_attention()
    {
        $this->assertTrue(PrinterStatusEnum::Offline->requiresAttention());
        $this->assertTrue(PrinterStatusEnum::MediaEmpty->requiresAttention());
        $this->assertTrue(PrinterStatusEnum::MediaJam->requiresAttention());
        
        $this->assertFalse(PrinterStatusEnum::Online->requiresAttention());
        $this->assertFalse(PrinterStatusEnum::Busy->requiresAttention());
    }

    public function test_printer_status_from_qz_code()
    {
        $this->assertEquals(PrinterStatusEnum::Online, PrinterStatusEnum::fromQzStatusCode('online'));
        $this->assertEquals(PrinterStatusEnum::MediaEmpty, PrinterStatusEnum::fromQzStatusCode('media-empty'));
        $this->assertEquals(PrinterStatusEnum::Unknown, PrinterStatusEnum::fromQzStatusCode('some-unknown-code'));
    }
}
```

### tests/Feature/Printing/PrintQueueProcessingTest.php
```php
<?php

namespace Tests\Feature\Printing;

use App\Domain\Printing\Models\PrintJob;
use App\Domain\Printing\Models\Printer;
use App\Enum\PrintJobStatusEnum;
use App\Models\Machine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrintQueueProcessingTest extends TestCase
{
    use RefreshDatabase;

    public function test_print_jobs_processed_by_priority()
    {
        $machine = Machine::factory()->create(['is_print_server' => true]);
        $printer = Printer::factory()->create(['machine_id' => $machine->id]);

        $lowPriorityJob = PrintJob::factory()->create([
            'printer_id' => $printer->id,
            'status' => PrintJobStatusEnum::Pending,
            'priority' => 1,
            'created_at' => now()->subMinutes(10),
        ]);

        $highPriorityJob = PrintJob::factory()->create([
            'printer_id' => $printer->id,
            'status' => PrintJobStatusEnum::Pending,
            'priority' => 10,
            'created_at' => now()->subMinutes(5),
        ]);

        $oldNormalJob = PrintJob::factory()->create([
            'printer_id' => $printer->id,
            'status' => PrintJobStatusEnum::Pending,
            'priority' => 5,
            'created_at' => now()->subMinutes(15),
        ]);

        $newNormalJob = PrintJob::factory()->create([
            'printer_id' => $printer->id,
            'status' => PrintJobStatusEnum::Pending,
            'priority' => 5,
            'created_at' => now()->subMinutes(1),
        ]);

        $jobs = PrintJob::pending()->prioritized()->get();

        // Should be ordered by priority desc, then created_at asc
        $this->assertEquals($highPriorityJob->id, $jobs[0]->id);
        $this->assertEquals($oldNormalJob->id, $jobs[1]->id);
        $this->assertEquals($newNormalJob->id, $jobs[2]->id);
        $this->assertEquals($lowPriorityJob->id, $jobs[3]->id);
    }

    public function test_machine_assignment_to_print_jobs()
    {
        $machine = Machine::factory()->create(['is_print_server' => true]);
        $job = PrintJob::factory()->create([
            'status' => PrintJobStatusEnum::Pending,
        ]);

        $job->assignToMachine($machine);

        $this->assertEquals($machine->id, $job->fresh()->processing_machine_id);
        $this->assertTrue($machine->processingPrintJobs->contains($job));
    }

    public function test_active_jobs_scope()
    {
        PrintJob::factory()->create(['status' => PrintJobStatusEnum::Pending]);
        PrintJob::factory()->create(['status' => PrintJobStatusEnum::Queued]);
        PrintJob::factory()->create(['status' => PrintJobStatusEnum::Printing]);
        PrintJob::factory()->create(['status' => PrintJobStatusEnum::Retrying]);
        PrintJob::factory()->create(['status' => PrintJobStatusEnum::Printed]);
        PrintJob::factory()->create(['status' => PrintJobStatusEnum::Failed]);
        PrintJob::factory()->create(['status' => PrintJobStatusEnum::Cancelled]);

        $activeJobs = PrintJob::active()->get();

        $this->assertEquals(3, $activeJobs->count());
        $this->assertTrue($activeJobs->contains('status', PrintJobStatusEnum::Queued));
        $this->assertTrue($activeJobs->contains('status', PrintJobStatusEnum::Printing));
        $this->assertTrue($activeJobs->contains('status', PrintJobStatusEnum::Retrying));
    }
}
```

### tests/Feature/API/PrinterControllerTest.php
```php
<?php

namespace Tests\Feature\API;

use App\Domain\Printing\Models\PrintJob;
use App\Domain\Printing\Models\Printer;
use App\Enum\PrintJobStatusEnum;
use App\Enum\QzConnectionStatusEnum;
use App\Models\Machine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrinterControllerTest extends TestCase
{
    use RefreshDatabase;

    protected Machine $machine;
    protected Printer $printer;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->machine = Machine::factory()->create([
            'is_print_server' => true,
            'should_discover_printers' => true,
        ]);
        
        $this->printer = Printer::factory()->create([
            'machine_id' => $this->machine->id,
        ]);
        
        $this->actingAs($this->machine, 'machine');
    }

    public function test_machine_can_update_qz_status()
    {
        $response = $this->postJson('/pos/auth/machines/qz-status', [
            'status' => QzConnectionStatusEnum::Connected->value,
            'pending_jobs' => 5,
        ]);

        $response->assertOk();
        
        $this->machine->refresh();
        $this->assertEquals(QzConnectionStatusEnum::Connected, $this->machine->qz_connection_status);
        $this->assertEquals(5, $this->machine->pending_print_jobs_count);
        $this->assertNotNull($this->machine->qz_last_seen_at);
    }

    public function test_machine_can_mark_job_as_queued()
    {
        $job = PrintJob::factory()->create([
            'printer_id' => $this->printer->id,
            'status' => PrintJobStatusEnum::Pending,
        ]);

        $response = $this->postJson("/pos/auth/printers/jobs/{$job->id}/queued");

        $response->assertOk();
        $this->assertEquals(PrintJobStatusEnum::Queued, $job->fresh()->status);
        $this->assertNotNull($job->fresh()->queued_at);
        $this->assertEquals($this->machine->id, $job->fresh()->processing_machine_id);
    }

    public function test_machine_can_mark_job_as_printing()
    {
        $job = PrintJob::factory()->create([
            'printer_id' => $this->printer->id,
            'status' => PrintJobStatusEnum::Queued,
            'processing_machine_id' => $this->machine->id,
        ]);

        $response = $this->postJson("/pos/auth/printers/jobs/{$job->id}/printing");

        $response->assertOk();
        $this->assertEquals(PrintJobStatusEnum::Printing, $job->fresh()->status);
        $this->assertNotNull($job->fresh()->started_at);
    }

    public function test_machine_can_mark_job_as_failed()
    {
        $job = PrintJob::factory()->create([
            'printer_id' => $this->printer->id,
            'status' => PrintJobStatusEnum::Printing,
            'processing_machine_id' => $this->machine->id,
        ]);

        $response = $this->postJson("/pos/auth/printers/jobs/{$job->id}/failed", [
            'error' => 'Printer offline',
        ]);

        $response->assertOk();
        $this->assertEquals(PrintJobStatusEnum::Failed, $job->fresh()->status);
        $this->assertNotNull($job->fresh()->failed_at);
        $this->assertEquals('Printer offline', $job->fresh()->error_message);
    }

    public function test_machine_can_retry_failed_job()
    {
        $job = PrintJob::factory()->create([
            'printer_id' => $this->printer->id,
            'status' => PrintJobStatusEnum::Failed,
            'retry_count' => 1,
        ]);

        $response = $this->postJson("/pos/auth/printers/jobs/{$job->id}/retry");

        $response->assertOk();
        $this->assertEquals(PrintJobStatusEnum::Retrying, $job->fresh()->status);
        $this->assertEquals(2, $job->fresh()->retry_count);
    }

    public function test_enhanced_job_index_returns_metadata()
    {
        PrintJob::factory()->count(3)->create([
            'printer_id' => $this->printer->id,
            'status' => PrintJobStatusEnum::Pending,
        ]);

        PrintJob::factory()->create([
            'printer_id' => $this->printer->id,
            'status' => PrintJobStatusEnum::Printing,
        ]);

        $response = $this->getJson('/pos/auth/printers/jobs');

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'printer',
                    'type',
                    'file',
                    'status',
                    'priority',
                    'retry_count',
                ],
            ],
            'meta' => [
                'total_pending',
                'total_printing',
                'printer_status',
            ],
        ]);

        $response->assertJson([
            'meta' => [
                'total_pending' => 3,
                'total_printing' => 1,
            ],
        ]);
    }
}
```

## Factory Updates

### database/factories/Domain/Printing/PrintJobFactory.php
```php
<?php

namespace Database\Factories\Domain\Printing;

use App\Domain\Printing\Models\PrintJob;
use App\Domain\Printing\Models\Printer;
use App\Enum\PrintJobStatusEnum;
use App\Enum\PrintJobTypeEnum;
use App\Models\Badge\Badge;
use Illuminate\Database\Eloquent\Factories\Factory;

class PrintJobFactory extends Factory
{
    protected $model = PrintJob::class;

    public function definition(): array
    {
        return [
            'printer_id' => Printer::factory(),
            'printable_type' => Badge::class,
            'printable_id' => Badge::factory(),
            'type' => $this->faker->randomElement(PrintJobTypeEnum::cases()),
            'status' => PrintJobStatusEnum::Pending,
            'file' => 'badges/' . $this->faker->uuid . '.pdf',
            'priority' => $this->faker->numberBetween(1, 10),
            'retry_count' => 0,
        ];
    }

    public function queued(): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => PrintJobStatusEnum::Queued,
            'queued_at' => now(),
        ]);
    }

    public function printing(): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => PrintJobStatusEnum::Printing,
            'queued_at' => now()->subMinutes(2),
            'started_at' => now(),
        ]);
    }

    public function printed(): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => PrintJobStatusEnum::Printed,
            'queued_at' => now()->subMinutes(5),
            'started_at' => now()->subMinutes(3),
            'printed_at' => now(),
        ]);
    }

    public function failed(): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => PrintJobStatusEnum::Failed,
            'failed_at' => now(),
            'error_message' => $this->faker->randomElement([
                'Printer offline',
                'Out of paper',
                'Paper jam',
                'Connection timeout',
            ]),
        ]);
    }
}
```

## Summary

This Laravel-specific implementation plan includes:

1. **Proper Laravel Migrations** - No database enums, using string fields with PHP enum casts
2. **PHP Enums with Business Logic** - Enums in App\Enum with helper methods
3. **Model Enhancements** - Proper casts, scopes, and relationships
4. **Comprehensive Test Suite** - Unit tests for enums, feature tests for transitions, API tests
5. **Factory Support** - Updated factories for easy testing

The system now properly tracks print job lifecycle, handles failures gracefully, and provides real-time status monitoring through QZ.io integration.