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
        return match ($this) {
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
