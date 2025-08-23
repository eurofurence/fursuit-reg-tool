<?php

namespace App\Events;

use App\Enum\PrinterStatusEnum;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PrinterStatusUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $printerName,
        public string $printerType, // 'badge' or 'receipt'
        public PrinterStatusEnum $status,
        public ?string $errorMessage = null
    ) {
        //
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('pos-printers'),
        ];
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'printer_name' => $this->printerName,
            'printer_type' => $this->printerType,
            'status' => $this->status->value,
            'status_label' => $this->status->getLabel(),
            'status_severity' => $this->status->getSeverity(),
            'status_icon' => $this->status->getIcon(),
            'error_message' => $this->errorMessage,
            'timestamp' => now()->toISOString(),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'printer.status.updated';
    }
}
