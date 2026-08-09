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
            // Printed and Failed are reachable from Pending because a reaped job is
            // not necessarily a job nobody printed. The agent holds the card, and if
            // its lease lapsed while the network was down it must still be able to
            // say what happened when it comes back. Refusing that left a printed card
            // sitting in the queue to be handed out and printed a second time, which
            // is the exact failure this pipeline exists to prevent. Who may say it is
            // decided at the endpoint: only the machine that owns the printer, and
            // only while nobody else holds the job.
            self::Pending => in_array($newStatus, [self::Queued, self::Printed, self::Failed, self::Cancelled], true),
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
     * Whether a card is still expected to come out of a printer for this job.
     *
     * Wider than isActive() by one state: a Pending job has not been claimed yet, but it
     * is queued and it will print. This is the question "does this badge already have a
     * card on its way", which is what stops the same badge being queued into two runs and
     * what decides whether cancelling one run may hand editing back to the attendee.
     *
     * Failed is deliberately not in the list. No card came out, the operator is the one
     * who decides whether to requeue it or print the badge again, and neither of those is
     * blocked by the failure.
     */
    public function isOutstanding(): bool
    {
        return in_array($this, [self::Pending, self::Queued, self::Printing, self::Retrying], true);
    }

    /**
     * The statuses isOutstanding() answers true for, for use in a whereIn.
     *
     * @return array<int, self>
     */
    public static function outstanding(): array
    {
        return array_values(array_filter(self::cases(), fn (self $case) => $case->isOutstanding()));
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
