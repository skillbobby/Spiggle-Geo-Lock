<?php

declare(strict_types=1);

namespace GeoLock\Support;

use Filament\Facades\Filament;
use Illuminate\Support\Arr;

/**
 * Decides which panels the middleware should guard, based on the
 * `geo-lock.middleware.panels` and `geo-lock.middleware.except_panels`
 * configuration values.
 */
final class PanelScope
{
    public static function matches(?string $panelId): bool
    {
        $only = self::ids('panels');
        $except = self::ids('except_panels');

        if ($panelId !== null && in_array($panelId, $except, strict: true)) {
            return false;
        }

        if ($only === []) {
            return true;
        }

        return $panelId !== null && in_array($panelId, $only, strict: true);
    }

    /**
     * The id of the panel the current request belongs to, when there is one.
     */
    public static function currentPanelId(): ?string
    {
        if (! class_exists(Filament::class)) {
            return null;
        }

        try {
            return Filament::getCurrentPanel()?->getId();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<int, string>
     */
    protected static function ids(string $key): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $value): string => is_scalar($value) ? (string) $value : '',
            Arr::wrap(config('geo-lock.middleware.' . $key, [])),
        ), static fn (string $value): bool => $value !== ''));
    }
}
