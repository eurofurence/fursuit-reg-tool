<?php

namespace App\Http\Controllers\Manage;

use App\Enum\FursuitReviewOutcomeEnum;
use App\Http\Controllers\Controller;
use App\Models\Fursuit\Fursuit;
use App\Models\Fursuit\FursuitSubmissionRevision;
use App\Models\User;
use App\Services\FursuitPresence;
use App\Services\FursuitReviewService;
use App\Support\Manage\EventScope;
use App\Support\Manage\Status;
use App\Support\Manage\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Response;

/**
 * The review queue: one fursuit, three verdicts, and a way back.
 *
 * The record page (FursuitController::show) is a record page - infolist, activity log,
 * every action the resource has. This is the surface a reviewer spends an afternoon in,
 * so it carries only what a verdict needs: the photo big enough to judge, the name and
 * species, three buttons, and the reason picker that appears when a verdict needs one.
 *
 * Three things it does that the Filament page could not.
 *
 * **It never blocks.** Opening a record does not lock it. The queue skips records another
 * reviewer is on (FursuitPresence), and if a reviewer arrives by link anyway the page says
 * who else is here and lets them decide. The old cache lock refused verdicts instead,
 * which made a shared link useless and a dead browser a five-minute freeze.
 *
 * **It can be taken back.** Every verdict is queued behind an undo window, so the arrow
 * back on a mis-click costs the attendee nothing - the mail has not gone out yet. See
 * FursuitReviewService.
 *
 * **Presence is refreshed by the page itself.** show() touches presence, so the client's
 * poll - a partial reload of `presence`, `undo` and `queue` - is also the heartbeat.
 * A GET with a side effect, deliberately: it is an advisory "I am still here" that is
 * idempotent, expires on its own, and would otherwise cost a second request every few
 * seconds per reviewer.
 */
class FursuitReviewController extends Controller
{
    public function __construct(private readonly FursuitReviewService $reviews) {}

    /**
     * The queue entry point: hand the reviewer whatever is waiting.
     *
     * A redirect rather than a page, because "which record do you work on now" is a query
     * over the queue and its answer is a URL the reviewer can then share, reload and undo
     * back to.
     */
    public function index(Request $request, EventScope $scope): RedirectResponse
    {
        Gate::authorize('viewAny', Fursuit::class);

        $next = $this->reviews->nextPending($scope->apply(Fursuit::query()), $request->user());

        if ($next === null) {
            Toast::flashSuccess(
                'Nothing left to review',
                'No pending fursuits are waiting in the selected event.',
            );

            return redirect()->route('admin.fursuits.index');
        }

        return redirect()->route('admin.fursuits.review.show', $next);
    }

    /**
     * One fursuit, ready to be judged.
     */
    public function show(Request $request, Fursuit $fursuit, EventScope $scope): Response
    {
        Gate::authorize('view', $fursuit);

        $reviewer = $request->user();

        FursuitPresence::touch($fursuit, $reviewer);

        $fursuit->load(['user', 'species', 'event']);

        return inertia('Manage/Fursuits/Review', [
            'fursuit' => $this->card($fursuit),
            'outcomes' => $this->outcomes($fursuit, $reviewer),
            /*
             * The three props the client polls. Kept at the top level and kept small,
             * because Inertia filters a partial visit by top-level key and this visit
             * happens every few seconds per reviewer.
             */
            'presence' => [
                'others' => FursuitPresence::others($fursuit, $reviewer),
                'heartbeatSeconds' => FursuitPresence::HEARTBEAT_SECONDS,
            ],
            /*
             * Only on the record the verdict applies to. It used to ride along to the next
             * fursuit, where "Approved on Fluffy - undo" sat above a different animal's photo:
             * the reviewer had to trust a name rather than see what they were taking back, and
             * a stray click undid a record that was no longer on screen.
             */
            'undo' => $this->undoBar($reviewer, $fursuit),
            /*
             * Where the left arrow goes: the record this reviewer last decided, while that verdict
             * can still be taken back. It is navigation, not the undo itself - the reviewer lands
             * on the fursuit, sees the photo and the verdict, and presses Undo there. That is the
             * whole point of moving the bar off the next record, and it needs its own way back
             * rather than relying on browser history, which a reviewer who has skipped twice since
             * no longer has in a usable place.
             */
            'back' => $this->backTarget($reviewer, $fursuit),
            'queue' => [
                'remaining' => $this->reviews->pendingCount($scope->apply(Fursuit::query())),
                'skipUrl' => route('admin.fursuits.next', [$fursuit, 'queue' => 1]),
                'indexUrl' => route('admin.fursuits.index'),
                'recordUrl' => route('admin.fursuits.show', $fursuit),
            ],
        ]);
    }

