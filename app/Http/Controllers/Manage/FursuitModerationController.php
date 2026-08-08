<?php

namespace App\Http\Controllers\Manage;

use App\Enum\FursuitReviewOutcomeEnum;
use App\Http\Controllers\Controller;
use App\Models\Fursuit\Fursuit;
use App\Models\Fursuit\States\Approved;
use App\Models\Fursuit\States\Rejected;
use App\Services\FursuitPresence;
use App\Services\FursuitReviewService;
use App\Support\Manage\EventScope;
use App\Support\Manage\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * The verbs of a review: the three verdicts, the correction of one, and the queue step.
 *
 * All of them are gated on `view`, not `update`. That is what the Filament page did: its
 * header actions carried no authorization of their own beyond the resource's view check,
 * so a reviewer - who fails `update`, which is admin-only - has always been able to
 * approve and reject. Moderating the queue is the reviewer role.
 *
 * Two shapes are worth knowing before reading the methods.
 *
 * **There is no claim.** The Filament page took a five-minute cache lock on load and then
 * *refused* every verdict unless the caller held it, which meant a reviewer who opened a
 * record by link could do nothing with it and a dead browser froze the record for five
 * minutes. Presence replaced it (App\Services\FursuitPresence): the queue skips records
 * somebody is on and the page says who else is there, but no verdict is ever refused
 * because of it. Claiming survives on the model but nothing calls it any more.
 *
 * **The verdict is not applied here.** FursuitReviewService owns it, because the queue
 * page, the record page and the edit form all have to mean the same thing by "approved" -
 * and because the attendee's mail is queued behind an undo window rather than sent inside
 * the transition. See that class for why undo is a restore and not a transition.
 */
class FursuitModerationController extends Controller
{
    public function __construct(private readonly FursuitReviewService $reviews) {}

    /**
     * The rejection reasons, as the desk has them arranged.
     *
     * A method and no longer a constant: the list lives in `review_reasons` and is edited in
     * Settings > Review Reasons, so it cannot be resolved at compile time. Each entry carries
     * the keyword the queue puts on a chip and the body the attendee receives.
     *
     * @return array<int, array{value: string, label: string, body: string}>
     */
    public static function rejectReasonOptions(): array
    {
        return FursuitReviewService::reasonOptions(FursuitReviewOutcomeEnum::Rejected);
    }

    /**
     * Fine on both counts: the card prints and the fursuit may be published.
     *
     * No success toast: the move to the next record is the feedback, and in the queue the
     * undo bar is. The refusal speaks, which the Filament action did not - it logged an
     * error and returned with no operator feedback at all.
     */
    public function approve(Request $request, Fursuit $fursuit, EventScope $scope): RedirectResponse
    {
        return $this->decide($request, $fursuit, $scope, FursuitReviewOutcomeEnum::Approved);
    }

    /**
     * Breaks the Code of Conduct: nothing prints and nothing is handed out until the
     * attendee changes the submission.
     */
    public function reject(Request $request, Fursuit $fursuit, EventScope $scope): RedirectResponse
    {
        return $this->decide($request, $fursuit, $scope, FursuitReviewOutcomeEnum::Rejected);
    }

    /**
     * Fine by the Code of Conduct, wrong for the gallery and Catch-Em-All.
     *
     * The outcome that did not exist before: a photo that is not a photo of a suit had to
     * be rejected outright, which cost the attendee a badge that was never against any
     * rule. Here the card is printed and handed out as normal and only the public surfaces
     * are closed.
     */
    public function blockPublication(Request $request, Fursuit $fursuit, EventScope $scope): RedirectResponse
    {
        return $this->decide($request, $fursuit, $scope, FursuitReviewOutcomeEnum::PublicationBlocked);
    }

    /**
     * Lift a publication block that was placed in error.
     *
     * Not a verdict: it changes nothing about the approval, writes no decision row and
     * mails nobody. A reviewer who wants the attendee told approves the record instead.
     */
    public function unblockPublication(Request $request, Fursuit $fursuit): RedirectResponse
    {
        Gate::authorize('view', $fursuit);

        if (! $fursuit->isPublicationBlocked()) {
            Toast::flashWarning(
                'Nothing to lift',
                'This fursuit is not blocked from the gallery.',
            );

            return back();
        }

        $this->reviews->unblockPublication($fursuit, $request->user());

        Toast::flashSuccess(
            'Publication block lifted',
            'The gallery and the game follow the attendee\'s own setting again.',
        );

        return back();
    }

