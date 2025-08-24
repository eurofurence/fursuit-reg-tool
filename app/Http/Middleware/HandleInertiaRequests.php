<?php

namespace App\Http\Middleware;

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
            'event' => \App\Models\Event::latest('starts_at')->first(),
            // Lazy load printer status - only needed for POS header display
            'printerStatus' => fn () => $this->getPrinterStatus($request),
        ];
    }

    private function getAuthContent(Request $request): array
    {
        if ($request->routeIs('pos.*')) {
            return [
                'user' => $request->user('machine-user')?->only(['id', 'name', 'is_admin']),
                // Machine data changes rarely, but SumUp reader is only needed for checkout
                'machine' => $request->routeIs('pos.checkout.*') 
                    ? $request->user('machine')?->load('sumupReader')
                    : $request->user('machine'),
            ];
        }

        return [
            'user' => $request->user()?->load('badges'),
            'balance' => $request->user()?->balanceInt,
        ];
    }

    private function getPrinterStatus(Request $request): ?array
    {
        // Only provide printer status for POS routes
        if (! $request->routeIs('pos.*') || ! $request->user('machine-user')) {
            return null;
        }

        // Get overall printer status - only count active printers
        $pausedCount = \App\Domain\Printing\Models\Printer::whereIn('status', [\App\Enum\PrinterStatusEnum::PAUSED->value, \App\Enum\PrinterStatusEnum::OFFLINE->value])
            ->where('is_active', true)
            ->count();
        $totalCount = \App\Domain\Printing\Models\Printer::where('is_active', true)
            ->count();
        $lastUpdated = \App\Domain\Printing\Models\Printer::where('is_active', true)
            ->max('updated_at');

        return [
            'has_issues' => $pausedCount > 0,
            'paused_count' => $pausedCount,
            'total_count' => $totalCount,
            'last_updated' => $lastUpdated,
        ];
    }
}
