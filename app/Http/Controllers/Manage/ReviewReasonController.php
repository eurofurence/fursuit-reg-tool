<?php

namespace App\Http\Controllers\Manage;

use App\Enum\FursuitReviewOutcomeEnum;
use App\Http\Controllers\Controller;
use App\Models\ReviewReason;
use App\Support\Manage\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Response;

/**
 * Settings > Review Reasons: the wording the review queue offers and the attendee receives.
 *
 * These used to be a PHP constant, so retiring a wording, fixing a typo in something thousands
 * of attendees read, or adding a reason the Rules of Conduct now cover all meant a pull request
 * - during the convention, when the people who know what the reason should say are the ones
 * working the queue.
 *
 * Every reason has two fields and they are not the same text:
 *
 *  - `keyword` is what the queue puts on a chip. A reviewer picking from eleven options scans
 *    them; the old picker showed the full paragraphs, which made it a wall of text.
 *  - `body` is the paragraph the attendee receives. The reviewer may still edit it before it
 *    goes out, so what the decision row stores is what was actually sent - this table is the
 *    starting point, not the record.
 *
 * A reason belongs to exactly one outcome, because the two lists say opposite things: a
 * rejection tells the attendee to fix their badge, and a publication block tells them their
 * badge is fine and is being printed.
 *
 * Retiring is deactivation, never deletion where it can be helped: `is_active` keeps the slug
 * resolvable in a request log while taking it out of the picker. Delete exists for a reason that
 * was created by mistake and never used.
 *
 * Reading is open to the panel; every write is admin-only and guarded twice - `can:manage-admin`
 * on the route group and `Gate::authorize('manage-admin')` in the method - the same
 * belt-and-braces pattern the rest of Settings uses.
 */
