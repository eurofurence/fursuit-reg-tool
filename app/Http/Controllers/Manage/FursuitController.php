<?php

namespace App\Http\Controllers\Manage;

use App\Enum\FursuitReviewOutcomeEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Manage\FursuitRequest;
use App\Models\Event;
use App\Models\Fursuit\Fursuit;
use App\Models\Fursuit\States\Approved;
use App\Models\Fursuit\States\FursuitStatusState;
use App\Models\Fursuit\States\Pending;
use App\Models\Fursuit\States\Rejected;
use App\Models\Species;
use App\Models\User;
use App\Services\FursuitPresence;
use App\Services\FursuitReviewService;
use App\Support\Manage\Action;
use App\Support\Manage\Column;
use App\Support\Manage\EventScope;
use App\Support\Manage\Filter;
use App\Support\Manage\Status;
use App\Support\Manage\Table;
use App\Support\Manage\Toast;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

/**
 * Fursuits: the list, the view page and the edit form (audit 4.3, 4.3.3, 4.3.4, 4.3.5).
 *
 * The moderation verbs live in FursuitModerationController and the mail-only action in
 * FursuitNotificationController; this class owns the three pages and the props they read.
 *
 * Four shapes differ from Filament, all of them decisions the plan already made.
 *
 *  - There is no create page. FursuitPolicy::create() returns false and stays false
 *    (plan 2.2, audit 38), so the Filament create page was already unreachable.
 *  - `status` is no longer a free-text TextInput writing straight through the cast. It
 *    is a picker over the transitions the state machine allows from the record's current
 *    state, and the write goes through transitionTo(), so approved_at / rejected_at
 *    bookkeeping, the activity entry and the user's notification all happen (plan 2.10
 *    #9, audit 21). `approved_at` and `rejected_at` stop being hand-editable and can no
 *    longer contradict `status`.
 *  - `event_id` is a relation select rather than a numeric TextInput (plan 2.6).
 *  - The activity log on the view page is read-only (plan 2.10 #12, audit 56).
 *
 * The list is event-scoped exactly as it is today (plan 2.9).
 */
class FursuitController extends Controller
{
    /**
     * Filament's default table date-time format, kept so timestamps read the same after
     * the move. The ISO string rides along as the cell title.
     */
    private const DATETIME_FORMAT = 'M j, Y H:i:s';

    /**
     * Filament's model label for this resource, as its delete modal renders it.
     */
    private const MODEL_LABEL = 'fursuit';

    /**
     * The state machine's edges, keyed by the stored state name, so the form and the
     * request validate against one list. Read through allowedTransitions().
     *
     * @var array<string, class-string<FursuitStatusState>>
     */
    public const STATES = [
        'pending' => Pending::class,
        'approved' => Approved::class,
        'rejected' => Rejected::class,
    ];

    /**
     * The list envelope is spread across top-level props rather than nested under one,
     * because useTableQuery reloads `rows`, `meta`, `filters`, `sort` and `search` as a
     * partial visit and Inertia filters partials by top-level key. Nested under a single
     * prop all five resolve to null and every sort, filter and page click is a silent
     * no-op.
     */
    public function index(Request $request, EventScope $scope): Response
    {
        Gate::authorize('viewAny', Fursuit::class);

        return inertia('Manage/Fursuits/Index', $this->table($request, $scope));
    }

