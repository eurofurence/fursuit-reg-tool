<?php

namespace App\Services;

use App\Enum\FursuitReviewOutcomeEnum;
use App\Models\Fursuit\Fursuit;
use App\Models\Fursuit\FursuitReviewDecision;
use App\Models\Fursuit\States\Approved;
use App\Models\Fursuit\States\Pending;
use App\Models\Fursuit\States\Rejected;
use App\Models\ReviewReason;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The one place a review verdict is applied.
 *
 * Read by the review queue, the record page and the edit form, so all three agree about
 * what "approved" does. Three things live here that used to live nowhere:
 *
 *  - the three outcomes (FursuitReviewOutcomeEnum), where approval used to be a yes/no
 *    that forced a reviewer to reject a badge over a gallery rule;
 *  - the decision row, which is what makes undo possible and what the attendee's mail is
 *    dispatched against;
 *  - the delay on that mail, which is why a mis-click in a fast queue is recoverable
 *    rather than something the attendee reads about.
 *
 * Undo is a restore, not a transition. The state machine has no approved -> pending edge
 * and should not get one: reverting a verdict is not a thing that happens to a fursuit,
 * it is the erasure of something that never should have happened to it. So the decision
 * row carries a snapshot and undo writes it back, with an activity entry saying so.
 */
class FursuitReviewService
{
    /**
     * How long a verdict can be taken back before the attendee is told.
     *
     * Long enough to notice the wrong button and press undo, short enough that an
     * attendee refreshing their badge page is not left wondering. It is also the delay on
     * every review mail, so raising it delays every notification by the same amount.
     */
    public const UNDO_WINDOW_MINUTES = 5;

    /**
     * The reasons shipped with the app, per outcome, keyed by slug.
     *
     * **Defaults, not the list.** The live list is `review_reasons`, which the desk edits in
     * Settings > Review Reasons; this array is what seeds that table while it is empty (see
     * the migration and ReviewReasonSeeder). Editing a string here changes nothing on an
     * installation that has already been seeded, which is the point: the wording is the
     * reviewers' to own.
     *
     * Each entry is a keyword and a body. The keyword is what the review queue puts on a chip,
     * because a reviewer picking from eleven options needs to scan them, not read them; the
     * body is the paragraph the attendee receives. Before the split, the queue showed the full
     * paragraphs, which made the picker a wall of text.
     *
     * Slugs, not list indexes. The Filament array was a list, so the persisted select value was
     * the integer index: clearing the select threw "Undefined array key", and reordering the
     * array silently rewired every prefill. A slug is stable under reordering, survives an edit
     * to the wording, and means something in a request log.
     *
     * @var array<string, array<string, array{keyword: string, body: string}>>
     */
    public const DEFAULT_REASONS = [
        /*
         * Rejections, which stop the card until the attendee changes the submission.
         *
         * Three categories, and the bar is deliberately this high: a rejection costs the attendee
         * their badge until they act, so it is reserved for content Eurofurence cannot hand out at
         * all. Everything else that is merely wrong for the gallery keeps the badge and closes the
         * public surfaces instead - see the publication list below.
         *
         * Things that are *not* rejections, each of which was on this list once:
         *
         *  - **Image quality.** "Too dark", "too blurry" is not our call. The attendee chose the
         *    photo; we print what they sent.
         *  - **Not a costume, artwork, AI art, a real animal, an identifiable person.** None of
         *    these breaks a rule the badge carries into the convention. They are publication
         *    blocks: printed and handed out, not shown in the gallery or the game.
         *  - **Fetish or overly revealing items.** The RoC restricts these in *public* areas, and
         *    the badge is still issued - it is the gallery that stays PG-13. So this is a
         *    publication block, and only nudity or a visibly anatomically correct suit is refused
         *    outright.
         *  - **Prop weapons.** The RoC bans *carrying* weapons and has look-alikes, LARP props and
         *    replicas checked in at the Security Office: a rule about an object somebody brings on
         *    site, not about a photograph. A suit photographed with a prop sword carries nothing.
         *  - **Body paint.** Needs Security's permission on site; a photo of it breaks nothing.
         */
        FursuitReviewOutcomeEnum::Rejected->value => [
            'drugs' => [
                'keyword' => 'Drugs',
                'body' => 'We determined that your submission contains drugs or drug promoting content, including legal substances.',
            ],
            'hate_speech' => [
                'keyword' => 'Harassment or hate speech',
                'body' => 'We determined that your submission contains harassment, hate speech, or symbols associated with it.',
            ],
            /*
             * RoC, Clothing: in convention-exclusive areas attendees may not wear clothing or
             * costumes which are visibly "anatomically correct" or indecently revealing their own
             * private anatomy, and the same standard applies to what we print and hand out.
             *
             * Do not justify this to the attendee with "a badge is worn in those areas". The
             * fursuit badge is a separate keepsake, not the attendee badge - wearing it is
             * optional, and the FAQ says so - which makes that argument both wrong and confusing.
             */
            'nudity' => [
                'keyword' => 'Nude or anatomically correct',
                'body' => 'We determined that your submission contains nude or visibly anatomically correct content.',
            ],
        ],
        /*
         * Publication blocks: the badge is approved, printed and handed out, and only the gallery
         * and Fursuit Catch-Em-All are closed. The wording has to say that in every case, because
         * a rejection asks the attendee to go and fix something and none of these does.
         */
        FursuitReviewOutcomeEnum::PublicationBlocked->value => [
            'artwork' => [
                'keyword' => 'Artwork',
                'body' => 'We determined that your submission is artwork rather than a photo of a costume.',
            ],
            'ai_generated' => [
                'keyword' => 'AI generated',
                'body' => 'We determined that your submission is AI generated rather than a photo of a real fursuit.',
            ],
            'real_animal' => [
                'keyword' => 'Real animal',
                'body' => 'We determined that your submission shows a real animal. We love animals, but they are not allowed on the convention premises.',
            ],
            'no_costume' => [
                'keyword' => 'Not a costume',
                'body' => 'We determined that your submission is a person, or is close enough to be identifiable as one.',
            ],
            'identifiable_human' => [
                'keyword' => 'Identifiable human',
                'body' => 'We determined that your submission contains an identifiable person, and we cannot verify that they consented to their face being published.',
            ],
            // RoC, Clothing: in public areas attendees may not wear clothing or accessories which
            // are overly revealing, inappropriate to the atmosphere of the convention, or likely to
            // draw reasonable complaint or offense, including obviously fetish-related items. The
            // badge is still issued; the gallery stays PG-13.
            'fetish' => [
                'keyword' => 'Adult or fetish items',
                'body' => 'We determined that your submission contains adult or fetish related items.',
            ],
        ],
    ];

