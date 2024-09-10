<?php

namespace App\Http\Controllers\POS\Printing;

use App\Badges\EF28_Badge;
use App\Enum\PrintJobStatusEnum;
use App\Enum\PrintJobTypeEnum;
use App\Http\Controllers\Controller;
use App\Models\Badge\Badge;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PrintBadgeController extends Controller
{
    public function __invoke(Badge $badge)
    {
        // Generate Fursuit Badge Image
        $printer = new EF28_Badge();
        $pdfContent = $printer->getPdf($badge);
        // Store PDF Content in PrintJobs Storage
        $filePath = 'badges/' . $badge->id . '.pdf';
        Storage::put($filePath, $pdfContent);
        $filePathPng = 'badges/' . $badge->id . '.png';
        Storage::put($filePathPng, $printer->getPng($badge));
        // Create PrintJob
        $printJob = $badge->printJobs()->create([
            'type' => PrintJobTypeEnum::Badge,
            'status' => PrintJobStatusEnum::Pending,
            'file' => $filePath,
        ]);
        // return back
        return redirect()->back()->with('message', 'Badge has been added to the print queue');
    }
}