    /**
     * The view page: the infolist, the moderation actions, and the read-only activity
     * list that used to be a relation manager.
     *
     * The activity list is a full table envelope of its own, which is why its props sit
     * at the top level beside `fursuit`: it is the only table on the page, so the five
     * keys useTableQuery reloads are unambiguous, and sorting and searching the log
     * therefore work the same way they do on every list page.
     */
    public function show(Request $request, Fursuit $fursuit): Response
    {
        Gate::authorize('view', $fursuit);

        $fursuit->load(['user', 'species', 'event']);

        /*
         * Reading a record counts as working it, so the queue skips it for other reviewers
         * while somebody has it open. Advisory only: presence never refuses a verdict, it
         * only keeps `next` from handing the same record to two people.
         */
        FursuitPresence::touch($fursuit, $request->user());

        return inertia('Manage/Fursuits/Show', [
            'fursuit' => $this->viewData($fursuit, $request->user()),
            'actions' => array_map(
                fn (Action $action) => $action->toArray(),
                $this->moderationActions($fursuit, $request->user()),
            ),
            /*
             * The two action forms the client renders itself rather than through
             * ActionButton: the reject modal prefills its textarea from the reason
             * picker, and the notification modal shows its reason field only for a
             * rejection. Both are live behaviour ActionButton has no concept of, and
             * the second is a bug fix in its own right (audit 73: the Filament Select
             * was never ->live(), so the conditional field only appeared on the next
             * form round-trip).
             */
            'rejectReasons' => FursuitModerationController::rejectReasonOptions(),
            // The publication block has its own list: the eight rejection strings all tell
            // the attendee to fix a badge which, in this case, is fine and will be printed.
            'publicationReasons' => FursuitReviewService::reasonOptions(FursuitReviewOutcomeEnum::PublicationBlocked),
            'notificationTypes' => FursuitNotificationController::typeOptions(),
            ...$this->activityTable($request, $fursuit),
        ]);
    }

    public function edit(Request $request, Fursuit $fursuit): Response
    {
        Gate::authorize('update', $fursuit);

        return inertia('Manage/Fursuits/Form', $this->formProps($fursuit, $request->user()));
    }

    /**
     * Saves the plain attributes, then runs the state change as a transition.
     *
     * The order matters: the transitions save the model themselves, so writing the
     * attributes first means one record reaches the notification and the activity entry
     * with the name the operator just gave it.
     */
    public function update(FursuitRequest $request, Fursuit $fursuit): RedirectResponse
    {
        $fursuit->update($request->payload());

        $target = $request->transitionTarget();

        if ($target !== null) {
            /*
             * Never `$fursuit->status = ...`. The whole point of plan 2.10 #9 is that
             * the column stops being written behind the machine's back: each transition
             * stamps its own timestamps, writes the activity entry and notifies the
             * owner. Rejected -> Pending is registered without a transition class, so it
             * takes no arguments at all; the other two carry the reviewer, and rejection
             * also carries the reason that is mailed out.
             */
            match ($target) {
                Approved::class => $fursuit->status->transitionTo(Approved::class, $request->user()),
                Rejected::class => $fursuit->status->transitionTo(Rejected::class, $request->user(), $request->rejectionReason()),
                default => $fursuit->status->transitionTo(Pending::class),
            };
        }

        // Filament's stock EditRecord toast; this resource declares none of its own.
        Toast::flashSuccess('Saved');

        return redirect()->route('admin.fursuits.show', $fursuit);
    }

    /**
     * Soft delete, as today: the model uses SoftDeletes and FursuitObserver cascades the
     * deletion to the fursuit's badges (audit 78).
     */
    public function destroy(Fursuit $fursuit): RedirectResponse
    {
        Gate::authorize('delete', $fursuit);

        $fursuit->delete();

        Toast::flashSuccess('Deleted');

        return redirect()->route('admin.fursuits.index');
    }

    /**
     * A signed, short-lived link to a private object on s3.
     *
     * Every read site in the panel reads s3 (audit 7.4), so this names the disk rather
     * than inheriting the default, which is what let the Filament upload write somewhere
     * else than the table and the infolist read from. A disk that cannot sign - the fake
     * used by the tests, or a local dev disk - falls back to a plain URL rather than
     * taking the page down.
     */
    /**
     * The photo the panel shows for a fursuit: the gallery webp, not the print master.
     *
     * The master is archival - FursuitImageService stores it print-sized, and well over a
     * megabyte at 2040x2720 is normal - so the review queue was pulling a print file over the
     * wire to fill a 700px column, once per record, hundreds of records in a sitting.
     * GenerateFursuitWebpJob renders the 1080x1920 variant the gallery uses the moment a photo
     * is submitted or replaced (FursuitObserver), so on any record a reviewer sees it already
     * exists.
     *
     * It falls back to the master rather than to nothing, because the variant is derived data:
     * a queue backlog, or a fursuit imported before the renders existed, must still show a
     * picture to judge.
     */
    public static function previewUrl(?Fursuit $fursuit): ?string
    {
        if ($fursuit === null) {
            return null;
        }

        return Fursuit::variantUrl($fursuit->image_webp) ?? self::imageUrl($fursuit->image);
    }

