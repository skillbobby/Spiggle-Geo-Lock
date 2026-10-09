<?php

declare(strict_types=1);

use GeoLock\Filament\Pages\GeoLockSettings;
use GeoLock\Models\GeoLockRule;
use Livewire\Livewire;

it('renders the settings page for an authorized user', function (): void {
    $user = $this->createUser([], ['super_admin']);

    $this->actingAs($user);

    Livewire::test(GeoLockSettings::class)
        ->assertOk()
        ->assertSee(__('geo-lock::geo-lock.settings.title'));
});

it('saves rules from the repeater', function (): void {
    $user = $this->createUser([], ['super_admin']);

    $this->actingAs($user);

    Livewire::test(GeoLockSettings::class)
        ->fillForm([
            'rules' => [
                [
                    'name' => 'Block Jamaica',
                    'type' => 'blacklist',
                    'roles' => ['editor'],
                    'countries' => ['JM'],
                    'regions' => ['JM-01'],
                    'is_active' => true,
                    'priority' => 5,
                ],
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(GeoLockRule::query()->count())->toBe(1);

    $rule = GeoLockRule::query()->first();

    expect($rule->name)->toBe('Block Jamaica')
        ->and($rule->countries)->toBe(['JM'])
        ->and($rule->regions)->toBe(['JM-01'])
        ->and($rule->roles)->toBe(['editor'])
        ->and($rule->priority)->toBe(5);
});
