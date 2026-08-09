<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Models\Badge\Badge;
use App\Models\Event;
use App\Models\Fursuit\Fursuit;
use App\Models\Fursuit\States\Pending;
use App\Support\Manage\EventScope;
use App\Support\Manage\Status;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * The dashboard, successor to the three the old panel widgets: StatsOverview,
 * BadgeStatusChart and EventComparisonChart.
 *
 * All three resolved "which event am I looking at" for themselves, three times, out of
 * the old panel's event session key. Here it is resolved once, from
 * App\Support\Manage\EventScope, the panel's one event filter, and handed to the four
 * stats and the two charts. That is also what makes the dashboard obey the header
 * selector the same way every list does: it narrows on a selected event and widens on
 * "All events", which under the old event-selector middleware was an unreachable state.
 *
 * Every prop here is a read. Nothing on this page writes, and neither does the 15s poll
 * that reloads `stats` and `charts`: the counts are counts, the chart data is a grouped
 * count, and no state is touched on the way through.
 *
 * The charts are shaped server-side, headings, colours, options and all, so the two Vue
 * components stay dumb the way ActionButton and DataTable do, and so the parity tests can
 * assert a label or a colour without rendering chart.js.
 */
class DashboardController extends Controller
{
    /**
     * The five colours of the widget's ramp, in its order, now bound one per
     * fulfillment state instead of being handed out in `GROUP BY` order.
     *
     * `array_slice($colors, 0, count($statusData))` gave out fewer colours than there
     * were segments as soon as a real event produced more than five payment x fulfillment
     * combinations, and the mapping moved every time the grouping came back in a
     * different order. Binding hue to fulfillment and opacity to payment
     * covers all ten combinations and cannot drift between two polls.
     */
    private const FULFILLMENT_COLORS = [
        'pending' => '239, 68, 68',        // red
        'processing' => '245, 158, 11',    // amber
        'printed' => '59, 130, 246',       // blue
        'ready_for_pickup' => '16, 185, 129', // emerald
        'picked_up' => '139, 92, 246',     // violet
    ];

    /**
     * Payment is the second axis of the doughnut, so it moves opacity rather than hue: a
     * paid badge is the solid colour of its fulfillment state, an unpaid one the same
     * colour washed out.
     */
    private const PAYMENT_ALPHA = [
        'paid' => null,
        'unpaid' => 0.45,
    ];

    /**
     * Segment order. The doughnut is drawn in this order rather than in whatever order
     * the database groups, so the legend does not reshuffle under the poll.
     */
    private const PAYMENT_ORDER = ['paid', 'unpaid'];

    private const FULFILLMENT_ORDER = ['pending', 'processing', 'printed', 'ready_for_pickup', 'picked_up'];

    /** The widgets' grey, used for their no-event fallbacks and for any unknown state. */
    private const GREY = '156, 163, 175';

    /** EventComparisonChart's two dataset colours, verbatim. */
    private const CURRENT_EVENT_COLOR = 'rgba(59, 130, 246, 0.8)';

    private const PREVIOUS_EVENT_COLOR = 'rgba(16, 185, 129, 0.8)';

    /**
     * The dashboard.
     *
     * `stats` and `charts` are separate top-level props because the poll reloads exactly
     * those two and nothing else, and closures so a partial visit that asks
     * for one does not run the other's queries.
     */
    public function index(Request $request, EventScope $scope): Response
    {
        return inertia('Manage/Dashboard', [
            'stats' => fn () => $this->stats($scope),
            'charts' => fn () => [
                'badgeStatus' => $this->badgeStatusChart($scope),
                'eventComparison' => $this->eventComparisonChart($scope),
            ],
        ]);
    }

