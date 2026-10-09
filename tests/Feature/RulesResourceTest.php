<?php

declare(strict_types=1);

use GeoLock\Filament\Pages\GeoLockSettings;
use GeoLock\Filament\Resources\GeoLockRules\GeoLockRuleResource;
use GeoLock\Filament\Resources\GeoLockRules\Pages\CreateGeoLockRule;
use GeoLock\Filament\Resources\GeoLockRules\Pages\EditGeoLockRule;
use GeoLock\Filament\Resources\GeoLockRules\Pages\ListGeoLockRules;
use GeoLock\Models\GeoLockRule;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->actingAs($this->createUser([], ['super_admin']));
});

it('renders the rule list page and shows the rules', function (): void {
    $rule = GeoLockRule::factory()->create(['name' => 'Block Jamaica']);

    Livewire::test(ListGeoLockRules::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$rule]);
});

it('creates a rule through the form', function (): void {
    Livewire::test(CreateGeoLockRule::class)
        ->fillForm([
            'name' => 'Created rule',
            'type' => 'blacklist',
            'countries' => ['JM'],
            'priority' => 3,
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(GeoLockRule::query()->where('name', 'Created rule')->exists())->toBeTrue();
});

it('updates a rule through the form', function (): void {
    $rule = GeoLockRule::factory()->create(['name' => 'Before']);

    Livewire::test(EditGeoLockRule::class, ['record' => $rule->getKey()])
        ->fillForm(['name' => 'After', 'priority' => 7])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($rule->fresh()->name)->toBe('After')
        ->and($rule->fresh()->priority)->toBe(7);
});

it('toggles a rule through the table action', function (): void {
    $rule = GeoLockRule::factory()->create(['is_active' => true]);

    Livewire::test(ListGeoLockRules::class)->callTableAction('toggleActive', $rule);

    expect($rule->fresh()->is_active)->toBeFalse();
});

it('hides the resource and settings page from unauthorized users', function (): void {
    config()->set('geo-lock.ui.authorization.manage', false);

    expect(GeoLockRuleResource::canViewAny())->toBeFalse()
        ->and(GeoLockRuleResource::canCreate())->toBeFalse()
        ->and(GeoLockSettings::canAccess())->toBeFalse();

    Livewire::test(GeoLockSettings::class)->assertStatus(403);
});

it('falls back to the translated navigation label when none is configured', function (): void {
    config()->set('geo-lock.ui.rule_resource.label', null);

    expect(GeoLockRuleResource::getNavigationLabel())->toBe(__('geo-lock::geo-lock.navigation.rules'));

    config()->set('geo-lock.ui.rule_resource.label', 'Access rules');

    expect(GeoLockRuleResource::getNavigationLabel())->toBe('Access rules');
});

it('falls back to the default slug when the configured slug is null', function (): void {
    config()->set('geo-lock.ui.settings_page.slug', null);

    expect(GeoLockSettings::getSlug())->toBe('geo-lock');
});
