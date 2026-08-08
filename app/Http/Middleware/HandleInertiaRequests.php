<?php

namespace App\Http\Middleware;

use App\Domain\Printing\Models\Printer;
use App\Enum\PrinterConditionEnum;
use App\Enum\PrinterStatusEnum;
use App\Models\Event;
use App\Support\DeskOpeningHours;
use App\Support\Manage\EventScope;
use App\Support\Manage\Navigation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        // Get event that did not end yet and is the next one
        $event = Event::latest('starts_at')->first();

        return [
            ...parent::share($request),
            'auth' => $this->getAuthContent($request),
            'flash' => [
                'message' => fn () => $request->session()->get('message'),
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
            'event' => $event,
            // The site navigation hides the Catch-Em-All entry outside the game window
            // rather than linking to a game nobody can play. Computed off the event we
            // already loaded, so the nav costs no extra query. See Event::isCatchEmAllActive().
            'catchEmAllActive' => (bool) $event?->isCatchEmAllActive(),
            // Puts an "Open" marker beside the Pickup entry in the desktop rail while the
            // badge desk is actually staffed. Read off the same already-loaded event as
            // the line above, so the marker costs no query either. See DeskOpeningHours.
            'deskOpenNow' => DeskOpeningHours::isOpenNow($event),
            // Lazy load printer status - only needed for POS header display
            'printerStatus' => fn () => $this->getPrinterStatus($request),
            ...$this->getManageContent($request),
        ];
    }

    /**
     * Props only the /manage panel needs.
     *
     * Spread in rather than shared unconditionally: the keys are absent everywhere
     * else, so the public site, the POS and Catch-Em-All never pay for the nav counts
     * or the event list. The values are closures, so even on /manage they only run
     * when the response is actually rendered.
     *
     * Toasts are not here. App\Support\Manage\Toast writes into Inertia's own flash
     * bag, which arrives as the top-level `flash` key on the page object rather than
     * as a prop, and stays out of the browser's history state so a back navigation
     * cannot replay a toast.
     *
     * @return array<string, mixed>
     */
    private function getManageContent(Request $request): array
    {
        if (! $request->routeIs('manage.*')) {
            return [];
        }

        return [
            'manageNav' => fn () => $this->manageNavigation()->groups(),
            'manageEvent' => fn () => app(EventScope::class)->toArray(),
            'manageStrip' => fn () => $this->manageNavigation()->strip(),
        ];
    }

    /**
     * Navigation with the selected event handed to it.
     *
     * The event id is a constructor argument, not something Navigation reads, so
     * EventScope stays the one reader of the selection. Resolving it out of the
     * container instead would hand the constructor its null default and every rail
     * count would silently be an all-events count, cached under one key.
     *
     * Built inside the shared closures rather than in share(): Inertia's middleware
     * calls share() before passing the request on, and ManageEventScope has not seeded
     * the scope yet at that point. Two calls, one per prop, cost one extra object; the
     * counts behind them are the same cached read.
     */
    private function manageNavigation(): Navigation
    {
        return new Navigation(app(EventScope::class)->id());
    }

    private function getAuthContent(Request $request): array
    {
        if ($request->routeIs('manage.*')) {
            $user = $request->user();

            // The ability flags let the sidebar hide what the user cannot reach,
            // mirroring what Filament's policies do to the nav today. No badges or
            // amountDue here: the admin never renders either.
            return [
                'user' => $user?->only(['id', 'name', 'email']),
                'can_access_manage' => $user !== null && Gate::forUser($user)->allows('access-manage'),
                'is_admin' => (bool) $user?->is_admin,
            ];
        }

        if ($request->routeIs('pos.*')) {
            return [
                // `is_manager` decides whether a price override prompts for a second
                // credential or goes straight through. It replaces an `is_admin` key
                // that was shared here for a column the staff table never had, so it
                // was always absent from the payload.
                'user' => $request->user('machine-user')?->only(['id', 'name', 'is_manager']),
                // Machine data with SumUp reader for all POS routes (needed in header)
                'machine' => $request->user('machine')?->load('sumupReader'),
            ];
        }

        return [
            'user' => $request->user()?->load('badges'),
            'amountDue' => $request->user()?->amountDue(),
        ];
    }

    private function getPrinterStatus(Request $request): ?array
    {
        // Only provide printer status for POS routes
        if (! $request->routeIs('pos.*') || ! $request->user('machine-user')) {
            return null;
        }

        // Get overall printer status - only count active printers
        $pausedCount = Printer::whereIn('status', [PrinterStatusEnum::PAUSED->value, PrinterStatusEnum::OFFLINE->value])
            ->where('is_active', true)
            ->count();
        $totalCount = Printer::where('is_active', true)
            ->count();
        $lastUpdated = Printer::where('is_active', true)
            ->max('updated_at');

        return [
            'has_issues' => $pausedCount > 0,
            'paused_count' => $pausedCount,
            'total_count' => $totalCount,
            'last_updated' => $lastUpdated,
            // Seeds the live indicator. Without it every screen starts green
            // and stays green until the next broadcast, so a printer that
            // jammed before the page was opened looks perfectly healthy.
            'conditions' => $this->printerConditions(),
        ];
    }

    /**
     * Worst current condition per printer type, for the POS header icons.
     *
     * Worst rather than newest: one icon stands for every printer of that
     * kind, and a station with a jammed printer and a healthy one has a
     * problem that the healthy one must not hide.
     */
    private function printerConditions(): array
    {
        $rank = ['danger' => 3, 'info' => 2, 'warning' => 1, 'success' => 0];
        $out = [];

        $printers = Printer::where('is_active', true)
            ->whereNotNull('condition')
            ->get();

        foreach ($printers as $printer) {
            $condition = $printer->condition;

            if (! $condition instanceof PrinterConditionEnum) {
                $condition = PrinterConditionEnum::tryFrom((string) $condition);
            }

            if ($condition === null) {
                continue;
            }

            $type = $printer->type?->value === 'receipt' ? 'receipt' : 'badge';
            $severity = $condition->severity();

            if (isset($out[$type]) && ($rank[$out[$type]['severity']] ?? 0) >= ($rank[$severity] ?? 0)) {
                continue;
            }

            $out[$type] = [
                'status' => $condition->value,
                'label' => $condition->label(),
                'severity' => $severity,
                'error_message' => $printer->condition_message,
            ];
        }

        return $out;
    }
}
