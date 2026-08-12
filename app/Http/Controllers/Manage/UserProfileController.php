<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserProfile\States\Approved;
use App\Models\UserProfile\States\Pending;
use App\Models\UserProfile\States\Rejected;
use App\Models\UserProfile\UserProfile;
use App\Support\Manage\Action;
use App\Support\Manage\Column;
use App\Support\Manage\Filter;
use App\Support\Manage\Status;
use App\Support\Manage\Table;
use App\Support\Manage\Toast;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

/**
 * Catch-Em-All profiles: the list, the record, and the three verdicts.
 *
 * A profile is the attendee-written half of the game - a description, a handful of links
 * and the avatar mirrored from the identity provider - and all three are shown to other
 * attendees, so all three are reviewed before they are. Anything the attendee changes
 * sends the profile back to pending (UserProfile::requiresReapproval), which is why the
 * list defaults to the pending filter: the queue is the whole job.
 *
 * Two shapes worth knowing.
 *
 * **The claim is kept.** Unlike the fursuit queue, which replaced its cache lock with
 * advisory presence, a profile verdict is refused unless the reviewer holds the claim.
 * The record page renews it on load, so opening the profile is the claim, and it expires
 * on its own after five minutes rather than freezing the row until somebody remembers it.
 *
 * **Verdicts authorize `view`, not `update`.** UserProfilePolicy::update is admin-only
 * and means editing the row; approving or rejecting is review work and is what a reviewer
 * is here for. Same split as the fursuit queue (docs/admin/roles.md).
 */
class UserProfileController extends Controller
{
    /**
     * The old panel's default table date-time format, kept so timestamps read the same as
     * they do on every other list.
     */
    private const DATETIME_FORMAT = 'M j, Y H:i:s';

    /**
     * The canned rejection texts the picker offers. Written to the attendee verbatim, so
     * they are full sentences rather than keywords, and "Other" leaves the box empty on
     * purpose: it is the one case that has to be typed.
     *
     * @var array<int, string>
     */
    public const REJECTION_REASONS = [
        'The profile contains offensive content or violates the rules.',
        'The profile contains illegal content or links to illegal content.',
        'The profile contains dangerous content or links to dangerous content.',
        'The profile contains spam or advertising.',
        'The profile contains personal information of other people.',
    ];

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', UserProfile::class);

