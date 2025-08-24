<?php

namespace App\Domain\Printing\Models;

use App\Enum\PrinterStatusEnum;
use App\Enum\PrintJobTypeEnum;
use App\Events\PrinterStatusUpdated;
use App\Models\Machine;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Printer extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected static function newFactory()
    {
        return \Database\Factories\PrinterFactory::new();
    }

    public function casts()
    {
        return [
            'type' => PrintJobTypeEnum::class,
            'status' => PrinterStatusEnum::class,
            'paper_sizes' => 'array',
            'last_state_update' => 'datetime',
        ];
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    public function currentJob(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Printing\Models\PrintJob::class, 'current_job_id');
    }

    public function printJobs()
    {
        return $this->hasMany(\App\Domain\Printing\Models\PrintJob::class);
    }

    // State management methods (migrated from PrinterState)
    public static function updatePrinterState(string $printerName, PrinterStatusEnum|string $status, ?int $jobId = null, ?string $errorMessage = null, ?string $machineName = null): ?self
    {
        $printer = self::where('name', $printerName)->first();

        if (! $printer) {
            return null;
        }

        $statusEnum = is_string($status) ? PrinterStatusEnum::from($status) : $status;

        $printer->update([
            'status' => $statusEnum->value,
            'current_job_id' => $jobId,
            'last_error_message' => $errorMessage,
            'last_state_update' => now(),
            'handling_machine_name' => $machineName,
        ]);

        // Determine printer type from name/config (simple heuristic)
        $printerType = self::determinePrinterType($printerName);

        // Broadcast status update to all POS clients
        broadcast(new PrinterStatusUpdated(
            $printerName,
            $printerType,
            $statusEnum,
            $errorMessage
        ));

        return $printer;
    }

    /**
     * Determine printer type based on printer name
     */
    private static function determinePrinterType(string $printerName): string
    {
        // Simple heuristic - you can make this more sophisticated based on your printer naming convention
        $nameLower = strtolower($printerName);

        if (str_contains($nameLower, 'receipt') || str_contains($nameLower, 'thermal') || str_contains($nameLower, 'pos')) {
            return 'receipt';
        }

        // Default to badge printer for card/badge printers
        return 'badge';
    }

    public static function getPrinterStates(): array
    {
        return self::with('currentJob')
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->keyBy('name')
            ->toArray();
    }

    public static function getPausedPrinters(): \Illuminate\Database\Eloquent\Collection
    {
        return self::whereIn('status', [PrinterStatusEnum::PAUSED->value, PrinterStatusEnum::OFFLINE->value])
            ->where('is_active', true)
            ->with('currentJob')
            ->orderBy('last_state_update', 'desc')
            ->get();
    }

    public static function clearPrinterError(string $printerName): bool
    {
        $printer = self::where('name', $printerName)->where('is_active', true)->first();

        if ($printer && in_array($printer->status, [PrinterStatusEnum::PAUSED, PrinterStatusEnum::OFFLINE])) {
            $printer->update([
                'status' => PrinterStatusEnum::IDLE->value,
                'current_job_id' => null,
                'last_error_message' => null,
                'last_state_update' => now(),
            ]);

            // Broadcast status update
            $printerType = self::determinePrinterType($printerName);
            broadcast(new PrinterStatusUpdated(
                $printerName,
                $printerType,
                PrinterStatusEnum::IDLE,
                null
            ));

            return true;
        }

        return false;
    }
}
