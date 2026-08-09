<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Models\Badge\Badge;
use App\Models\Badge\State_Payment\Paid;
use App\Models\Event;
use App\Models\EventUser;
use App\Models\User;
use App\Support\Manage\Action;
use App\Support\Manage\Column;
use App\Support\Manage\Status;
use App\Support\Manage\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Response;

/**
 * DB Service, the successor to App\the old panel\Pages\DbService.
 *
 * One repair lives here, the one the old panel page carried: badges that were charged the
 * badge fee although their owner still had unused prepaid entitlement for the event. The
 * audit's method table names exactly that one repair plus its preview, its cancel, its
 * reset and the two formatters, and nothing else is added here.
 *
 * The page follows the read-only tool shape (Manage/Tools/BadgePreview): state is the URL
 * and the session, not component state. `?review=1` is the review step, which recomputes
 * the report as a pure read on every GET, so it survives a reload; the result panel is a
 * one-shot session flash, because after the write there is nothing left to recompute.
 *
 * Three requests, one of which writes:
 *
 *  - GET   /admin/maintenance/db-service           renders idle, review or result. Reads only.
 *  - POST  .../preview                             `previewFreeBadgeFix()`. Reads only, flashes
 *                                                  the "Nothing to fix" toast, redirects to
 *                                                  `?review=1`.
 *  - POST  .../apply                               `applyFreeBadgeFix()`. The only write in the
 *                                                  module.
 *
 * `cancelFreeBadgeFix()` and `resetFreeBadgeFix()` were wire:click handlers that cleared
 * component state; here they are plain GET links back to the page, so neither touches the
 * server's state at all.
 *
 * Not event-scoped. It operates on `Event::getActiveEvent()`, the newest event by
 * `starts_at`, not the header selection, exactly as the old panel page did. The page names the event it is about to touch on screen rather than
 * leaving "the current event" to be guessed at.
 *
 * Admin only, through `manage-admin`. This is the successor to `DbService::canAccess()`,
 * the one page-level gate in the old panel: the panel admits reviewers too, so the
 * extra gate is required on every one of the three endpoints, not only on the GET.
 *
 * The analysis and the write below are `App\Services\FreeBadgeRepairService`, which was
 * deleted from the repository in commit 5aa2148 together with the old panel page it served.
 * They are reproduced here, in the module that owns the screen, rather than by restoring
 * the deleted file. Two things differ from the audit's description of that service, both
 * of them consequences of the wallet package being removed in fa0554e:
 * the `$user->deposit(...)` credit is gone (docs/wallet-removal-plan.md line 140 asks for
 * exactly that deletion, and the service had already lost the call before it was deleted),
 * and the copy no longer promises a wallet transaction. Zeroing the total *is* the
 * correction now: it drops the badge out of `User::amountDue()`. The amount that had been
 * wrongly charged is still reported and still written to the activity log.
 *
 * Which is also why a third thing differs, and this one is a fix rather than a
 * consequence: the repair no longer touches a badge that has already been paid. See
 * `analyseUser()` and plan 2.10 #75.
 */
class DbServiceController extends Controller
{
    /**
     * The activity-log message, verbatim. Anything reading the log for this repair keys on
     * it, so it is a constant rather than a literal in the loop.
     */
    public const ACTIVITY_DESCRIPTION = 'Corrected wrongly charged prepaid badge to free';

    /**
     * `FreeBadgeRepairService::imageUrl()`'s window, and the one plan 2.7 fixes on for every
     * private S3 read in the panel.
     */
    private const IMAGE_URL_TTL_MINUTES = 15;

    /**
     * Where `apply()` parks its outcome for the redirect that follows it. A flash, not a
     * persisted row: the Livewire page dropped `$freeBadgeResult` on the next page load too.
     */
    private const RESULT_KEY = 'db_service_free_badge_result';

