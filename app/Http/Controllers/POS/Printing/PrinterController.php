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
        $printers = $machine->printers()->get();

        return PrintJob::whereHas('printer', fn ($query) => $query->where('machine_id', $machine->id))
            ->where('status', '=', PrintJobStatusEnum::Pending)
            ->limit(5)
            ->orderBy('created_at')
            ->get()
            ->map(fn (PrintJob $printJob) => [
                'id' => $printJob->id,
                'printer' => $printJob->printer->name,
                'type' => $printJob->type,
                'file' => Storage::drive('s3')->temporaryUrl($printJob->file, now()->addDay()),
                'paper' => collect($printJob->printer->paper_sizes)->where('name', $printJob->printer->default_paper_size)->first(),
                'duplex' => ($printJob->type === PrintJobTypeEnum::Receipt) ? false : $printJob->printable->dual_side_print,
            ])->values()->toArray();
    }

    public function jobPrinted(PrintJob $job)
    {
        $job->update([
            'status' => PrintJobStatusEnum::Printed,
            'printed_at' => now(),
        ]);
    }
}
