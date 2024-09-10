<?php

namespace App\Jobs\Printing;

use App\Badges\EF28_Badge;
use App\Enum\PrintJobStatusEnum;
use App\Enum\PrintJobTypeEnum;
use App\Models\Badge\Badge;
use App\Models\Machine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class PrintBadgeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly Badge $badge, private Machine $machine)
    {
    }

    public function handle(): void
    {
        $printer = new EF28_Badge();
        $pdfContent = $printer->getPdf($this->badge);
        // Store PDF Content in PrintJobs Storage
        $filePath = 'badges/' . $this->badge->id . '.pdf';
        Storage::put($filePath, $pdfContent);
        $currentMachine = $this->machine;
        // Mark badge as printed if not printed
        // Create PrintJob
        $this->badge->printJobs()->create([
            'printer_id' => $currentMachine->badge_printer_id,
            'type' => PrintJobTypeEnum::Badge,
            'status' => PrintJobStatusEnum::Pending,
            'file' => $filePath,
        ]);
    }
}
