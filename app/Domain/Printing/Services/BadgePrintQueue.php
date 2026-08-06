<?php

namespace App\Domain\Printing\Services;

use App\Domain\Printing\Models\PrintBatch;
use App\Domain\Printing\Models\Printer;
use App\Enum\PrintBatchStatusEnum;
use App\Enum\PrintJobTypeEnum;
use App\Jobs\Printing\GenerateBadgePrintFileJob;
use App\Models\Badge\Badge;
use App\Models\Badge\State_Fulfillment\Processing;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * The single way badges get sent to a printer.
 *
 * Every print action -- the admin row action, the admin bulk action and both
 * POS endpoints -- comes through here, so there is exactly one description of
 * what "print this badge" means. Before this existed each of those four built
 * its own PrintJob directly and none of them set print_batch_id, which meant
 * the agent's batch lane never saw a single job and the batch page was always
 * empty however much you printed.
 *
 * A batch is made even for one badge. A one-job batch looks like overkill, but
 * the batch is what carries the things that make printing recoverable: frozen
 * order, a pause that stops the rest of the run when a card fails, the badge
 * lock, and a counter the operator can watch. A lone job outside a batch has
 * none of that, and it is exactly the card that goes missing.
 */
class BadgePrintQueue
{
    /**
     * Queue badges for printing and return the batch the agent will claim from.
     *
     * Returns null when nothing was printable, so callers can tell the
     * operator rather than reporting success over an empty run.
     */
    public static function queue(
        Collection $badges,
        ?Printer $printer = null,
        ?string $name = null,
        ?int $createdById = null,
    ): ?PrintBatch {
        $badges = $badges->filter(fn (Badge $badge) => $badge->exists);

        if ($badges->isEmpty()) {
            return null;
        }

        $printer = $printer ?? self::defaultBadgePrinter();

        // Move to Processing first: that is what allocates custom_id, and the
        // print file is rendered from it. Rendering before the transition
        // would put a badge with no id on the card.
        $badges->each(function (Badge $badge) {
            if ($badge->status_fulfillment->canTransitionTo(Processing::class)) {
                $badge->status_fulfillment->transitionTo(Processing::class);
            }
        });

        $badges = $badges->map(fn (Badge $badge) => $badge->fresh());

        self::ensurePrintFiles($badges);

        $batch = PrintBatch::build(
            name: $name ?? self::nameFor($badges),
            badges: $badges,
            printer: $printer,
            eventId: $badges->first()?->fursuit?->event_id,
            createdById: $createdById,
        );

        // Built batches are drafts, and a draft is not selectable by the
        // agent. Nothing here is waiting on an operator to review it, so it
        // goes straight to ready or it would sit unclaimable forever.
        $batch->transitionTo(PrintBatchStatusEnum::Ready);

        Log::info('badge print batch queued', [
            'batch_id' => $batch->id,
            'badges' => $badges->count(),
            'printer_id' => $printer?->id,
        ]);

        return $batch->fresh();
    }

    /**
     * Render any badge whose print file is missing or no longer matches.
     *
     * Synchronously, because PrintBatch::build() refuses a stale file and the
     * operator is standing at the printer waiting. Queueing the render would
     * only move the failure to a place nobody is looking at.
     */
    private static function ensurePrintFiles(Collection $badges): void
    {
        foreach ($badges as $badge) {
            $current = $badge->print_file_path
                && $badge->print_file_hash === GenerateBadgePrintFileJob::inputHash($badge);

            if (! $current) {
                GenerateBadgePrintFileJob::dispatchSync($badge);
                $badge->refresh();
            }
        }
    }

    private static function nameFor(Collection $badges): string
    {
        if ($badges->count() === 1) {
            return 'Badge '.($badges->first()->custom_id ?? $badges->first()->id);
        }

        return $badges->count().' badges';
    }

    private static function defaultBadgePrinter(): ?Printer
    {
        return Printer::where('type', PrintJobTypeEnum::Badge)
            ->where('is_active', true)
            ->orderBy('id')
            ->first();
    }
}
