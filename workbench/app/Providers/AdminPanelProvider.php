<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Filament\Panel;
use Filament\PanelProvider;
use GeoLock\GeoLockPlugin;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->colors([
                'primary' => 'amber',
            ])
            ->plugin(GeoLockPlugin::make())
            ->authGuard('web');
    }
}
