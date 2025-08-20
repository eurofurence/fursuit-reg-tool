<?php

namespace App\Http\Controllers\POS;

use App\Domain\Printing\Models\PrintJob;
use App\Enum\PrintJobStatusEnum;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PrintQueueController extends Controller
{
    public function index()
    {
        $printJobs = PrintJob::with(['printable', 'printer'])
            ->orderBy('created_at', 'desc')
            ->paginate(50);

        return Inertia::render('POS/PrintQueue/Index', [
            'printJobs' => $printJobs,
        ]);
    }

    public function markAsPrinted(PrintJob $printJob)
    {
        $printJob->update([
            'status' => PrintJobStatusEnum::Printed,
            'printed_at' => now(),
        ]);

        return redirect()->back()->with('success', 'Print job marked as printed');
    }

    public function retry(PrintJob $printJob)
    {
        $printJob->update([
            'status' => PrintJobStatusEnum::Pending,
            'printed_at' => null,
        ]);

        return redirect()->back()->with('success', 'Print job queued for retry');
    }

    public function delete(PrintJob $printJob)
    {
        $printJob->delete();

        return redirect()->back()->with('success', 'Print job deleted');
    }
}