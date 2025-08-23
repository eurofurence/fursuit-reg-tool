<?php

namespace App\Jobs\Printing;

use App\Badges\EF28_Badge;
use App\Badges\EF29_Badge;
use App\Domain\Printing\Models\Printer;
use App\Enum\PrintJobStatusEnum;
use App\Enum\PrintJobTypeEnum;
use App\Models\Badge\Badge;
use App\Models\Badge\State_Fulfillment\Printed;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class PrintBadgeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly Badge $badge) {}

    public function handle(): void
    {
        if ($this->badge->status_fulfillment->canTransitionTo(Printed::class)) {
            $this->badge->status_fulfillment->transitionTo(Printed::class);
        }

        // Determine badge class based on event badge_class column
        $badgeClass = $this->badge->fursuit->event->badge_class ?? 'EF28_Badge';

        $printer = match ($badgeClass) {
            'EF29_Badge' => new EF29_Badge,
            'EF28_Badge' => new EF28_Badge,
            default => new EF28_Badge, // Fallback to EF28 for safety
        };
        $pdfContent = $printer->getPdf($this->badge);
        // Store PDF Content in PrintJobs Storage
        $filePath = 'badges/'.$this->badge->id.'.pdf';
        Storage::put($filePath, $pdfContent);
        // Printer to send job to
        $sendTo = Printer::where('is_active', true)
            ->where('type', 'badge')
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
