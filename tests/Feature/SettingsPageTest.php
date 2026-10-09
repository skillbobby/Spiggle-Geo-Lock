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

it('runs the myLocation header action and sends a notification', function (): void {
    $user = $this->createUser([], ['super_admin']);

    $this->actingAs($user);

    fakeGeo(['127.0.0.1' => ['country_code' => 'US', 'region_code' => 'US-FL']]);

    Livewire::test(GeoLockSettings::class)
        ->callAction('myLocation')
        ->assertNotified();
});

it('runs the simulate header action and sends a notification', function (): void {
    $user = $this->createUser([], ['super_admin']);

    $this->actingAs($user);

    fakeGeo(['8.8.8.8' => ['country_code' => 'JM']]);

    Livewire::test(GeoLockSettings::class)
        ->callAction('simulate', ['ip' => '8.8.8.8'])
        ->assertNotified();
});

it('renders the header and footer widgets without errors', function (): void {
    $user = $this->createUser([], ['super_admin']);

    $this->actingAs($user);

    $component = Livewire::test(GeoLockSettings::class)->assertOk();

    $page = $component->instance();

    $header = new ReflectionMethod($page, 'getHeaderWidgets');
    $header->setAccessible(true);

    $footer = new ReflectionMethod($page, 'getFooterWidgets');
    $footer->setAccessible(true);

    expect($header->invoke($page))->toBeArray()
        ->and($footer->invoke($page))->toBeArray();
});
