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