<?php

declare(strict_types=1);

use GeoLock\Enums\GeoLockOutcome;
use GeoLock\Facades\GeoLock;
use GeoLock\Filament\Pages\GeoLockSettings;
use GeoLock\Models\GeoLockRule;
use GeoLock\Services\GeoLockManager;

it('boots the plugin and exposes the manager', function (): void {
    expect(app(GeoLockManager::class))->toBeInstanceOf(GeoLockManager::class)
        ->and(GeoLock::isEnabled())->toBeTrue();
});

it('denies a blacklisted visitor', function (): void {
    GeoLockRule::factory()->blacklist()->forCountries(['JM'])->forEveryRole()->create();

    fakeGeo(['8.8.8.8' => ['country_code' => 'JM']]);

    $decision = GeoLock::evaluate(null, '8.8.8.8');

    expect($decision->outcome)->toBe(GeoLockOutcome::DeniedBlacklist)
        ->and($decision->denied())->toBeTrue();
});

it('allows a visitor outside of the blacklist', function (): void {
    GeoLockRule::factory()->blacklist()->forCountries(['JM'])->forEveryRole()->create();

    fakeGeo(['8.8.8.8' => ['country_code' => 'US', 'region_code' => 'US-FL']]);

    $decision = GeoLock::evaluate(null, '8.8.8.8');

    expect($decision->allowed())->toBeTrue()
        ->and($decision->location->regionCode)->toBe('US-FL');
});

it('registers the settings page on the panel', function (): void {
    expect(GeoLockSettings::getUrl())->toContain('geo-lock');
});
