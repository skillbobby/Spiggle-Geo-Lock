<?php

declare(strict_types=1);

namespace GeoLock\Filament\Widgets;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use GeoLock\Enums\GeoLockOutcome;
use GeoLock\Models\GeoLockAttempt;
use GeoLock\Support\Authorizer;
use GeoLock\Support\CountryData;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use Throwable;

class RecentGeoLockAttempts extends TableWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = null;

    public static function canView(): bool
    {
        if (! Authorizer::canManage(Authorizer::user())) {
            return false;
        }

        // The audit table is optional: hide the widget when it is missing
        // rather than breaking the page.
        try {
            return Schema::hasTable((string) config('geo-lock.audit.table', 'geo_lock_attempts'));
        } catch (Throwable) {
            return false;
        }
    }

    public function getHeading(): ?string
    {
        return __('geo-lock::geo-lock.widgets.recent.heading');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => GeoLockAttempt::query()->latestFirst())
            ->heading($this->getHeading())
            ->description(__('geo-lock::geo-lock.widgets.recent.description'))
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('geo-lock::geo-lock.widgets.recent.columns.when'))
                    ->since()
                    ->sortable(),

                TextColumn::make('outcome')
                    ->label(__('geo-lock::geo-lock.widgets.recent.columns.outcome'))
                    ->badge()
                    ->formatStateUsing(fn (GeoLockOutcome $state): string => $state->getLabel())
                    ->color(fn (GeoLockOutcome $state): string => $state->getColor())
                    ->icon(fn (GeoLockOutcome $state): string => $state->getIcon()),

                TextColumn::make('ip')
                    ->label(__('geo-lock::geo-lock.widgets.recent.columns.ip'))
                    ->fontFamily('mono')
                    ->searchable(),

                TextColumn::make('country_code')
                    ->label(__('geo-lock::geo-lock.widgets.recent.columns.location'))
                    ->formatStateUsing(fn (GeoLockAttempt $record): string => collect([
                        $record->city,
                        $record->regionLabel(),
                        $record->countryLabel(),
                    ])->filter()->implode(', ') ?: __('geo-lock::geo-lock.location.unknown')),

                TextColumn::make('roles')
                    ->label(__('geo-lock::geo-lock.widgets.recent.columns.roles'))
                    ->badge()
                    ->color('gray')
                    ->placeholder('-'),

                TextColumn::make('rule.name')
                    ->label(__('geo-lock::geo-lock.widgets.recent.columns.rule'))
                    ->placeholder('-'),

                TextColumn::make('url')
                    ->label(__('geo-lock::geo-lock.widgets.recent.columns.url'))
                    ->limit(40)
                    ->tooltip(fn (GeoLockAttempt $record): ?string => $record->url)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('outcome')
                    ->label(__('geo-lock::geo-lock.widgets.recent.columns.outcome'))
                    ->options(GeoLockOutcome::options()),

                SelectFilter::make('country_code')
                    ->label(__('geo-lock::geo-lock.widgets.recent.columns.country'))
                    ->options(fn (): array => $this->blockedCountries()),
            ])
            ->defaultPaginationPageOption(5)
            ->paginated([5, 10, 25])
            ->emptyStateHeading(__('geo-lock::geo-lock.widgets.recent.empty'))
            ->emptyStateDescription(__('geo-lock::geo-lock.widgets.recent.empty_description'));
    }

    /**
     * @return array<string, string>
     */
    protected function blockedCountries(): array
    {
        try {
            /** @var array<int, string> $codes */
            $codes = GeoLockAttempt::query()
                ->whereNotNull('country_code')
                ->distinct()
                ->orderBy('country_code')
                ->pluck('country_code')
                ->all();
        } catch (Throwable) {
            return [];
        }

        return CountryData::labelsFor($codes);
    }
}
