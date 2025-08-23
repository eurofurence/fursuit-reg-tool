<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrinterState extends Model
{
    protected $fillable = [
        'name',
        'status',
        'current_job_id',
        'last_error_message',
        'last_update',
        'machine_name'
    ];

    protected $casts = [
        'last_update' => 'datetime'
    ];

    public function currentJob(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Printing\Models\PrintJob::class, 'current_job_id');
    }

    // Static methods for managing printer states
    public static function updatePrinterState(string $printerName, string $status, ?int $jobId = null, ?string $errorMessage = null, ?string $machineName = null): self
    {
        return self::updateOrCreate(
            ['name' => $printerName],
            [
                'status' => $status,
                'current_job_id' => $jobId,
                'last_error_message' => $errorMessage,
                'last_update' => now(),
                'machine_name' => $machineName
            ]
        );
    }

    public static function getPrinterStates(): array
    {
        return self::with('currentJob')
            ->orderBy('name')
            ->get()
            ->keyBy('name')
            ->toArray();
    }

    public static function getPausedPrinters(): \Illuminate\Database\Eloquent\Collection
    {
        return self::where('status', 'paused')
            ->with('currentJob')
            ->orderBy('last_update', 'desc')
            ->get();
    }

    public static function clearPrinterError(string $printerName): bool
    {
        $printer = self::where('name', $printerName)->first();
        if ($printer && $printer->status === 'paused') {
            $printer->update([
                'status' => 'idle',
                'current_job_id' => null,
                'last_error_message' => null,
                'last_update' => now()
            ]);
            return true;
        }
        return false;
    }
}
