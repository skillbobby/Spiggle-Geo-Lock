<?php

declare(strict_types=1);

namespace GeoLock;

use GeoLock\Contracts\GeoLockRuleRepository;
use GeoLock\Contracts\GeoResolver;
use GeoLock\Contracts\RoleDirectory;
use GeoLock\Events\GeoLockAccessDenied;
use GeoLock\Events\GeoLockAccessGranted;
use GeoLock\Events\GeoLockBypassed;
use GeoLock\Listeners\RecordGeoLockAttempt;
use GeoLock\Repositories\DatabaseGeoLockRuleRepository;
use GeoLock\Resolvers\CacheGeoResolver;
use GeoLock\Resolvers\FallbackGeoResolver;
use GeoLock\Resolvers\NullGeoResolver;
use GeoLock\Services\BypassInspector;
use GeoLock\Services\GeoLockManager;
use GeoLock\Services\RuleEvaluator;
use GeoLock\Support\MiddlewareRegistry;
use GeoLock\Support\Roles\RoleDirectoryFactory;
use Filament\Panel;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Throwable;

class GeoLockServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('geo-lock')
            ->hasConfigFile()
            ->hasViews()
            ->hasTranslations()
            ->hasMigrations([
                'create_geo_lock_rules_table',
                'create_geo_lock_attempts_table',
            ]);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(GeoResolver::class, fn (): GeoResolver => $this->makeResolver());
        $this->app->bind(GeoLockRuleRepository::class, DatabaseGeoLockRuleRepository::class);
        $this->app->singleton(RoleDirectory::class, fn (): RoleDirectory => RoleDirectoryFactory::make());
        $this->app->singleton(RuleEvaluator::class);

        $this->app->singleton(BypassInspector::class, fn ($app): BypassInspector => new BypassInspector(
            $app->make(RoleDirectory::class),
            $app->make(Gate::class),
        ));

        $this->app->singleton(GeoLockManager::class);
        $this->app->alias(GeoLockManager::class, 'geo-lock');
    }

    public function packageBooted(): void
    {
        $this->registerCommands();
        $this->registerEventListeners();
        $this->registerPanelMiddleware();
    }

    protected function registerCommands(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            Console\Commands\CheckCommand::class,
            Console\Commands\DoctorCommand::class,
            Console\Commands\PruneCommand::class,
        ]);
    }

    protected function registerEventListeners(): void
    {
        $events = $this->app->make(Dispatcher::class);

        foreach ([GeoLockAccessDenied::class, GeoLockAccessGranted::class, GeoLockBypassed::class] as $event) {
            $events->listen($event, RecordGeoLockAttempt::class);
        }
    }

    /**
     * Guard every Filament panel with the middleware, unless the application
     * opted out (or uses the plugin on selected panels instead).
     */
    protected function registerPanelMiddleware(): void
    {
        if (! config('geo-lock.middleware.auto_register', true)) {
            return;
        }

        if (! class_exists(Panel::class)) {
            return;
        }

        Panel::configureUsing(function (Panel $panel): void {
            if (! config('geo-lock.enabled', true)) {
                return;
            }

            MiddlewareRegistry::apply($panel);
        });
    }

    protected function makeResolver(): GeoResolver
    {
        $driver = (string) config('geo-lock.resolver.driver', 'torann');
        $resolver = $this->resolveDriver($driver);

        $fallbacks = [];

        foreach ((array) config('geo-lock.resolver.fallbacks', []) as $fallback) {
            if (is_string($fallback) && $fallback !== '') {
                $fallbacks[] = $this->resolveDriver($fallback);
            }
        }

        if ($fallbacks !== []) {
            $resolver = new FallbackGeoResolver([$resolver, ...$fallbacks]);
        }

        if (config('geo-lock.resolver.cache.enabled', true)) {
            $resolver = new CacheGeoResolver(
                resolver: $resolver,
                cache: $this->app->make(CacheFactory::class),
                store: config('geo-lock.resolver.cache.store'),
                ttl: (int) config('geo-lock.resolver.cache.ttl', 86400),
                prefix: (string) config('geo-lock.resolver.cache.prefix', 'geo-lock:location'),
            );
        }

        return $resolver;
    }

    protected function resolveDriver(string $driver): GeoResolver
    {
        $class = config('geo-lock.resolver.drivers.' . $driver, $driver);

        if (! is_string($class) || ! class_exists($class)) {
            return new NullGeoResolver;
        }

        $resolver = $this->app->make($class);

        return $resolver instanceof GeoResolver ? $resolver : new NullGeoResolver;
    }
}
