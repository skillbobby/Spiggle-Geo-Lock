<?php

declare(strict_types=1);

namespace GeoLock\Filament\Resources\GeoLockRules\Pages;

use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use GeoLock\Filament\Resources\GeoLockRules\GeoLockRuleResource;
use GeoLock\Models\GeoLockRule;

class EditGeoLockRule extends EditRecord
{
    protected static string $resource = GeoLockRuleResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    public function getTitle(): string
    {
        return __('geo-lock::geo-lock.rules.edit', ['name' => $this->getRecord()->label()]);
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    public function getRecord(): GeoLockRule
    {
        /** @var GeoLockRule $record */
        $record = parent::getRecord();

        return $record;
    }
}
