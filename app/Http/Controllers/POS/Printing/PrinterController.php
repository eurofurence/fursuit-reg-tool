<?php

namespace App\Http\Controllers\POS\Printing;

use App\Domain\Printing\Models\PrintJob;
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
        return PrintJob::whereHas('printer', fn ($query) => $query->where('machine_id', $machine->id))
            ->whereIn('status', [PrintJobStatusEnum::Pending, PrintJobStatusEnum::Queued])
            ->limit(5)
            ->prioritized()
            ->get()
            ->map(fn (PrintJob $printJob) => [
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

        switch ($qzStatus) {
            case 'SPOOLING':
            case 'PRINTING':
            case 'RENDERING_LOCALLY':
                $newStatus = PrintJobStatusEnum::Printing;
                break;

            case 'COMPLETE':
            case 'FINISHED':
                // Job actually completed - mark as printed
                $newStatus = PrintJobStatusEnum::Printed;
                break;

            case 'ERROR':
            case 'ABORTED':
            case 'FAILED':
                $newStatus = PrintJobStatusEnum::Failed;
                break;

            case 'CANCELED':
            case 'CANCELLED':
            case 'PAPEROUT':
            case 'USER_INTERVENTION':
            case 'OFFLINE':
                $newStatus = PrintJobStatusEnum::Retrying;
                break;

            case 'QUEUED':
            case 'WAITING':
                $newStatus = PrintJobStatusEnum::Queued;
                break;
        }

        // Only transition if we have a valid status mapping and transition is allowed
        if ($newStatus && $job->status->canTransitionTo($newStatus)) {
            $errorMessage = ($newStatus === PrintJobStatusEnum::Failed)
                ? $request->input('message', "QZ Job failed with status: {$qzStatus}")
                : null;

            $job->transitionTo($newStatus, $errorMessage);

            \Log::info("Job {$job->id} transitioned from {$job->status->value} to {$newStatus->value} via QZ callback");
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
            \App\Enum\PrinterStatusEnum::tryFrom($request->input('status')) ?? \App\Enum\PrinterStatusEnum::Unknown,
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
        }
    }
}
