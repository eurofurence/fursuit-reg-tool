<?php

namespace App\Http\Controllers\POS;

use App\Domain\Checkout\Models\Checkout\Checkout;
use App\Domain\Printing\Models\PrintJob;
use App\Enum\PrintJobStatusEnum;
use App\Http\Controllers\Controller;
use App\Models\Badge\Badge;
use App\Models\Event;
use App\Models\EventUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;

/**
 * Numbers for the POS statistics screen.
 *
 * Two rules hold everywhere here:
 *
 * 1. Money is counted from checkouts, not from badge totals. A badge's `total`
 *    is what it was priced at, and at EF30 nearly every badge is free or
 *    prepaid, so summing it reports ~0 while the desk has actually taken
 *    thousands in cash and card. Checkouts are what the till did.
 * 2. Money is in CENTS the whole way to the browser, which formats it. Mixing
 *    euros and cents is what made the old page multiply by 100 in one place and
 *    not in another.
 *
 * "Today" is counted on the column that records the event itself — picked_up_at,
 * printed_at, paid_at — never updated_at, which moves for unrelated edits.
 */
class StatisticsController extends Controller
{
    public function index()
    {
        $currentEvent = Event::getActiveEvent();

        // Keyed by event: without it, switching the active event served the
        // previous one's numbers until the cache expired.
        $statistics = Cache::remember(
            'pos_statistics_'.($currentEvent?->id ?? 'none'),
            60,
            fn () => $this->generatePosStatistics($currentEvent)
        );

        return Inertia::render('POS/Statistics/Index', $statistics);
    }

    private function generatePosStatistics(?Event $currentEvent): array
    {
        return [
            'today' => $this->getToday($currentEvent),
            'totals' => $this->getTotals($currentEvent),
            'fulfillment' => $this->getFulfillment($currentEvent),
            'printing' => $this->getPrinting($currentEvent),
            'daily' => $this->getDaily($currentEvent),
            'currentEvent' => $currentEvent ? [
                'name' => $currentEvent->name,
                'starts_at' => $currentEvent->starts_at,
                'ends_at' => $currentEvent->ends_at,
            ] : null,
            'generatedAt' => now()->toIso8601String(),
        ];
    }

    private function badges(?Event $currentEvent): Builder
    {
        return Badge::query()->when(
            $currentEvent,
            fn ($q) => $q->whereHas('fursuit', fn ($f) => $f->where('event_id', $currentEvent->id))
        );
    }

    /**
     * Finished checkouts only: ACTIVE ones are baskets that nobody has paid
     * yet, and CANCELLED ones never took money.
     */
    private function checkouts(?Event $currentEvent): Builder
    {
        return Checkout::query()
            ->where('status', 'FINISHED')
            ->when($currentEvent, fn ($q) => $q->whereHas(
                'items.badge.fursuit',
                fn ($f) => $f->where('event_id', $currentEvent->id)
            ));
    }

    private function getToday(?Event $currentEvent): array
    {
        $paidToday = (clone $this->checkouts($currentEvent))->whereDate('updated_at', today());

        return [
            'badges_ordered' => $this->badges($currentEvent)->whereDate('created_at', today())->count(),
            'badges_printed' => $this->badges($currentEvent)->whereDate('printed_at', today())->count(),
            'badges_handed_out' => $this->badges($currentEvent)
                ->where('status_fulfillment', 'picked_up')
                ->whereDate('picked_up_at', today())
                ->count(),
            'money_total' => (int) (clone $paidToday)->sum('total'),
            'money_cash' => (int) (clone $paidToday)->where('payment_method', 'cash')->sum('total'),
            'money_card' => (int) (clone $paidToday)->where('payment_method', 'card')->sum('total'),
            'checkouts' => (clone $paidToday)->count(),
        ];
    }

