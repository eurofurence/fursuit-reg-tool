<?php

namespace App\Http\Controllers\POS\Printing;

use App\Domain\Printing\Models\Printer;
use App\Domain\Printing\Models\PrintJob;
use App\Enum\PrinterStatusEnum;
use App\Enum\PrintJobStatusEnum;
use App\Enum\PrintJobTypeEnum;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PrinterController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->input('printers');

        collect($data)->each(function ($printer) use ($request) {
            $request->user('machine')->printers()->firstOrCreate([
                'name' => $printer['name'],
            ], [
                'name' => $printer['name'],
                'type' => PrintJobTypeEnum::Receipt,
                'paper_sizes' => $printer['sizes'],
                'default_paper_size' => $printer['sizes'][0]['name'],
            ]);
        });
    }

    public function jobIndex()
    {
        $machine = auth('machine')->user();

        // Get jobs that are ready to be printed (pending or queued)
        // BUT only for printers that are IDLE (one job at a time per printer)
        $jobs = PrintJob::whereHas('printer', function ($query) use ($machine) {
            $query->where('machine_id', $machine->id)
                ->where('status', PrinterStatusEnum::IDLE->value); // Only idle printers
        })
            ->whereIn('status', [PrintJobStatusEnum::Pending, PrintJobStatusEnum::Queued])
            ->with(['printable'])
            ->orderBy('priority', 'desc')
            ->orderBy('created_at', 'asc')
            ->limit(50) // Get more to sort properly, then limit later
            ->get();

        // Apply custom badge sorting for database-agnostic solution
        $sortedJobs = $jobs->sort(function (PrintJob $a, PrintJob $b) {
            // High priority jobs always come first
            if ($a->priority > 1 && $b->priority <= 1) {
                return -1;
            }
            if ($b->priority > 1 && $a->priority <= 1) {
                return 1;
            }
            if ($a->priority !== $b->priority) {
                return $b->priority <=> $a->priority;
            }

            // For same priority, apply badge sorting if both are badges
            if ($a->printable_type === 'App\\Models\\Badge\\Badge' &&
                $b->printable_type === 'App\\Models\\Badge\\Badge') {

                $aCustomId = $a->printable?->custom_id;
                $bCustomId = $b->printable?->custom_id;

                // Handle null custom_ids
                if (! $aCustomId && ! $bCustomId) {
                    return 0;
                }
                if (! $aCustomId) {
                    return 1;
                }
                if (! $bCustomId) {
                    return -1;
                }

                // Parse attendee ID and badge number
                $aParts = explode('-', $aCustomId, 2);
                $bParts = explode('-', $bCustomId, 2);

                if (count($aParts) === 2 && count($bParts) === 2) {
                    $aAttendeeId = (int) $aParts[0];
                    $bAttendeeId = (int) $bParts[0];
                    $aBadgeNumber = (int) $aParts[1];
                    $bBadgeNumber = (int) $bParts[1];

                    // Sort by attendee ID first (ascending)
                    if ($aAttendeeId !== $bAttendeeId) {
                        return $aAttendeeId <=> $bAttendeeId;
                    }

                    // Then by badge number (descending within same attendee)
                    return $bBadgeNumber <=> $aBadgeNumber;
                }
            }

            // Default to creation time
            return $a->created_at <=> $b->created_at;
        });

        return $sortedJobs->take(5)->map(fn (PrintJob $printJob) => [
            'id' => $printJob->id,
            'printer' => $printJob->printer->name,
            'type' => $printJob->type,
            'status' => $printJob->status->value,
            'file' => Storage::drive('s3')->temporaryUrl($printJob->file, now()->addDay()),
            'paper' => collect($printJob->printer->paper_sizes)->where('name', $printJob->printer->default_paper_size)->first(),
            'duplex' => ($printJob->type === PrintJobTypeEnum::Receipt) ? false : $printJob->printable->dual_side_print,
            'priority' => $printJob->priority,
            'retry_count' => $printJob->retry_count,
        ])->values()->toArray();
    }

    public function jobShow(PrintJob $job)
    {
        $machine = auth('machine')->user();

        return response()->json([
            'id' => $job->id,
            'status' => $job->status->value,
            'printer' => $job->printer->name,
            'type' => $job->type->value,
            'qz_job_name' => $job->qz_job_name,
            'last_qz_status' => $job->last_qz_status,
            'retry_count' => $job->retry_count,
        ]);
    }

    public function jobPrinted(PrintJob $job)
    {
        $machine = auth('machine')->user();

        // Assign the job to this machine for processing
        $job->assignToMachine($machine);

        // Manual override: Force job to printed state
        // This should only be used for manual intervention, not automatic completion
        // Automatic completion should happen via QZ-Tray callbacks in qzJobStatusUpdate()

        // Transition through proper states if needed
        if ($job->status === PrintJobStatusEnum::Pending) {
            $job->transitionTo(PrintJobStatusEnum::Queued);
        }

        if ($job->status === PrintJobStatusEnum::Queued) {
            $job->transitionTo(PrintJobStatusEnum::Printing);
        }

        // Force mark as printed (manual override)
        $job->transitionTo(PrintJobStatusEnum::Printed);

        \Log::info("Print job {$job->id} manually marked as printed by machine {$machine->id}");

        return response()->json(['success' => true]);
    }

    public function jobFailed(PrintJob $job, Request $request)
    {
        $request->validate([
            'error_message' => 'nullable|string|max:1000',
        ]);

        $machine = auth('machine')->user();
        $job->assignToMachine($machine);

        // Transition to failed state with error message
        $job->transitionTo(PrintJobStatusEnum::Failed, $request->input('error_message', 'Print job failed'));

        return response()->json(['success' => true]);
    }

    public function jobStatusUpdate(PrintJob $job, Request $request)
    {
        $request->validate([
            'status' => 'required|string|in:queued,printing,retrying',
            'qz_job_name' => 'nullable|string',
            'printer_name' => 'nullable|string',
        ]);

        $machine = auth('machine')->user();
        $job->assignToMachine($machine);

        $statusMap = [
            'queued' => PrintJobStatusEnum::Queued,
            'printing' => PrintJobStatusEnum::Printing,
            'retrying' => PrintJobStatusEnum::Retrying,
        ];

        $newStatus = $statusMap[$request->input('status')];

        if ($job->status->canTransitionTo($newStatus)) {
            $job->transitionTo($newStatus);

            // Update printer status when job transitions to printing
            if ($newStatus === PrintJobStatusEnum::Printing) {
                $printerName = $request->input('printer_name') ?? $job->printer->name;

                Printer::updatePrinterState(
                    $printerName,
                    PrinterStatusEnum::PROCESSING,
                    $job->id,
                    null,
                    $machine->name
                );

                \Log::info("Printer {$printerName} status updated to processing when job {$job->id} started");
            }
        }

        // Store additional QZ-specific metadata
        if ($request->has('qz_job_name')) {
            $job->update(['qz_job_name' => $request->input('qz_job_name')]);
        }

        return response()->json(['success' => true, 'new_status' => $newStatus->value]);
    }

    public function qzJobStatusUpdate(PrintJob $job, Request $request)
    {
        $request->validate([
            'job_id' => 'required|integer',
            'qz_job_name' => 'required|string',
            'status' => 'required|string',
            'event_type' => 'required|string|in:JOB,PRINTER',
            'severity' => 'required|string|in:INFO,WARN,ERROR,FATAL',
            'message' => 'nullable|string',
            'printer_name' => 'nullable|string',
        ]);

        $machine = auth('machine')->user();
        $job->assignToMachine($machine);

        // Log QZ-specific job status for debugging and monitoring
        \Log::info('QZ Job Status Update', [
            'job_id' => $job->id,
            'qz_job_name' => $request->input('qz_job_name'),
            'status' => $request->input('status'),
            'event_type' => $request->input('event_type'),
            'severity' => $request->input('severity'),
            'message' => $request->input('message'),
            'printer_name' => $request->input('printer_name'),
        ]);

        // Map QZ job status to our job status
        $qzStatus = strtoupper($request->input('status'));
        $newStatus = null;
        $printerStatus = null;

        switch ($qzStatus) {
            case 'SPOOLING':
            case 'PRINTING':
            case 'RENDERING_LOCALLY':
            case 'RETAINED':
                $newStatus = PrintJobStatusEnum::Printing;
                $printerStatus = PrinterStatusEnum::PROCESSING;
                break;

            case 'COMPLETE':
            case 'FINISHED':
            case 'DELETING':
            case 'DELETED':
                // Job actually completed - mark as printed
                $newStatus = PrintJobStatusEnum::Printed;
                $printerStatus = PrinterStatusEnum::IDLE;
                
                // Auto-resume printer if it was paused due to stuck jobs
                $this->autoResumePrinterIfStuck($job->printer);
                break;

            case 'ERROR':
            case 'ABORTED':
            case 'FAILED':
                $newStatus = PrintJobStatusEnum::Failed;
                $printerStatus = PrinterStatusEnum::IDLE; // Free up printer for next job
                break;

            case 'CANCELED':
            case 'CANCELLED':
            case 'PAPEROUT':
            case 'USER_INTERVENTION':
            case 'OFFLINE':
                $newStatus = PrintJobStatusEnum::Retrying;
                $printerStatus = PrinterStatusEnum::PAUSED; // Pause printer due to intervention needed
                break;

            case 'QUEUED':
            case 'WAITING':
                $newStatus = PrintJobStatusEnum::Queued;
                // Keep current printer status
                break;
        }

        // Only transition if we have a valid status mapping and transition is allowed
        if ($newStatus && $job->status->canTransitionTo($newStatus)) {
            $errorMessage = ($newStatus === PrintJobStatusEnum::Failed)
                ? $request->input('message', "QZ Job failed with status: {$qzStatus}")
                : null;

            $job->transitionTo($newStatus, $errorMessage);

            \Log::info("Job {$job->id} transitioned from {$job->status->value} to {$newStatus->value} via QZ callback");
            
            // If this is a badge print job that just completed, transition the badge to ReadyForPickup
            if ($newStatus === PrintJobStatusEnum::Printed && $job->printable_type === \App\Models\Badge\Badge::class) {
                $badge = $job->printable;
                if ($badge && $badge->status_fulfillment->canTransitionTo(\App\Models\Badge\State_Fulfillment\ReadyForPickup::class)) {
                    $badge->status_fulfillment->transitionTo(\App\Models\Badge\State_Fulfillment\ReadyForPickup::class);
                    \Log::info("Badge {$badge->id} transitioned to ReadyForPickup after print job completion");
                }
            }
        }

        // Update printer status if we have a mapping
        if ($printerStatus) {
            $printerName = $request->input('printer_name') ?? $job->printer->name;
            $machine = auth('machine')->user();

            Printer::updatePrinterState(
                $printerName,
                $printerStatus,
                ($printerStatus === PrinterStatusEnum::IDLE) ? null : $job->id,
                ($printerStatus === PrinterStatusEnum::PAUSED) ? $request->input('message') : null,
                $machine->name
            );

            \Log::info("Printer {$printerName} status updated to {$printerStatus->value} via QZ callback");
        }

        // Update QZ-specific metadata
        $job->update([
            'qz_job_name' => $request->input('qz_job_name'),
            'last_qz_status' => $qzStatus,
            'last_qz_message' => $request->input('message'),
        ]);

        return response()->json(['success' => true, 'job_status' => $job->fresh()->status->value]);
    }

    public function printerStatusUpdate(Request $request)
    {
        $request->validate([
            'printer_name' => 'required|string',
            'status' => 'required|string',
            'event_type' => 'required|string|in:PRINTER,JOB',
            'severity' => 'required|string|in:INFO,WARN,ERROR,FATAL',
            'message' => 'nullable|string',
        ]);

        $machine = auth('machine')->user();

        // Find the printer
        $printer = $machine->printers()->where('name', $request->input('printer_name'))->first();

        if (! $printer) {
            return response()->json(['error' => 'Printer not found'], 404);
        }

        // Update printer status record
        \App\Domain\Printing\Models\PrinterStatus::updateOrCreateForPrinter(
            $printer,
            $machine,
            \App\Enum\PrinterStatusEnum::tryFrom($request->input('status')) ?? \App\Enum\PrinterStatusEnum::UNKNOWN,
            null, // status_code
            \App\Enum\PrinterStatusSeverityEnum::tryFrom($request->input('severity')) ?? \App\Enum\PrinterStatusSeverityEnum::Info,
            $request->input('message')
        );

        // Handle job-specific status updates
        if ($request->input('event_type') === 'JOB') {
            $this->handleJobStatusFromPrinter($request, $machine);
        }

        return response()->json(['success' => true]);
    }

    private function handleJobStatusFromPrinter(Request $request, $machine)
    {
        $status = $request->input('status');
        $severity = $request->input('severity');

        // Find the most recent printing job for this printer
        $job = PrintJob::whereHas('printer', fn ($q) => $q->where('machine_id', $machine->id))
            ->whereHas('printer', fn ($q) => $q->where('name', $request->input('printer_name')))
            ->whereIn('status', [PrintJobStatusEnum::Queued, PrintJobStatusEnum::Printing])
            ->orderBy('started_at', 'desc')
            ->first();

        if (! $job) {
            return;
        }

        // Map QZ status to our job status
        switch ($status) {
            case 'SPOOLING':
            case 'PRINTING':
            case 'RENDERING_LOCALLY':
                if ($job->status->canTransitionTo(PrintJobStatusEnum::Printing)) {
                    $job->transitionTo(PrintJobStatusEnum::Printing);
                }
                break;

            case 'COMPLETE':
                if ($job->status->canTransitionTo(PrintJobStatusEnum::Printed)) {
                    $job->transitionTo(PrintJobStatusEnum::Printed);
                }
                break;

            case 'ERROR':
            case 'ABORTED':
            case 'OFFLINE':
                if ($job->status->canTransitionTo(PrintJobStatusEnum::Failed)) {
                    $job->transitionTo(PrintJobStatusEnum::Failed, $request->input('message', 'Job failed: '.$status));
                }
                break;

            case 'CANCELED':
            case 'PAPEROUT':
            case 'USER_INTERVENTION':
                if ($job->status->canTransitionTo(PrintJobStatusEnum::Retrying)) {
                    $job->transitionTo(PrintJobStatusEnum::Retrying);
                }
                break;

            case 'DELETED':
                // CRITICAL: Don't delete jobs, pause the printer queue instead
                \Log::warning("Job {$job->id} was DELETED by printer {$request->input('printer_name')} - pausing printer queue");

                // Mark job as failed (don't delete from system)
                if ($job->status->canTransitionTo(PrintJobStatusEnum::Failed)) {
                    $job->transitionTo(PrintJobStatusEnum::Failed, 'Job was deleted by printer - requires manual intervention');
                }

                // Pause the printer queue via printer state
                \App\Domain\Printing\Models\Printer::updatePrinterState(
                    $request->input('printer_name'),
                    'paused',
                    $job->id,
                    'Printer deleted job - manual intervention required',
                    auth('machine')->user()->name ?? 'Unknown'
                );
                break;
        }
    }

    public function printerEventWebhook(Request $request)
    {
        $request->validate([
            'printer_name' => 'required|string',
            'event_type' => 'required|string',
            'status' => 'required|string',
            'severity' => 'required|string|in:INFO,WARN,ERROR,FATAL',
            'message' => 'required|string',
        ]);

        $machine = auth('machine')->user();

        // Log and handle the printer event (this will auto-pause if needed)
        $event = \App\Models\PrinterEvent::logAndHandle([
            'printer_name' => $request->input('printer_name'),
            'event_type' => $request->input('event_type'),
            'status' => $request->input('status'),
            'severity' => $request->input('severity'),
            'message' => $request->input('message'),
            'machine_name' => $machine->name ?? 'Unknown',
        ]);

        return response()->json([
            'success' => true,
            'event_id' => $event->id,
            'handled' => $event->handled,
            'action_taken' => $event->handled ? 'Printer paused due to critical event' : 'Event logged',
        ]);
    }

    /**
     * Auto-resume printer if it was paused due to stuck jobs
     */
    private function autoResumePrinterIfStuck(Printer $printer): void
    {
        // Refresh printer data to get current status
        $printer->refresh();
        
        if ($printer->status !== PrinterStatusEnum::PAUSED) {
            return; // Printer is not paused
        }

        // Check if the pause reason mentions stuck jobs or timeout
        $pauseReason = $printer->last_error_message ?? '';
        $isStuckJobPause = str_contains($pauseReason, 'processing for over') || 
                          str_contains($pauseReason, 'minutes') ||
                          str_contains($pauseReason, 'stuck');

        if ($isStuckJobPause) {
            // Auto-resume the printer since it's working again
            Printer::updatePrinterState(
                $printer->name,
                PrinterStatusEnum::IDLE,
                null,
                null,
                auth('machine')->user()->name ?? 'System'
            );

            \Log::info("Printer '{$printer->name}' auto-resumed after successful job completion", [
                'printer_id' => $printer->id,
                'previous_pause_reason' => $pauseReason
            ]);
        }
    }
}
