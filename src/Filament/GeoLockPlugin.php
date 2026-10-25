<?php

namespace Spiggle\GeoLock\Filament;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Spiggle\GeoLock\Filament\Pages\GeoLockRules;
use Spiggle\GeoLock\Http\Middleware\EnforceGeoLock;

class GeoLockPlugin implements Plugin
{
    public static function make(): static
    {
        return app(static::class);
    }

    public static function get(): static
    {
        /** @var static $plugin */
        $plugin = filament(app(static::class)->getId());

        return $plugin;
    }

    public function getId(): string
    {
        return 'spiggle-geo-lock';
    }

    public function register(Panel $panel): void
    {
        $panel
            ->pages([
                GeoLockRules::class,
            ])
            ->authMiddleware([
                EnforceGeoLock::class,
            ], isPersistent: true);
    }

    public function boot(Panel $panel): void
    {
        //
    }
}