    /**
     * Write the global event selection.
     *
     * A missing or null event_id means all events, which is a real state here rather
     * than the unreachable branch it was under the old event-selector middleware. An unknown id is
     * a validation error, so the session can never hold an id nothing matches.
     *
     * Lives on the dashboard controller only because phase 0 owns no other non-module
     * controller; move it to its own controller when a later phase adds one.
     */
    public function selectEvent(Request $request, EventScope $scope): RedirectResponse
    {
        $validated = $request->validate([
            'event_id' => ['nullable', 'integer', 'exists:events,id'],
        ]);

        $scope->select(isset($validated['event_id']) ? (int) $validated['event_id'] : null);

        return back();
    }

    /**
     * StatsOverview's four stats, in its order, with its strings.
     *
     * @return array<int, array<string, mixed>>
     */
    private function stats(EventScope $scope): array
    {
        $current = $scope->event();
        $previous = $this->previousEvent($current);

        $badges = $this->scopedBadges($scope)->count();
        $fursuits = $this->scopedFursuits($scope)->count();
        $pending = $this->scopedFursuits($scope)->whereState('status', Pending::class)->count();

        return [
            $this->currentEventStat($current),

            $this->comparisonStat(
                'current-event-badges',
                'Current Event Badges',
                $badges,
                $previous === null ? 0 : $this->badgesForEvent($previous),
                $previous,
            ),

            $this->comparisonStat(
                'current-event-fursuits',
                'Current Event Fursuits',
                $fursuits,
                $previous === null ? 0 : $this->fursuitsForEvent($previous),
                $previous,
            ),

            [
                'key' => 'pending-approval',
                'label' => 'Pending Approval',
                'value' => $pending,
                'description' => 'Awaiting review',
                'icon' => 'clock',
                'tone' => $pending > 0 ? Status::WARN : Status::OK,
                'url' => route('admin.fursuits.index'),
            ],
        ];
    }

    /**
     * Stat 1. Three cases, where the widget had two.
     *
     * The widget's `No Event` fallback stood for "no event selected", which could only
     * happen when the table was empty, because the middleware re-seeded the session on
     * every request. Now "all events" is a choice an operator can actually make and it is
     * not the same thing as having no events at all, so it gets its own line rather than
     * reporting an order window no single event owns. The empty-table copy is unchanged.
     *
     * @return array<string, mixed>
     */
    private function currentEventStat(?Event $current): array
    {
        if ($current !== null) {
            $open = $current->allowsOrders();

            return [
                'key' => 'current-event',
                'label' => 'Current Event',
                'value' => $current->name,
                'description' => $open ? 'Orders Open' : 'Orders Closed',
                'icon' => $open ? 'circle-check' : 'circle-x',
                'tone' => $open ? Status::OK : Status::DANGER,
                'url' => null,
            ];
        }

        if (Event::exists()) {
            return [
                'key' => 'current-event',
                'label' => 'Current Event',
                'value' => 'All events',
                'description' => 'Not scoped to one event',
                'icon' => 'layers',
                'tone' => Status::IDLE,
                'url' => null,
            ];
        }

        return [
            'key' => 'current-event',
            'label' => 'Current Event',
            'value' => 'No Event',
            'description' => 'No current event',
            'icon' => 'circle-x',
            'tone' => Status::DANGER,
            'url' => null,
        ];
    }

    /**
     * Stats 2 and 3: a count, and how it compares with the previous event.
     *
     * `No previous event` now means what it says. The widget printed it whenever the diff
     * was exactly zero, so two events with the same badge count reported that the older
     * one did not exist. A zero diff against a real event reads
     * `0 vs {name}`, the same shape as the other two branches.
     *
     * @return array<string, mixed>
     */
    private function comparisonStat(string $key, string $label, int $value, int $previousValue, ?Event $previous): array
    {
        $stat = [
            'key' => $key,
            'label' => $label,
            'value' => $value,
            'url' => null,
        ];

        if ($previous === null) {
            return $stat + [
                'description' => 'No previous event',
                'icon' => 'minus',
                'tone' => Status::IDLE,
            ];
        }

        $diff = $value - $previousValue;

        if ($diff > 0) {
            return $stat + [
                'description' => "+{$diff} vs {$previous->name}",
                'icon' => 'trending-up',
                'tone' => Status::OK,
            ];
        }

        if ($diff < 0) {
            return $stat + [
                'description' => "{$diff} vs {$previous->name}",
                'icon' => 'trending-down',
                'tone' => Status::DANGER,
            ];
        }

        return $stat + [
            'description' => "{$diff} vs {$previous->name}",
            'icon' => 'minus',
            'tone' => Status::IDLE,
        ];
    }

