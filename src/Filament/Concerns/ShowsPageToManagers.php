<?php

namespace Spiggle\GeoLock\Filament\Concerns;

use Filament\Navigation\NavigationItem;

trait ShowsPageToManagers
{
    public static function canAccess(): bool
    {
        $user = auth()->user();

        if ($user === null || ! method_exists($user, 'hasAnyRole')) {
            return false;
        }

        $roles = array_map('strval', (array) config('geo-lock.manager_roles', ['super_admin']));

        return $roles !== [] && $user->hasAnyRole($roles);
    }

    public static function registerNavigationItems(): void
    {
        if (! static::shouldRegisterNavigation()) {
            return;
        }

        filament()->getCurrentOrDefaultPanel()
            ?->navigationItems(static::getNavigationItems());
    }

    /**
     * @return array<NavigationItem>
     */
    public static function getNavigationItems(): array
    {
        return array_map(
            fn (NavigationItem $item): NavigationItem => $item->visible(fn (): bool => static::canAccess()),
            parent::getNavigationItems(),
        );
    }
}
