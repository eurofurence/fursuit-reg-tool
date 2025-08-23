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

    public function jobPrinted(PrintJob $job)
    {
        $machine = auth('machine')->user();
        
        // Assign the job to this machine for processing
        $job->assignToMachine($machine);
        
        // Transition through proper states: Pending/Queued -> Printing -> Printed
        if ($job->status === PrintJobStatusEnum::Pending) {
            $job->transitionTo(PrintJobStatusEnum::Queued);
        }
        
        if ($job->status === PrintJobStatusEnum::Queued) {
            $job->transitionTo(PrintJobStatusEnum::Printing);
        }
        
        // Finally mark as printed
        $job->transitionTo(PrintJobStatusEnum::Printed);
        
        return response()->json(['success' => true]);
    }

    public function jobFailed(PrintJob $job, Request $request)
    {
        $request->validate([
            'error_message' => 'nullable|string|max:1000'
        ]);

        $machine = auth('machine')->user();
        $job->assignToMachine($machine);
        
        // Transition to failed state with error message
        $job->transitionTo(PrintJobStatusEnum::Failed, $request->input('error_message', 'Print job failed'));
        
        return response()->json(['success' => true]);
    }
}