    /**
     * Rejected -> Approved as an apology.
     *
     * Deliberately not routed through FursuitReviewService: this is not a queue verdict.
     * It stays on the record rather than advancing, it mails immediately rather than
     * behind an undo window, and the mail it sends is the rejection-reversal one, which
     * exists precisely to say "we got that wrong" and is sent even after the event has
     * ended.
     */
    public function approveRejected(Request $request, Fursuit $fursuit): RedirectResponse
    {
        Gate::authorize('view', $fursuit);

        if (! $fursuit->status instanceof Rejected) {
            Toast::flashDanger(
                'Nothing was approved',
                'This fursuit is not rejected.',
            );

            return back();
        }

        $fursuit->status->transitionTo(Approved::class, $request->user());

        Toast::flashSuccess('Rejected fursuit approved successfully');

        return back();
    }

    /**
     * Hand the reviewer the next record to work on.
     */
    public function next(Request $request, Fursuit $fursuit, EventScope $scope): RedirectResponse
    {
        Gate::authorize('view', $fursuit);

        FursuitPresence::leave($fursuit, $request->user());

        return $this->advance($request, $fursuit, $scope);
    }

    /**
     * The shared body of the three verdicts.
     *
     * The reason is validated against the picker for *this* outcome, so a rejection cannot
     * be filed under a publication-block slug. Only the final text is stored and mailed;
     * the slug exists to prefill the textarea and is validated so a request cannot carry a
     * key nothing recognises.
     */
    private function decide(
        Request $request,
        Fursuit $fursuit,
        EventScope $scope,
        FursuitReviewOutcomeEnum $outcome,
    ): RedirectResponse {
        Gate::authorize('view', $fursuit);

        $reason = null;

        /*
         * A block on somebody who never asked to be published needs no reason, because it is
         * recorded as a plain approval and the attendee is told nothing about a request they
         * did not make. Requiring one here would refuse the single keystroke the reviewer
         * meant to make.
         */
        $silent = $this->reviews->silentlyApproves($fursuit, $outcome);

        if ($outcome->requiresReason() && ! $silent) {
            $validated = $request->validate([
                'reason' => ['nullable', 'string', Rule::in(FursuitReviewService::reasonSlugs($outcome))],
                'custom_reason' => ['required', 'string'],
            ]);

            $reason = $validated['custom_reason'];
        }

        $user = $request->user();

        if (! $this->reviews->can($fursuit, $outcome, $user)) {
            Toast::flashDanger(
                'Nothing was decided',
                'This fursuit cannot be '.$this->pastTense($outcome).' from its current status.',
            );

            return back();
        }

        $this->reviews->decide($fursuit, $outcome, $user, $reason);

        FursuitPresence::leave($fursuit, $user);

        $redirect = $this->advance($request, $fursuit, $scope);

        /*
         * After advance(), deliberately. There is one toast slot, and advance() uses it to say
         * the queue is empty - which matters less than telling the reviewer that the Block
         * they pressed was recorded as an approval. Flashing this second means it wins.
         */
        if ($silent) {
            Toast::flashSuccess(
                'Approved, not published',
                'The attendee asked for neither the gallery nor the game, so this was approved with nothing to block and nothing to explain to them.',
            );
        }

        return $redirect;
    }

    private function pastTense(FursuitReviewOutcomeEnum $outcome): string
    {
        return match ($outcome) {
            FursuitReviewOutcomeEnum::Approved => 'approved',
            FursuitReviewOutcomeEnum::Rejected => 'rejected',
            FursuitReviewOutcomeEnum::PublicationBlocked => 'blocked from the gallery',
        };
    }

    /**
     * The queue step: the next record waiting, or an explicit empty state.
     *
     * Always into the queue. It used to branch on where the verdict came from, because the record
     * page offered the same verdicts and being thrown from a record you were reading onto a
     * different fursuit is not what a record page should do. The record page has no verdicts any
     * more - every one of them lives in the queue - so there is one destination and no flag to get
     * wrong.
     *
     * The empty state is explicit: Filament redirected to the index and left the reviewer to work
     * out whether the queue was empty or its three-try walk had simply given up.
     */
    private function advance(Request $request, Fursuit $fursuit, EventScope $scope): RedirectResponse
    {
        $next = $this->reviews->nextPending(
            $scope->apply(Fursuit::query()),
            $request->user(),
            $fursuit,
        );

        if ($next === null) {
            Toast::flashSuccess(
                'Nothing left to review',
                'No pending fursuits are waiting in the selected event.',
            );

            return redirect()->route('admin.fursuits.index');
        }

        return redirect()->route('admin.fursuits.review.show', $next);
    }
}
