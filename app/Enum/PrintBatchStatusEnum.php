<?php

namespace App\Enum;

/**
 * Lifecycle of a print batch.
 *
 * A printer works one batch through to completion before starting another, so
 * the batch is what an operator picks in the agent UI and what pauses when the
 * hardware faults.
 */
enum PrintBatchStatusEnum: string
{
    case Draft = 'draft';
    case Ready = 'ready';
    case Printing = 'printing';
    case Paused = 'paused';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function canTransitionTo(self $newStatus): bool
    {
        return match ($this) {
            self::Draft => in_array($newStatus, [self::Ready, self::Cancelled], true),
            self::Ready => in_array($newStatus, [self::Printing, self::Draft, self::Cancelled], true),
            self::Printing => in_array($newStatus, [self::Paused, self::Completed, self::Cancelled], true),
            self::Paused => in_array($newStatus, [self::Printing, self::Cancelled], true),
            self::Completed, self::Cancelled => false,
        };
    }

    /**
     * Whether the agent may claim jobs from a batch in this state.
     */
    public function isClaimable(): bool
    {
        return $this === self::Printing;
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Ready => 'Ready to print',
            self::Printing => 'Printing',
            self::Paused => 'Paused',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
        };
    }
}
