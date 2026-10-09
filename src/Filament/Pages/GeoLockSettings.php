<?php

declare(strict_types=1);

namespace GeoLock\Filament\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use GeoLock\Contracts\GeoLockRuleRepository;
use GeoLock\Contracts\RoleDirectory;
use GeoLock\Data\GeoLockDecision;
use GeoLock\Enums\RuleType;
use GeoLock\Filament\Resources\GeoLockRules\Schemas\GeoLockRuleForm;
use GeoLock\Filament\Widgets\RecentGeoLockAttempts;
use GeoLock\Models\GeoLockRule;
use GeoLock\Services\GeoLockManager;
use GeoLock\Support\Authorizer;

/**
 * The centralized rules editor, shaped after the reference design: a
 * collapsible repeater of rules, a live summary of the current visitor and a
 * log of recent decisions.
 *
 * @property-read Schema $form
 */
class GeoLockSettings extends Page
{
    protected static ?string $title = null;

    protected string $view = 'geo-lock::pages.geo-lock-settings';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->fillForm();
    }

    public static function canAccess(): bool
    {
        return Authorizer::canManage(Authorizer::user());
    }

    public function getTitle(): string
    {
        return __('geo-lock::geo-lock.settings.title');
    }

    public function getSubheading(): ?string
    {
        return __('geo-lock::geo-lock.settings.subheading');
    }

    public static function getNavigationLabel(): string
    {
        return __('geo-lock::geo-lock.settings.navigation_label');
    }

    public static function getNavigationGroup(): ?string
    {
        $group = config('geo-lock.ui.navigation_group');

        return filled($group) ? (string) $group : null;
    }

    public static function getNavigationIcon(): string|BackedEnum|null
    {
        return config('geo-lock.ui.navigation_icon', 'heroicon-o-globe-alt');
    }

    public static function getNavigationSort(): ?int
    {
        $sort = config('geo-lock.ui.navigation_sort');

        return filled($sort) ? (int) $sort : null;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Repeater::make('rules')
                    ->hiddenLabel()
                    ->addActionLabel(__('geo-lock::geo-lock.settings.add_rule'))
                    ->collapsible()
                    ->cloneable()
                    ->reorderable()
                    ->itemLabel(fn (array $state): ?string => $this->ruleItemLabel($state))
                    ->schema(GeoLockRuleForm::ruleFields())
                    ->columns(2)
                    ->defaultItems(0)
                    ->columnSpanFull(),
            ])
            ->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        /** @var view-string $locationCardView */
        $locationCardView = 'geo-lock::components.location-card';

        return $schema
            ->components([
                View::make($locationCardView)
                    ->viewData(fn (): array => [
                        'decision' => $this->currentDecision(),
                        'rules' => $this->manager()->rules()->count(),
                        'activeRules' => $this->manager()->rules()->where('is_active', true)->count(),
                    ]),

                $this->getFormContentComponent(),
            ]);
    }

    public function getFormContentComponent(): Component
    {
        return Form::make([EmbeddedSchema::make('form')])
            ->id('form')
            ->livewireSubmitHandler('save')
            ->footer([
                Actions::make($this->getFormActions())
                    ->alignment(Alignment::Start)
                    ->key('form-actions'),
            ]);
    }

    /**
     * @return array<int, Action>
     */
    public function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label(__('geo-lock::geo-lock.settings.save'))
                ->submit('save')
                ->keyBindings(['mod+s'])
                ->visible(static::canAccess()),
        ];
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $data = $this->form->getState();

        /** @var array<int, array<string, mixed>> $rules */
        $rules = array_values(array_filter((array) ($data['rules'] ?? []), is_array(...)));

        $this->rules()->sync($rules);

        $this->fillForm();

        Notification::make()
            ->success()
            ->title(__('geo-lock::geo-lock.settings.saved'))
            ->send();
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('myLocation')
                ->label(__('geo-lock::geo-lock.actions.my_location'))
                ->icon('heroicon-o-map-pin')
                ->color('gray')
                ->action(function (): void {
                    $location = $this->manager()->resolve($this->manager()->clientIp(request()));

                    Notification::make()
                        ->title($location->label())
                        ->body(__('geo-lock::geo-lock.actions.my_location_body', [
                            'ip' => $location->ip,
                            'country' => $location->countryLabel() ?? __('geo-lock::geo-lock.location.unknown'),
                            'region' => $location->regionLabel() ?? '-',
                        ]))
                        ->info()
                        ->send();
                }),

            Action::make('simulate')
                ->label(__('geo-lock::geo-lock.actions.simulate'))
                ->icon('heroicon-o-beaker')
                ->color('gray')
                ->schema([
                    TextInput::make('ip')
                        ->label(__('geo-lock::geo-lock.actions.simulate_ip'))
                        ->default(fn (): string => (string) request()->ip())
                        ->required()
                        ->rules(['ip']),

                    Select::make('role')
                        ->label(__('geo-lock::geo-lock.actions.simulate_role'))
                        ->options(fn (): array => $this->roles()->availableRoles())
                        ->placeholder(__('geo-lock::geo-lock.actions.simulate_all_roles')),
                ])
                ->action(function (array $data): void {
                    $decision = $this->manager()->evaluate(
                        Authorizer::user(),
                        (string) $data['ip'],
                        request(),
                        filled($data['role'] ?? null) ? [(string) $data['role']] : null,
                        applyBypass: false,
                    );

                    Notification::make()
                        ->title($decision->outcome->getLabel())
                        ->body(__('geo-lock::geo-lock.actions.simulate_result', [
                            'location' => $decision->location->label(),
                            'message' => $decision->message(),
                        ]))
                        ->color($decision->outcome->getColor())
                        ->icon($decision->outcome->getIcon())
                        ->persistent()
                        ->send();
                }),

            Action::make('flush')
                ->label(__('geo-lock::geo-lock.actions.flush_cache'))
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->requiresConfirmation()
                ->action(function (): void {
                    $this->manager()->flush();

                    Notification::make()
                        ->success()
                        ->title(__('geo-lock::geo-lock.actions.flushed'))
                        ->send();
                }),
        ];
    }

    /**
     * @return array<int, class-string>
     */
    protected function getFooterWidgets(): array
    {
        return config('geo-lock.ui.widgets.recent_attempts', true) ? [RecentGeoLockAttempts::class] : [];
    }

    protected function fillForm(): void
    {
        $this->form->fill([
            'rules' => $this->rules()->all(activeOnly: false)
                ->map(static fn (GeoLockRule $rule): array => [
                    'id' => $rule->getKey(),
                    'name' => $rule->name,
                    'description' => $rule->description,
                    'roles' => $rule->roles ?? [],
                    'type' => $rule->type instanceof RuleType ? $rule->type->value : $rule->type,
                    'countries' => $rule->countries ?? [],
                    'regions' => $rule->regions ?? [],
                    'is_active' => (bool) $rule->is_active,
                    'priority' => (int) $rule->priority,
                ])
                ->values()
                ->all(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    protected function ruleItemLabel(array $state): ?string
    {
        $name = $state['name'] ?? null;

        if (filled($name)) {
            return (string) $name;
        }

        $type = RuleType::fromMixed(is_string($state['type'] ?? null) ? $state['type'] : null);

        return $type?->getLabel();
    }

    protected function currentDecision(): GeoLockDecision
    {
        return $this->manager()->evaluate(Authorizer::user());
    }

    protected function manager(): GeoLockManager
    {
        return app(GeoLockManager::class);
    }

    protected function rules(): GeoLockRuleRepository
    {
        return $this->manager()->repository();
    }

    protected function roles(): RoleDirectory
    {
        return $this->manager()->roleDirectory();
    }

    public static function getSlug(?Panel $panel = null): string
    {
        $slug = config('geo-lock.ui.settings_page.slug');

        return filled($slug) ? (string) $slug : 'geo-lock';
    }
}
