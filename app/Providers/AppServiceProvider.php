<?php

namespace App\Providers;

use App\Domain\Checkout\Models\TseClient;
use App\Models\Badge\Badge;
use App\Models\Fursuit\Fursuit;
use App\Models\SumUpReader;
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
        Badge::observe(\App\Observers\BadgeObserver::class);
        TseClient::observe(\App\Observers\TseClientsObserver::class);
        SumUpReader::observe(\App\Observers\SumUpReaderObserver::class);
        $socialite = $this->app->make(Factory::class);
        $socialite->extend('identity', function () use ($socialite) {
            $config = config('services.identity');

            return $socialite->buildProvider(SocialiteIdentityProvider::class, $config);
        });
        Http::macro('attsrv', function () {
            return Http::acceptJson()
                ->baseUrl(config('services.attsrv.url'));
        });
        Http::macro('fiskaly', function () {
            return Http::baseUrl(config('services.fiskaly.url'));
        });
        Http::macro('sumup', function () {
            return Http::baseUrl(config('services.sumup.url'))->withToken(config('services.sumup.api_secret'));
        });
    }
}
