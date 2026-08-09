<?php

namespace App\Http\Controllers\POS\Printing;

use App\Domain\Printing\Models\Printer;
use App\Enum\PrinterStatusEnum;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class PrinterStateController extends Controller
{
    public function updateState(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string',
            'status' => 'required|in:'.implode(',', array_column(PrinterStatusEnum::cases(), 'value')),
            'current_job_id' => 'nullable|integer|exists:print_jobs,id',
            'last_error_message' => 'nullable|string',
            'machine_name' => 'nullable|string',
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
            'printer_state' => $printerState,
        ]);
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
            'last_updated' => $lastUpdated,
        ])->header('Last-Modified', $lastUpdated);
    }
}
