<?php

namespace Spiggle\GeoLock\Filament\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spiggle\GeoLock\Filament\Concerns\ShowsPageToManagers;
use Spiggle\GeoLock\Models\GeoLockRule;
use Spiggle\GeoLock\Services\RequestGeoLocator;
use Spiggle\GeoLock\Support\Countries;
use UnitEnum;

/**
 * @property-read Schema $form
 */
class GeoLockRules extends Page
{
    use ShowsPageToManagers;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    protected static ?string $slug = 'geo-lock-rules';

    protected static ?string $title = 'Geo Lock Rules';

    protected static ?string $navigationLabel = 'Geo Lock Rules';

    protected static string|UnitEnum|null $navigationGroup = 'Access';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static ?int $navigationSort = 40;

    public function mount(): void
    {
        $this->form->fill([
            'rules' => GeoLockRule::query()
                ->orderBy('sort')
                ->get()
                ->map(fn (GeoLockRule $rule): array => [
                    'roles' => array_values((array) $rule->roles),
                    'type' => $rule->type,
                    'countries' => array_values((array) $rule->countries),
                    'regions' => array_values((array) $rule->regions),
                    'is_active' => $rule->is_active,
                ])
                ->all(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Placeholder::make('detected_location')
                    ->label('Detected location')
                    ->content(fn (): string => app(RequestGeoLocator::class)->locate(request())->label())
                    ->columnSpanFull(),
                Repeater::make('rules')
                    ->label('Configure Access Rules')
                    ->helperText('Define which roles can access the panel from specific geographical regions.')
                    ->schema([
                        $this->roleField(),
                        Select::make('type')
                            ->label('Rule Type')
                            ->options([
                                'allow' => 'Allow Only These Locations (Whitelist)',
                                'block' => 'Block These Locations (Blacklist)',
                            ])
                            ->required()
                            ->native(false),
                        Select::make('countries')
                            ->label('Countries')
                            ->options(['*' => 'All countries (*)'] + Countries::options())
                            ->multiple()
                            ->searchable()
                            ->required()
                            ->helperText('Use * for every country.'),
                        TagsInput::make('regions')
                            ->label('Specific Regions / States')
                            ->placeholder('Kingston')
                            ->helperText('Leave empty to match the whole country. Use * for every region.'),
                        Toggle::make('is_active')
                            ->label('Rule Active')
                            ->default(true)
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->collapsed(function (?Schema $item): bool {
                        $state = $item?->getRawState();

                        if (! is_array($state)) {
                            return true;
                        }

                        $roles = array_values(array_filter((array) ($state['roles'] ?? [])));

                        return $roles !== [] && in_array($state['type'] ?? null, ['allow', 'block'], true);
                    })
                    ->addAction(function (Action $action): Action {
                        return $action->action(function (Repeater $component): void {
                            $newUuid = $component->generateUuid();
                            $items = $component->getRawState();

                            if ($newUuid) {
                                $items[$newUuid] = [];
                            } else {
                                $items[] = [];
                            }

                            $component->rawState($items);
                            $component->getChildSchema($newUuid ?? array_key_last($items))->fill();
                            $component->callAfterStateUpdated();

                            if ($component->shouldPartiallyRenderAfterActionsCalled()) {
                                $component->partiallyRender();
                            }
                        });
                    })
                    ->addActionLabel('Add New Rule')
                    ->itemLabel(fn (array $state): string => static::summary($state))
                    ->columnSpanFull(),
            ])
            ->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')
                            ->label('Save changes')
                            ->submit('save'),
                    ]),
                ]),
        ]);
    }

    public function save(): void
    {
        $rules = $this->form->getState()['rules'] ?? [];

        DB::transaction(function () use ($rules): void {
            GeoLockRule::query()->delete();

            foreach (array_values($rules) as $index => $rule) {
                GeoLockRule::query()->create([
                    'roles' => array_values((array) ($rule['roles'] ?? [])),
                    'type' => $rule['type'],
                    'countries' => array_values(array_map(
                        fn (mixed $country): string => strtoupper((string) $country),
                        (array) ($rule['countries'] ?? []),
                    )),
                    'regions' => array_values(array_filter(array_map(
                        fn (mixed $region): string => trim((string) $region),
                        (array) ($rule['regions'] ?? []),
                    ))),
                    'is_active' => (bool) ($rule['is_active'] ?? true),
                    'sort' => $index,
                ]);
            }
        });

        Notification::make()
            ->title('Geo lock rules saved')
            ->success()
            ->send();
    }

    private function roleField(): Select|TagsInput
    {
        if (! class_exists(Role::class)) {
            return TagsInput::make('roles')
                ->label('Apply to Roles')
                ->required()
                ->placeholder('Editor')
                ->helperText('Use * for every role.');
        }

        return Select::make('roles')
            ->label('Apply to Roles')
            ->options(fn (): array => ['*' => 'All roles (*)'] + Role::query()->orderBy('name')->pluck('name', 'name')->all())
            ->multiple()
            ->searchable()
            ->required()
            ->helperText('Which user roles should this rule affect? Use * for every role.');
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public static function summary(array $state): string
    {
        $roles = array_values(array_filter(array_map(
            fn (mixed $role): string => trim((string) $role),
            (array) ($state['roles'] ?? []),
        )));
        $type = $state['type'] ?? null;

        if ($roles === [] || ! in_array($type, ['allow', 'block'], true)) {
            return 'New rule';
        }

        $status = ($state['is_active'] ?? true) ? 'Active' : 'Inactive';
        $verb = $type === 'block' ? 'Block' : 'Allow';
        $who = in_array('*', $roles, true) ? 'all roles' : implode(', ', $roles);
        $countries = array_values(array_filter(array_map(
            fn (mixed $country): string => strtoupper(trim((string) $country)),
            (array) ($state['countries'] ?? []),
        )));
        $where = in_array('*', $countries, true)
            ? 'all countries'
            : implode(', ', array_map(fn (string $code): string => Countries::name($code), $countries));
        $regions = array_values(array_filter(array_map(
            fn (mixed $region): string => trim((string) $region),
            (array) ($state['regions'] ?? []),
        )));
        $regionText = '';

        if (in_array('*', $regions, true)) {
            $regionText = ' · all regions';
        } elseif ($regions !== []) {
            $regionText = ' · '.implode(', ', $regions);
        }

        $place = $where !== '' ? ' · '.$where : '';

        return "{$status} · {$verb} {$who}{$place}{$regionText}";
    }
}
