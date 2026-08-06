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
            self::Pending => in_array($newStatus, [self::Queued, self::Cancelled], true),
            // Back to Pending covers an expired lease: the agent that claimed the
            // job died, so it returns to the queue rather than being lost.
            self::Queued => in_array($newStatus, [self::Printing, self::Pending, self::Failed, self::Cancelled], true),
            self::Printing => in_array($newStatus, [self::Printed, self::Failed, self::Pending], true),
            self::Failed => in_array($newStatus, [self::Retrying, self::Cancelled], true),
            self::Retrying => in_array($newStatus, [self::Queued, self::Cancelled], true),
            self::Printed, self::Cancelled => false,
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Printed, self::Cancelled], true);
    }

    public function isActive(): bool
    {
        return in_array($this, [self::Queued, self::Printing, self::Retrying], true);
    }

    /**
     * Whether an agent currently holds a lease on this job. Such a job is
     * reclaimable once that lease expires.
     */
    public function holdsLease(): bool
    {
        return in_array($this, [self::Queued, self::Printing], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Queued => 'Claimed',
            self::Printing => 'Printing',
            self::Printed => 'Printed',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
            self::Retrying => 'Retrying',
        };
    }
}
