<?php

namespace App\Providers;

use App\Models\Fursuit\Fursuit;
use App\Providers\Socialite\SocialiteIdentityProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\ServiceProvider;
use Laravel\Socialite\Contracts\Factory;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Fursuit::observe(\App\Observers\FursuitObserver::class);
        $socialite = $this->app->make(Factory::class);
        $socialite->extend('identity', function () use ($socialite) {
            $config = config('services.identity');

            return $socialite->buildProvider(SocialiteIdentityProvider::class, $config);
        });
        Http::macro('attsrv', function () {
            return Http::acceptJson()
                ->baseUrl(config('services.attsrv.url'));
        });
    }
}