    /**
     * The same, at grid size: the 500px thumbnail behind a list row.
     *
     * A table of fifty rows used to sign and serve fifty print masters.
     */
    public static function thumbUrl(?Fursuit $fursuit): ?string
    {
        if ($fursuit === null) {
            return null;
        }

        return Fursuit::variantUrl($fursuit->image_thumb)
            ?? Fursuit::variantUrl($fursuit->image_webp)
            ?? self::imageUrl($fursuit->image);
    }

    public static function imageUrl(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        $disk = Storage::disk('s3');

        try {
            return $disk->temporaryUrl($path, now()->addMinutes(15));
        } catch (\Throwable) {
            try {
                return $disk->url($path);
            } catch (\Throwable) {
                return null;
            }
        }
    }

    /**
     * The state names reachable from a record's current state, in machine order.
     *
     * One list, read by the form's picker and by FursuitRequest's Rule::in, so the form
     * cannot offer a transition the request would refuse or the machine would reject.
     *
     * @return array<int, string>
     */
    public static function allowedTransitions(Fursuit $fursuit, User $reviewer): array
    {
        $current = $fursuit->status;

        return collect(self::STATES)
            ->filter(function (string $state, string $stateName) use ($current, $reviewer) {
                // `$current::$name` reads the state class's own static $name, not the
                // loop variable: PHP takes the property name literally after `::`.
                if ($current::$name === $stateName) {
                    return false;
                }

                /*
                 * canTransitionTo() instantiates the transition class to ask it, so the
                 * arguments have to be the ones each constructor is typed for:
                 * PendingToRejected takes a reason as well as the reviewer, and
                 * Rejected -> Pending has no transition class at all.
                 */
                return match ($state) {
                    Rejected::class => $current->canTransitionTo(Rejected::class, $reviewer, ''),
                    Approved::class => $current->canTransitionTo(Approved::class, $reviewer),
                    default => $current->canTransitionTo(Pending::class),
                };
            })
            ->keys()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function table(Request $request, EventScope $scope): array
    {
        // The list renders an owner, a species and an image per row. Without the eager
        // load that is three queries a row.
        $query = $scope->apply(Fursuit::query()->with(['user', 'species']));

        return Table::make($query)
            ->name('fursuits')
            ->columns($this->columns())
            // FursuitResource declares no defaultSort and falls back to primary-key
            // order. Stated rather than left implicit, so the order does not depend on
            // whatever the driver happens to return.
            ->defaultSort('id')
            ->filters([
                /*
                 * The one filter this table has ever had, and it opens on Pending
                 * (audit 135). The list has never shown the full set on first load, so
                 * losing the default would read as missing data rather than as a wider
                 * view. Clearing it is a separate request carrying Filter::CLEARED.
                 */
                Filter::select('status', 'Status')
                    ->options([
                        'pending' => 'Pending',
                        'approved' => 'Approved',
                        'rejected' => 'Rejected',
                    ])
                    ->default('pending'),

                /*
                 * The other half of a verdict. An approved fursuit may still be barred
                 * from the gallery and the game, and that is not visible in `status` -
                 * which is the point of it, but it also means the list could not answer
                 * "what did we block, and was that right" without this.
                 */
                Filter::ternary('publication_blocked', 'Gallery blocked')
                    ->trueLabel('Blocked')
                    ->falseLabel('Not blocked')
                    ->apply(fn (Builder $query, mixed $value) => $value
                        ? $query->whereNotNull('publication_blocked_at')
                        : $query->whereNull('publication_blocked_at')),
            ])
            ->rows(fn (Fursuit $fursuit) => [
                'user_name' => $fursuit->user?->name,
                'species_name' => $fursuit->species?->name,
                'status' => Status::fursuit($fursuit->status),
                'name' => $fursuit->name,
                // The 500px gallery thumbnail. A row does not need the print master, and fifty
                // rows of them was fifty signed multi-megabyte objects per page load.
                'image' => self::thumbUrl($fursuit),
                'published' => (bool) $fursuit->published,
                'catch_em_all' => (bool) $fursuit->catch_em_all,
                'publication_blocked' => $fursuit->isPublicationBlocked(),
            ])
            ->recordUrl(fn (Fursuit $fursuit) => route('admin.fursuits.show', $fursuit))
            // ViewAction only. EditAction sits commented out in the resource (audit
            // 4.3), and the edit page is reached from the view page instead.
            ->rowActions(fn (Fursuit $fursuit) => [
                Action::link('view', 'View', route('admin.fursuits.show', $fursuit))->icon('eye'),
            ])
            // FursuitResource declares no bulk actions, and the create header action is
            // hidden in practice because the policy refuses it.
            ->bulkActions([])
            // The way into the queue. The list is where a reviewer lands, and working the
            // backlog record page by record page is the thing the queue page exists to
            // stop.
            ->pageActions([
                Action::link('review', 'Review queue', route('admin.fursuits.review'))->icon('shield-check'),
            ])
            ->toArray($request);
    }

    /**
     * The audit's seven columns, in order, with Filament's own auto labels verbatim.
     *
     * The audit's `user.name` and `species.name` are keyed `user_name` and `species_name`
     * here, labels unchanged: a dot in a cell key is read as a path by every data_get
     * consumer, including Inertia's own prop assertions. Both sort through a correlated
     * subquery rather than a join, so a fursuit whose owner row is gone - `user_id` is
     * nullable since 2025_07_31_180103 - still sorts instead of dropping out of the list.
     *
     * @return array<int, Column>
     */
    private function columns(): array
    {
        return [
            Column::text('user_name', 'By')->sortUsing(
                fn (Builder $query, string $dir) => $query->orderBy(
                    User::select('name')->whereColumn('users.id', 'fursuits.user_id'),
                    $dir,
                )
            ),
            Column::text('species_name', 'Species.name')->sortUsing(
                fn (Builder $query, string $dir) => $query->orderBy(
                    Species::select('name')->whereColumn('species.id', 'fursuits.species_id'),
                    $dir,
                )
            ),
            // Searchable against the stored state name, which is what the Filament
            // column searched: `pending`, `approved`, `rejected`.
            Column::badge('status', 'Status')->searchable(),
            Column::text('name', 'Name')->searchable(),
            // Filament's ImageColumn is ->circular() here (audit 4.3 column 5).
            Column::image('image', 'Image')->circular(),
            Column::bool('published', 'Published'),
            Column::bool('catch_em_all', 'Catch em all'),
            // Read beside the two above, never instead of them: those are the attendee's
            // switches and this is the reviewer's veto over both.
            Column::bool('publication_blocked', 'Gallery blocked'),
        ];
    }

    /**
     * The infolist, transcribed (audit 4.3). Hints and helper texts are the attendee-
     * facing strings the Filament entries carried, verbatim, because they explain to the
     * reviewer what the attendee was told when they submitted.
     *
     * @return array<string, mixed>
     */
    private function viewData(Fursuit $fursuit, ?User $viewer): array
    {
        return [
            'id' => $fursuit->id,
            'name' => $fursuit->name,
            'species' => $fursuit->species?->name,
            'image' => self::previewUrl($fursuit),
            'published' => (bool) $fursuit->published,
            'catch_em_all' => (bool) $fursuit->catch_em_all,
            'status' => Status::fursuit($fursuit->status),
            /*
             * The second half of the verdict: approved records may still be barred from
             * the gallery and Catch-Em-All. The two `published` / `catch_em_all` flags
             * above are the attendee's own switches, and the block sits over them, so this
             * has to be read beside them or the page would claim a blocked fursuit is
             * published.
             */
            'publication' => [
                'blocked' => $fursuit->isPublicationBlocked(),
                'reason' => $fursuit->publication_block_reason,
                'blockedAt' => $fursuit->publication_blocked_at?->toIso8601String(),
            ],
            /*
             * Who else is looking. The Filament page showed no indication at all, so a
             * reviewer could only find out by pressing a button and watching where it took
             * them - and the lock behind that button then refused their verdict.
             */
            'presence' => [
                'others' => $viewer === null ? [] : FursuitPresence::others($fursuit, $viewer),
                'heartbeatSeconds' => FursuitPresence::HEARTBEAT_SECONDS,
            ],
            /*
             * How many times the submission has been changed since it was made. The record
             * page keeps it to a count and points at the queue page for the pictures: this
             * page already carries the full activity log, and the side-by-side comparison is
             * what the review surface is for.
             */
            'revisions' => $fursuit->submissionRevisions()->count(),
            'editUrl' => $viewer !== null && Gate::forUser($viewer)->allows('update', $fursuit)
                ? route('admin.fursuits.edit', $fursuit)
                : null,
        ];
    }

    /**
     * The record page's header actions.
     *
     * Two deliberate departures from ViewFursuit, both of them plan decisions.
     *
     * Claim and Unclaim are gone. The lock they took refused verdicts, so a reviewer who
     * opened a record by link could do nothing with it and a dead browser froze the record
     * for five minutes (plan 2.10 #41, audit 69/71). Presence replaced it, and presence is
     * advisory: it is shown, never enforced. A verdict therefore needs no claim first.
     *
     * The publication block is new. Approval used to be a yes/no, so a photo that broke a
     * gallery rule but no rule in the Code of Conduct could only be rejected - which stops
     * the card as well, costing the attendee a badge over a gallery rule.
     *
     * @return array<int, Action>
     */
    private function moderationActions(Fursuit $fursuit, ?User $viewer): array
    {
        if ($viewer === null) {
            return [];
        }

        $status = $fursuit->status;
        $reviews = app(FursuitReviewService::class);
        $canApprove = $reviews->can($fursuit, FursuitReviewOutcomeEnum::Approved, $viewer);
        $canReject = $reviews->can($fursuit, FursuitReviewOutcomeEnum::Rejected, $viewer);
        $canBlock = $reviews->can($fursuit, FursuitReviewOutcomeEnum::PublicationBlocked, $viewer);

        return array_values(array_filter([
            $canApprove
                ? Action::post('approve', 'Approve', route('admin.fursuits.approve', $fursuit))
                    ->icon('circle-check')
                    ->tone(Status::OK)
                    // A bare requiresConfirmation(): the label as the heading, the
                    // framework's default body, and Confirm to submit.
                    ->confirmDefault()
                : null,

            $canReject
                ? Action::post('reject', 'Reject', route('admin.fursuits.reject', $fursuit))
                    ->icon('circle-x')
                    ->tone(Status::DANGER)
                    ->confirmDefault()
                    ->fields([
                        [
                            'key' => 'reason',
                            'label' => 'Reason',
                            'type' => 'select',
                            'options' => FursuitModerationController::rejectReasonOptions(),
                            'required' => false,
                        ],
                        [
                            'key' => 'custom_reason',
                            'label' => 'Reason Sent to the User!',
                            'type' => 'textarea',
                            'required' => true,
                        ],
                    ])
                : null,

            $canBlock
                ? Action::post('block-publication', 'Block from gallery', route('admin.fursuits.block-publication', $fursuit))
                    ->icon('eye-off')
                    ->tone(Status::WARN)
                    ->confirm(
                        'Block from gallery and game',
                        'The badge is approved, printed and handed out. It will not appear in the gallery and cannot be caught in the game.',
                        'Block publication',
                    )
                    ->fields([
                        [
                            'key' => 'reason',
                            'label' => 'Reason',
                            'type' => 'select',
                            'options' => FursuitReviewService::reasonOptions(FursuitReviewOutcomeEnum::PublicationBlocked),
                            'required' => false,
                        ],
                        [
                            'key' => 'custom_reason',
                            'label' => 'Reason Sent to the User!',
                            'type' => 'textarea',
                            'required' => true,
                        ],
                    ])
                : null,

            $fursuit->isPublicationBlocked()
                ? Action::delete('unblock-publication', 'Lift gallery block', route('admin.fursuits.unblock-publication', $fursuit))
                    ->icon('eye')
                    ->tone(Status::INFO)
                    ->confirm(
                        'Lift the gallery block',
                        'The gallery and the game follow the attendee\'s own setting again. The attendee is not notified.',
                        'Lift block',
                    )
                : null,

            $status instanceof Rejected
                ? Action::post('approve-rejected', 'Approve (Rejected)', route('admin.fursuits.approve-rejected', $fursuit))
                    ->icon('circle-check')
                    ->tone(Status::OK)
                    ->confirm(
                        'Approve Rejected Fursuit',
                        'This will send an apology email to the user and approve the fursuit.',
                        'Yes, approve it',
                    )
                : null,

            // No visibility predicate and no confirmation, as today: it sends mail
            // without changing state and without checking the current state.
            Action::post('send-notification', 'Send Notification', route('admin.fursuits.notify', $fursuit))
                ->icon('mail')
                ->tone(Status::INFO)
                ->fields([
                    [
                        'key' => 'notification_type',
                        'label' => 'Notification Type',
                        'type' => 'select',
                        'options' => FursuitNotificationController::typeOptions(),
                        'required' => true,
                    ],
                    [
                        'key' => 'rejection_reason',
                        'label' => 'Rejection Reason (Required for Rejection)',
                        'type' => 'textarea',
                        'required' => false,
                    ],
                ]),

            Action::link('next', 'Next Fursuit', route('admin.fursuits.next', $fursuit))
                ->icon('arrow-right')
                ->tone(Status::INFO),

            /*
             * Into the queue surface. Not a ViewFursuit action: the queue page did not
             * exist. A reviewer who lands on a record from the list should not have to
             * work the rest of the afternoon through record pages.
             */
            Action::link('review', 'Review in queue', route('admin.fursuits.review.show', $fursuit))
                ->icon('shield-check')
                ->tone(Status::INFO),

            /*
             * Not a ViewFursuit action. ViewFursuit had no edit button and the row
             * EditAction is commented out, so /admin/fursuits/{id}/edit is reachable
             * today only by typing the URL. The route exists in plan 2.1, so the page
             * offers the link rather than leaving it unreachable; the policy still
             * decides who sees it.
             */
            Gate::forUser($viewer)->allows('update', $fursuit)
                ? Action::link('edit', 'Edit', route('admin.fursuits.edit', $fursuit))->icon('pencil')
                : null,
        ]));
    }

    /**
     * The read-only successor to ActivitiesRelationManager (audit 4.3.5).
     *
     * Create, edit, delete and bulk delete are gone with plan 2.10 #12: an audit trail
     * the audited party can edit is not an audit trail, `causer` was never set on a
     * manual create, and a form round-trip double-encoded `properties` into a
     * collection-cast column. ActivityPolicy refuses every write and no write route
     * exists.
     *
     * Two additions the relation manager lacked and audit 134 calls out: a visible
     * timestamp, and a declared newest-first order instead of primary-key order.
     *
     * @return array<string, mixed>
     */
    private function activityTable(Request $request, Fursuit $fursuit): array
    {
        Gate::authorize('viewAny', Activity::class);

        $activities = new Activity;
        $table = $activities->getTable();

        $query = Activity::query()
            ->where('subject_type', $fursuit->getMorphClass())
            ->where('subject_id', $fursuit->getKey())
            ->with('causer');

        /*
         * `causer` is a MorphTo, so Table's generic relation search cannot reach it:
         * whereHas() refuses a MorphTo outright. The search is applied here instead,
         * against the users who can actually cause an entry, and no column is declared
         * searchable so Table leaves the term alone and only echoes it back to the box.
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
            ->name('fursuit-activities')
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
            /*
             * Newest first, by key rather than by timestamp. The log is append-only, so
             * the two orders agree, and several entries routinely land inside the same
             * second - a transition writes its entry alongside the model's own `updated`
             * one - which would leave a timestamp sort tied and the order down to the
             * driver. The timestamp column is still sortable on request.
             */
            ->defaultSort('id', 'desc')
            ->filters([])
            ->rows(fn (Activity $activity) => [
                'description' => $activity->description,
                'causer_name' => $activity->causer?->name,
                'created_at' => $this->datetime($activity->created_at),
            ])
            // No row url, no row actions, no bulk actions: the log is a record of what
            // happened, not a list of things to do.
            ->bulkActions([])
            ->pageActions([])
            ->toArray($request);
    }

    /**
     * Shared by the edit page: the record, the two relation option lists, and the
     * transitions the machine allows from where this record stands.
     *
     * @return array<string, mixed>
     */
    private function formProps(Fursuit $fursuit, User $reviewer): array
    {
        return [
            'fursuit' => [
                'id' => $fursuit->id,
                'user_id' => $fursuit->user_id,
                'species_id' => $fursuit->species_id,
                'event_id' => $fursuit->event_id,
                'status' => $fursuit->status::$name,
                'statusLabel' => Status::fursuit($fursuit->status),
                'name' => $fursuit->name,
                'image' => $fursuit->image,
                // The preview beside the upload field, so replacing a photo does not first
                // download a print file.
                'imageUrl' => self::previewUrl($fursuit),
                'published' => (bool) $fursuit->published,
                'catch_em_all' => (bool) $fursuit->catch_em_all,
            ],
            /*
             * EditFursuit's own header: View, and Delete with Filament's default delete
             * copy (audit 4.3.3). The delete is a soft delete and FursuitObserver
             * cascades it to the fursuit's badges.
             */
            'actions' => array_map(fn (Action $action) => $action->toArray(), array_values(array_filter([
                Action::link('view', 'View', route('admin.fursuits.show', $fursuit))->icon('eye'),
                Gate::forUser($reviewer)->allows('delete', $fursuit)
                    ? Action::delete('delete', 'Delete', route('admin.fursuits.destroy', $fursuit))
                        ->icon('trash-2')
                        ->tone(Status::DANGER)
                        ->confirmDelete(self::MODEL_LABEL)
                    : null,
            ]))),
            'users' => User::orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (User $user) => ['value' => $user->id, 'label' => $user->name])
                ->all(),
            'species' => Species::orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Species $species) => ['value' => $species->id, 'label' => $species->name])
                ->all(),
            // Same order as the global event selector, so one event list in the panel is
            // never ordered differently from another (plan 2.10 #53).
            'events' => Event::orderByDesc('starts_at')
                ->get()
                ->map(fn (Event $event) => ['value' => $event->id, 'label' => $event->name])
                ->all(),
            /*
             * The transition picker. Only the edges the machine allows from here, so the
             * form cannot ask for a state the transition would refuse - which the
             * TextInput it replaces could, and did, by writing the column directly.
             */
            'transitions' => array_map(
                fn (string $name) => ['value' => $name, 'label' => Status::fursuit($name)['label']],
                self::allowedTransitions($fursuit, $reviewer),
            ),
            // The one purpose POST /admin/uploads accepts today. Named here so the form
            // never guesses which disk and which limits its file goes through.
            'uploadPurpose' => 'fursuit_image',
        ];
    }

    /**
     * @return array{display: string, title: string}|null
     */
    private function datetime(?CarbonInterface $value): ?array
    {
        if ($value === null) {
            return null;
        }

        return [
            'display' => $value->format(self::DATETIME_FORMAT),
            'title' => $value->toIso8601String(),
        ];
    }
}
