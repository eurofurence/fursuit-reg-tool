<?php

namespace App\Models\Fursuit;

use App\Enum\FursuitReviewOutcomeEnum;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A verdict a reviewer handed down, and everything needed to take it back.
 *
 * Written by FursuitReviewService, read by DeliverFursuitReviewDecisionJob (which asks
 * "is this still the current verdict?" before mailing anybody) and by the review queue,
 * which offers undo for as long as the mail has not gone out.
 *
 * @property FursuitReviewOutcomeEnum $outcome
 * @property array<string, mixed> $restore
 */
class FursuitReviewDecision extends Model
{
    protected $guarded = [];

    protected $casts = [
        'outcome' => FursuitReviewOutcomeEnum::class,
        'restore' => 'array',
        'notify_at' => 'datetime',
        'notified_at' => 'datetime',
        'undone_at' => 'datetime',
    ];

    public function fursuit(): BelongsTo
    {
        return $this->belongsTo(Fursuit::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function undoneBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'undone_by_id');
    }

    /**
     * Whether this verdict can still be erased rather than corrected.
     *
     * Three conditions, and the third is the one that matters: once the attendee has been
     * mailed, taking the verdict back silently would leave them holding a message about a
     * decision that no longer exists. After that point the fix is a new verdict, which
     * carries its own mail.
     */
    public function isUndoable(): bool
    {
        return $this->undone_at === null
            && $this->notified_at === null
            && $this->isCurrent();
    }

    /**
     * Whether this is the newest verdict for its fursuit.
     *
     * A second reviewer may have decided again in the meantime - or the attendee may have
     * resubmitted, which writes no decision but does reset the fursuit - so undoing an
     * older row would restore a state nobody asked for.
     */
    public function isCurrent(): bool
    {
        return ! static::query()
            ->where('fursuit_id', $this->fursuit_id)
            ->where('id', '>', $this->getKey())
            ->exists();
    }

    /**
     * Verdicts still waiting to be announced, oldest first.
     */
    public function scopePendingNotification(Builder $query): Builder
    {
        return $query->whereNull('notified_at')->whereNull('undone_at')->orderBy('id');
    }
}