    /**
     * BadgeStatusChart. Doughnut, legend at the bottom, one segment per
     * payment x fulfillment combination that has badges.
     *
     * @return array<string, mixed>
     */
    private function badgeStatusChart(EventScope $scope): array
    {
        $chart = [
            'heading' => 'Current Event Badge Status',
            'type' => 'doughnut',
            'options' => [
                'plugins' => [
                    'legend' => [
                        'display' => true,
                        'position' => 'bottom',
                    ],
                ],
            ],
        ];

        if (! Event::exists()) {
            return $chart + [
                'labels' => ['No Active Event'],
                'datasets' => [
                    [
                        'data' => [0],
                        'backgroundColor' => ['rgb('.self::GREY.')'],
                    ],
                ],
            ];
        }

        $counts = $this->badgeStatusCounts($scope);

        $labels = [];
        $data = [];
        $colors = [];

        foreach ($this->statusOrder($counts) as [$payment, $fulfillment]) {
            $labels[] = Status::badgePayment($payment)['label'].' / '.Status::badgeFulfillment($fulfillment)['label'];
            $data[] = $counts[$payment][$fulfillment];
            $colors[] = $this->statusColor($payment, $fulfillment);
        }

        return $chart + [
            'labels' => $labels,
            'datasets' => [
                [
                    'data' => $data,
                    'backgroundColor' => $colors,
                ],
            ],
        ];
    }

    /**
     * The grouped count behind the doughnut.
     *
     * `selectRaw('status_payment, status_fulfillment, COUNT(*) as count')` is replaced
     *. The two grouped columns go through the builder so the grammar
     * quotes them for whichever driver is connected, the aggregate is plain ANSI
     * `COUNT(*)`, and its alias is `badge_count`, not the bare `count` the widget used.
     * The select list is exactly the two grouped columns plus the aggregate, so MySQL's
     * ONLY_FULL_GROUP_BY is satisfied and SQLite, which does not enforce it, returns the
     * same rows. The test database is SQLite and production is MySQL, so this has to hold
     * on both.
     *
     * Public, and taking its base query as an argument, so DashboardTest can compile the
     * one real query under both grammars rather than retyping its shape.
     */
    public static function badgeStatusCountQuery(Builder $badges): QueryBuilder
    {
        return $badges
            ->toBase()
            ->select(['status_payment', 'status_fulfillment'])
            ->selectRaw('COUNT(*) as badge_count')
            ->groupBy('status_payment', 'status_fulfillment');
    }

    /**
     * The doughnut's counts as [payment][fulfillment] => count.
     *
     * @return array<string, array<string, int>>
     */
    private function badgeStatusCounts(EventScope $scope): array
    {
        $rows = self::badgeStatusCountQuery($this->scopedBadges($scope))->get();

        $counts = [];

        foreach ($rows as $row) {
            // A badge whose state column is null groups as one bucket rather than
            // vanishing; Status renders it as `Unknown`.
            $payment = (string) $row->status_payment;
            $fulfillment = (string) $row->status_fulfillment;

            $counts[$payment][$fulfillment] = (int) $row->badge_count;
        }

        return $counts;
    }