    /**
     * The reason picker for one outcome, as the desk arranged it.
     *
     * `label` is the keyword the chip carries and `body` is the paragraph the attendee receives;
     * the client puts the first on the button and the second into the textarea. Two fields
     * because a reviewer picking from eleven options scans keywords, while the attendee needs a
     * sentence that explains itself.
     *
     * Falls back to the shipped defaults when the table is empty, so a database that has not
     * been seeded yet still has a working review queue rather than a picker with nothing in it.
     * The migration seeds on deploy; this is the safety net, not the mechanism.
     *
     * @return array<int, array{value: string, label: string, body: string}>
     */
    public static function reasonOptions(FursuitReviewOutcomeEnum $outcome): array
    {
        $reasons = ReviewReason::pickerFor($outcome);

        if ($reasons->isEmpty()) {
            return collect(self::DEFAULT_REASONS[$outcome->value] ?? [])
                ->map(fn (array $reason, string $slug) => [
                    'value' => $slug,
                    'label' => $reason['keyword'],
                    'body' => $reason['body'],
                ])
                ->values()
                ->all();
        }

        return $reasons
            ->map(fn (ReviewReason $reason) => [
                'value' => $reason->slug,
                'label' => $reason->keyword,
                'body' => $reason->body,
            ])
            ->all();
    }

    /**
     * The slugs a verdict may be filed under, so a rejection cannot carry a publication-block
     * reason and a retired reason cannot be revived by a hand-made request.
     *
     * @return array<int, string>
     */
    public static function reasonSlugs(FursuitReviewOutcomeEnum $outcome): array
    {
        return array_column(self::reasonOptions($outcome), 'value');
    }

