<?php

declare(strict_types=1);

namespace GeoLock\Filament\Resources\GeoLockRules;

use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use GeoLock\Filament\Resources\GeoLockRules\Pages\CreateGeoLockRule;
use GeoLock\Filament\Resources\GeoLockRules\Pages\EditGeoLockRule;
use GeoLock\Filament\Resources\GeoLockRules\Pages\ListGeoLockRules;
use GeoLock\Filament\Resources\GeoLockRules\Schemas\GeoLockRuleForm;
use GeoLock\Filament\Resources\GeoLockRules\Tables\GeoLockRulesTable;
use GeoLock\Models\GeoLockRule;
use GeoLock\Support\Authorizer;
use Illuminate\Database\Eloquent\Model;

class GeoLockRuleResource extends Resource
{
    protected static ?string $model = GeoLockRule::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-globe-alt';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return GeoLockRuleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return GeoLockRulesTable::configure($table);
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListGeoLockRules::route('/'),
            'create' => CreateGeoLockRule::route('/create'),
            'edit' => EditGeoLockRule::route('/{record}/edit'),
        ];
    }

    public static function getNavigationLabel(): string
    {
        return (string) config('geo-lock.ui.rule_resource.label', __('geo-lock::geo-lock.navigation.rules'));
    }

    public static function getNavigationGroup(): ?string
    {
        $group = config('geo-lock.ui.navigation_group');

        return filled($group) ? (string) $group : null;
    }

    public static function getNavigationIcon(): string|\BackedEnum|null
    {
        return config('geo-lock.ui.navigation_icon', static::$navigationIcon);
    }

    public static function getNavigationSort(): ?int
    {
        $sort = config('geo-lock.ui.navigation_sort');

        return filled($sort) ? (int) $sort : null;
    }

    public static function getModelLabel(): string
    {
        return __('geo-lock::geo-lock.rules.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('geo-lock::geo-lock.rules.plural');
    }

    public static function canManage(): bool
    {
        return Authorizer::canManage(Authorizer::user());
    }

    public static function canViewAny(): bool
    {
        return static::canManage() && parent::canViewAny();
    }

    public static function canCreate(): bool
    {
        return static::canManage() && parent::canCreate();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canManage() && parent::canEdit($record);
    }

    public static function canDelete(Model $record): bool
    {
        return static::canManage() && parent::canDelete($record);
    }

    public static function canDeleteAny(): bool
    {
        return static::canManage() && parent::canDeleteAny();
    }

    public static function canReorder(): bool
    {
        return static::canManage() && parent::canReorder();
    }
}
