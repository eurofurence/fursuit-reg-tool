<?php

namespace App\Http\Controllers\POS\Printing;

use App\Http\Controllers\Controller;
use App\Models\PrinterState;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PrinterStateController extends Controller
{
    public function index(): Response
    {
        $printerStates = PrinterState::with('currentJob')->orderBy('name')->get()->values();
        
        return Inertia::render('POS/Printers/Index', [
            'printerStates' => $printerStates
        ]);
    }

    public function updateState(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string',
            'status' => 'required|in:idle,working,paused',
            'current_job_id' => 'nullable|integer|exists:print_jobs,id',
            'last_error_message' => 'nullable|string',
            'machine_name' => 'nullable|string'
        ]);

        $printerState = PrinterState::updatePrinterState(
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
        $printer = PrinterState::where('name', $printerName)->first();
        
        if (!$printer || $printer->status !== 'paused') {
            return response()->json(['error' => 'Printer not found or not paused'], 404);
        }

        // Reset the current job to pending with priority 1 so it gets picked up first
        if ($printer->current_job_id) {
            $printJob = \App\Domain\Printing\Models\PrintJob::find($printer->current_job_id);
            if ($printJob) {
                $printJob->update([
                    'status' => 'pending',
                    'priority' => 1,
                    'error_message' => null,
                    'updated_at' => now()
                ]);
            }
        }

        // Mark printer as idle and ready for retry
        $printer->update([
            'status' => 'idle',
            'current_job_id' => null, // Clear the current job so it can be reclaimed
            'last_error_message' => null,
            'last_update' => now()
        ]);

        return response()->json([
            'success' => true,
            'message' => "Printer {$printerName} ready for retry, job reset to pending"
        ]);
    }

    public function skipJob(Request $request, string $printerName)
    {
        $printer = PrinterState::where('name', $printerName)->first();
        
        if (!$printer || $printer->status !== 'paused') {
            return response()->json(['error' => 'Printer not found or not paused'], 404);
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
            'last_update' => now()
        ]);

        return response()->json([
            'success' => true,
            'message' => "Job skipped on printer {$printerName}"
        ]);
    }

    public function clearError(Request $request, string $printerName)
    {
        $success = PrinterState::clearPrinterError($printerName);
        
        if ($success) {
            return response()->json([
                'success' => true,
                'message' => "Error cleared for printer {$printerName}"
            ]);
        }

        return response()->json(['error' => 'Printer not found or not paused'], 404);
    }

    public function getStates(Request $request)
    {
        $lastUpdated = PrinterState::max('updated_at');
        $clientLastUpdated = $request->header('If-Modified-Since');
        
        // If client has the same timestamp, return 304 Not Modified
        if ($clientLastUpdated && $clientLastUpdated === $lastUpdated) {
            return response('', 304);
        }
        
        $states = PrinterState::with('currentJob')->orderBy('name')->get()->values();
        
        return response()->json([
            'states' => $states,
            'last_updated' => $lastUpdated
        ])->header('Last-Modified', $lastUpdated);
    }
}