    /**
     * Take back the last verdict this reviewer handed down.
     *
     * Scoped to the reviewer and to verdicts nobody has been told about yet; see
     * FursuitReviewService::undoable(). A refusal says which of the two it was, because
     * "undo did nothing" is indistinguishable from a broken button.
     */
    public function undo(Request $request): RedirectResponse
    {
        Gate::authorize('viewAny', Fursuit::class);

        $reviewer = $request->user();
        $decision = $this->reviews->undoable($reviewer);

        if ($decision === null) {
            Toast::flashWarning(
                'Nothing to undo',
                'Your last decision has already been sent to the attendee, or somebody has decided again since.',
            );

            return back();
        }

        $fursuit = $decision->fursuit;

        if (! $this->reviews->undo($decision, $reviewer)) {
            Toast::flashWarning(
                'Nothing was undone',
                'That decision can no longer be taken back.',
            );

            return back();
        }

        /*
         * The verdict named first and the fursuit second, rather than glued into one sentence:
         * the labels are phrases now ("Rejected, must be fixed"), and reading one mid-sentence
         * cost more than the sentence saved.
         */
        Toast::flashSuccess(
            'Decision undone',
            'Took back "'.$decision->outcome->label().'" on '.($fursuit->name ?? 'the fursuit').'. Nothing was sent to the attendee.',
        );

        return redirect()->route('admin.fursuits.review.show', $fursuit);
    }

    /**
     * Everything the reviewer looks at before deciding.
     *
     * The badge total and the owner are here because they change what a verdict costs: a
     * rejection stops a card the attendee may already have paid for, and a name is what
     * makes "this is the third resubmission" legible.
     *
     * @return array<string, mixed>
     */
    private function card(Fursuit $fursuit): array
    {
        $badge = $fursuit->badges()->whereNull('extra_copy_of')->first();

        return [
            'id' => $fursuit->id,
            'name' => $fursuit->name,
            'species' => $fursuit->species?->name,
            /*
             * The gallery webp, which is 1080x1920 - big enough to judge a submission on, and
             * not the archival print master this page used to pull once per record.
             * GenerateFursuitWebpJob renders it the moment the attendee submits or replaces a
             * photo, so it exists by the time a reviewer gets here; the helper falls back to the
             * master if a render is still queued.
             */
            'image' => FursuitController::previewUrl($fursuit),
            'status' => Status::fursuit($fursuit->status),
            'owner' => $fursuit->user?->name,
            'event' => $fursuit->event?->name,
            'submittedAt' => $fursuit->created_at?->toIso8601String(),
            'published' => (bool) $fursuit->published,
            'catchEmAll' => (bool) $fursuit->catch_em_all,
            'publication' => [
                'blocked' => $fursuit->isPublicationBlocked(),
                'reason' => $fursuit->publication_block_reason,
            ],
            'badge' => $badge === null ? null : [
                'id' => $badge->id,
                'customId' => $badge->custom_id,
                'total' => $badge->total,
            ],
            // What was decided last time, so a resubmission is not judged blind.
            'lastDecision' => $this->lastDecision($fursuit),
            // And what the submission looked like before, for the same reason.
            'history' => $this->history($fursuit),
            'recordUrl' => route('admin.fursuits.show', $fursuit),
        ];
    }

    /**
     * The superseded versions of this submission, newest first.
     *
     * `imageChanged` is the answer to the question a reviewer actually has on a
     * resubmission: an attendee who was told their image is not a photo of a costume and
     * sent the same file back is otherwise indistinguishable from one who fixed it. It
     * compares each version's photo with the one that replaced it, so a name-only edit reads
     * as a name-only edit.
     *
     * The old photo is still in the bucket because the attendee editor stopped deleting it
     * (see BadgeController::update); `image` is null for a version whose file predates that,
     * and the page says so rather than showing an empty frame.
     *
     * @return array<int, array<string, mixed>>
     */
    private function history(Fursuit $fursuit): array
    {
        $revisions = $fursuit->submissionRevisions()->with('changedBy')->orderByDesc('id')->get();

        if ($revisions->isEmpty()) {
            return [];
        }

        // What each version was replaced by, walking back from the record as it stands now.
        $newer = [
            'name' => $fursuit->name,
            'species' => $fursuit->species?->name,
            'image' => $fursuit->image,
        ];

        return $revisions->map(function (FursuitSubmissionRevision $revision) use (&$newer) {
            $entry = [
                'id' => $revision->id,
                'name' => $revision->name,
                'species' => $revision->species_name,
                'image' => FursuitController::imageUrl($revision->image),
                'changedAt' => $revision->created_at?->toIso8601String(),
                'changedBy' => $revision->changedBy?->name,
                'imageChanged' => $revision->image !== $newer['image'],
                'nameChanged' => $revision->name !== $newer['name'],
                'speciesChanged' => $revision->species_name !== $newer['species'],
            ];

            $newer = [
                'name' => $revision->name,
                'species' => $revision->species_name,
                'image' => $revision->image,
            ];

            return $entry;
        })->all();
    }

    /**
     * The previous verdict on this record, if there is one.
     *
     * @return array<string, mixed>|null
     */
    private function lastDecision(Fursuit $fursuit): ?array
    {
        $decision = $fursuit->reviewDecisions()->with('reviewer')->whereNull('undone_at')->latest('id')->first();

        if ($decision === null) {
            return null;
        }

        return [
            'outcome' => $decision->outcome->label(),
            'tone' => $decision->outcome->tone(),
            'reason' => $decision->reason,
            'reviewer' => $decision->reviewer?->name,
            'at' => $decision->created_at?->toIso8601String(),
        ];
    }

