<?php

namespace App\Filament\Widgets;

use App\Models\Badge\Badge;
use App\Models\Event;
use App\Models\Fursuit\Fursuit;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverview extends BaseWidget
{
    protected function getStats(): array
    {
        // Get current and previous event based on starts_at
        $currentEvent = Event::orderBy('starts_at', 'desc')->first();
        $previousEvent = Event::where('starts_at', '<', $currentEvent?->starts_at ?? now())
            ->orderBy('starts_at', 'desc')
            ->first();

        // Current event stats
        $currentEventBadges = $currentEvent ? 
            Badge::whereHas('fursuit', fn($q) => $q->where('event_id', $currentEvent->id))->count() : 0;
        $currentEventFursuits = $currentEvent ? 
            Fursuit::where('event_id', $currentEvent->id)->count() : 0;
        $currentEventPending = $currentEvent ? 
            Fursuit::where('event_id', $currentEvent->id)->where('status', 'pending')->count() : 0;

        // Previous event stats for comparison
        $previousEventBadges = $previousEvent ? 
            Badge::whereHas('fursuit', fn($q) => $q->where('event_id', $previousEvent->id))->count() : 0;
        $previousEventFursuits = $previousEvent ? 
            Fursuit::where('event_id', $previousEvent->id)->count() : 0;

        $badgeDiff = $currentEventBadges - $previousEventBadges;
        $fursuitDiff = $currentEventFursuits - $previousEventFursuits;

        return [
            // Current Event Section
            Stat::make('Current Event', $currentEvent?->name ?? 'No Event')
                ->description($currentEvent ? 
                    ($currentEvent->allowsOrders() ? 'Orders Open' : 'Orders Closed') : 'No current event')
                ->descriptionIcon($currentEvent && $currentEvent->allowsOrders() ? 
                    'heroicon-m-check-circle' : 'heroicon-m-x-circle')
                ->color($currentEvent && $currentEvent->allowsOrders() ? 'success' : 'danger'),

            Stat::make('Current Event Badges', $currentEventBadges)
                ->description($badgeDiff > 0 ? "+{$badgeDiff} vs {$previousEvent?->name}" : 
                    ($badgeDiff < 0 ? "{$badgeDiff} vs {$previousEvent?->name}" : "No previous event"))
                ->descriptionIcon($badgeDiff > 0 ? 'heroicon-m-arrow-trending-up' : 
                    ($badgeDiff < 0 ? 'heroicon-m-arrow-trending-down' : 'heroicon-m-minus'))
                ->color($badgeDiff > 0 ? 'success' : ($badgeDiff < 0 ? 'danger' : 'gray')),

            Stat::make('Current Event Fursuits', $currentEventFursuits)
                ->description($fursuitDiff > 0 ? "+{$fursuitDiff} vs {$previousEvent?->name}" : 
                    ($fursuitDiff < 0 ? "{$fursuitDiff} vs {$previousEvent?->name}" : "No previous event"))
                ->descriptionIcon($fursuitDiff > 0 ? 'heroicon-m-arrow-trending-up' : 
                    ($fursuitDiff < 0 ? 'heroicon-m-arrow-trending-down' : 'heroicon-m-minus'))
                ->color($fursuitDiff > 0 ? 'success' : ($fursuitDiff < 0 ? 'danger' : 'gray')),

            Stat::make('Pending Approval', $currentEventPending)
                ->description('Awaiting review')
                ->descriptionIcon('heroicon-m-clock')
                ->color($currentEventPending > 0 ? 'warning' : 'success')
                ->url(route('filament.admin.resources.fursuits.index')),

        ];
    }
}