    /**
     * Whether `$reviewer` can hand down `$outcome` on `$fursuit` right now.
     *
     * Approval and a publication block both need the approved edge, because a publication
     * block *is* an approval with the public surfaces switched off.
     */
    public function can(Fursuit $fursuit, FursuitReviewOutcomeEnum $outcome, User $reviewer): bool
    {
        if ($outcome === FursuitReviewOutcomeEnum::Rejected) {
            return ! $fursuit->status instanceof Rejected
                && $fursuit->status->canTransitionTo(Rejected::class, $reviewer, '');
        }

        // An approved fursuit has no approval edge left, but its publication verdict is
        // not a state at all: blocking it, or clearing a block by approving again, stays
        // available.
        if ($fursuit->status instanceof Approved) {
            return $outcome === FursuitReviewOutcomeEnum::PublicationBlocked
                ? ! $fursuit->isPublicationBlocked()
                : $fursuit->isPublicationBlocked();
        }

        return $fursuit->status->canTransitionTo(Approved::class, $reviewer);
    }

    /**
     * Whether a publication block would be nothing but an approval.
     *
     * An attendee who ticked neither the gallery nor the game is not asking to be published,
     * so there is nothing to block and nothing to tell them about. A reviewer looking at
     * digital art will reach for the block anyway - it is the obvious button for "this is not
     * a photo of a suit" - and turning that into a message explaining that a request they
     * never made has been refused would be confusing at best.
     *
     * So the verdict becomes a plain approval: the card prints, the attendee gets the
     * approval mail, and the reviewer is told on screen what was recorded instead.
     */
    public function silentlyApproves(Fursuit $fursuit, FursuitReviewOutcomeEnum $outcome): bool
    {
        return $outcome === FursuitReviewOutcomeEnum::PublicationBlocked
            && ! $fursuit->published
            && ! $fursuit->catch_em_all
            && ! $fursuit->isPublicationBlocked();
    }

    /**
     * Apply a verdict and record it. The attendee is told later, by the sweeper.
     *
     * Nothing is dispatched here on purpose. A delayed job would make the undo window a
     * property of the queue driver: on the `sync` connection - which the test suite uses,
     * and which any misconfigured environment can fall back to - a delay is ignored and the
     * mail goes out inside this request, silently removing the only thing that makes a
     * mis-click recoverable. `notify_at` on the row is the window, and
     * `fursuits:deliver-review-decisions` is what reads it.
     *
     * @param  string|null  $reason  Required for both negative outcomes; ignored for an approval.
     */
    public function decide(
        Fursuit $fursuit,
        FursuitReviewOutcomeEnum $outcome,
        User $reviewer,
        ?string $reason = null,
    ): FursuitReviewDecision {
        // A block on somebody who never asked to be published is an approval; see
        // silentlyApproves(). The reason goes with it, because there is nothing to explain.
        if ($this->silentlyApproves($fursuit, $outcome)) {
            $outcome = FursuitReviewOutcomeEnum::Approved;
            $reason = null;
        }

        return DB::transaction(function () use ($fursuit, $outcome, $reviewer, $reason) {
            $restore = self::snapshot($fursuit);

            match ($outcome) {
                FursuitReviewOutcomeEnum::Approved => $this->applyApproved($fursuit, $reviewer),
                FursuitReviewOutcomeEnum::Rejected => $this->applyRejected($fursuit, $reviewer, (string) $reason),
                FursuitReviewOutcomeEnum::PublicationBlocked => $this->applyPublicationBlocked($fursuit, $reviewer, (string) $reason),
            };

            return $fursuit->reviewDecisions()->create([
                'reviewer_id' => $reviewer->id,
                'outcome' => $outcome,
                'reason' => $outcome->requiresReason() ? $reason : null,
                'restore' => $restore,
                'notify_at' => now()->addMinutes(self::UNDO_WINDOW_MINUTES),
            ]);
        });
    }