    /**
     * The three verdicts, declared server-side with everything the button needs.
     *
     * Availability is decided here rather than in the page, so "a rejected fursuit offers
     * no second rejection" is something a feature test can assert. An unavailable outcome
     * is still sent, carrying the reason, because a button that silently disappears reads
     * as a bug in a keyboard-driven surface where the shortcut is muscle memory.
     *
     * @return array<int, array<string, mixed>>
     */
    private function outcomes(Fursuit $fursuit, User $reviewer): array
    {
        /*
         * A block on an attendee who asked for neither the gallery nor the game would be
         * recorded as a plain approval, so the button is not offered at all - two buttons that
         * do the same thing is a worse surface than one. Its key is folded into Approve
         * instead, because the reviewer looking at digital art reaches for `g` either way and
         * that keystroke should still work.
         */
        $silent = $this->reviews->silentlyApproves($fursuit, FursuitReviewOutcomeEnum::PublicationBlocked);

        return collect(FursuitReviewOutcomeEnum::cases())
            ->reject(fn (FursuitReviewOutcomeEnum $outcome) => $silent
                && $outcome === FursuitReviewOutcomeEnum::PublicationBlocked)
            ->map(function (FursuitReviewOutcomeEnum $outcome) use ($fursuit, $reviewer, $silent) {
                $available = $this->reviews->can($fursuit, $outcome, $reviewer);

                // Both keys land on Approve while the block is folded into it.
                $shortcuts = $silent && $outcome === FursuitReviewOutcomeEnum::Approved
                    ? [$outcome->shortcut(), FursuitReviewOutcomeEnum::PublicationBlocked->shortcut()]
                    : [$outcome->shortcut()];

                return [
                    'value' => $outcome->value,
                    'label' => $outcome->label(),
                    'consequence' => $silent && $outcome === FursuitReviewOutcomeEnum::Approved
                        ? 'Prints and is handed out. The attendee asked for neither the gallery nor the game, so there is nothing to publish.'
                        : $outcome->consequence(),
                    'tone' => $outcome->tone(),
                    'icon' => $outcome->icon(),
                    'shortcuts' => $shortcuts,
                    'requiresReason' => $outcome->requiresReason(),
                    'reasons' => FursuitReviewService::reasonOptions($outcome),
                    'url' => route($this->routeFor($outcome), [$fursuit, 'queue' => 1]),
                    'available' => $available,
                    'unavailableReason' => $available
                        ? null
                        : 'Not available from '.Status::fursuit($fursuit->status)['label']
                            .($fursuit->isPublicationBlocked() ? ', already blocked from the gallery' : ''),
                ];
            })
            ->values()
            ->all();
    }

    private function routeFor(FursuitReviewOutcomeEnum $outcome): string
    {
        return match ($outcome) {
            FursuitReviewOutcomeEnum::Approved => 'admin.fursuits.approve',
            FursuitReviewOutcomeEnum::Rejected => 'admin.fursuits.reject',
            FursuitReviewOutcomeEnum::PublicationBlocked => 'admin.fursuits.block-publication',
        };
    }

    /**
     * The undo bar, or nothing when there is nothing to take back.
     *
     * `expiresAt` is when the mail goes out, which is also when undo stops being possible,
     * so the page can count down rather than offer a button that fails.
     *
     * @return array<string, mixed>|null
     */
    private function undoBar(User $reviewer, Fursuit $fursuit): ?array
    {
        $decision = $this->reviews->undoable($reviewer);

        if ($decision === null || $decision->fursuit_id !== $fursuit->getKey()) {
            return null;
        }

        return [
            'url' => route('admin.fursuits.review.undo'),
            'outcome' => $decision->outcome->label(),
            'tone' => $decision->outcome->tone(),
            'fursuit' => $decision->fursuit?->name,
            'fursuitId' => $decision->fursuit_id,
            'expiresAt' => $decision->notify_at?->toIso8601String(),
        ];
    }

    /**
     * The record the left arrow walks back to, or null when there is nothing to go back to.
     *
     * The reviewer's last verdict that can still be erased, and only while it is a *different*
     * record than the one on screen - there is no point offering a trip to the page you are already
     * on, and on that page the undo bar is what answers.
     *
     * Deliberately not an undo. The key navigates; the button on the record undoes. A reviewer
     * working at speed should never erase a verdict with a keystroke aimed at a record they cannot
     * see - which is what the arrow used to do.
     *
     * @return array<string, mixed>|null
     */
    private function backTarget(User $reviewer, Fursuit $fursuit): ?array
    {
        $decision = $this->reviews->undoable($reviewer);

        if ($decision === null || $decision->fursuit === null || $decision->fursuit_id === $fursuit->getKey()) {
            return null;
        }

        return [
            'url' => route('admin.fursuits.review.show', $decision->fursuit),
            'fursuit' => $decision->fursuit->name,
            'outcome' => $decision->outcome->label(),
            'expiresAt' => $decision->notify_at?->toIso8601String(),
        ];
    }
}
