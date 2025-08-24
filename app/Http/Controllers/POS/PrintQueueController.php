<?php

namespace App\Http\Controllers\POS;

use App\Domain\Printing\Models\PrintJob;
use App\Enum\PrintJobStatusEnum;
use App\Http\Controllers\Controller;
use Inertia\Inertia;

class PrintQueueController extends Controller
{
    public function index()
    {
        $printJobs = PrintJob::with(['printable', 'printer'])
            ->orderBy('created_at', 'desc')
            ->paginate(15);

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
        // Create a new retry job with printer reassignment (find available printer)
        $retryJob = $printJob->createRetryJob(reassignPrinter: true);

        $message = $retryJob->printer_id === $printJob->printer_id
            ? "Retry job #{$retryJob->id} created on same printer ({$retryJob->printer->name})"
            : "Retry job #{$retryJob->id} reassigned from {$printJob->printer->name} to {$retryJob->printer->name}";

        return redirect()->back()->with('success', $message);
    }

    public function delete(PrintJob $printJob)
    {
        $printJob->delete();

        return redirect()->back()->with('success', 'Print job deleted');
    }
}
