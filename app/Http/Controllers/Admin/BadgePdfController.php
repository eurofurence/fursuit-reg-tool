<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Badges\EF28_Badge;
use App\Badges\EF29_Badge;
use App\Models\Badge\Badge;
use Illuminate\Http\Response;

class BadgePdfController extends Controller
{
    public function view(string $customId): Response
    {
        $badge = Badge::with(['fursuit.user', 'fursuit.species', 'fursuit.event'])
            ->where('custom_id', $customId)
            ->firstOrFail();
        
        $badgeClass = $badge->fursuit->event->badge_class ?? 'EF28_Badge';
        
        $printer = match ($badgeClass) {
            'EF29_Badge' => new EF29_Badge,
            'EF28_Badge' => new EF28_Badge,
            default => new EF28_Badge,
        };
        
        $pdfContent = $printer->getPdf($badge);
        
        $filename = 'badge-' . preg_replace('/[^a-zA-Z0-9-_]/', '', $customId) . '.pdf';
        
        return response($pdfContent, 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="' . $filename . '"');
    }
    
    public function download(string $customId): Response
    {
        $badge = Badge::with(['fursuit.user', 'fursuit.species', 'fursuit.event'])
            ->where('custom_id', $customId)
            ->firstOrFail();
        
        $badgeClass = $badge->fursuit->event->badge_class ?? 'EF28_Badge';
        
        $printer = match ($badgeClass) {
            'EF29_Badge' => new EF29_Badge,
            'EF28_Badge' => new EF28_Badge,
            default => new EF28_Badge,
        };
        
        $pdfContent = $printer->getPdf($badge);
        
        $filename = 'badge-' . preg_replace('/[^a-zA-Z0-9-_]/', '', $customId) . '.pdf';
        
        return response($pdfContent, 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }
}