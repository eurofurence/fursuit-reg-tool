<?php

namespace App\Filament\Widgets;

use App\Models\Badge\Badge;
use App\Models\Badge\States\Pending;
use App\Models\Fursuit\Fursuit;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverview extends BaseWidget
{
    protected function getStats(): array
    {
        return [
            Stat::make('Total Badges', Badge::count()),
            Stat::make('Total Fursuiters', Fursuit::count()),
            Stat::make('Pending Approval', Fursuit::where('status', 'pending')
                ->count())
            ->url(route('filament.admin.resources.fursuits.index')),
            Stat::make('Approved', Fursuit::where('status', 'approved')->count()),
            Stat::make('Rejected', Fursuit::where('status', 'rejected')->count()),
        ];
    }
}
