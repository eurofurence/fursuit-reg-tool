<?php

namespace App\Domain\Printing\Models;

use App\Domain\Printing\Exceptions\StalePrintFileException;
use App\Enum\PrintBatchStatusEnum;
use App\Enum\PrintJobStatusEnum;
use App\Enum\PrintJobTypeEnum;
use App\Jobs\Printing\GenerateBadgePrintFileJob;
use App\Models\Badge\Badge;
use App\Models\Event;
use App\Models\Machine;
use App\Models\Staff;
use App\Models\User;
use Database\Factories\PrintBatchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A set of cards printed as one run.
 *
 * A printer works a single batch through to completion before moving to the
 * next, which is what allows the queue to stop dead on a fault rather than
 * draining past it and losing cards. Operators pick the batch to run from the
 * print agent.
 */
class PrintBatch extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected static function newFactory()
    {
        return PrintBatchFactory::new();
    }

    protected function casts(): array
    {
        return [
            'status' => PrintBatchStatusEnum::class,
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'total_jobs' => 'integer',
            'printed_count' => 'integer',
            'verified_count' => 'integer',
            'failed_count' => 'integer',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function printer(): BelongsTo
    {
        return $this->belongsTo(Printer::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * The desk clerk who sent this run to the printer, if it came from the POS.
     *
     * Separate from createdBy(): the POS authenticates staff against the `staff`
     * table through the `machine-user` guard, not `users`.
     */
    public function createdByStaff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'created_by_staff_id');
    }

    public function printJobs(): HasMany
    {
        return $this->hasMany(PrintJob::class);
    }

    /**
     * Order badges for printing: ascending by attendee, then *descending* by
     * badge number, so 1000-1, 1001-2, 1001-1, 1002-1.
     *
     * Attendees ascending because the cards are filed into the pickup bins one
     * at a time as they come out, so they have to arrive in filing order.
     *
     * Badge numbers descending within an attendee so that the spare copies
     * land under the main badge and the -1 ends up on top of that attendee's
     * little pile, which is the one staff hand over.
     *
     * This is what BatchPrintJob did, and the rework got it wrong twice: first
     * by sorting both descending (in 06ce8bf, which printed the whole run
     * backwards), then by sorting both ascending, which fixed the attendees but
     * put the spare copy on top. Restoring the original behaviour.
     *
     * Sorting happens once here and is frozen into PrintJob::sequence.
     *
     * @param  Collection<int, Badge>  $badges
     * @return Collection<int, Badge>
     */
    public static function sortBadgesForPrinting(Collection $badges): Collection
    {
        return $badges->sort(function (Badge $a, Badge $b) {
            [$aAttendee, $aNumber] = self::parseCustomId($a->custom_id);
            [$bAttendee, $bNumber] = self::parseCustomId($b->custom_id);

            // Badges without a usable custom_id sort last rather than scrambling
            // the run; they still print, just at the end where they are noticed.
            if ($aAttendee === null || $bAttendee === null) {
                return ($aAttendee === null ? 1 : 0) <=> ($bAttendee === null ? 1 : 0)
                    ?: $a->id <=> $b->id;
            }

            return $aAttendee <=> $bAttendee
                ?: $bNumber <=> $aNumber
                ?: $a->id <=> $b->id;
        })->values();
    }

    /**
     * Split a custom_id such as "1234-2" into [attendee id, badge number].
     *
     * @return array{0: int|null, 1: int}
     */
    public static function parseCustomId(?string $customId): array
    {
        if (! $customId) {
            return [null, 0];
        }

        $parts = explode('-', $customId, 2);

        if (count($parts) !== 2 || ! is_numeric($parts[0])) {
            return [null, 0];
        }

        return [(int) $parts[0], (int) $parts[1]];
    }

    /**
     * Build a batch from a set of badges. The only way to populate one.
     *
     * Batches are immutable: the contents are fixed here and nothing can be
     * added afterwards. That is what makes the run predictable, and it is why
     * every badge in it is locked at the same moment.
     *
     * @param  Collection<int, Badge>  $badges
     *
     * @throws StalePrintFileException when any badge's artwork is missing or out of date
     */
    public static function build(
        string $name,
        Collection $badges,
        ?Printer $printer = null,
        ?int $eventId = null,
        ?int $createdById = null,
        ?int $createdByStaffId = null,
    ): self {
        return DB::transaction(function () use ($name, $badges, $printer, $eventId, $createdById, $createdByStaffId) {
            $batch = self::open(
                name: $name,
                printer: $printer,
                eventId: $eventId,
                createdById: $createdById,
                createdByStaffId: $createdByStaffId,
                expectedJobs: $badges->count(),
            );

            $batch->commitBadges($badges);

            return $batch->fresh();
        });
    }

    /**
     * Create the empty batch a run will be filled into.
     *
     * Split out of `build()` because rendering the artwork happens on a queue now, and
     * the row has to exist before that work starts: it is what the operator watches, it
     * is what a failed preparation is recorded against, and it is what the toast can name
     * the moment the button is pressed. A batch with no jobs is inert - `scopeSelectable`
     * and `isClaimable()` both ignore Draft - so nothing can print out of one by accident
     * while it is still being prepared.
     *
     * `$expectedJobs` is what the run is expected to end up holding. It is only a
     * placeholder for the progress display; `commitBadges()` replaces it with the count
     * that was actually committed.
     */
    public static function open(
        string $name,
        ?Printer $printer = null,
        ?int $eventId = null,
        ?int $createdById = null,
        ?int $createdByStaffId = null,
        int $expectedJobs = 0,
    ): self {
        return self::create([
            'name' => $name,
            'event_id' => $eventId,
            'printer_id' => $printer?->id,
            'created_by_id' => $createdById,
            'created_by_staff_id' => $createdByStaffId,
            'status' => PrintBatchStatusEnum::Draft,
            'total_jobs' => $expectedJobs,
        ]);
    }

    /**
     * Freeze a set of badges into this batch: one job each, in print order.
     *
     * Refuses a batch that already holds jobs. Batches are immutable once filled, and the
     * preparation job that calls this can in principle be retried, so a second pass must
     * never double the run.
     *
     * @param  Collection<int, Badge>  $badges
     *
     * @throws StalePrintFileException when any badge's artwork is missing or out of date
     */
    public function commitBadges(Collection $badges): void
    {
        if ($this->isSealed()) {
            throw new \RuntimeException("Print batch {$this->id} already holds jobs and cannot be filled again.");
        }

        self::assertPrintFilesAreCurrent($badges);

        DB::transaction(function () use ($badges) {
            $ordered = self::sortBadgesForPrinting($badges);

            foreach ($ordered->values() as $position => $badge) {
                $this->printJobs()->create([
                    'printer_id' => $this->printer_id ?? $badge->printJobs()->value('printer_id'),
                    'printable_type' => $badge->getMorphClass(),
                    'printable_id' => $badge->id,
                    'type' => PrintJobTypeEnum::Badge,
                    'status' => PrintJobStatusEnum::Pending,
                    'file' => $badge->print_file_path,
                    'sequence' => $position + 1,
                    'priority' => 1,
                ]);

                // Committing a badge to a batch is the point of no return for
                // the attendee: the artwork is already rendered, so letting them
                // edit now would put a card in the stack that no longer matches
                // the order.
                $badge->forceFill(['printing_locked_at' => now()])->saveQuietly();
            }

            $this->update(['total_jobs' => $ordered->count()]);
        });
    }

    /**
     * Refuse to batch a badge whose rendered artwork is missing or out of date.
     *
     * Generation has to happen before batching, and nothing else enforces that.
     * `GenerateBadgePrintFileJob::invalidateFor()` only marks a file stale, so
     * between an attendee's edit and the next `badges:generate-print-files` pass
     * the badge carries a PDF that no longer matches the order. Building is the
     * point of no return: it freezes the sequence and locks the badge out of
     * further edits, and the operator is standing at the printer by the time
     * anything downstream could notice. Failing loudly here, naming the badges,
     * is the only place the mistake is still cheap.
     *
     * @param  Collection<int, Badge>  $badges
     *
     * @throws StalePrintFileException
     */
    private static function assertPrintFilesAreCurrent(Collection $badges): void
    {
        $stale = $badges->filter(fn (Badge $badge) => ! $badge->print_file_path
            || $badge->print_file_hash !== GenerateBadgePrintFileJob::inputHash($badge));

        if ($stale->isNotEmpty()) {
            throw StalePrintFileException::for($stale->values());
        }
    }

    /**
     * Whether this batch has been committed and can no longer be changed.
     */
    public function isSealed(): bool
    {
        return $this->printJobs()->exists();
    }

    /**
     * Cancel the batch and everything in it that has not printed yet.
     *
     * Reachable from the print agent, so an operator standing at a jammed
     * printer can stop the run without going to find a laptop. Cards that
     * already printed stay printed; nothing further will be.
     */
    public function cancel(string $reason = 'Cancelled by operator'): bool
    {
        return DB::transaction(function () use ($reason) {
            $outstanding = $this->printJobs()
                ->whereIn('status', [
                    PrintJobStatusEnum::Pending,
                    PrintJobStatusEnum::Queued,
                    PrintJobStatusEnum::Printing,
                    PrintJobStatusEnum::Retrying,
                    PrintJobStatusEnum::Failed,
                ])
                ->get();

            foreach ($outstanding as $job) {
                // Printing jobs have to come back to a cancellable state first;
                // a card mid-transfer is not something we can un-print.
                if ($job->status === PrintJobStatusEnum::Printing) {
                    $job->transitionTo(PrintJobStatusEnum::Pending);
                }

                $job->transitionTo(PrintJobStatusEnum::Cancelled);

                // No card was ever produced for this badge, so hand editing back
                // to the attendee rather than leaving them stuck.
                //
                // "No card" is two questions, not one. Nothing printed, and
                // nothing is still on its way: a badge that also sits in another
                // live run would otherwise be unlocked here while that run still
                // holds a card for it, and the attendee could edit the order out
                // from under artwork that is already frozen into a queued job.
                $badge = $job->printable;
                if ($badge instanceof Badge && $this->hasNoCardLeft($badge)) {
                    $badge->forceFill(['printing_locked_at' => null])->saveQuietly();
                }
            }

            $this->update(['pause_reason' => $reason]);
            $this->recalculateCounters();

            return $this->transitionTo(PrintBatchStatusEnum::Cancelled);
        });
    }

    /**
     * Whether this badge has neither a printed card nor one still on its way.
     *
     * The only condition under which the printing lock may come off.
     */
    private function hasNoCardLeft(Badge $badge): bool
    {
        return $badge->printJobs()
            ->where(fn ($query) => $query
                ->where('status', PrintJobStatusEnum::Printed)
                ->orWhereIn('status', PrintJobStatusEnum::outstanding()))
            ->doesntExist();
    }

    /**
     * Atomically hand the next job in this batch to a machine.
     *
     * Row locking is the whole point: the previous system let the browser decide
     * what to print next, and two poll cycles four seconds apart would hand the
     * same job out twice and print the card twice. Returns null when the batch
     * is not claimable or has nothing left to give.
     */
    public function claimNextJob(Machine $machine, int $leaseSeconds = 180): ?PrintJob
    {
        return DB::transaction(function () use ($machine, $leaseSeconds) {
            /** @var self|null $batch */
            $batch = self::query()->whereKey($this->getKey())->lockForUpdate()->first();

            if (! $batch || ! $batch->status->isClaimable()) {
                return null;
            }

            /** @var PrintJob|null $job */
            $job = $batch->printJobs()
                ->where('status', PrintJobStatusEnum::Pending)
                ->orderBy('sequence')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            $job?->claim($machine, $leaseSeconds);

            return $job;
        });
    }

    public function transitionTo(PrintBatchStatusEnum $newStatus, ?string $reason = null): bool
    {
        if (! $this->status->canTransitionTo($newStatus)) {
            return false;
        }

        $updates = ['status' => $newStatus];

        match ($newStatus) {
            PrintBatchStatusEnum::Printing => $updates += [
                'started_at' => $this->started_at ?? now(),
                'pause_reason' => null,
            ],
            PrintBatchStatusEnum::Paused => $updates['pause_reason'] = $reason,
            PrintBatchStatusEnum::Completed => $updates['completed_at'] = now(),
            default => null,
        };

        return $this->update($updates);
    }

    /**
     * Halt the batch. Called whenever a job fails or the hardware faults, so
     * nothing else prints until someone has dealt with it.
     */
    public function pause(string $reason): bool
    {
        return $this->transitionTo(PrintBatchStatusEnum::Paused, $reason);
    }

    public function resume(): bool
    {
        // Whoever resumed has just dealt with whatever stopped the batch, so
        // the cards that failed go back in the queue. Without this they stayed
        // failed: they blocked the batch from ever completing, and the badge
        // was quietly never printed.
        $this->requeueFailedJobs();

        return $this->transitionTo(PrintBatchStatusEnum::Printing);
    }

    /**
     * Return every failed job in this batch to the queue. Count requeued.
     */
    public function requeueFailedJobs(): int
    {
        $requeued = 0;

        // Deliberately skips jobs whose card may physically exist. Those need a
        // person to look in the output bin, and requeueing them is exactly the
        // duplicate this whole path was producing.
        $failed = $this->printJobs()
            ->where('status', PrintJobStatusEnum::Failed)
            ->where('card_may_exist', false)
            ->get();

        foreach ($failed as $job) {
            if ($job->requeue()) {
                $requeued++;
            }
        }

        if ($requeued > 0) {
            $this->recalculateCounters();
        }

        return $requeued;
    }

    /**
     * Refresh the denormalised counters from the jobs themselves.
     */
    public function recalculateCounters(): void
    {
        $counts = $this->printJobs()
            ->selectRaw('count(*) as total')
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as printed', [PrintJobStatusEnum::Printed->value])
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as failed', [PrintJobStatusEnum::Failed->value])
            ->selectRaw('sum(case when verified_print_at is not null then 1 else 0 end) as verified')
            ->first();

        $this->update([
            'total_jobs' => (int) $counts->total,
            'printed_count' => (int) $counts->printed,
            'failed_count' => (int) $counts->failed,
            'verified_count' => (int) $counts->verified,
        ]);
    }

    /**
     * Mark the batch complete once no job is left to print. A failed job blocks
     * completion: it has to be retried or cancelled deliberately, so a batch can
     * never quietly finish with cards missing.
     */
    public function completeIfFinished(): bool
    {
        $outstanding = $this->printJobs()
            ->whereIn('status', [
                PrintJobStatusEnum::Pending,
                PrintJobStatusEnum::Queued,
                PrintJobStatusEnum::Printing,
                PrintJobStatusEnum::Retrying,
                PrintJobStatusEnum::Failed,
            ])
            ->exists();

        if ($outstanding) {
            return false;
        }

        return $this->transitionTo(PrintBatchStatusEnum::Completed);
    }

    /**
     * Cards in this batch that printed but nobody has confirmed came out right.
     */
    public function unverifiedJobs(): HasMany
    {
        return $this->printJobs()
            ->where('status', PrintJobStatusEnum::Printed)
            ->whereNull('verified_print_at');
    }

    /**
     * Runs one desk clerk started. Never every run: a clerk who queued nothing
     * has no print jobs, and a null staff id must not match the batches the
     * admin panel or the console queued.
     */
    public function scopeStartedByStaff($query, ?int $staffId)
    {
        return $query->where('created_by_staff_id', $staffId ?? 0);
    }

    /**
     * Runs that have something to tell the clerk who started them.
     *
     * A run only speaks once it has stopped moving: finished, stuck behind a
     * failed card, or cancelled. Printing and Ready say nothing, because
     * "still going" is not news to the person standing at the counter.
     *
     * The comparison is against the status that was acknowledged, not a
     * timestamp, so a run dismissed while paused comes back when it completes.
     */
    public function scopeNeedingDeskAttention($query)
    {
        return $query
            ->whereIn('status', [
                PrintBatchStatusEnum::Completed,
                PrintBatchStatusEnum::Paused,
                PrintBatchStatusEnum::Cancelled,
            ])
            ->where(function ($q) {
                $q->whereNull('desk_dismissed_status')
                    ->orWhereColumn('desk_dismissed_status', '!=', 'status');
            });
    }

    /**
     * Acknowledge the batch in its current state. Silences it until it moves on.
     */
    public function dismissForDesk(): bool
    {
        return $this->update(['desk_dismissed_status' => $this->status->value]);
    }

    public function scopeClaimable($query)
    {
        return $query->where('status', PrintBatchStatusEnum::Printing);
    }

    public function scopeSelectable($query)
    {
        return $query->whereIn('status', [
            PrintBatchStatusEnum::Ready,
            PrintBatchStatusEnum::Printing,
            PrintBatchStatusEnum::Paused,
        ]);
    }
}
