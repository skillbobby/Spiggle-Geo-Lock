<?php

declare(strict_types=1);

namespace GeoLock\Filament\Resources\GeoLockRules\Schemas;

use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use GeoLock\Contracts\RoleDirectory;
use GeoLock\Enums\RuleType;
use GeoLock\Support\CountryData;
use GeoLock\Support\RegionData;

/**
 * The form used by the rules resource and the settings page repeater.
 */
final class GeoLockRuleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('geo-lock::geo-lock.form.sections.rule'))
                    ->description(__('geo-lock::geo-lock.form.sections.rule_description'))
                    ->columns(2)
                    ->schema(self::ruleFields()),
            ]);
    }

    /**
     * @return array<int, mixed>
     */
    public static function ruleFields(): array
    {
        return [
            Hidden::make('id'),

            TextInput::make('name')
                ->label(__('geo-lock::geo-lock.form.name'))
                ->placeholder(__('geo-lock::geo-lock.form.name_placeholder'))
                ->maxLength(255),

            Select::make('type')
                ->label(__('geo-lock::geo-lock.form.type'))
                ->options(RuleType::options())
                ->default(RuleType::Blacklist->value)
                ->required()
                ->selectablePlaceholder(false)
                ->native(false)
                ->live()
                ->helperText(fn (mixed $state): string => (RuleType::fromMixed(is_string($state) ? $state : null)?->getDescription()) ?? ''),

            self::rolesField(),

            TextInput::make('priority')
                ->label(__('geo-lock::geo-lock.form.priority'))
                ->helperText(__('geo-lock::geo-lock.form.priority_helper'))
                ->numeric()
                ->default(0)
                ->required(),

            Select::make('countries')
                ->label(__('geo-lock::geo-lock.form.countries'))
                ->helperText(__('geo-lock::geo-lock.form.countries_helper'))
                ->multiple()
                ->required()
                ->native(false)
                ->live()
                ->searchable()
                ->getSearchResultsUsing(fn (string $search): array => CountryData::search($search))
                ->getOptionLabelsUsing(fn (array $values): array => CountryData::labelsFor($values))
                ->options(fn (): array => CountryData::options()),

            Select::make('regions')
                ->label(__('geo-lock::geo-lock.form.regions'))
                ->helperText(__('geo-lock::geo-lock.form.regions_helper'))
                ->multiple()
                ->native(false)
                ->searchable()
                ->visible(fn (Get $get): bool => filled($get('countries')))
                ->options(fn (Get $get): array => RegionData::groupedOptions($get('countries')))
                ->getSearchResultsUsing(fn (string $search, Get $get): array => RegionData::search($get('countries'), $search))
                ->getOptionLabelsUsing(fn (array $values): array => RegionData::labelsFor($values)),

            Toggle::make('is_active')
                ->label(__('geo-lock::geo-lock.form.is_active'))
                ->helperText(__('geo-lock::geo-lock.form.is_active_helper'))
                ->default(true),

            Textarea::make('description')
                ->label(__('geo-lock::geo-lock.form.description'))
                ->rows(2)
                ->columnSpanFull(),
        ];
    }

    /**
     * Roles are a multi-select when the application exposes a role list, and
     * free text tags otherwise.
     */
    protected static function rolesField(): TagsInput|Select
    {
        $options = app(RoleDirectory::class)->availableRoles();

        if ($options === []) {
            return TagsInput::make('roles')
                ->label(__('geo-lock::geo-lock.form.roles'))
                ->helperText(__('geo-lock::geo-lock.form.roles_free_text_helper'))
                ->placeholder(__('geo-lock::geo-lock.form.roles_placeholder'));
        }

        return Select::make('roles')
            ->label(__('geo-lock::geo-lock.form.roles'))
            ->helperText(__('geo-lock::geo-lock.form.roles_helper'))
            ->options($options)
            ->multiple()
            ->native(false)
            ->searchable()
            ->preload();
    }
}
