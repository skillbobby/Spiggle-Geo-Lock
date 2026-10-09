<?php

declare(strict_types=1);

namespace GeoLock\Support;

use Filament\Panel;
use GeoLock\Http\Middleware\EnforceGeoLock;

/**
 * Makes sure the {@see EnforceGeoLock} middleware is only ever registered once
 * per panel, whether it comes from the automatic registration or from the
 * `GeoLockPlugin`.
 */
final class MiddlewareRegistry
{
    /**
     * @var array<string, bool>
     */
    protected static array $applied = [];

    public static function apply(Panel $panel): bool
    {
        $id = $panel->getId();

        if (self::$applied[$id] ?? false) {
            return false;
        }

        self::$applied[$id] = true;

        $panel->middleware(
            [EnforceGeoLock::class],
            isPersistent: (bool) config('geo-lock.middleware.persistent', true),
        );

        return true;
    }

    public static function applied(?string $panelId = null): bool|array
    {
        return $panelId === null ? self::$applied : (self::$applied[$panelId] ?? false);
    }

    public static function flush(): void
    {
        self::$applied = [];
    }
}