    /**
     * The page. Idle, review or result, decided by the query string and the flash.
     *
     * Every branch is a read. `?review=1` recomputes the report instead of carrying it
     * through the session, so what the operator confirms is what the database says now,
     * and a reload does not empty the screen.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('manage-admin');

        $event = Event::getActiveEvent();
        $report = $request->boolean('review') ? $this->report($event) : null;
        $result = $request->session()->get(self::RESULT_KEY);
        $result = is_array($result) ? $result : null;

        return inertia('Manage/Tools/DbService', [
            'event' => $event === null ? null : ['id' => $event->id, 'name' => $event->name],
            'report' => $report,
            'result' => $result,
            'actions' => $this->actions($report, $result),
        ]);
    }

    /**
     * `previewFreeBadgeFix()`. A pure read: it computes the report only to decide whether
     * the "Nothing to fix" notification fires, then hands the review URL to the browser,
     * which recomputes it in `index()`.
     */
    public function preview(Request $request): RedirectResponse
    {
        Gate::authorize('manage-admin');

        $report = $this->report(Event::getActiveEvent());

        if ($report['affected_badge_count'] === 0) {
            Toast::flashSuccess('Nothing to fix', 'No wrongly-charged prepaid badges were found for the current event.');
        }

        // the old panel set `reviewingFreeBadges = true` whether or not anything was found, so
        // the empty report is shown with its three zeroed stat cards rather than swallowed.
        return redirect()->route('admin.maintenance.db-service', ['review' => 1]);
    }

    /**
     * `applyFreeBadgeFix()`. The only write in the module.
     */
    public function apply(Request $request): RedirectResponse
    {
        Gate::authorize('manage-admin');

        $result = $this->repair(Event::getActiveEvent(), $request->user());

        if ($result['success']) {
            Toast::flashSuccess(
                'Fix applied',
                'Converted '.$result['fixed_badge_count'].' badge(s) for '.$result['fixed_user_count'].' user(s) to free.'
            );
        } else {
            Toast::flashDanger('Fix failed', $result['error'] ?? 'Unknown error.');
        }

        return redirect()
            ->route('admin.maintenance.db-service')
            ->with(self::RESULT_KEY, $result);
    }

    /**
     * The blade's buttons, declared server-side so the confirm copy and the visibility rule
     * are assertable rather than re-derived in the client.
     *
     * Three mutually exclusive states, in the blade's order of precedence: a result hides
     * everything but "Run again"; a report offers apply and cancel; otherwise the page is
     * idle and offers the preview.
     *
     * @param  array<string, mixed>|null  $report
     * @param  array<string, mixed>|null  $result
     * @return array<int, array<string, mixed>>
     */
    private function actions(?array $report, ?array $result): array
    {
        $page = route('admin.maintenance.db-service');

        if ($result !== null) {
            // `resetFreeBadgeFix`, which cleared report, result and review flag. A link back
            // to the bare page does all three, and writes nothing.
            return [
                Action::link('run-again', 'Run again', $page)->icon('refresh-cw')->toArray(),
            ];
        }

        if ($report === null) {
            return [
                Action::post('preview', 'Fix free badges', route('admin.maintenance.db-service.preview'))
                    ->icon('search')
                    ->toArray(),
            ];
        }

        $actions = [];

        // Shown only when there is something to convert, matching the blade.
        if ($report['affected_badge_count'] > 0) {
            $actions[] = Action::post('apply', 'Confirm & apply fix', route('admin.maintenance.db-service.apply'))
                ->icon('check')
                // the old panel's color('success').
                ->tone(Status::OK)
                /*
                 * The wire:confirm string from db-service.blade.php:112, verbatim, with its
                 * interpolation. It moves from a browser confirm() into ManageDialog, which
                 * is the panel's one dialog, but the sentence the operator reads before
                 * money moves is byte for byte the sentence they read today.
                 */
                ->confirm(
                    'Confirm & apply fix',
                    'Convert '.$report['affected_badge_count'].' badge(s) to free and refund '
                        .Column::euros($report['total_refund_cents']).'? This cannot be undone automatically.',
                    'Confirm & apply fix'
                )
                ->toArray();
        }

        // `cancelFreeBadgeFix`, which cleared the review flag and the report.
        $actions[] = Action::link('cancel', 'Cancel', $page)->icon('x')->toArray();

        return $actions;
    }

