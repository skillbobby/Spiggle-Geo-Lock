<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use GeoLock\Support\Authorizer;
use GeoLock\Support\CountryData;
use GeoLock\Support\IpAddress;
use GeoLock\Support\MiddlewareRegistry;
use GeoLock\Support\RegionData;
use Illuminate\Support\Facades\Gate;

describe('IpAddress', function (): void {
    it('validates addresses', function (): void {
        expect(IpAddress::isValid('8.8.8.8'))->toBeTrue()
            ->and(IpAddress::isValid('2001:4860:4860::8888'))->toBeTrue()
            ->and(IpAddress::isValid('not-an-ip'))->toBeFalse()
            ->and(IpAddress::isValid(123))->toBeFalse();
    });

    it('detects public and private addresses', function (): void {
        expect(IpAddress::isPublic('8.8.8.8'))->toBeTrue()
            ->and(IpAddress::isPublic('192.168.1.1'))->toBeFalse()
            ->and(IpAddress::isPublic('127.0.0.1'))->toBeFalse()
            ->and(IpAddress::isPrivate('10.0.0.1'))->toBeTrue()
            ->and(IpAddress::isPrivate('8.8.8.8'))->toBeFalse();
    });

    it('anonymises addresses', function (): void {
        expect(IpAddress::anonymize('8.8.8.8'))->toBe('8.8.8.0')
            ->and(IpAddress::anonymize('2001:db8::1'))->toBe('2001:db8::0')
            ->and(IpAddress::anonymize('invalid'))->toBe('invalid');
    });
});

describe('CountryData', function (): void {
    it('exposes names, labels and the full list', function (): void {
        expect(CountryData::name('US'))->toBe('United States')
            ->and(CountryData::label('JM'))->toBe('Jamaica (JM)')
            ->and(CountryData::exists('US'))->toBeTrue()
            ->and(CountryData::exists('ZZ'))->toBeFalse()
            ->and(CountryData::count())->toBe(249);
    });

    it('builds the flag emoji of a country', function (): void {
        expect(CountryData::flag('US'))->toBe("\u{1F1FA}\u{1F1F8}")
            ->and(CountryData::flag('JM'))->toBe("\u{1F1EF}\u{1F1F2}")
            ->and(CountryData::flag('us'))->toBe("\u{1F1FA}\u{1F1F8}")
            ->and(CountryData::flag('ZZ'))->toBeNull()
            ->and(CountryData::flag(null))->toBeNull();
    });

    it('searches the country list', function (): void {
        expect(CountryData::search('jam'))->toHaveKey('JM');
    });

    it('builds labels and grouped options', function (): void {
        expect(CountryData::labelsFor(['US']))->toBe(['US' => 'United States (US)'])
            ->and(CountryData::options())->toHaveKey('US')
            ->and(CountryData::groupedOptions())->not->toBeEmpty();
    });
});

describe('RegionData', function (): void {
    it('looks up subdivisions', function (): void {
        expect(RegionData::name('US-FL'))->toBe('Florida')
            ->and(RegionData::label('US-FL'))->toBe('Florida (US)')
            ->and(RegionData::countryOf('US-FL'))->toBe('US')
            ->and(RegionData::exists('US-FL'))->toBeTrue()
            ->and(RegionData::exists('ZZ-XX'))->toBeFalse()
            ->and(RegionData::name('JM-01'))->toBe('Kingston')
            ->and(RegionData::name('GB-ENG'))->toBe('England');
    });

    it('lists subdivisions per country', function (): void {
        expect(RegionData::forCountry('US'))->toHaveKey('US-FL')
            ->and(RegionData::forCountries(['US', 'JM']))->toHaveKey('JM-01')
            ->and(RegionData::groupedOptions(['US']))->toHaveKey('United States (US)');
    });

    it('normalises raw subdivision codes', function (): void {
        expect(RegionData::match('US', 'FL'))->toBe('US-FL')
            ->and(RegionData::match('US', 'fl'))->toBe('US-FL')
            ->and(RegionData::match('US', 'USA-FL'))->toBe('US-FL')
            ->and(RegionData::match('US', '', 'Florida'))->toBe('US-FL')
            ->and(RegionData::match('US', 'ZZ'))->toBe('US-ZZ')
            ->and(RegionData::match('', 'FL'))->toBeNull();
    });

    it('searches and labels subdivisions', function (): void {
        expect(RegionData::search(['US'], 'florida'))->toHaveKey('US-FL')
            ->and(RegionData::labelsFor(['US-FL']))->toBe(['US-FL' => 'Florida (US)']);
    });
});

describe('Authorizer', function (): void {
    it('allows everybody when no rule is configured', function (): void {
        config()->set('geo-lock.ui.authorization.manage', null);

        expect(Authorizer::canManage(null))->toBeTrue();
    });

    it('honours a boolean rule', function (): void {
        config()->set('geo-lock.ui.authorization.manage', false);
        expect(Authorizer::canManage(null))->toBeFalse();

        config()->set('geo-lock.ui.authorization.manage', true);
        expect(Authorizer::canManage(null))->toBeTrue();
    });

    it('honours a gate ability', function (): void {
        config()->set('geo-lock.ui.authorization.manage', 'manage-geo-lock');
        Gate::define('manage-geo-lock', fn ($user): bool => true);

        $user = $this->createUser();

        expect(Authorizer::canManage($user))->toBeTrue()
            ->and(Authorizer::canManage(null))->toBeFalse();
    });

    it('honours a closure rule', function (): void {
        config()->set('geo-lock.ui.authorization.manage', fn ($user): bool => $user === null);
        expect(Authorizer::canManage(null))->toBeTrue();

        config()->set('geo-lock.ui.authorization.manage', fn ($user): bool => $user !== null);
        expect(Authorizer::canManage(null))->toBeFalse();
    });
});

describe('MiddlewareRegistry', function (): void {
    it('registers the middleware only once per panel', function (): void {
        MiddlewareRegistry::flush();

        $panel = Filament::getPanel('admin');

        expect(MiddlewareRegistry::applied('admin'))->toBeFalse()
            ->and(MiddlewareRegistry::apply($panel))->toBeTrue()
            ->and(MiddlewareRegistry::apply($panel))->toBeFalse()
            ->and(MiddlewareRegistry::applied('admin'))->toBeTrue();
    });
});
