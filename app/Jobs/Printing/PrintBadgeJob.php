<?php

namespace App\Jobs\Printing;

use App\Badges\EF28_Badge;
use App\Domain\Printing\Models\Printer;
use App\Enum\PrintJobStatusEnum;
use App\Enum\PrintJobTypeEnum;
use App\Models\Badge\Badge;
use App\Models\Badge\States\Printed;
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

    public function __construct(private readonly Badge $badge)
    {
    }

    public function handle(): void
    {
        if($this->badge->status !== Printed::class && $this->badge->status->canTransitionTo(Printed::class)) {
            $this->badge->status->transitionTo(Printed::class);
        }


        $printer = new EF28_Badge();
        $pdfContent = $printer->getPdf($this->badge);
        // Store PDF Content in PrintJobs Storage
        $filePath = 'badges/' . $this->badge->id . '.pdf';
        Storage::put($filePath, $pdfContent);
        // Printer to send job to
        $sendTo = Printer::where('is_active', true)
            ->where('type', 'badge')
            ->where('is_double', (bool) $this->badge->dual_side_print)
            ->firstOrFail();
        // Create PrintJob
        $this->badge->printJobs()->create([
            'printer_id' => $sendTo->id,
            'type' => PrintJobTypeEnum::Badge,
            'status' => PrintJobStatusEnum::Pending,
            'file' => $filePath,
        ]);
    }
}
