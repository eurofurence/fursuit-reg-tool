<?php

namespace App\Http\Controllers\PrintAgent;

use App\Domain\Printing\Models\PrintJob;
use App\Enum\PrintCompletionSourceEnum;
use App\Enum\PrintJobStatusEnum;
use App\Enum\PrintJobTypeEnum;
use App\Enum\PrintVerificationSourceEnum;
use App\Models\Badge\Badge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * The print agent's job loop: claim one card, print it, report what happened,
 * then come back for the next one.
 *
 * Strictly one job at a time per printer. The browser-based system this
 * replaces polled on a timer with no in-flight guard and would hand the same
 * job out twice, printing the card twice.
 */
class AgentJobController extends AgentController
{
    /** How long a claim is good for before the reaper takes it back. */
    private const LEASE_SECONDS = 180;

    /**
     * Hand out the next job for the agent to print.
     *
     * Two lanes, because two kinds of work arrive by different routes. Cards are
     * batched and claimed through the batch the operator selected. Receipts are
     * never batched: they are created by a sale that has already happened and
     * somebody is waiting at the counter for the paper, so a receipt worker
     * claims by printer instead and takes whatever is queued for it.
     */
    public function claim(Request $request): JsonResponse
    {
        $data = $request->validate([
            'batch_id' => 'nullable|integer|required_without:printer_name',
            'printer_name' => 'nullable|string|required_without:batch_id',
        ]);

        $this->touchAgent($request);

        if (empty($data['batch_id'])) {
            return $this->claimUnbatched($data['printer_name']);
        }

        $batch = $this->batchForMachine($data['batch_id']);
        $job = $batch->claimNextJob($this->machine(), self::LEASE_SECONDS);

        if (! $job) {
            return response()->json([
                'job' => null,
                'batch_status' => $batch->fresh()->status->value,
            ]);
        }

        return response()->json(['job' => $this->describe($job)]);
    }

    /**
     * The receipt lane: claim by printer, with no batch involved.
     *
     * Scoped to the caller's own printers like every other endpoint, so a
     * machine cannot drain the receipt queue of the till next to it.
     */
    private function claimUnbatched(string $printerName): JsonResponse
    {
        $printer = $this->printerForMachine($printerName);
        $job = PrintJob::claimNextUnbatched($printer, $this->machine(), self::LEASE_SECONDS);

        return response()->json(['job' => $job ? $this->describe($job) : null]);
    }

    /**
     * Keep the lease alive. A retransfer card takes well over a minute, so the
     * agent renews while it waits rather than being reaped mid-print.
     */
    public function heartbeat(Request $request, int $job): JsonResponse
    {
        $printJob = $this->jobForMachine($job);
        $this->touchAgent($request);

        $extended = $printJob->heartbeat(self::LEASE_SECONDS);

        return response()->json([
            'extended' => $extended,
            'status' => $printJob->fresh()->status->value,
            'lease_expires_at' => $printJob->fresh()->lease_expires_at?->toIso8601String(),
        ]);
    }

    /**
     * The card is now physically going through the printer.
     */
    public function printing(Request $request, int $job): JsonResponse
    {
        $printJob = $this->jobForMachine($job);
        $this->touchAgent($request);

        $printJob->markPrinting();
        $printJob->heartbeat(self::LEASE_SECONDS);

        return response()->json(['status' => $printJob->fresh()->status->value]);
    }

    /**
     * The job finished. The agent must say how it knows.
     */
    public function printed(Request $request, int $job): JsonResponse
    {
        $data = $request->validate([
            'completion_source' => ['required', 'string', 'in:firmware,spooler_only,operator,recovered'],
            'firmware_job_id' => 'nullable|string|max:64',
            'firmware_job_uuid' => 'nullable|string|max:64',
        ]);

        $printJob = $this->jobForMachine($job);
        $this->touchAgent($request);

        $marked = $printJob->markPrinted(
            PrintCompletionSourceEnum::from($data['completion_source']),
            $data['firmware_job_id'] ?? null,
            $data['firmware_job_uuid'] ?? null,
        );

        return response()->json([
            'marked' => $marked,
            'status' => $printJob->fresh()->status->value,
        ]);
    }

