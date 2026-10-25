<?php

namespace Spiggle\GeoLock\Filament\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Toggle;
use Filament\Navigation\NavigationItem;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spiggle\GeoLock\Models\GeoLockRule;
use Spiggle\GeoLock\Services\RequestGeoLocator;
use Spiggle\GeoLock\Support\Countries;
use UnitEnum;

/**
 * @property-read Schema $form
 */
class GeoLockRules extends Page
{
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
                            ->options(Countries::options())
                            ->multiple()
                            ->searchable()
                            ->required(),
                        TagsInput::make('regions')
                            ->label('Specific Regions / States')
                            ->placeholder('Kingston')
                            ->helperText('Leave empty to match the whole country.'),
                        Toggle::make('is_active')
                            ->label('Rule Active')
                            ->default(true)
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->addActionLabel('Add New Rule')
                    ->itemLabel(function (array $state): ?string {
                        $type = ($state['type'] ?? '') === 'block' ? 'Block' : 'Allow';
                        $roles = collect($state['roles'] ?? [])->filter()->implode(', ');

                        return $roles !== '' ? $type.' · '.$roles : 'New rule';
                    })
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

    public static function canAccess(): bool
    {
        $user = auth()->user();

        if ($user === null || ! method_exists($user, 'hasAnyRole')) {
            return false;
        }

        $roles = array_map('strval', (array) config('geo-lock.manager_roles', ['super_admin']));

        return $roles !== [] && $user->hasAnyRole($roles);
    }

    public static function registerNavigationItems(): void
    {
        if (! static::shouldRegisterNavigation()) {
            return;
        }

        filament()->getCurrentOrDefaultPanel()
            ?->navigationItems(static::getNavigationItems());
    }

    /**
     * @return array<NavigationItem>
     */
    public static function getNavigationItems(): array
    {
        return array_map(
            fn (NavigationItem $item): NavigationItem => $item->visible(fn (): bool => static::canAccess()),
            parent::getNavigationItems(),
        );
    }

    private function roleField(): Select|TagsInput
    {
        if (! class_exists(Role::class)) {
            return TagsInput::make('roles')
                ->label('Apply to Roles')
                ->required()
                ->placeholder('Editor');
        }

        return Select::make('roles')
            ->label('Apply to Roles')
            ->options(fn (): array => Role::query()->orderBy('name')->pluck('name', 'name')->all())
            ->multiple()
            ->searchable()
            ->required()
            ->helperText('Which user roles should this rule affect?');
    }
}
