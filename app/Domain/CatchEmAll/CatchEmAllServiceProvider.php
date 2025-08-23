<?php

namespace App\Domain\CatchEmAll;

use App\Domain\CatchEmAll\Services\AchievementService;
use App\Domain\CatchEmAll\Services\GameStatsService;
use Illuminate\Support\ServiceProvider;

class CatchEmAllServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->singleton(AchievementService::class);
        $this->app->singleton(GameStatsService::class);
    }

    public function boot()
    {
        //
    }
}
