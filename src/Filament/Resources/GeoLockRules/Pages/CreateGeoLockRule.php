<?php

declare(strict_types=1);

namespace GeoLock\Filament\Resources\GeoLockRules\Pages;

use Filament\Resources\Pages\CreateRecord;
use GeoLock\Filament\Resources\GeoLockRules\GeoLockRuleResource;

class CreateGeoLockRule extends CreateRecord
{
    protected static string $resource = GeoLockRuleResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    public function getTitle(): string
    {
        return __('geo-lock::geo-lock.rules.create');
    }
}