    /**
     * Put the fursuit back the way it was and cancel the mail.
     *
     * Returns false when the verdict can no longer be erased - somebody decided again, or
     * the attendee has already been told - rather than throwing, because the caller's job
     * is to say so to the reviewer.
     */
    public function undo(FursuitReviewDecision $decision, User $reviewer): bool
    {
        if (! $decision->isUndoable()) {
            return false;
        }

        return (bool) DB::transaction(function () use ($decision, $reviewer) {
            $fursuit = $decision->fursuit;

            if ($fursuit === null) {
                return false;
            }

            /*
             * forceFill, not a transition. There is no approved -> pending edge and there
             * should not be one; see the class comment. Writing the snapshot back also
             * restores approved_at / rejected_at, which a transition would stamp anew.
             */
            $fursuit->forceFill($decision->restore)->save();

            $decision->forceFill([
                'undone_at' => now(),
                'undone_by_id' => $reviewer->id,
            ])->save();

            activity()
                ->performedOn($fursuit)
                ->causedBy($reviewer)
                ->withProperties([
                    'outcome' => $decision->outcome->value,
                    'decision_id' => $decision->id,
                ])
                ->log('Review decision undone');

            return true;
        });
    }

    /**
     * This reviewer's last verdict, while it can still be erased.
     *
     * Scoped to the reviewer on purpose: undo is "take back what I just did", so it must
     * not reach across to a colleague's decision on a record this reviewer never saw.
     */
    public function undoable(User $reviewer): ?FursuitReviewDecision
    {
        $decision = FursuitReviewDecision::query()
            ->with('fursuit')
            ->where('reviewer_id', $reviewer->id)
            ->whereNull('undone_at')
            ->whereNull('notified_at')
            ->latest('id')
            ->first();

        if ($decision === null || ! $decision->isUndoable()) {
            return null;
        }

        return $decision;
    }

    /**
     * The next fursuit waiting for a verdict that nobody else is looking at.
     *
     * Ordered by id so two reviewers walking the queue see the same sequence, and
     * event-scoped by the caller's query so it cannot hand out a fursuit from a past
     * event. Presence is advisory and cached rather than a column, so "nobody is on it"
     * cannot be a where clause; lazy() stops at the first free record instead of loading
     * the queue to find it.
     *
     * Falls back to a busy record only if every waiting fursuit has somebody on it, so a
     * reviewer is never told the queue is empty when it is not - the page says who else is
     * there and lets them decide.
     *
     * Records whose gallery render is still in flight are held back (imageRenderSettled):
     * the verdict is passed on the photo, and for the seconds between an upload and
     * GenerateFursuitWebpJob there is no photo to pass it on. They come back into the
     * queue as soon as the render lands, and after Fursuit::IMAGE_RENDER_GRACE_MINUTES
     * even if it never does, so a permanently failed encode cannot swallow a submission.
     */
    public function nextPending(Builder $scoped, ?User $viewer = null, ?Fursuit $after = null): ?Fursuit
    {
        $query = $scoped
            ->whereState('status', Pending::class)
            ->imageRenderSettled()
            ->when($after !== null, fn (Builder $query) => $query->whereKeyNot($after->getKey()))
            ->orderBy('id');

        $busy = null;

        foreach ($query->lazy() as $candidate) {
            if (! FursuitPresence::isBusy($candidate, $viewer)) {
                return $candidate;
            }

            $busy ??= $candidate;
        }

        return $busy;
    }

    /**
     * How many fursuits are still waiting in the given scope.
     *
     * Counts what nextPending() would actually hand out, renders in flight excluded -
     * otherwise the queue reads "3 remaining" and then tells the reviewer there is
     * nothing left.
     */
    public function pendingCount(Builder $scoped): int
    {
        return $scoped->whereState('status', Pending::class)->imageRenderSettled()->count();
    }

    /**
     * What the attendee's two switches were before the block that is standing now.
     *
     * Read from the block's own decision row, so lifting a block always restores the state
     * that block overwrote - not the state of some earlier verdict, and not `true`.
     *
     * @return array{published: bool, catch_em_all: bool}|null
     */
    private function switchesBeforeBlock(Fursuit $fursuit): ?array
    {
        $block = $fursuit->reviewDecisions()
            ->where('outcome', FursuitReviewOutcomeEnum::PublicationBlocked->value)
            ->whereNull('undone_at')
            ->latest('id')
            ->first();

        if ($block === null) {
            return null;
        }

        return [
            'published' => (bool) ($block->restore['published'] ?? false),
            'catch_em_all' => (bool) ($block->restore['catch_em_all'] ?? false),
        ];
    }