    /**
     * The job failed. This also pauses the batch, so nothing else prints onto a
     * jammed or empty printer.
     */
    public function failed(Request $request, int $job): JsonResponse
    {
        $data = $request->validate([
            'reason' => 'required|string|max:1000',
        ]);

        $printJob = $this->jobForMachine($job);
        $this->touchAgent($request);

        $printJob->markFailed($data['reason']);

        return response()->json([
            'status' => $printJob->fresh()->status->value,
            'batch_status' => $printJob->batch?->fresh()->status->value,
        ]);
    }

    /**
     * Print this card again, leaving the rest of the run alone.
     *
     * Distinct from `failed`, which pauses the batch and asks for a human.
     * A card that came out badly needs reprinting, not the other twenty-three
     * cards stopped behind it.
     */
    public function reprint(Request $request, int $job): JsonResponse
    {
        $data = $request->validate([
            'reason' => 'nullable|string|max:1000',
        ]);

        $printJob = $this->jobForMachine($job);
        $this->touchAgent($request);

        $replacement = $printJob->reprintCard($data['reason'] ?? 'Card rejected at the printer');

        if ($replacement === null) {
            return response()->json([
                'error' => 'That batch is finished, so the card cannot be added back to it. '
                    .'Print the badge again from the POS.',
            ], 409);
        }

        return response()->json([
            'job' => ['id' => $replacement->id, 'sequence' => $replacement->sequence],
            'batch_status' => $printJob->batch?->fresh()->status->value,
        ]);
    }

    /**
     * Stamp the card as verified.
     *
     * Deliberately separate from `printed`. Whether the job finished and whether
     * the right card came out are different questions, and the camera work that
     * answers the second one happens entirely on the agent machine: no frames or
     * OCR output are uploaded, only the verdict.
     */
    public function verify(Request $request, int $job): JsonResponse
    {
        $data = $request->validate([
            'source' => ['required', 'string', 'in:camera,operator'],
        ]);

        $printJob = $this->jobForMachine($job);
        $this->touchAgent($request);

        $printJob->markVerified(PrintVerificationSourceEnum::from($data['source']));

        return response()->json([
            'verified_print_at' => $printJob->fresh()->verified_print_at?->toIso8601String(),
        ]);
    }

    /**
     * Everything the agent needs to print and verify one card, in one payload,
     * so it never has to make a second call mid-job.
     */
    private function describe(PrintJob $job): array
    {
        $printable = $job->printable;
        $printer = $job->printer;

        return [
            'id' => $job->id,
            'sequence' => $job->sequence,
            'type' => $job->type->value,
            'status' => $job->status->value,
            'printer' => $printer?->name,
            'lease_expires_at' => $job->lease_expires_at?->toIso8601String(),
            'attempt' => $job->attempt_count,

            'file_url' => $this->temporaryUrl($job),

            // Null-safe throughout. A soft-deleted badge used to make the whole
            // polling endpoint 500, which stopped every printer on the machine.
            'duplex' => $job->type === PrintJobTypeEnum::Receipt
                ? false
                : (bool) ($printable->dual_side_print ?? false),
            'paper' => collect($printer?->paper_sizes ?? [])
                ->firstWhere('name', $printer?->default_paper_size),

            // Used only for local verification: the agent OCRs the card and
            // compares against these without sending anything back.
            'expected' => $printable instanceof Badge ? [
                'custom_id' => $printable->custom_id,
                'fursuit_name' => $printable->fursuit?->name,
            ] : null,
        ];
    }

    private function temporaryUrl(PrintJob $job): ?string
    {
        if (! $job->file) {
            return null;
        }

        return Storage::drive('s3')->temporaryUrl($job->file, now()->addHours(6));
    }

    /**
     * Jobs this machine currently holds, so a restarted agent can work out what
     * it was in the middle of instead of abandoning cards.
     */
    public function held(Request $request): JsonResponse
    {
        $this->touchAgent($request);

        $jobs = PrintJob::query()
            ->where('processing_machine_id', $this->machine()->id)
            ->whereIn('status', [PrintJobStatusEnum::Queued, PrintJobStatusEnum::Printing])
            ->with(['printer', 'printable'])
            ->get()
            ->map(fn (PrintJob $job) => $this->describe($job));

        return response()->json(['jobs' => $jobs]);
    }
}
