<?php

namespace App\Filament\Widgets;

use App\Models\Badge\Badge;
use App\Models\Event;
use Filament\Widgets\ChartWidget;

class BadgeStatusChart extends ChartWidget
{
    protected static ?string $heading = 'Current Event Badge Status';
    protected static ?int $sort = 3;
    
    protected function getData(): array
    {
        // Get current event based on starts_at
        $currentEvent = Event::orderBy('starts_at', 'desc')->first();
        
        if (!$currentEvent) {
            return [
                'datasets' => [
                    [
                        'data' => [0],
                        'backgroundColor' => ['rgb(156, 163, 175)'],
                    ],
                ],
                'labels' => ['No Active Event'],
            ];
        }
        
        // Get badge status counts for current event
        $badgeStatusCounts = Badge::whereHas('fursuit', function ($query) use ($currentEvent) {
            $query->where('event_id', $currentEvent->id);
        })
        ->selectRaw('status_payment, status_fulfillment, COUNT(*) as count')
        ->groupBy(['status_payment', 'status_fulfillment'])
        ->get();
        
        $statusLabels = [];
        $statusData = [];
        $colors = [
            'rgb(239, 68, 68)',   // red
            'rgb(245, 158, 11)',  // amber
            'rgb(59, 130, 246)',  // blue
            'rgb(16, 185, 129)',  // emerald
            'rgb(139, 92, 246)',  // violet
        ];
        
        foreach ($badgeStatusCounts as $index => $status) {
            $label = ucfirst($status->status_payment) . ' / ' . ucfirst($status->status_fulfillment);
            $statusLabels[] = $label;
            $statusData[] = $status->count;
        }
        
        return [
            'datasets' => [
                [
                    'data' => $statusData,
                    'backgroundColor' => array_slice($colors, 0, count($statusData)),
                ],
            ],
            'labels' => $statusLabels,
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }
    
    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => [
                    'display' => true,
                    'position' => 'bottom',
                ],
            ],
        ];
    }
}