class ReviewReasonController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('access-manage');

        return inertia('Manage/Settings/ReviewReasons', [
            'canEdit' => Gate::allows('manage-admin'),
            'outcomes' => collect($this->editableOutcomes())
                ->map(fn (FursuitReviewOutcomeEnum $outcome) => [
                    'value' => $outcome->value,
                    'label' => $outcome->label(),
                    'consequence' => $outcome->consequence(),
                    'tone' => $outcome->tone(),
                    // Inactive ones ride along: this is the screen where they are brought back,
                    // and a list that hides them looks like the reason was deleted.
                    'reasons' => ReviewReason::query()
                        ->forOutcome($outcome)
                        ->orderBy('sort_order')
                        ->orderBy('id')
                        ->get()
                        ->map(fn (ReviewReason $reason) => [
                            'id' => $reason->id,
                            'slug' => $reason->slug,
                            'keyword' => $reason->keyword,
                            'body' => $reason->body,
                            'sortOrder' => $reason->sort_order,
                            'isActive' => $reason->is_active,
                            'updateUrl' => route('admin.settings.review-reasons.update', $reason),
                            'destroyUrl' => route('admin.settings.review-reasons.destroy', $reason),
                        ])
                        ->all(),
                ])
                ->all(),
            'storeUrl' => route('admin.settings.review-reasons.store'),
            'restoreUrl' => route('admin.settings.review-reasons.restore-defaults'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('manage-admin');

        $validated = $request->validate($this->rules());

        $outcome = FursuitReviewOutcomeEnum::from($validated['outcome']);

        ReviewReason::create([
            'outcome' => $outcome->value,
            // Derived from the keyword, and made unique inside the outcome, so an operator never
            // has to invent a slug - and so the value that ends up in a request log reads like
            // the reason it names.
            'slug' => $this->uniqueSlug($outcome, $validated['keyword']),
            'keyword' => $validated['keyword'],
            'body' => $validated['body'],
            // At the end of its list, which is where a new reason belongs: the order is what the
            // desk arranged, and inserting into the middle of it is a reorder, not a create.
            'sort_order' => (int) ReviewReason::query()->forOutcome($outcome)->max('sort_order') + 10,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        Toast::flashSuccess('Reason added');

        return back();
    }

    public function update(Request $request, ReviewReason $reviewReason): RedirectResponse
    {
        Gate::authorize('manage-admin');

        $validated = $request->validate([
            'keyword' => ['required', 'string', 'max:60'],
            'body' => ['required', 'string', 'max:2000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $wantsActive = $validated['is_active'] ?? $reviewReason->is_active;

        // An outcome with no reasons left is a picker a reviewer cannot use: the verdict needs a
        // reason and there would be nothing to pick. Deactivating the last one is refused here
        // rather than discovered in the queue.
        if (! $wantsActive && $this->isLastActive($reviewReason)) {
            Toast::flashDanger(
                'Keep at least one reason',
                'The review queue needs something to offer for '.$reviewReason->outcome->label().'.',
            );

            return back();
        }

        $reviewReason->update([
            'keyword' => $validated['keyword'],
            'body' => $validated['body'],
            'sort_order' => $validated['sort_order'] ?? $reviewReason->sort_order,
            'is_active' => $wantsActive,
        ]);

        Toast::flashSuccess('Saved');

        return back();
    }

    public function destroy(ReviewReason $reviewReason): RedirectResponse
    {
        Gate::authorize('manage-admin');

        if ($this->isLastActive($reviewReason)) {
            Toast::flashDanger(
                'Keep at least one reason',
                'The review queue needs something to offer for '.$reviewReason->outcome->label().'.',
            );

            return back();
        }

        $reviewReason->delete();

        Toast::flashSuccess('Reason deleted');

        return back();
    }

    /**
     * Put back anything shipped that this installation no longer has.
     *
     * Only inserts, and only what is missing by slug: a desk that has rewritten a reason keeps
     * its wording, and one that deleted a reason it now wants gets it back. There is deliberately
     * no "reset to defaults", because that would throw away wording the desk wrote.
     */
    public function restoreDefaults(): RedirectResponse
    {
        Gate::authorize('manage-admin');

        $added = 0;

        foreach ($this->editableOutcomes() as $outcome) {
            foreach (ReviewReason::defaultsFor($outcome) as $reason) {
                $existing = ReviewReason::query()
                    ->forOutcome($outcome)
                    ->where('slug', $reason['slug'])
                    ->first();

                if ($existing !== null) {
                    continue;
                }

                ReviewReason::create($reason);
                $added++;
            }
        }

        if ($added === 0) {
            Toast::flashSuccess('Nothing to restore', 'Every reason we ship is already here.');

            return back();
        }

        Toast::flashSuccess($added.' reason(s) restored');

        return back();
    }

    /**
     * @return array<int, FursuitReviewOutcomeEnum>
     */
    private function editableOutcomes(): array
    {
        // Approval has no reason: there is nothing to explain to somebody whose badge is fine.
        return array_values(array_filter(
            FursuitReviewOutcomeEnum::cases(),
            fn (FursuitReviewOutcomeEnum $outcome) => $outcome->requiresReason(),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            'outcome' => [
                'required',
                Rule::in(array_map(
                    fn (FursuitReviewOutcomeEnum $outcome) => $outcome->value,
                    $this->editableOutcomes(),
                )),
            ],
            'keyword' => ['required', 'string', 'max:60'],
            'body' => ['required', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    private function isLastActive(ReviewReason $reason): bool
    {
        return $reason->is_active
            && ReviewReason::query()
                ->forOutcome($reason->outcome)
                ->active()
                ->whereKeyNot($reason->getKey())
                ->doesntExist();
    }

    private function uniqueSlug(FursuitReviewOutcomeEnum $outcome, string $keyword): string
    {
        $base = Str::slug($keyword, '_') ?: 'reason';
        $slug = $base;
        $suffix = 2;

        while (ReviewReason::query()->forOutcome($outcome)->where('slug', $slug)->exists()) {
            $slug = $base.'_'.$suffix++;
        }

        return $slug;
    }
}