    /**
     * `FreeBadgeRepairService::preview()`, plus the display strings the blade produced with
     * `formatEuro()` and `imageUrl()`.
     *
     * Reads only. No query in this path writes, and the S3 signature is computed rather
     * than stored, so a preview leaves the database exactly as it found it.
     *
     * @return array<string, mixed>
     */
    private function report(?Event $event): array
    {
        if ($event === null) {
            return [
                'event_id' => null,
                'event_name' => null,
                'affected_user_count' => 0,
                'affected_badge_count' => 0,
                'total_refund_cents' => 0,
                'total_refund' => Column::euros(0),
                'rows' => [],
            ];
        }

        $rows = [];
        $userCount = 0;
        $refund = 0;

        foreach ($this->eventUsersWithPrepaid($event) as $eventUser) {
            $analysis = $this->analyseUser($eventUser, $event);
            $badgesToFix = $analysis['badges_to_fix'];

            if ($badgesToFix->isEmpty()) {
                continue;
            }

            $userCount++;

            foreach ($badgesToFix as $badge) {
                $refund += (int) $badge->total;

                $rows[] = [
                    'badge_id' => $badge->id,
                    'custom_id' => $badge->custom_id,
                    'image_url' => $this->imageUrl($badge->fursuit?->image),
                    'fursuit' => $badge->fursuit?->name,
                    'species' => $badge->fursuit?->species?->name,
                    'owner' => $eventUser->user?->name,
                    'user_id' => $eventUser->user_id,
                    'prepaid_badges' => (int) $eventUser->prepaid_badges,
                    'badges_total' => $analysis['badges_total'],
                    'should_be_free' => $analysis['should_be_free'],
                    'should_be_paid' => $analysis['should_be_paid'],
                    'current_total_cents' => (int) $badge->total,
                    // One server-side formatter for every money surface in the panel
                    // . Column::euros() is the same '€'.number_format($c / 100, 2)
                    // DbService::formatEuro() was.
                    'refund' => Column::euros($badge->total),
                ];
            }
        }

        return [
            'event_id' => $event->id,
            'event_name' => $event->name,
            'affected_user_count' => $userCount,
            'affected_badge_count' => count($rows),
            'total_refund_cents' => $refund,
            'total_refund' => Column::euros($refund),
            'rows' => $rows,
        ];
    }

    /**
     * `FreeBadgeRepairService::repair()`, atomically.
     *
     * The analysis is re-run here rather than taking the previewed badge ids from the
     * request: a client-supplied id list would let the browser choose which badges get
     * zeroed. The rows are locked for the duration, and `is_free_badge` is its own
     * idempotency marker, since a badge this converted no longer qualifies on a second
     * run. What remains of audit landmine 124 is that a badge which qualifies *between*
     * the preview and the apply is written although it was never shown; the counters that
     * come back are the ones actually written, so the result panel always reports the
     * truth even when it disagrees with the confirm dialog.
     *
     * @return array{success: bool, error: string|null, event_id: int|null, fixed_user_count: int, fixed_badge_count: int, total_refunded_cents: int, total_refunded: string|null}
     */
    private function repair(?Event $event, ?User $admin): array
    {
        if ($event === null) {
            // Verbatim, as the audit records it.
            return $this->failed(null, 'No active event.');
        }

        $fixedBadges = 0;
        $fixedUsers = 0;
        $refunded = 0;

        try {
            DB::transaction(function () use ($event, $admin, &$fixedBadges, &$fixedUsers, &$refunded) {
                foreach ($this->eventUsersWithPrepaid($event, lock: true) as $eventUser) {
                    $badgesToFix = $this->analyseUser($eventUser, $event)['badges_to_fix'];

                    if ($badgesToFix->isEmpty()) {
                        continue;
                    }

                    $userFixed = 0;

                    foreach ($badgesToFix as $badge) {
                        $oldTotal = (int) $badge->total;
                        $oldSubtotal = (int) $badge->subtotal;
                        $oldTax = (int) $badge->tax;

                        $badge->is_free_badge = true;
                        $badge->total = 0;
                        $badge->subtotal = 0;
                        $badge->tax = 0;
                        $badge->status_payment = Paid::class;
                        $badge->paid_at = now();
                        // saveQuietly, as the service did: BadgeObserver::updated()
                        // recomputes subtotal and tax from a dirty total and saves again,
                        // which would write over the zeros this loop just set.
                        $badge->saveQuietly();

                        // Zeroing the total is the correction: the badge no longer counts
                        // towards User::amountDue(). The amount that had been wrongly
                        // charged is reported below and in the activity log.
                        $refunded += $oldTotal;

                        activity()
                            ->performedOn($badge)
                            ->causedBy($admin)
                            ->withProperties([
                                'reason' => 'free_badge_fix',
                                'event_id' => $event->id,
                                'prepaid_badges' => (int) $eventUser->prepaid_badges,
                                'old_total' => $oldTotal,
                                'old_subtotal' => $oldSubtotal,
                                'old_tax' => $oldTax,
                                'new_total' => 0,
                                'refunded_cents' => $oldTotal,
                            ])
                            ->log(self::ACTIVITY_DESCRIPTION);

                        $fixedBadges++;
                        $userFixed++;
                    }

                    if ($userFixed > 0) {
                        $fixedUsers++;
                    }
                }
            });
        } catch (\Throwable $e) {
            return $this->failed($event->id, $e->getMessage());
        }

        return [
            'success' => true,
            'error' => null,
            'event_id' => $event->id,
            'fixed_user_count' => $fixedUsers,
            'fixed_badge_count' => $fixedBadges,
            'total_refunded_cents' => $refunded,
            'total_refunded' => Column::euros($refunded),
        ];
    }