        return inertia('Manage/Profiles/Index', $this->table($request));
    }

    /**
     * The queue entry point: hand the reviewer whatever is waiting.
     *
     * A redirect rather than a page, for the reason the fursuit queue is one: "which
     * profile do I work on now" is a query, and its answer is a URL that can be reloaded
     * and shared.
     */
    public function review(Request $request): RedirectResponse
    {
        Gate::authorize('viewAny', UserProfile::class);

        return $this->toNext($request->user(), null);
    }

    /**
     * One profile, everything a verdict needs, and the log of what happened to it.
     *
     * Opening the record renews the claim, so a reviewer who follows a link is holding it
     * by the time the buttons render, and a reviewer who walks away loses it after five
     * minutes rather than blocking the row until the cache is cleared by hand.
     */
    public function show(Request $request, UserProfile $userProfile): Response
    {
        Gate::authorize('view', $userProfile);

        $reviewer = $request->user();
        $userProfile->load(['user', 'links']);

        if (! $userProfile->isClaimed() || $userProfile->isClaimedBySelf($reviewer)) {
            $userProfile->unclaim();
            $userProfile->claim($reviewer);
        }

        return inertia('Manage/Profiles/Show', [
            'profile' => $this->card($userProfile, $reviewer),
            'actions' => array_map(
                fn (Action $action) => $action->toArray(),
                $this->reviewActions($userProfile, $reviewer),
            ),
            'rejectionReasons' => self::REJECTION_REASONS,
            'queue' => [
                'remaining' => $this->pendingQuery()->count(),
                'nextUrl' => route('admin.profiles.next', $userProfile),
                'indexUrl' => route('admin.profiles.index'),
            ],
            ...$this->activityTable($request, $userProfile),
        ]);
    }

    /**
     * Publish the description, the links and the avatar, all three at once.
     */
    public function approve(Request $request, UserProfile $userProfile): RedirectResponse
    {
        Gate::authorize('view', $userProfile);

        $reviewer = $request->user();

        if (! $this->holdsClaim($userProfile, $reviewer)) {
            return back();
        }

        if (! $userProfile->status->canTransitionTo(Approved::class, $reviewer)) {
            Toast::flashWarning('Nothing to approve', 'This profile is already approved.');

            return back();
        }

        $userProfile->status->transitionTo(Approved::class, $reviewer);
        $userProfile->unclaim();

        Toast::flashSuccess(
            'Profile approved',
            'The description, the links and the avatar are now shown to other attendees.',
        );

        return $this->toNext($reviewer, $userProfile);
    }

    /**
     * Keep it hidden, and tell the attendee why.
     *
     * The reason is required and is shown to the profile owner verbatim, which is why the
     * picker's texts are full sentences: whatever is in this box is what they read.
     */
    public function reject(Request $request, UserProfile $userProfile): RedirectResponse
    {
        Gate::authorize('view', $userProfile);

        $reviewer = $request->user();

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        if (! $this->holdsClaim($userProfile, $reviewer)) {
            return back();
        }

        if (! $userProfile->status->canTransitionTo(Rejected::class, $reviewer, $validated['reason'])) {
            Toast::flashWarning('Nothing to reject', 'This profile is already rejected.');

            return back();
        }

        $userProfile->status->transitionTo(Rejected::class, $reviewer, $validated['reason']);
        $userProfile->unclaim();

        Toast::flashSuccess('Profile rejected', 'The description and links stay hidden from other attendees.');

        return $this->toNext($reviewer, $userProfile);
    }

    /**
     * Put a rejected profile back in the queue.
     *
     * The correction of a verdict rather than a verdict: it clears the rejection so the
     * profile is judged again from scratch, and the reviewer stays on the record.
     */
    public function reopen(Request $request, UserProfile $userProfile): RedirectResponse
    {
        Gate::authorize('view', $userProfile);

        $reviewer = $request->user();

        if (! $this->holdsClaim($userProfile, $reviewer)) {
            return back();
        }

        if (! $userProfile->status->canTransitionTo(Pending::class, $reviewer)) {
            Toast::flashWarning('Nothing to reopen', 'Only a rejected profile can be moved back to pending.');

            return back();
        }

        $userProfile->status->transitionTo(Pending::class, $reviewer);

        Toast::flashSuccess('Back to pending', 'The profile is waiting for review again.');

        return back();
    }

    /**
     * Skip this one: drop the claim and take the next profile in the queue.
     */
    public function next(Request $request, UserProfile $userProfile): RedirectResponse
    {
        Gate::authorize('view', $userProfile);

        $reviewer = $request->user();

        if ($userProfile->isClaimedBySelf($reviewer)) {
            $userProfile->unclaim();
        }

        return $this->toNext($reviewer, $userProfile);
    }

    /**
     * Give up the claim without deciding anything, and stay on the record.
     */
    public function unclaim(Request $request, UserProfile $userProfile): RedirectResponse
    {
        Gate::authorize('view', $userProfile);

        if ($userProfile->isClaimedBySelf($request->user())) {
            $userProfile->unclaim();
        }

        return back();
    }

    /**
     * Profiles waiting for a verdict, oldest change first.
     *
     * `updated_at` rather than `created_at`: the row is created with the account and only
     * becomes review work when the attendee writes something, so the age that matters is
     * the age of the change.
     */
    public function pendingQuery(): Builder
    {
        return UserProfile::query()
            ->whereNull('approved_at')
            ->whereNull('rejected_at')
            ->orderBy('updated_at');
    }

    /**
     * The next profile nobody else is holding, or the list when there is none.
     *
     * Claimed profiles are skipped rather than refused, so two reviewers working the queue
     * at once do not land on the same record; a claim expires after five minutes, so
     * nothing is skipped for longer than that.
     */
    private function toNext(User $reviewer, ?UserProfile $current): RedirectResponse
    {
        $next = $this->pendingQuery()
            ->when($current, fn (Builder $query) => $query->whereKeyNot($current->getKey()))
            ->get()
            ->first(fn (UserProfile $profile) => ! $profile->isClaimed() || $profile->isClaimedBySelf($reviewer));

        if ($next === null) {
            Toast::flashSuccess('Nothing left to review', 'No profiles are waiting for a verdict.');

            return redirect()->to(Table::returnUrl('profiles', route('admin.profiles.index')));
        }

        return redirect()->route('admin.profiles.show', $next);
    }

    /**
     * Whether this reviewer may still decide, with the toast when they may not.
     *
     * The claim is what keeps two reviewers from deciding the same profile a second apart,
     * and it expires on its own, so losing it is an ordinary thing that has to read as one:
     * the refusal says to reload rather than leaving a button that did nothing.
     */
    private function holdsClaim(UserProfile $userProfile, User $reviewer): bool
    {
        if ($userProfile->isClaimedBySelf($reviewer)) {
            return true;
        }

        Toast::flashWarning(
            'Your claim on this profile has expired',
            'Somebody else may be reviewing it now. Reload the page to claim it again.',
        );

        return false;
    }

    /**
     * Everything the reviewer looks at before deciding.
     *
     * The avatar is part of the judgement rather than decoration: it is mirrored from the
     * identity provider and a changed one sends the profile back to pending
     * (MirrorUserAvatarJob), so it is reviewed alongside the text the attendee wrote.
     *
     * @return array<string, mixed>
     */
    private function card(UserProfile $userProfile, User $reviewer): array
    {
        return [
            'id' => $userProfile->id,
            'uuid' => $userProfile->uuid,
            'user' => $userProfile->user?->name,
            'avatar' => $userProfile->user?->avatar_url,
            'description' => $userProfile->description,
            'links' => $userProfile->links->pluck('url')->values()->all(),
            'status' => Status::userProfile($userProfile->status),
            'rejectionReason' => $userProfile->status instanceof Rejected
                ? $userProfile->rejection_reason
                : null,
            'updatedAt' => $userProfile->updated_at?->toIso8601String(),
            'publicUrl' => route('catch-em-all.profiles.show', $userProfile->uuid),
            'claim' => [
                'held' => $userProfile->isClaimedBySelf($reviewer),
                // True only when somebody else holds it: the reviewer's own claim is the
                // normal state and does not need a banner.
                'takenByOther' => $userProfile->isClaimed() && ! $userProfile->isClaimedBySelf($reviewer),
            ],
        ];
    }

    /**
     * The verdicts, declared server-side with everything the button needs.
     *
     * Reject is not here: its dialog fills the reason box from the picker as the reviewer
     * chooses, which ActionButton has no concept of, so the record page renders that one
     * itself. Its URL rides along in `actions` all the same, as `reject`, so the page never
     * builds a route of its own.
     *
     * @return array<int, Action>
     */
    private function reviewActions(UserProfile $userProfile, User $reviewer): array
    {
        $held = $userProfile->isClaimedBySelf($reviewer);
        $lost = $held ? null : 'Somebody else is reviewing this profile.';

        return array_values(array_filter([
            $userProfile->status->canTransitionTo(Approved::class, $reviewer)
                ? Action::post('approve', 'Approve', route('admin.profiles.approve', $userProfile))
                    ->icon('circle-check')
                    ->tone(Status::OK)
                    ->confirm(
                        'Approve profile',
                        'This publishes the description, the links and the avatar.',
                        'Approve',
                    )
                    ->disabled($lost)
                : null,

            $userProfile->status->canTransitionTo(Rejected::class, $reviewer, '')
                ? Action::post('reject', 'Reject', route('admin.profiles.reject', $userProfile))
                    ->icon('circle-x')
                    ->tone(Status::DANGER)
                    ->disabled($lost)
                : null,

            $userProfile->status->canTransitionTo(Pending::class, $reviewer)
                ? Action::post('reopen', 'Move back to pending', route('admin.profiles.reopen', $userProfile))
                    ->icon('rotate-ccw')
                    ->tone(Status::WARN)
                    ->confirmDefault()
                    ->disabled($lost)
                : null,

            $held
                ? Action::delete('unclaim', 'Release', route('admin.profiles.unclaim', $userProfile))
                    ->icon('lock-open')
                    ->tone(Status::INFO)
                : null,

            Action::link('next', 'Next profile', route('admin.profiles.next', $userProfile))
                ->icon('arrow-right')
                ->tone(Status::INFO),
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function table(Request $request): array
    {
        $query = UserProfile::query()->with('user')->withCount('links');

        return Table::make($query)
            ->name('profiles')
            ->columns([
                Column::image('avatar', 'Avatar')->circular(),
                Column::text('user_name', 'User')
                    ->searchable('user.name')
                    ->sortUsing(fn (Builder $builder, string $dir) => $builder->orderBy(
                        User::select('name')->whereColumn('users.id', 'user_profiles.user_id'),
                        $dir,
                    )),
                Column::badge('status', 'Status'),
                Column::text('description', 'Description')->searchable(),
                Column::number('links_count', 'Links')->sortable(),
                Column::datetime('updated_at', 'Last changed')->sortable(),
            ])
            /*
             * Oldest change first, which is the queue order: the profile that has been
             * waiting longest is the one a reviewer should reach next. Every other list in
             * the panel defaults to newest first because it is a record list; this one is a
             * backlog.
             */
            ->defaultSort('updated_at', 'asc')
            ->filters([
                Filter::select('status', 'Status')
                    ->options([
                        'pending' => 'Pending',
                        'approved' => 'Approved',
                        'rejected' => 'Rejected',
                    ])
                    /*
                     * The list opens on the work rather than on everything. `status` is
                     * derived from the two timestamps rather than stored, so the filter
                     * cannot be a plain where() on a column.
                     */
                    ->default('pending')
                    ->pinned()
                    ->apply(fn (Builder $query, mixed $value) => match ($value) {
                        'pending' => $query->whereNull('approved_at')->whereNull('rejected_at'),
                        'approved' => $query->whereNotNull('approved_at'),
                        'rejected' => $query->whereNotNull('rejected_at'),
                        default => $query,
                    }),
            ])
            ->rows(fn (UserProfile $profile) => [
                'avatar' => $profile->user?->avatar_url,
                'user_name' => $profile->user?->name,
                'status' => Status::userProfile($profile->status),
                'description' => $profile->description,
                'links_count' => $profile->links_count,
                'updated_at' => $this->datetime($profile->updated_at),
            ])
            ->recordUrl(fn (UserProfile $profile) => route('admin.profiles.show', $profile))
            ->rowActions(fn (UserProfile $profile) => [
                Action::link('review', 'Review', route('admin.profiles.show', $profile))->icon('shield-check'),
            ])
            ->bulkActions([])
            ->pageActions([
                Action::link('review-queue', 'Review pending', route('admin.profiles.review'))
                    ->icon('shield-check'),
            ])
            ->toArray($request);
    }

    /**
     * The record's own log, read-only, in the same envelope every other table arrives in.
     *
     * It is the only table on the page, so its five reloadable keys sit at the top level
     * beside `profile` and sorting, searching and paging work the way they do on a list.
     *
     * @return array<string, mixed>
     */
    private function activityTable(Request $request, UserProfile $userProfile): array
    {
        Gate::authorize('viewAny', Activity::class);

        $table = (new Activity)->getTable();

        $query = Activity::query()
            ->where('subject_type', $userProfile->getMorphClass())
            ->where('subject_id', $userProfile->getKey())
            ->with('causer');

        /*
         * `causer` is a MorphTo, which whereHas() refuses, so the search is applied here
         * against the users who can actually cause an entry and no column is declared
         * searchable. Same shape as the fursuit log.
         */
        $search = trim((string) $request->input('search', ''));

        if ($search !== '') {
            $query->whereHasMorph(
                'causer',
                [User::class],
                fn (Builder $causer) => $causer->where('name', 'like', '%'.$search.'%'),
            );
        }

        return Table::make($query)
            ->name('profile-activities')
            ->columns([
                Column::text('description', 'Description'),
                Column::text('causer_name', 'By')->sortUsing(
                    fn (Builder $builder, string $dir) => $builder->orderBy(
                        User::select('name')
                            ->whereColumn('users.id', $table.'.causer_id')
                            ->where($table.'.causer_type', (new User)->getMorphClass()),
                        $dir,
                    )
                ),
                Column::datetime('created_at', 'Logged at')->sortable(),
            ])
            // Newest first by key rather than by timestamp: the log is append-only and a
            // transition writes several entries inside the same second.
            ->defaultSort('id', 'desc')
            ->filters([])
            ->rows(fn (Activity $activity) => [
                'description' => $activity->description,
                'causer_name' => $activity->causer?->name,
                'created_at' => $this->datetime($activity->created_at),
            ])
            ->bulkActions([])
            ->pageActions([])
            ->toArray($request);
    }

    /**
     * @return array{display: string, title: string}|null
     */
    private function datetime(?CarbonInterface $value): ?array
    {
        return $value === null ? null : [
            'display' => $value->format(self::DATETIME_FORMAT),
            'title' => $value->toIso8601String(),
        ];
    }
}
