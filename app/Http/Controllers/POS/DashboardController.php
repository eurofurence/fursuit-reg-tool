<?php

namespace App\Http\Controllers\POS;

use App\Domain\Printing\Models\PrintBatch;
use App\Domain\Printing\Models\PrintJob;
use App\Domain\Printing\Services\DeskPrintNotifications;
use App\Enum\PrintBatchStatusEnum;
use App\Enum\PrintJobStatusEnum;
use App\Http\Controllers\Controller;
use App\Models\Badge\Badge;
use App\Models\Event;
use App\Models\Machine;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function __invoke()
    {
        $currentEvent = Event::getActiveEvent();

        $staffId = auth('machine-user')->id();
        $machine = auth('machine')->user();

        $pickedUpTotal = $this->getPickedUpTotal($currentEvent);
        $myPickedUpTotal = $this->getPickedUpTotal($currentEvent, $staffId);

        $stats = [
            'ready_for_pickup' => $this->getReadyForPickup($currentEvent, $machine),
            'my_picked_up_total' => $myPickedUpTotal,
            'my_picked_up_today' => $this->getPickedUpToday($currentEvent, $staffId),
            'picked_up_total' => $pickedUpTotal,
            'picked_up_today' => $this->getPickedUpToday($currentEvent),
            // The logged-in staff member's share of every handout at this event.
            // Handouts made before attribution existed count towards the total
            // but towards nobody's share, so this reads low until the data ages out.
            'my_share_percent' => $pickedUpTotal > 0
                ? round($myPickedUpTotal / $pickedUpTotal * 100)
                : 0,
            'pending_print' => $this->getPrintJobCount([PrintJobStatusEnum::Pending]),
            'active_print' => $this->getPrintJobCount([
                PrintJobStatusEnum::Queued,
                PrintJobStatusEnum::Printing,
                PrintJobStatusEnum::Retrying,
            ]),
            'failed_print' => $this->getPrintJobCount([PrintJobStatusEnum::Failed]),
            'my_print_batches_running' => $this->getMyRunningBatchCount($staffId),
        ];

        return Inertia::render('POS/Dashboard', [
            'stats' => $stats,
            // Batches this clerk started that have finished, stalled or been
            // cancelled. Polled with the stats, so a card that comes out while the
            // attendee is still at the counter shows up without a reload.
            'printNotifications' => DeskPrintNotifications::pendingFor($staffId),
            'event' => $currentEvent ? [
                'name' => $currentEvent->name,
                'state' => $currentEvent->state->value,
            ] : null,
            'badgeRange' => [
                'min' => $machine?->badge_range_min,
                'max' => $machine?->badge_range_max,
            ],
        ]);
    }

    private function badgesForEvent(?Event $currentEvent)
    {
        $query = Badge::query();
        if ($currentEvent) {
            $query->whereHas('fursuit', function ($q) use ($currentEvent) {
                $q->where('event_id', $currentEvent->id);
            });
        }

        return $query;
    }

    private function getPickedUpTotal(?Event $currentEvent, ?int $staffId = null): int
    {
        return $this->badgesForEvent($currentEvent)
            ->where('status_fulfillment', 'picked_up')
            ->when($staffId, fn ($q) => $q->where('picked_up_by_staff_id', $staffId))
            ->count();
    }

    private function getReadyForPickup(?Event $currentEvent, ?Machine $machine = null): int
    {
        return $this->badgesForEvent($currentEvent)
            ->where('status_fulfillment', 'ready_for_pickup')
            ->tap(fn ($q) => $this->applyBadgeRange($q, $currentEvent, $machine))
            ->count();
    }

    /**
     * Narrow a badge query to the attendee-ID crate this desk holds.
     *
     * attendee_id is a string column, so the comparison has to be numeric or
     * "1000" would sort below "999". CAST … AS SIGNED is understood by MySQL
     * and, through NUMERIC affinity, by the SQLite used in tests.
     */
    private function applyBadgeRange($query, ?Event $currentEvent, ?Machine $machine): void
    {
        if (! $machine || ! $machine->hasBadgeRange()) {
            return;
        }

        $query->whereHas('fursuit.user.eventUsers', function ($q) use ($currentEvent, $machine) {
            if ($currentEvent) {
                $q->where('event_id', $currentEvent->id);
            }

            if ($machine->badge_range_min !== null) {
                $q->whereRaw('CAST(attendee_id AS SIGNED) >= ?', [$machine->badge_range_min]);
            }

            if ($machine->badge_range_max !== null) {
                $q->whereRaw('CAST(attendee_id AS SIGNED) <= ?', [$machine->badge_range_max]);
            }
        });
    }

    /**
     * Counted on picked_up_at so that later edits to the badge (a reprint, a
     * payment fix) stay out of today's tally.
     */
    private function getPickedUpToday(?Event $currentEvent, ?int $staffId = null): int
    {
        return $this->badgesForEvent($currentEvent)
            ->where('status_fulfillment', 'picked_up')
            ->whereDate('picked_up_at', today())
            ->when($staffId, fn ($q) => $q->where('picked_up_by_staff_id', $staffId))
            ->count();
    }

    /**
     * Runs this clerk started that are still on their way through a printer.
     */
    private function getMyRunningBatchCount(?int $staffId): int
    {
        if ($staffId === null) {
            return 0;
        }

        return PrintBatch::query()
            ->startedByStaff($staffId)
            ->whereIn('status', [
                PrintBatchStatusEnum::Draft,
                PrintBatchStatusEnum::Ready,
                PrintBatchStatusEnum::Printing,
                PrintBatchStatusEnum::Paused,
            ])
            ->count();
    }

    /**
     * @param  array<PrintJobStatusEnum>  $statuses
     */
    private function getPrintJobCount(array $statuses): int
    {
        return PrintJob::whereIn('status', array_map(fn ($s) => $s->value, $statuses))->count();
    }
}