    /**
     * The service's failure payload: every counter zeroed, the reason carried.
     *
     * @return array{success: bool, error: string|null, event_id: int|null, fixed_user_count: int, fixed_badge_count: int, total_refunded_cents: int, total_refunded: string|null}
     */
    private function failed(?int $eventId, string $error): array
    {
        return [
            'success' => false,
            'error' => $error,
            'event_id' => $eventId,
            'fixed_user_count' => 0,
            'fixed_badge_count' => 0,
            'total_refunded_cents' => 0,
            'total_refunded' => Column::euros(0),
        ];
    }

    /**
     * `analyseUser()`: how many badges the user holds for the event, how many of them
     * should be free, and which paid main badges have to be converted.
     *
     * The prepaid rules are the ones documented in CLAUDE.md and docs/bugfix-03-fix.md, and
     * they are subtle enough to spell out:
     *
     *  - the full `prepaid_badges` entitlement is honoured as free, with no `-1`;
     *  - spare copies (`extra_copy_of !== null`) are always separately paid and never
     *    consume the allowance, so only main badges are counted or converted;
     *  - badges already free count against the allowance, which is what makes a second run
     *    of this repair a no-op;
     *  - a badge whose fee has actually been taken is never converted. The deleted service
     *    selected on `is_free_badge` alone, so a badge already in `Paid` - money through
     *    the POS, a `checkout_items` row against it - was zeroed too, and with the wallet
     *    gone nothing gave the money back: the confirm dialog promised a refund the write
     *    could not make, `paid_at` was overwritten with `now()`, and the checkout still
     *    recorded the original amount. Only a badge still owing its fee is converted, and
     *    zeroing that really is the correction;
     *  - the lowest ids are converted first, so a rerun of the same data converts the same
     *    badges.
     *
     * @return array{badges_total: int, should_be_free: int, should_be_paid: int, badges_to_fix: Collection<int, Badge>}
     */
    private function analyseUser(EventUser $eventUser, Event $event): array
    {
        $allowed = (int) $eventUser->prepaid_badges;

        $badges = Badge::query()
            ->whereHas('fursuit', function ($query) use ($eventUser, $event) {
                $query->where('user_id', $eventUser->user_id)
                    ->where('event_id', $event->id);
            })
            ->with(['fursuit.species'])
            ->get();

        $mainBadges = $badges->whereNull('extra_copy_of');
        $freeMainCount = $mainBadges->where('is_free_badge', true)->count();

        // Charged, and still owing it. A badge already in Paid is out of reach of this
        // repair: zeroing its total returns nothing to whoever paid it.
        $chargedMain = $mainBadges
            ->where('is_free_badge', false)
            ->reject(fn (Badge $badge) => $badge->status_payment instanceof Paid)
            ->sortBy('id')
            ->values();

        $badgesTotal = $badges->count();
        $shouldBeFree = min($allowed, $mainBadges->count());
        $shouldBePaid = $badgesTotal - $shouldBeFree;

        $toConvert = max(0, min($allowed - $freeMainCount, $chargedMain->count()));

        return [
            'badges_total' => $badgesTotal,
            'should_be_free' => $shouldBeFree,
            'should_be_paid' => $shouldBePaid,
            'badges_to_fix' => $chargedMain->take($toConvert),
        ];
    }

    /**
     * The users with an entitlement for this event. `lockForUpdate()` only on the write
     * path, so a preview never takes a row lock.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, EventUser>
     */
    private function eventUsersWithPrepaid(Event $event, bool $lock = false)
    {
        $query = EventUser::with('user')
            ->where('event_id', $event->id)
            ->where('prepaid_badges', '>', 0);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get();
    }

    /**
     * `imageUrl()`: a 15-minute signed URL for a private S3 object, best effort.
     *
     * Same mechanism as every other private image read in the panel. Null when
     * there is no path or the disk cannot answer; the client falls back to the placeholder,
     * which now exists in `public/images/`.
     */
    private function imageUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        try {
            return Storage::disk('s3')->temporaryUrl($path, now()->addMinutes(self::IMAGE_URL_TTL_MINUTES));
        } catch (\Throwable) {
            try {
                return Storage::disk('s3')->url($path);
            } catch (\Throwable) {
                return null;
            }
        }
    }
}
