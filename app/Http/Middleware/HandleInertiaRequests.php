<?php

namespace App\Http\Middleware;

use App\Domain\Printing\Models\Printer;
use App\Enum\PrinterConditionEnum;
use App\Enum\PrinterStatusEnum;
use App\Models\Event;
use Illuminate\Http\Request;
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
        return [
            ...parent::share($request),
            'auth' => $this->getAuthContent($request),
            'flash' => [
                'message' => fn () => $request->session()->get('message'),
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
            // Get event that did not end yet and is the next one
            'event' => Event::latest('starts_at')->first(),
            // Lazy load printer status - only needed for POS header display
            'printerStatus' => fn () => $this->getPrinterStatus($request),
        ];
    }

    private function getAuthContent(Request $request): array
    {
        if ($request->routeIs('pos.*')) {
            return [
                'user' => $request->user('machine-user')?->only(['id', 'name', 'is_admin']),
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
