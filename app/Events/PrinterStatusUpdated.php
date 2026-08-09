<?php

namespace App\Events;

use App\Enum\PrinterConditionEnum;
use App\Enum\PrinterStatusEnum;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A printer changed state, pushed live to every POS screen.
 *
 * Carries presentation strings rather than an enum because two different
 * vocabularies feed it. The queue speaks PrinterStatusEnum; the print agent
 * reports hardware conditions (PrinterConditionEnum) read over SNMP. Both have
 * to arrive on one channel, because the POS shows one icon per printer and
 * cannot be asked to reconcile two overlapping event types.
 */
class PrinterStatusUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $printerName,
        public string $printerType, // 'badge' or 'receipt'
        public string $status,
        public string $statusLabel,
        public string $statusSeverity,
        public ?string $statusIcon = null,
        public ?string $errorMessage = null
    ) {
        //
    }

    /**
     * From a queue-side status.
     */
    public static function fromStatus(
        string $printerName,
        string $printerType,
        PrinterStatusEnum $status,
        ?string $errorMessage = null
    ): self {
        return new self(
            $printerName,
            $printerType,
            $status->value,
            $status->getLabel(),
            $status->getSeverity(),
            $status->getIcon(),
            $errorMessage,
        );
    }

    /**
     * From what the printer itself reported to the agent.
     */
    public static function fromCondition(
        string $printerName,
        string $printerType,
        PrinterConditionEnum $condition,
        ?string $errorMessage = null
    ): self {
        return new self(
            $printerName,
            $printerType,
            $condition->value,
            $condition->label(),
            $condition->severity(),
            null,
            $errorMessage ?? $condition->remedy(),
        );
    }

    public function broadcastOn(): array
    {
        return [
            new Channel('pos-printers'),
        ];
    }

    public function broadcastWith(): array
    {
        return [
            'printer_name' => $this->printerName,
            'printer_type' => $this->printerType,
            'status' => $this->status,
            'status_label' => $this->statusLabel,
            'status_severity' => $this->statusSeverity,
            'status_icon' => $this->statusIcon,
            'error_message' => $this->errorMessage,
            'timestamp' => now()->toISOString(),
        ];
    }

    public function broadcastAs(): string
    {
        return 'printer.status.updated';
    }
}
