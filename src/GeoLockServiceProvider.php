<?php

namespace Spiggle\GeoLock;

use Illuminate\Support\ServiceProvider;
use Spiggle\GeoLock\Services\AccessDecider;
use Spiggle\GeoLock\Services\RequestGeoLocator;

class GeoLockServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/geo-lock.php', 'geo-lock');

        $this->app->singleton(RequestGeoLocator::class);
        $this->app->singleton(AccessDecider::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'geo-lock');

        $this->publishes([
            __DIR__.'/../config/geo-lock.php' => config_path('geo-lock.php'),
        ], 'geo-lock-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'geo-lock-migrations');
    }
}
