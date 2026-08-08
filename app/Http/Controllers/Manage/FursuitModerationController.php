<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Models\Fursuit\Fursuit;
use App\Models\Fursuit\States\Approved;
use App\Models\Fursuit\States\Pending;
use App\Models\Fursuit\States\Rejected;
use App\Support\Manage\EventScope;
use App\Support\Manage\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The approval workflow of ViewFursuit (audit 4.3.1), as six endpoints.
 *
 * All six are gated on `view`, not `update`. That is what the Filament page did: its
 * header actions carried no authorization of their own beyond the resource's view
 * check, so a reviewer - who fails `update`, which is admin-only - has always been able
 * to claim, approve and reject. Moderating the queue is the reviewer role.
 *
 * Four things behave differently, all of them in the plan.
 *
 *  - Claiming is explicit. `public $defaultAction = 'Claim'` mounted the Claim action on
 *    every page load, so opening a pending fursuit took the lock without a gesture
 *    (plan 2.10 #41, audit 69).
 *  - Unclaiming checks ownership. Fursuit::unclaim() is declared with zero parameters
 *    and was called with one, so anyone could drop anyone's claim (plan 2.10 #41, audit
 *    71). The check lives here rather than in the model, which the POS also uses.
 *  - Approve and Reject say something when they refuse. Both logged an error and
 *    returned with no operator feedback at all (plan 2.10 #43, audit 72).
 *  - The next-record walk is deterministic. toNextFursuit() looped three times and then
 *    redirected to the last candidate whether or not it was claimed, over an unordered,
 *    unscoped `Fursuit::where('status','pending')->first()` that handed reviewers
 *    fursuits from past events (plan 2.10 #42, plan 2.9, audit 76).
 */
class FursuitModerationController extends Controller
{
    /**
     * The eight rejection reasons, verbatim and in order, keyed by slug.
     *
     * The Filament array was a list, so the persisted select value was the integer index
     * `0`-`7`: clearing the select threw "Undefined array key", and reordering the array
     * silently rewired every prefill (plan 2.10 #40, audit 37). Slugs are stable under
     * reordering and mean something in a request log.
     *
     * Only `custom_reason` is ever stored and mailed. The picker exists to fill the
     * textarea, and the reviewer may edit it afterwards, which is the behaviour today.
     *
     * @var array<string, string>
     */
    public const REJECT_REASONS = [
        'human' => 'The submission shows a human. We can only accept badges created for fursuits.',
        'explicit' => 'The submission is explicit and does not follow our guidelines.',
        'low_quality' => 'The submission is of low quality and does not meet our guidelines.',
        'not_a_photo' => 'The submission is a not a photo. We only accept photos, we do not accept illustrations or other digital art as fursuit images.',
        'real_animal' => 'The submission shows an animal. We do not allow images of real animals, only fursuits.',
        'ai_generated' => 'The submission is AI generated and does not show a real fursuit.',
        'name' => 'The name of the fursuit is not appropriate.',
        'species' => 'The species of the fursuit is not appropriate.',
    ];

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function rejectReasonOptions(): array
    {
        return collect(self::REJECT_REASONS)
            ->map(fn (string $reason, string $key) => ['value' => $key, 'label' => $reason])
            ->values()
            ->all();
    }

    /**
     * Take the lock, or be sent to a record that is free.
     *
     * The "already claimed by somebody else" branch is the Filament action's own: it
     * moves the reviewer on rather than letting two people work the same record. It now
     * says so, because with claiming made explicit a silent jump to a different fursuit
     * is indistinguishable from a bug.
     */
    public function claim(Request $request, Fursuit $fursuit, EventScope $scope): RedirectResponse
    {
        Gate::authorize('view', $fursuit);

        $user = $request->user();

        if ($fursuit->isClaimed() && ! $fursuit->isClaimedBySelf($user)) {
            Toast::flashWarning(
                'Already claimed',
                'Another reviewer is working on this fursuit.',
            );

            return $this->advance($fursuit, $scope);
        }

        $fursuit->claim($user);

        return back();
    }

    /**
     * Drop your own lock, and only your own.
     */
    public function unclaim(Request $request, Fursuit $fursuit): RedirectResponse
    {
        Gate::authorize('view', $fursuit);

        if (! $fursuit->isClaimedBySelf($request->user())) {
            Toast::flashDanger(
                'Nothing was unclaimed',
                'This fursuit is not claimed by you.',
            );

            return back();
        }

        $fursuit->unclaim();

        return back();
    }

    /**
     * Pending -> Approved, then on to the next record in the queue.
     *
     * No success toast: the Filament action had none and the move to the next fursuit is
     * the feedback. The refusals below are the change (plan 2.10 #43).
     */
    public function approve(Request $request, Fursuit $fursuit, EventScope $scope): RedirectResponse
    {
        Gate::authorize('view', $fursuit);

        $user = $request->user();

        if (! $fursuit->isClaimedBySelf($user)) {
            Toast::flashDanger(
                'Nothing was approved',
                'Claim this fursuit before approving it.',
            );

            return back();
        }

        if (! $fursuit->status->canTransitionTo(Approved::class, $user)) {
            Toast::flashDanger(
                'Nothing was approved',
                'This fursuit cannot be approved from its current status.',
            );

            return back();
        }

        // Runs PendingToApproved (or RejectedToApproved): stamps approved_at, clears
        // rejected_at, writes the activity entry and notifies the owner.
        $fursuit->status->transitionTo(Approved::class, $user);

        return $this->advance($fursuit, $scope);
    }

    /**
     * Pending -> Rejected with the reason that is mailed to the owner, then on to the
     * next record.
     */
    public function reject(Request $request, Fursuit $fursuit, EventScope $scope): RedirectResponse
    {
        Gate::authorize('view', $fursuit);

        $validated = $request->validate([
            // The picker only prefills the textarea; it is never stored or sent, which
            // is the behaviour today. Validated all the same so a request cannot carry
            // a key nothing recognises.
            'reason' => ['nullable', 'string', 'in:'.implode(',', array_keys(self::REJECT_REASONS))],
            'custom_reason' => ['required', 'string'],
        ]);

        $user = $request->user();

        if (! $fursuit->isClaimedBySelf($user)) {
            Toast::flashDanger(
                'Nothing was rejected',
                'Claim this fursuit before rejecting it.',
            );

            return back();
        }

        if (! $fursuit->status->canTransitionTo(Rejected::class, $user, $validated['custom_reason'])) {
            Toast::flashDanger(
                'Nothing was rejected',
                'This fursuit cannot be rejected from its current status.',
            );

            return back();
        }

        // PendingToRejected stamps rejected_at, clears approved_at, logs the entry with
        // the reason as a property and mails FursuitRejectedNotification($reason).
        $fursuit->status->transitionTo(Rejected::class, $user, $validated['custom_reason']);

        return $this->advance($fursuit, $scope);
    }

    /**
     * Rejected -> Approved. Requires no claim, unlike Approve and Reject, and stays on
     * the record rather than advancing: it is an apology, not a queue step.
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

        // RejectedToApproved always notifies, regardless of whether the event has ended.
        $fursuit->status->transitionTo(Approved::class, $request->user());

        Toast::flashSuccess('Rejected fursuit approved successfully');

        return back();
    }

    /**
     * Hand the reviewer the next record to work on.
     */
    public function next(Fursuit $fursuit, EventScope $scope): RedirectResponse
    {
        Gate::authorize('view', $fursuit);

        return $this->advance($fursuit, $scope);
    }

    /**
     * The queue step: the next pending fursuit, or the list with an explicit empty
     * state.
     *
     * The empty state is new. Filament redirected to the index and left the reviewer to
     * work out whether the queue was empty or the walk had simply given up after three
     * tries (audit 76).
     */
    private function advance(Fursuit $fursuit, EventScope $scope): RedirectResponse
    {
        $next = $this->nextPending($fursuit, $scope);

        if ($next === null) {
            Toast::flashSuccess(
                'Nothing left to review',
                'No pending fursuits are waiting in the selected event.',
            );

            return redirect()->route('manage.fursuits.index');
        }

        return redirect()->route('manage.fursuits.show', $next);
    }

    /**
     * The oldest pending fursuit in the current event scope that nobody is holding.
     *
     * Ordered by id so two reviewers walking the queue at the same time see the same
     * sequence, and event-scoped so it cannot hand out a fursuit from a past event
     * (plan 2.9). The claim lives in the cache rather than in a column, so "unclaimed"
     * cannot be a where clause; `lazy()` stops at the first free record instead of
     * loading the whole queue to find it.
     */
    private function nextPending(Fursuit $fursuit, EventScope $scope): ?Fursuit
    {
        $query = $scope->apply(Fursuit::query())
            ->whereState('status', Pending::class)
            ->whereKeyNot($fursuit->getKey())
            ->orderBy('id');

        foreach ($query->lazy() as $candidate) {
            if ($candidate->isNotClaimed()) {
                return $candidate;
            }
        }

        return null;
    }
}
