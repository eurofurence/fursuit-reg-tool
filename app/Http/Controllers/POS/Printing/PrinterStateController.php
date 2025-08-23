<?php

namespace App\Http\Controllers\POS\Printing;

use App\Http\Controllers\Controller;
use App\Domain\Printing\Models\Printer;
use App\Enum\PrinterStatusEnum;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PrinterStateController extends Controller
{
    public function index(): Response
    {
        // Only show printer states for active printers
        $printerStates = Printer::with(['currentJob', 'machine'])
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->values();

        // Get recent critical events for context
        $recentEvents = \App\Models\PrinterEvent::whereIn('severity', ['ERROR', 'FATAL'])
            ->orWhere('handled', true)
            ->orderBy('event_time', 'desc')
            ->limit(20)
            ->get();

        return Inertia::render('POS/Printers/Index', [
            'printerStates' => $printerStates,
            'recentEvents' => $recentEvents
        ]);
    }

    public function updateState(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string',
            'status' => 'required|in:' . implode(',', array_column(PrinterStatusEnum::cases(), 'value')),
            'current_job_id' => 'nullable|integer|exists:print_jobs,id',
            'last_error_message' => 'nullable|string',
            'machine_name' => 'nullable|string'
        ]);

        $printerState = Printer::updatePrinterState(
            $validated['name'],
            $validated['status'],
            $validated['current_job_id'] ?? null,
            $validated['last_error_message'] ?? null,
            $validated['machine_name'] ?? null
        );

        return response()->json([
            'success' => true,
            'printer_state' => $printerState
        ]);
    }

    public function retryJob(Request $request, string $printerName)
    {
        $printer = Printer::where('name', $printerName)->where('is_active', true)->first();

        if (!$printer || !in_array($printer->status, [PrinterStatusEnum::PAUSED, PrinterStatusEnum::OFFLINE])) {
            return response()->json(['error' => 'Printer not found or not in error state'], 404);
        }

        // Create a new retry job from the failed job (same printer - no reassignment)
        $retryJobId = null;
        if ($printer->current_job_id) {
            $originalJob = \App\Domain\Printing\Models\PrintJob::find($printer->current_job_id);
            if ($originalJob) {
                $retryJob = $originalJob->createRetryJob(reassignPrinter: false);
                $retryJobId = $retryJob->id;
            }
        }

        // Mark printer as idle and ready to pick up the retry job
        $printer->update([
            'status' => 'idle',
            'current_job_id' => null, // Clear the current job so retry job can be claimed
            'last_error_message' => null,
            'last_state_update' => now()
        ]);

        $message = $retryJobId 
            ? "Printer {$printerName} ready for retry. New job #{$retryJobId} created."
            : "Printer {$printerName} cleared and ready.";

        return response()->json([
            'success' => true,
            'message' => $message,
            'retry_job_id' => $retryJobId
        ]);
    }

    public function skipJob(Request $request, string $printerName)
    {
        $printer = Printer::where('name', $printerName)->where('is_active', true)->first();

        if (!$printer || !in_array($printer->status, [PrinterStatusEnum::PAUSED, PrinterStatusEnum::OFFLINE])) {
            return response()->json(['error' => 'Printer not found or not in error state'], 404);
        }

        // Mark the current job as failed if it exists
        if ($printer->current_job_id) {
            // Update the print job to failed status
            $printJob = \App\Domain\Printing\Models\PrintJob::find($printer->current_job_id);
            if ($printJob) {
                $printJob->update([
                    'status' => 'failed',
                    'error_message' => 'Job skipped by user from printer management'
                ]);
            }
        }

        // Clear printer state
        $printer->update([
            'status' => 'idle',
            'current_job_id' => null,
            'last_error_message' => null,
            'last_state_update' => now()
        ]);

        return response()->json([
            'success' => true,
            'message' => "Job skipped on printer {$printerName}"
        ]);
    }

    public function clearError(Request $request, string $printerName)
    {
        $success = Printer::clearPrinterError($printerName);

        if ($success) {
            return response()->json([
                'success' => true,
                'message' => "Error cleared for printer {$printerName}"
            ]);
        }

        return response()->json(['error' => 'Printer not found or not in error state'], 404);
    }

    public function getStates(Request $request)
    {
        // Only check timestamps for active printers
        $lastUpdated = Printer::where('is_active', true)->max('updated_at');
        $clientLastUpdated = $request->header('If-Modified-Since');

        // If client has the same timestamp, return 304 Not Modified
        if ($clientLastUpdated && $clientLastUpdated === $lastUpdated) {
            return response('', 304);
        }

        // Only return states for active printers
        $states = Printer::with('currentJob')
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->values();

        return response()->json([
            'states' => $states,
            'last_updated' => $lastUpdated
        ])->header('Last-Modified', $lastUpdated);
    }
}
