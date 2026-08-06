<?php

namespace App\Http\Controllers\PrintAgent;

use App\Domain\Printing\Models\PrintBatch;
use App\Enum\PrintBatchStatusEnum;
use App\Enum\PrintJobStatusEnum;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Batch selection for the print agent.
 *
 * A printer finishes one batch before starting the next, and the operator picks
 * which one from the agent UI.
 */
class AgentBatchController extends AgentController
{
    /**
     * Batches this machine could pick up.
     */
    public function index(Request $request): JsonResponse
    {
        $this->touchAgent($request);

        $batches = PrintBatch::query()
            ->selectable()
            ->where(function ($query) {
                $query->whereNull('printer_id')
                    ->orWhereHas('printer', fn ($q) => $q->where('machine_id', $this->machine()->id));
            })
            ->with('event')
            ->orderBy('id')
            ->get()
            ->map(fn (PrintBatch $batch) => $this->describe($batch));

        return response()->json(['batches' => $batches]);
    }

    /**
     * Take ownership of a batch and start printing it.
     */
    public function start(Request $request, int $batch): JsonResponse
    {
        $data = $request->validate([
            'printer_name' => 'required|string',
        ]);

        $this->touchAgent($request);

        $printBatch = $this->batchForMachine($batch);
        $printer = $this->printerForMachine($data['printer_name']);

        // A batch that is already running on another printer must not be picked
        // up twice, or two printers would race for the same cards.
        if ($printBatch->printer_id && $printBatch->printer_id !== $printer->id) {
            return response()->json([
                'error' => 'Batch is already assigned to another printer.',
            ], 409);
        }

        // Starting a batch this printer is already running is a no-op, not an
        // error. The agent re-asserts the start whenever it is unsure -- after
        // an unattended hand-off to the next batch, or after a resume -- and
        // answering 409 there would stop a queue that is running perfectly.
        if ($printBatch->status === PrintBatchStatusEnum::Printing) {
            return response()->json(['batch' => $this->describe($printBatch)]);
        }

        if ($printBatch->status === PrintBatchStatusEnum::Draft) {
            $printBatch->transitionTo(PrintBatchStatusEnum::Ready);
        }

        $printBatch->update(['printer_id' => $printer->id]);

        if (! $printBatch->fresh()->transitionTo(PrintBatchStatusEnum::Printing)) {
            return response()->json([
                'error' => "Cannot start a batch that is {$printBatch->fresh()->status->value}.",
            ], 409);
        }

        return response()->json(['batch' => $this->describe($printBatch->fresh())]);
    }

    public function pause(Request $request, int $batch): JsonResponse
    {
        $data = $request->validate([
            'reason' => 'required|string|max:1000',
        ]);

        $this->touchAgent($request);

        $printBatch = $this->batchForMachine($batch);
        $printBatch->pause($data['reason']);

        return response()->json(['batch' => $this->describe($printBatch->fresh())]);
    }

    /**
     * Abandon the batch from the print station.
     *
     * An operator standing at a broken printer can stop the whole run without
     * going to find a laptop. Cards already printed stay printed; everything
     * still queued is cancelled and will not print.
     */
    public function cancel(Request $request, int $batch): JsonResponse
    {
        $data = $request->validate([
            'reason' => 'nullable|string|max:1000',
        ]);

        $this->touchAgent($request);

        $printBatch = $this->batchForMachine($batch);

        if (! $printBatch->cancel($data['reason'] ?? 'Cancelled at the print station')) {
            return response()->json([
                'error' => "Cannot cancel a batch that is {$printBatch->status->value}.",
            ], 409);
        }

        return response()->json(['batch' => $this->describe($printBatch->fresh())]);
    }

    public function resume(Request $request, int $batch): JsonResponse
    {
        $this->touchAgent($request);

        $printBatch = $this->batchForMachine($batch);

        if (! $printBatch->resume()) {
            return response()->json([
                'error' => "Cannot resume a batch that is {$printBatch->status->value}.",
            ], 409);
        }

        return response()->json(['batch' => $this->describe($printBatch->fresh())]);
    }

    private function describe(PrintBatch $batch): array
    {
        $batch->recalculateCounters();
        $batch->refresh();

        return [
            'id' => $batch->id,
            'name' => $batch->name,
            'status' => $batch->status->value,
            'event' => $batch->event?->name,
            'printer_id' => $batch->printer_id,
            'pause_reason' => $batch->pause_reason,
            'totals' => [
                'jobs' => $batch->total_jobs,
                'printed' => $batch->printed_count,
                'verified' => $batch->verified_count,
                'failed' => $batch->failed_count,
                'remaining' => $batch->printJobs()
                    ->where('status', PrintJobStatusEnum::Pending)
                    ->count(),
            ],
        ];
    }
}
