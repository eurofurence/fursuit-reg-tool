<?php

namespace App\Filament\Pages;

use App\Models\Event;
use App\Services\FreeBadgeRepairService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class DbService extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-wrench-screwdriver';

    protected static ?string $navigationGroup = 'Maintenance';

    protected static ?string $navigationLabel = 'DB Service';

    protected static ?string $title = 'DB Service';

    protected static string $view = 'filament.pages.db-service';

    /** Preview report for the "Fix free badges" action (null until generated). */
    public ?array $freeBadgeReport = null;

    /** Result summary after the fix has been applied (null until applied). */
    public ?array $freeBadgeResult = null;

    public bool $reviewingFreeBadges = false;

    /**
     * Restrict the whole Maintenance group + this page to admins. The panel itself also admits
     * reviewers (User::canAccessPanel), so this extra gate is required.
     */
    public static function canAccess(): bool
    {
        return (bool) (auth()->user()?->is_admin);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return (bool) (auth()->user()?->is_admin);
    }

    /**
     * Step 1 — build a non-mutating preview of the badges that would be fixed.
     */
    public function previewFreeBadgeFix(): void
    {
        $this->freeBadgeResult = null;
        $this->freeBadgeReport = app(FreeBadgeRepairService::class)->preview(Event::getActiveEvent());
        $this->reviewingFreeBadges = true;

        if ($this->freeBadgeReport['affected_badge_count'] === 0) {
            Notification::make()
                ->title('Nothing to fix')
                ->body('No wrongly-charged prepaid badges were found for the current event.')
                ->success()
                ->send();
        }
    }

    /**
     * Step 2 — apply the fix after the admin confirms the preview.
     */
    public function applyFreeBadgeFix(): void
    {
        $result = app(FreeBadgeRepairService::class)->repair(Event::getActiveEvent(), auth()->user());

        $this->freeBadgeResult = $result;
        $this->freeBadgeReport = null;
        $this->reviewingFreeBadges = false;

        if ($result['success']) {
            Notification::make()
                ->title('Fix applied')
                ->body("Converted {$result['fixed_badge_count']} badge(s) for {$result['fixed_user_count']} user(s) to free.")
                ->success()
                ->send();
        } else {
            Notification::make()
                ->title('Fix failed')
                ->body($result['error'] ?? 'Unknown error.')
                ->danger()
                ->send();
        }
    }

    public function cancelFreeBadgeFix(): void
    {
        $this->reviewingFreeBadges = false;
        $this->freeBadgeReport = null;
    }

    public function resetFreeBadgeFix(): void
    {
        $this->freeBadgeReport = null;
        $this->freeBadgeResult = null;
        $this->reviewingFreeBadges = false;
    }

    /**
     * Best-effort displayable image URL, used by the Blade view.
     */
    public function imageUrl(?string $path): ?string
    {
        return app(FreeBadgeRepairService::class)->imageUrl($path);
    }

    public function formatEuro(int $cents): string
    {
        return '€'.number_format($cents / 100, 2);
    }
}
