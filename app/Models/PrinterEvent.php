<?php

namespace App\Models;

use App\Enum\PrinterStatusEnum;
use Illuminate\Database\Eloquent\Model;

class PrinterEvent extends Model
{
    protected $fillable = [
        'printer_name',
        'event_type',
        'status',
        'severity',
        'message',
        'machine_name',
        'raw_event',
        'handled',
        'event_time'
    ];

    protected $casts = [
        'raw_event' => 'array',
        'handled' => 'boolean',
        'event_time' => 'datetime'
    ];

    // Static method to log printer events and handle automatic actions
    public static function logAndHandle(array $eventData): self
    {
        $event = self::create([
            'printer_name' => $eventData['printer_name'],
            'event_type' => $eventData['event_type'] ?? 'PRINTER',
            'status' => $eventData['status'],
            'severity' => $eventData['severity'] ?? 'INFO',
            'message' => $eventData['message'],
            'machine_name' => $eventData['machine_name'] ?? null,
            'raw_event' => $eventData,
            'event_time' => now(),
            'handled' => false
        ]);

        // Auto-handle critical events that should pause printers
        if ($event->shouldPausePrinter()) {
            $event->pausePrinter();
        }
        // Auto-handle recovery events that should restore printers
        elseif ($event->shouldRestorePrinter()) {
            $event->restorePrinter();
        }

        return $event;
    }

    // Determine if this event should pause the printer
    public function shouldPausePrinter(): bool
    {
        $pauseTriggers = [
            'OFFLINE',
            'PAPER_OUT', 'PAPEROUT', 'OUT_OF_PAPER',
            'PAPER_JAM', 'JAM', 'MEDIA_JAM',
            'DOOR_OPEN', 'COVER_OPEN',
            'USER_INTERVENTION', 'INTERVENTION_REQUIRED',
            'ERROR', 'FATAL'
        ];

        return in_array($this->severity, ['ERROR', 'FATAL']) || 
               collect($pauseTriggers)->some(fn($trigger) => 
                   str_contains(strtoupper($this->status), $trigger) || 
                   str_contains(strtoupper($this->message), $trigger)
               );
    }

    // Pause the printer due to this event
    public function pausePrinter(): void
    {
        // Determine appropriate status based on event type
        $status = $this->determineStatusFromEvent();
        
        \App\Domain\Printing\Models\Printer::updatePrinterState(
            $this->printer_name,
            $status,
            null, // Don't change current job
            $this->message,
            $this->machine_name
        );

        $this->update(['handled' => true]);
        
        \Log::warning("Printer {$this->printer_name} set to {$status->value} due to event: {$this->message}");
    }

    // Determine the appropriate status based on the event
    private function determineStatusFromEvent(): PrinterStatusEnum
    {
        // OFFLINE events should set printer to offline status
        if (str_contains(strtoupper($this->status), 'OFFLINE') || 
            str_contains(strtoupper($this->message), 'OFFLINE')) {
            return PrinterStatusEnum::OFFLINE;
        }
        
        // All other critical events should pause the printer
        return PrinterStatusEnum::PAUSED;
    }

    // Determine if this event should restore the printer to idle
    public function shouldRestorePrinter(): bool
    {
        $restoreTriggers = [
            'OK', 'READY', 'ONLINE', 'IDLE', 'AVAILABLE'
        ];

        // Only restore if the event indicates printer is ready/ok
        $statusIndicatesReady = collect($restoreTriggers)->some(fn($trigger) => 
            str_contains(strtoupper($this->status), $trigger) || 
            str_contains(strtoupper($this->message), $trigger)
        );

        if (!$statusIndicatesReady) {
            return false;
        }

        // Check if the printer is currently in a state that needs restoration
        $printer = \App\Domain\Printing\Models\Printer::where('name', $this->printer_name)->first();
        
        if (!$printer) {
            return false;
        }

        // Only restore if printer is currently OFFLINE or PAUSED
        $currentStatus = $printer->status; // Already cast to enum by model
        return in_array($currentStatus, [PrinterStatusEnum::OFFLINE, PrinterStatusEnum::PAUSED]);
    }

    // Restore the printer to idle state
    public function restorePrinter(): void
    {
        \App\Domain\Printing\Models\Printer::updatePrinterState(
            $this->printer_name,
            PrinterStatusEnum::IDLE,
            null, // Clear any current job assignment
            null, // Clear error message
            $this->machine_name
        );

        $this->update(['handled' => true]);
        
        \Log::info("Printer {$this->printer_name} restored to IDLE due to recovery event: {$this->message}");
    }

    // Get recent events for a printer
    public static function getRecentForPrinter(string $printerName, int $limit = 10)
    {
        return self::where('printer_name', $printerName)
            ->orderBy('event_time', 'desc')
            ->limit($limit)
            ->get();
    }
}