    private function getTotals(?Event $currentEvent): array
    {
        $paid = $this->checkouts($currentEvent);

        return [
            'participants' => $currentEvent
                ? EventUser::where('event_id', $currentEvent->id)->count()
                : 0,
            'badges' => $this->badges($currentEvent)->count(),
            'badges_unpaid' => $this->badges($currentEvent)->where('status_payment', 'unpaid')->count(),
            // What the desk would still collect if every unpaid badge were paid.
            'unpaid_value' => (int) $this->badges($currentEvent)->where('status_payment', 'unpaid')->sum('total'),
            'money_total' => (int) (clone $paid)->sum('total'),
            'money_cash' => (int) (clone $paid)->where('payment_method', 'cash')->sum('total'),
            'money_card' => (int) (clone $paid)->where('payment_method', 'card')->sum('total'),
            'checkouts' => (clone $paid)->count(),
            'double_sided' => $this->badges($currentEvent)->where('dual_side_print', true)->count(),
            'extra_copies' => $this->badges($currentEvent)->whereNotNull('extra_copy_of')->count(),
        ];
    }

    /**
     * @return array<string, array{label: string, count: int, percent: int}>
     */
    private function getFulfillment(?Event $currentEvent): array
    {
        $total = $this->badges($currentEvent)->count();

        $states = [
            'pending' => 'Not printed',
            'processing' => 'Printing',
            'printed' => 'Printed',
            'ready_for_pickup' => 'Ready for pickup',
            'picked_up' => 'Picked up',
        ];

        $out = [];
        foreach ($states as $state => $label) {
            $count = $this->badges($currentEvent)->where('status_fulfillment', $state)->count();
            $out[$state] = [
                'label' => $label,
                'count' => $count,
                'percent' => $total > 0 ? (int) round($count / $total * 100) : 0,
            ];
        }

        return $out;
    }

    private function getPrinting(?Event $currentEvent): array
    {
        // Not scoped to the event: receipts belong to checkouts rather than
        // badges, and scoping by badge dropped every receipt job, which made
        // the old "by type" panel report zero receipts forever.
        $countByStatus = fn (array $statuses) => PrintJob::whereIn(
            'status',
            array_map(fn (PrintJobStatusEnum $s) => $s->value, $statuses)
        )->count();

        $printedToday = PrintJob::where('status', PrintJobStatusEnum::Printed->value)
            ->whereDate('printed_at', today());

        return [
            'pending' => $countByStatus([PrintJobStatusEnum::Pending]),
            'active' => $countByStatus([
                PrintJobStatusEnum::Queued,
                PrintJobStatusEnum::Printing,
                PrintJobStatusEnum::Retrying,
            ]),
            'failed' => $countByStatus([PrintJobStatusEnum::Failed]),
            'printed_today' => (clone $printedToday)->count(),
            'average_seconds' => $this->averagePrintSeconds(),
            'badge_jobs' => PrintJob::where('type', 'badge')->count(),
            'receipt_jobs' => PrintJob::where('type', 'receipt')->count(),
        ];
    }

    /**
     * Measured over today's prints only. Averaging the whole event would drag
     * in jobs that sat in a paused queue overnight and tell the desk nothing
     * about how the printer is behaving now.
     */
    private function averagePrintSeconds(): ?int
    {
        $seconds = PrintJob::where('status', PrintJobStatusEnum::Printed->value)
            ->whereDate('printed_at', today())
            ->whereNotNull('printed_at')
            ->get(['created_at', 'printed_at'])
            ->map(fn ($job) => $job->created_at->diffInSeconds($job->printed_at))
            ->avg();

        return $seconds === null ? null : (int) round($seconds);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getDaily(?Event $currentEvent): array
    {
        if (! $currentEvent?->starts_at || ! $currentEvent->ends_at) {
            return [];
        }

        $days = [];
        $day = $currentEvent->starts_at->copy()->startOfDay();
        $end = $currentEvent->ends_at->copy()->endOfDay();

        while ($day->lte($end)) {
            $days[] = [
                'date' => $day->toDateString(),
                'day_name' => $day->format('D'),
                'badges_ordered' => $this->badges($currentEvent)->whereDate('created_at', $day)->count(),
                'badges_handed_out' => $this->badges($currentEvent)
                    ->where('status_fulfillment', 'picked_up')
                    ->whereDate('picked_up_at', $day)
                    ->count(),
                'money' => (int) $this->checkouts($currentEvent)->whereDate('updated_at', $day)->sum('total'),
            ];

            $day->addDay();
        }

        return $days;
    }
}
