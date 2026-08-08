<?php

namespace App\Http\Controllers\Admin;

use App\Badges\EF28_Badge;
use App\Badges\EF29_Badge;
use App\Badges\EF30_Badge;
use App\Http\Controllers\Controller;
use App\Models\Badge\Badge;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class BadgePdfController extends Controller
{
    public function view(string $customId): Response
    {
        Gate::authorize('manage-admin');

        $badge = Badge::with(['fursuit.user', 'fursuit.species', 'fursuit.event'])
            ->where('custom_id', $customId)
            ->firstOrFail();

        $badgeClass = $badge->fursuit->event->badge_class ?? 'EF30_Badge';

        $printer = match ($badgeClass) {
            'EF29_Badge' => new EF29_Badge,
            'EF28_Badge' => new EF28_Badge,
            'EF30_Badge' => new EF30_Badge,
            default => new EF30_Badge,
        };

        $pdfContent = $printer->getPdf($badge);

        $filename = 'badge-'.preg_replace('/[^a-zA-Z0-9-_]/', '', $customId).'.pdf';

        return response($pdfContent, 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="'.$filename.'"');
    }

    public function download(string $customId): Response
    {
        Gate::authorize('manage-admin');

        $badge = Badge::with(['fursuit.user', 'fursuit.species', 'fursuit.event'])
            ->where('custom_id', $customId)
            ->firstOrFail();

        $badgeClass = $badge->fursuit->event->badge_class ?? 'EF30_Badge';

        $printer = match ($badgeClass) {
            'EF30_Badge' => new EF30_Badge,
            'EF29_Badge' => new EF29_Badge,
            'EF28_Badge' => new EF28_Badge,
            default => new EF30_Badge,
        };

        $pdfContent = $printer->getPdf($badge);

        $filename = 'badge-'.preg_replace('/[^a-zA-Z0-9-_]/', '', $customId).'.pdf';

        return response($pdfContent, 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="'.$filename.'"');
    }
}
