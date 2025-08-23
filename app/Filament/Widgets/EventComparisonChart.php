<?php

namespace App\Filament\Widgets;

use App\Models\Badge\Badge;
use App\Models\Event;
use App\Models\Fursuit\Fursuit;
use Filament\Widgets\ChartWidget;

class EventComparisonChart extends ChartWidget
{
    protected static ?string $heading = 'Event Comparison';
    protected static ?int $sort = 2;
    
    protected function getData(): array
    {
        // Get current and previous event based on starts_at
        $currentEvent = Event::orderBy('starts_at', 'desc')->first();
        $previousEvent = Event::where('starts_at', '<', $currentEvent?->starts_at ?? now())
            ->orderBy('starts_at', 'desc')
            ->first();
        
        if (!$currentEvent) {
            return [
                'datasets' => [
                    [
                        'label' => 'No Events',
                        'data' => [0, 0],
                        'backgroundColor' => 'rgba(156, 163, 175, 0.8)',
                    ],
                ],
                'labels' => ['Badges', 'Fursuits'],
            ];
        }
        
        // Get current event data
        $currentBadgeCount = Badge::whereHas('fursuit', function ($query) use ($currentEvent) {
            $query->where('event_id', $currentEvent->id);
        })->count();
        
        $currentFursuitCount = Fursuit::where('event_id', $currentEvent->id)->count();
        
        $datasets = [
            [
                'label' => $currentEvent->name,
                'data' => [$currentBadgeCount, $currentFursuitCount],
                'backgroundColor' => 'rgba(59, 130, 246, 0.8)',
            ],
        ];
        
        // Add previous event data if it exists
        if ($previousEvent) {
            $previousBadgeCount = Badge::whereHas('fursuit', function ($query) use ($previousEvent) {
                $query->where('event_id', $previousEvent->id);
            })->count();
            
            $previousFursuitCount = Fursuit::where('event_id', $previousEvent->id)->count();
            
            $datasets[] = [
                'label' => $previousEvent->name,
                'data' => [$previousBadgeCount, $previousFursuitCount],
                'backgroundColor' => 'rgba(16, 185, 129, 0.8)',
            ];
        }
        
        return [
            'datasets' => $datasets,
            'labels' => ['Badges', 'Fursuits'],
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
    
    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => [
                    'display' => true,
                ],
            ],
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                ],
            ],
        ];
    }
}