<?php

namespace Spiggle\GeoLock\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Spiggle\GeoLock\Filament\Concerns\ShowsPageToManagers;
use Spiggle\GeoLock\Models\GeoLockEvent;
use Spiggle\GeoLock\Support\Countries;
use UnitEnum;

class GeoLockLog extends Page implements HasTable
{
    use InteractsWithTable;
    use ShowsPageToManagers;

    protected static ?string $slug = 'geo-lock-log';

    protected static ?string $title = 'Geo Lock Log';

    protected static ?string $navigationLabel = 'Geo Lock Log';

    protected static string|UnitEnum|null $navigationGroup = 'Access';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?int $navigationSort = 41;

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => GeoLockEvent::query())
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('user_label')
                    ->label('User')
                    ->searchable()
                    ->placeholder('Unknown user'),
                TextColumn::make('role_list')
                    ->label('Roles')
                    ->state(fn (GeoLockEvent $record): string => implode(', ', $record->roles) ?: 'None'),
                TextColumn::make('place')
                    ->label('Location')
                    ->state(fn (GeoLockEvent $record): string => $this->place($record)),
                TextColumn::make('reason')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === 'blocked' ? 'Blocked' : 'Outside allow list'),
                TextColumn::make('ip')
                    ->label('IP')
                    ->searchable()
                    ->placeholder('Unknown'),
            ])
            ->recordAction(null)
            ->emptyStateHeading('No denials yet')
            ->emptyStateDescription('A blocked visit is recorded here. Allowed visits are not.')
            ->paginated([25, 50]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedTable::make(),
        ]);
    }

    private function place(GeoLockEvent $record): string
    {
        if ($record->country === null || $record->country === '') {
            return 'Country unknown';
        }

        $country = Countries::name($record->country).' ('.$record->country.')';

        if ($record->region === null || $record->region === '') {
            return $country;
        }

        return $record->region.', '.$country;
    }
}