    /**
     * Everything undo has to put back.
     *
     * @return array<string, mixed>
     */
    private static function snapshot(Fursuit $fursuit): array
    {
        return [
            'status' => $fursuit->status::$name,
            'approved_at' => optional($fursuit->approved_at)->toDateTimeString(),
            'rejected_at' => optional($fursuit->rejected_at)->toDateTimeString(),
            'publication_blocked_at' => optional($fursuit->publication_blocked_at)->toDateTimeString(),
            'publication_block_reason' => $fursuit->publication_block_reason,
            'published' => (bool) $fursuit->published,
            'catch_em_all' => (bool) $fursuit->catch_em_all,
        ];
    }

    /**
     * Fine on both counts: the card prints and the fursuit may be published.
     *
     * Clearing the block is part of the verdict. A reviewer who blocked publication and
     * then approves the same record - the attendee resubmitted a real photo, most often -
     * means "all clear", and leaving the old block in place would silently keep the
     * fursuit out of the gallery forever.
     */
    private function applyApproved(Fursuit $fursuit, User $reviewer): void
    {
        if ($fursuit->isPublicationBlocked()) {
            $fursuit->clearPublicationBlock();
            $fursuit->save();
        }

        if ($fursuit->status instanceof Approved) {
            return;
        }

        // notify: false - DeliverFursuitReviewDecisionJob owns the mail, behind the undo
        // window.
        $fursuit->status->transitionTo(Approved::class, $reviewer, false);
    }

    /**
     * Breaks the Code of Conduct: nothing prints, nothing is handed out.
     */
    private function applyRejected(Fursuit $fursuit, User $reviewer, string $reason): void
    {
        $fursuit->status->transitionTo(Rejected::class, $reviewer, $reason, false);
    }

    /**
     * Fine by the Code of Conduct, wrong for the gallery and the game.
     *
     * Three writes that have to happen together. The fursuit is approved, so its card is
     * printed and handed out like any other. The block is stamped, which is what the
     * gallery, the game and the review queue read. And the attendee's two switches are
     * turned off.
     *
     * Turning the switches off is not redundant with the block. `catch_em_all` is read in
     * places a block column would have to be threaded through one by one - the badge
     * artwork draws the catch code and its QR from it, the catch-code lookup matches on it,
     * the observer mints the code from it - and a printed QR that no longer resolves is
     * worse than no QR. Flipping the switch closes all of those at once; the block column
     * then keeps the surfaces closed even if the attendee turns the switch back on while
     * the block still stands.
     *
     * The snapshot on the decision row is what makes this safe to undo: the switches were
     * the attendee's, so undo - and lifting the block - put back what they asked for rather
     * than what a reviewer happened to leave behind.
     */
    private function applyPublicationBlocked(Fursuit $fursuit, User $reviewer, string $reason): void
    {
        if (! $fursuit->status instanceof Approved) {
            $fursuit->status->transitionTo(Approved::class, $reviewer, false);
            $fursuit->refresh();
        }

        $fursuit->publication_blocked_at = now();
        $fursuit->publication_block_reason = $reason;
        $fursuit->published = false;
        $fursuit->catch_em_all = false;
        $fursuit->save();

        activity()
            ->performedOn($fursuit)
            ->causedBy($reviewer)
            ->withProperties(['reason' => $reason])
            ->log('Fursuit publication blocked');
    }

    /**
     * Lift a publication block without touching the approval.
     *
     * Not a verdict and not undoable: it is the correction of one, and the attendee is told
     * through the ordinary approval mail only if a verdict follows. Used by the record page
     * when a block was placed in error.
     *
     * The attendee's two switches come back from the snapshot the block wrote, because the
     * block turned them off; restoring them to `true` unconditionally would publish a
     * fursuit whose owner never asked to be published.
     */
    public function unblockPublication(Fursuit $fursuit, User $reviewer): void
    {
        if (! $fursuit->isPublicationBlocked()) {
            return;
        }

        $fursuit->clearPublicationBlock();

        $wanted = $this->switchesBeforeBlock($fursuit);

        if ($wanted !== null) {
            $fursuit->published = $wanted['published'];
            $fursuit->catch_em_all = $wanted['catch_em_all'];
        }

        $fursuit->save();

        activity()
            ->performedOn($fursuit)
            ->causedBy($reviewer)
            ->log('Fursuit publication block lifted');
    }
}
