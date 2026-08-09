<?php

namespace App\Domain\Printing\Services;

use App\Domain\Printing\Models\PrintBatch;
use App\Enum\PrintBatchStatusEnum;
use App\Models\Badge\Badge;
use Illuminate\Support\Collection;

/**
 * What a desk clerk is told about the runs they started.
 *
 * A clerk sends a badge to the printer while the attendee waits at the counter,
 * and until now nothing came back: the batch finished somewhere out of sight and
 * the only way to find out was to open the whole print queue and hunt for the
 * card. This turns a clerk's own batches into a short list on the dashboard,
 * each one pointing at the attendee whose card it is.
 */
class DeskPrintNotifications
{
    /**
     * Runs by this clerk that have stopped moving and not been acknowledged.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function pendingFor(?int $staffId): array
    {
        if ($staffId === null) {
            return [];
        }

        return self::present(
            self::query($staffId)->needingDeskAttention()->get()
        );
    }

    /**
     * Every recent run by this clerk, acknowledged or not, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function recentFor(?int $staffId, int $limit = 40): array
    {
        if ($staffId === null) {
            return [];
        }

        return self::present(
            self::query($staffId)->limit($limit)->get()
        );
    }

    private static function query(int $staffId)
    {
        return PrintBatch::query()
            ->startedByStaff($staffId)
            ->with(['printJobs.printable.fursuit.user.eventUsers'])
            ->orderByDesc('id');
    }

    /**
     * @param  Collection<int, PrintBatch>  $batches
     * @return array<int, array<string, mixed>>
     */
    private static function present(Collection $batches): array
    {
        return $batches->map(fn (PrintBatch $batch) => self::presentBatch($batch))->all();
    }

    /**
     * @return array<string, mixed>
     */
    private static function presentBatch(PrintBatch $batch): array
    {
        $badges = self::badgesOf($batch);

        return [
            'id' => $batch->id,
            'name' => $batch->name,
            'status' => $batch->status->value,
            'status_label' => $batch->status->label(),
            'tone' => self::toneOf($batch->status),
            'headline' => self::headlineOf($batch, $badges->count()),
            'total_jobs' => $batch->total_jobs,
            'printed_count' => $batch->printed_count,
            'failed_count' => $batch->failed_count,
            'pause_reason' => $batch->pause_reason,
            'completed_at' => $batch->completed_at?->toIso8601String(),
            'created_at' => $batch->created_at?->toIso8601String(),
            'dismissed' => $batch->desk_dismissed_status === $batch->status->value,
            'badges' => $badges->all(),
        ];
    }

    /**
     * The cards in the run, with the attendee each one belongs to.
     *
     * A retry adds a second job for a badge that is already in the run, so the
     * list is keyed by badge: the clerk cares about cards, not jobs.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private static function badgesOf(PrintBatch $batch): Collection
    {
        return $batch->printJobs
            ->map(fn ($job) => $job->printable)
            ->filter(fn ($printable) => $printable instanceof Badge)
            ->unique('id')
            ->values()
            ->map(function (Badge $badge) {
                $attendeeId = self::attendeeIdOf($badge);

                return [
                    'id' => $badge->id,
                    'custom_id' => $badge->custom_id,
                    'attendee_id' => $attendeeId,
                    'attendee_name' => $badge->fursuit?->user?->name,
                    'fursuit_name' => $badge->fursuit?->name,
                    // Null when the attendee has no registration for the badge's
                    // event; the POS attendee page is looked up by attendee id and
                    // has nothing to open without one.
                    'attendee_url' => $attendeeId === null
                        ? null
                        : route('pos.attendee.show', ['attendeeId' => $attendeeId]),
                ];
            });
    }

    private static function attendeeIdOf(Badge $badge): ?string
    {
        $eventId = $badge->fursuit?->event_id;

        if ($eventId === null) {
            return null;
        }

        return $badge->fursuit?->user?->eventUsers
            ->firstWhere('event_id', $eventId)?->attendee_id;
    }

    private static function headlineOf(PrintBatch $batch, int $badgeCount): string
    {
        $cards = $badgeCount === 1 ? 'card' : 'cards';

        return match ($batch->status) {
            PrintBatchStatusEnum::Completed => $badgeCount === 1
                ? 'Card printed, ready for pickup'
                : "All {$badgeCount} {$cards} printed, ready for pickup",
            PrintBatchStatusEnum::Paused => 'Stopped at the printer, needs a look',
            PrintBatchStatusEnum::Cancelled => 'Run cancelled, nothing more will print',
            PrintBatchStatusEnum::Printing => "Printing, {$batch->printed_count} of {$batch->total_jobs} done",
            default => $batch->status->label(),
        };
    }

    private static function toneOf(PrintBatchStatusEnum $status): string
    {
        return match ($status) {
            PrintBatchStatusEnum::Completed => 'good',
            PrintBatchStatusEnum::Paused, PrintBatchStatusEnum::Cancelled => 'bad',
            default => 'neutral',
        };
    }
}
