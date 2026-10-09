<?php

declare(strict_types=1);

namespace GeoLock\Filament\Resources\GeoLockRules\Tables;

use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use GeoLock\Enums\RuleType;
use GeoLock\Models\GeoLockRule;
use GeoLock\Support\Authorizer;
use Illuminate\Support\Collection;

final class GeoLockRulesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('geo-lock::geo-lock.table.name'))
                    ->searchable()
                    ->sortable()
                    ->placeholder(__('geo-lock::geo-lock.rules.unnamed'))
                    ->description(fn (GeoLockRule $record): ?string => $record->description)
                    ->wrap(),

                TextColumn::make('type')
                    ->label(__('geo-lock::geo-lock.table.type'))
                    ->badge()
                    ->formatStateUsing(fn (RuleType $state): string => $state->getLabel())
                    ->color(fn (RuleType $state): string => $state->getColor())
                    ->icon(fn (RuleType $state): string => $state->getIcon())
                    ->sortable(),

                TextColumn::make('roles')
                    ->label(__('geo-lock::geo-lock.table.roles'))
                    ->badge()
                    ->color('gray')
                    ->placeholder(__('geo-lock::geo-lock.table.every_role'))
                    ->state(fn (GeoLockRule $record): array => $record->roleLabels()),

                TextColumn::make('countries')
                    ->label(__('geo-lock::geo-lock.table.countries'))
                    ->badge()
                    ->color('primary')
                    ->limitList(3)
                    ->state(fn (GeoLockRule $record): Collection => $record->countryLabels()),

                TextColumn::make('regions')
                    ->label(__('geo-lock::geo-lock.table.regions'))
                    ->badge()
                    ->color('info')
                    ->limitList(2)
                    ->state(fn (GeoLockRule $record): Collection => $record->regionLabels()),

                TextColumn::make('priority')
                    ->label(__('geo-lock::geo-lock.table.priority'))
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                IconColumn::make('is_active')
                    ->label(__('geo-lock::geo-lock.table.is_active'))
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('danger')
                    ->sortable(),

                TextColumn::make('attempts_count')
                    ->label(__('geo-lock::geo-lock.table.blocks'))
                    ->counts('attempts')
                    ->numeric()
                    ->alignEnd()
                    ->toggleable(),

                TextColumn::make('updated_at')
                    ->label(__('geo-lock::geo-lock.table.updated_at'))
                    ->since()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('priority', 'desc')
            ->reorderable('priority')
            ->striped()
            ->filters([
                SelectFilter::make('type')
                    ->label(__('geo-lock::geo-lock.table.type'))
                    ->options(RuleType::options()),

                SelectFilter::make('roles')
                    ->label(__('geo-lock::geo-lock.table.roles'))
                    ->multiple()
                    ->options(fn (): array => app(\GeoLock\Contracts\RoleDirectory::class)->availableRoles())
                    ->query(fn ($query, array $state) => $query->when(
                        filled($state['values'] ?? null),
                        fn ($query) => $query->forRoles($state['values']),
                    )),

                TernaryFilter::make('is_active')
                    ->label(__('geo-lock::geo-lock.table.is_active')),
            ])
            ->recordActions([
                EditAction::make(),

                Action::make('toggleActive')
                    ->label(fn (GeoLockRule $record): string => $record->is_active
                        ? __('geo-lock::geo-lock.actions.deactivate')
                        : __('geo-lock::geo-lock.actions.activate'))
                    ->icon(fn (GeoLockRule $record): string => $record->is_active
                        ? 'heroicon-o-pause-circle'
                        : 'heroicon-o-play-circle')
                    ->color(fn (GeoLockRule $record): string => $record->is_active ? 'warning' : 'success')
                    ->requiresConfirmation()
                    ->visible(fn (): bool => Authorizer::canManage(auth()->user()))
                    ->action(function (GeoLockRule $record): void {
                        $record->update(['is_active' => ! $record->is_active]);
                    })
                    ->successNotificationTitle(__('geo-lock::geo-lock.actions.toggled')),

                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading(__('geo-lock::geo-lock.table.empty_heading'))
            ->emptyStateDescription(__('geo-lock::geo-lock.table.empty_description'));
    }
}
