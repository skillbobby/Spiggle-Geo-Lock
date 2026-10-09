<?php

declare(strict_types=1);

namespace GeoLock\Filament\Resources\GeoLockRules\Pages;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use GeoLock\Filament\Pages\GeoLockSettings;
use GeoLock\Filament\Resources\GeoLockRules\GeoLockRuleResource;

class ListGeoLockRules extends ListRecords
{
    protected static string $resource = GeoLockRuleResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('settings')
                ->label(__('geo-lock::geo-lock.actions.settings'))
                ->icon('heroicon-o-adjustments-horizontal')
                ->color('gray')
                ->url(fn (): string => GeoLockSettings::getUrl())
                ->visible(fn (): bool => GeoLockSettings::canAccess()),

            CreateAction::make()
                ->label(__('geo-lock::geo-lock.actions.new_rule'))
                ->icon('heroicon-o-plus'),
        ];
    }
}
