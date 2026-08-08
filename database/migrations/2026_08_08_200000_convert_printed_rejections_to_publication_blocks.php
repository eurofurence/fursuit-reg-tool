<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Re-file old rejections as "approved, not published" where the card was printed anyway.
 *
 * Before the review system separated the two questions, a submission that was wrong for the gallery -
 * artwork rather than a photo of a suit, most of them - could only be rejected, and nothing stopped
 * the card being printed regardless. The result is a pile of records marked `rejected` whose badge
 * was printed and handed out: the status says one thing and what happened says another.
 *
 * This states what actually happened. Those records become approved (the card exists, so it was
 * approved in practice) and publication-blocked (the gallery and the game never showed them, and
 * should not start now).
 *
 * **Only records whose card already exists.** A rejection with nothing printed is left alone, because
 * approving it here would make it printable for the first time - and with no reason text stored
 * anywhere, we cannot tell a "this is digital art" rejection from a Code of Conduct one. Those stay
 * rejected for a human to look at.
 *
 * Nothing is notified. This is a bookkeeping correction of decisions from past conventions, so it
 * writes columns directly rather than going through the state machine, which would mail every one of
 * these attendees about a verdict from 2024.
 *
 * Idempotent by construction: the WHERE clause matches only rows that are still `rejected`, so a
 * second run finds nothing.
 */
return new class extends Migration
{
    /**
     * What the attendee reads if they open their badge page, which renders this string. Same wording
     * as the publication mail, because it answers the same question.
     */
    private const REASON = 'We check every submission against our guidelines, and it was determined that yours did not meet the guidelines for publication.';

    public function up(): void
    {
        $ids = $this->rejectedWithACard();

        if ($ids === []) {
            return;
        }

        foreach (array_chunk($ids, 500) as $chunk) {
            DB::table('fursuits')
                ->whereIn('id', $chunk)
                ->where('status', 'rejected')
                ->update([
                    'status' => 'approved',
                    // The moment somebody actually looked at it, rather than the moment this
                    // migration ran.
                    'approved_at' => DB::raw('COALESCE(approved_at, rejected_at, updated_at)'),
                    'rejected_at' => null,
                    'publication_blocked_at' => DB::raw('COALESCE(publication_blocked_at, rejected_at, updated_at)'),
                    'publication_block_reason' => self::REASON,
                    /*
                     * The attendee's own switches go off with the block, as FursuitReviewService does
                     * it: `catch_em_all` is read by the catch-code lookup, and a card printed years
                     * ago should not become catchable now. There is no decision row to restore them
                     * from later, which is correct - nobody made a decision here, we are recording
                     * one that was already made.
                     */
                    'published' => false,
                    'catch_em_all' => false,
                ]);
        }
    }

    /**
     * Rejected fursuits whose badge was printed, is waiting at the desk, or was collected.
     *
     * Soft-deleted rows on both sides are included: they are history either way, and leaving them
     * inconsistent would make the same query answer differently depending on whether somebody had
     * tidied up.
     *
     * @return array<int, int>
     */
    private function rejectedWithACard(): array
    {
        return DB::table('fursuits')
            ->where('fursuits.status', 'rejected')
            ->whereExists(fn ($query) => $query
                ->select(DB::raw(1))
                ->from('badges')
                ->whereColumn('badges.fursuit_id', 'fursuits.id')
                ->where(fn ($card) => $card
                    ->whereNotNull('badges.printed_at')
                    ->orWhereIn('badges.status_fulfillment', ['printed', 'ready_for_pickup', 'picked_up'])))
            ->pluck('fursuits.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Deliberately irreversible.
     *
     * Rolling back would mean re-rejecting records whose card is in somebody's hands, and the
     * information needed to tell these apart from rejections that were always rejections - the reason
     * text - was never stored. A `down()` that guessed would be worse than none.
     */
    public function down(): void
    {
        // No-op on purpose; see the note above.
    }
};