    /**
     * The declared combinations that actually have badges, in declared order, then
     * anything the database holds that the state classes do not declare.
     *
     * @param  array<string, array<string, int>>  $counts
     * @return array<int, array{0: string, 1: string}>
     */
    private function statusOrder(array $counts): array
    {
        $order = [];

        foreach (self::PAYMENT_ORDER as $payment) {
            foreach (self::FULFILLMENT_ORDER as $fulfillment) {
                if (isset($counts[$payment][$fulfillment])) {
                    $order[] = [$payment, $fulfillment];
                }
            }
        }

        foreach ($counts as $payment => $fulfillments) {
            foreach ($fulfillments as $fulfillment => $count) {
                if (! in_array([(string) $payment, (string) $fulfillment], $order, true)) {
                    $order[] = [(string) $payment, (string) $fulfillment];
                }
            }
        }

        return $order;
    }

    /**
     * One stable colour per combination: the fulfillment state picks the hue, the payment
     * state picks the opacity. A state neither map knows is grey rather than undefined,
     * which is what chart.js drew once the ramp ran out.
     */
    private function statusColor(string $payment, string $fulfillment): string
    {
        $rgb = self::FULFILLMENT_COLORS[$fulfillment] ?? self::GREY;

        // array_key_exists, not ??: `paid` maps to a null alpha, which means "solid".
        $alpha = array_key_exists($payment, self::PAYMENT_ALPHA) ? self::PAYMENT_ALPHA[$payment] : 0.45;

        return $alpha === null ? "rgb({$rgb})" : "rgba({$rgb}, {$alpha})";
    }

    /**
     * EventComparisonChart. Two fixed bars, one dataset per event.
     *
     * @return array<string, mixed>
     */
    private function eventComparisonChart(EventScope $scope): array
    {
        $chart = [
            'heading' => 'Event Comparison',
            'type' => 'bar',
            'labels' => ['Badges', 'Fursuits'],
            'options' => [
                'plugins' => [
                    'legend' => [
                        'display' => true,
                    ],
                ],
                'scales' => [
                    'y' => [
                        'beginAtZero' => true,
                    ],
                ],
            ],
        ];

        if (! Event::exists()) {
            return $chart + [
                'datasets' => [
                    [
                        'label' => 'No Events',
                        'data' => [0, 0],
                        'backgroundColor' => 'rgba('.self::GREY.', 0.8)',
                    ],
                ],
            ];
        }

        $current = $scope->event();

        $datasets = [
            [
                // "All events" is one bar over everything rather than a bar for an event
                // nobody selected; the label says which it is.
                'label' => $current?->name ?? 'All events',
                'data' => [
                    $this->scopedBadges($scope)->count(),
                    $this->scopedFursuits($scope)->count(),
                ],
                'backgroundColor' => self::CURRENT_EVENT_COLOR,
            ],
        ];

        $previous = $this->previousEvent($current);

        if ($previous !== null) {
            $datasets[] = [
                'label' => $previous->name,
                'data' => [
                    $this->badgesForEvent($previous),
                    $this->fursuitsForEvent($previous),
                ],
                'backgroundColor' => self::PREVIOUS_EVENT_COLOR,
            ];
        }

        return $chart + ['datasets' => $datasets];
    }

    /**
     * The event before the selected one, by `starts_at`.
     *
     * There is no previous event when nothing is selected: "all events" already includes
     * every event, so there is nothing left to compare it against. An event with no
     * `starts_at` has no position in the sequence either.
     */
    private function previousEvent(?Event $current): ?Event
    {
        if ($current === null || $current->starts_at === null) {
            return null;
        }

        return Event::where('starts_at', '<', $current->starts_at)
            ->orderByDesc('starts_at')
            ->first();
    }

    /**
     * Badges of the selected event, through `fursuit.event_id` as the widget did, or all
     * badges when the operator asked for all events.
     */
    private function scopedBadges(EventScope $scope): Builder
    {
        return $scope->apply(Badge::query(), 'fursuit');
    }

    private function scopedFursuits(EventScope $scope): Builder
    {
        return $scope->apply(Fursuit::query());
    }

    private function badgesForEvent(Event $event): int
    {
        return Badge::whereHas('fursuit', fn ($query) => $query->where('event_id', $event->id))->count();
    }

    private function fursuitsForEvent(Event $event): int
    {
        return Fursuit::where('event_id', $event->id)->count();
    }
}
