<?php

declare(strict_types=1);

use GeoLock\Contracts\GeoResolver;
use GeoLock\Data\GeoLocation;
use GeoLock\Resolvers\ArrayGeoResolver;
use GeoLock\Resolvers\CacheGeoResolver;
use GeoLock\Resolvers\FallbackGeoResolver;
use GeoLock\Resolvers\NullGeoResolver;
use GeoLock\Resolvers\TorannGeoResolver;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Torann\GeoIP\GeoIP;
use Torann\GeoIP\Location;

describe('ArrayGeoResolver', function (): void {
    it('resolves an exact match and normalises the region', function (): void {
        $resolver = new ArrayGeoResolver([
            '8.8.8.8' => ['country_code' => 'US', 'region_code' => 'FL', 'city' => 'Miami'],
        ]);

        $location = $resolver->resolve('8.8.8.8');

        expect($location?->countryCode)->toBe('US')
            ->and($location?->regionCode)->toBe('US-FL')
            ->and($location?->city)->toBe('Miami')
            ->and($resolver->name())->toBe('array');
    });

    it('falls back to the wildcard entry', function (): void {
        $resolver = new ArrayGeoResolver(['*' => 'JM']);

        expect($resolver->resolve('1.2.3.4')?->countryCode)->toBe('JM');
    });

    it('accepts a string shorthand', function (): void {
        $resolver = new ArrayGeoResolver(['8.8.8.8' => 'JM']);

        expect($resolver->resolve('8.8.8.8')?->countryCode)->toBe('JM');
    });

    it('returns an unknown location for an unmapped address', function (): void {
        $location = (new ArrayGeoResolver)->resolve('8.8.8.8');

        expect($location?->isResolved())->toBeFalse()
            ->and($location?->resolver)->toBe('array');
    });

    it('supports put, fill, forget and isEmpty', function (): void {
        $resolver = new ArrayGeoResolver;

        expect($resolver->isEmpty())->toBeTrue();

        $resolver->put('8.8.8.8', 'US')->fill(['1.1.1.1' => 'GB']);

        expect($resolver->isEmpty())->toBeFalse()
            ->and($resolver->resolve('1.1.1.1')?->countryCode)->toBe('GB');

        $resolver->forget('8.8.8.8');

        expect($resolver->resolve('8.8.8.8')?->isResolved())->toBeFalse();
    });

    it('accepts a GeoLocation instance', function (): void {
        $resolver = new ArrayGeoResolver(['8.8.8.8' => new GeoLocation(ip: '8.8.8.8', countryCode: 'US')]);

        expect($resolver->resolve('8.8.8.8')?->countryCode)->toBe('US');
    });
});

it('never resolves anything with the null resolver', function (): void {
    $resolver = new NullGeoResolver;

    expect($resolver->resolve('8.8.8.8')?->isResolved())->toBeFalse()
        ->and($resolver->name())->toBe('null');
});

describe('FallbackGeoResolver', function (): void {
    it('skips resolvers that return nothing', function (): void {
        $resolver = new FallbackGeoResolver([new NullGeoResolver, new ArrayGeoResolver(['8.8.8.8' => 'US'])]);

        expect($resolver->resolve('8.8.8.8')?->countryCode)->toBe('US')
            ->and($resolver->name())->toBe('fallback(null,array)');
    });

    it('swallows exceptions and tries the next resolver', function (): void {
        $throwing = new class implements GeoResolver
        {
            public function resolve(string $ip): ?GeoLocation
            {
                throw new RuntimeException('geoip unavailable');
            }

            public function name(): string
            {
                return 'boom';
            }
        };

        $resolver = new FallbackGeoResolver([$throwing, new ArrayGeoResolver(['8.8.8.8' => 'JM'])]);

        expect($resolver->resolve('8.8.8.8')?->countryCode)->toBe('JM');
    });

    it('returns the last location when nothing resolves', function (): void {
        $resolver = new FallbackGeoResolver([new NullGeoResolver, new NullGeoResolver]);

        expect($resolver->resolve('8.8.8.8')?->isResolved())->toBeFalse();
    });
});

describe('CacheGeoResolver', function (): void {
    it('caches a resolved location and forgets it on demand', function (): void {
        $inner = new class(['8.8.8.8' => 'US']) implements GeoResolver
        {
            public int $calls = 0;

            /**
             * @param  array<string, string>  $locations
             */
            public function __construct(private array $locations) {}

            public function resolve(string $ip): ?GeoLocation
            {
                $this->calls++;

                $country = $this->locations[$ip] ?? null;

                return $country === null
                    ? GeoLocation::unknown($ip, 'counting')
                    : new GeoLocation(ip: $ip, countryCode: $country, resolver: 'counting');
            }

            public function name(): string
            {
                return 'counting';
            }
        };

        $resolver = new CacheGeoResolver($inner, app(CacheFactory::class), 'array', 60, 'geo-lock:test');

        expect($resolver->resolve('8.8.8.8')?->countryCode)->toBe('US');

        expect($resolver->resolve('8.8.8.8')?->countryCode)->toBe('US')
            ->and($inner->calls)->toBe(1);

        $resolver->forget('8.8.8.8');
        $resolver->resolve('8.8.8.8');

        expect($inner->calls)->toBe(2)
            ->and($resolver->inner())->toBe($inner);
    });

    it('does not cache private addresses', function (): void {
        $inner = new class implements GeoResolver
        {
            public int $calls = 0;

            public function resolve(string $ip): ?GeoLocation
            {
                $this->calls++;

                return GeoLocation::unknown($ip, 'counting', isPrivate: true);
            }

            public function name(): string
            {
                return 'counting';
            }
        };

        $resolver = new CacheGeoResolver($inner, app(CacheFactory::class), 'array', 60, 'geo-lock:test-private');

        $resolver->resolve('127.0.0.1');
        $resolver->resolve('127.0.0.1');

        expect($inner->calls)->toBe(2);
    });
});

describe('TorannGeoResolver', function (): void {
    it('normalises a resolved location', function (): void {
        $geoip = Mockery::mock(GeoIP::class);
        $geoip->shouldReceive('getLocation')->once()->with('8.8.8.8')->andReturn(new Location([
            'ip' => '8.8.8.8',
            'iso_code' => 'US',
            'state' => 'FL',
            'state_name' => 'Florida',
            'city' => 'Miami',
            'postal_code' => '33101',
            'lat' => 25.76,
            'lon' => -80.19,
            'default' => false,
        ]));

        $location = (new TorannGeoResolver($geoip))->resolve('8.8.8.8');

        expect($location?->countryCode)->toBe('US')
            ->and($location?->regionCode)->toBe('US-FL')
            ->and($location?->city)->toBe('Miami')
            ->and($location?->resolver)->toBe('torann');
    });

    it('falls back to the state name for the region', function (): void {
        $geoip = Mockery::mock(GeoIP::class);
        $geoip->shouldReceive('getLocation')->andReturn(new Location([
            'iso_code' => 'US',
            'state' => '',
            'state_name' => 'Florida',
            'default' => false,
        ]));

        expect((new TorannGeoResolver($geoip))->resolve('8.8.8.8')?->regionCode)->toBe('US-FL');
    });

    it('treats the torann default location as unresolved', function (): void {
        $geoip = Mockery::mock(GeoIP::class);
        $geoip->shouldReceive('getLocation')->andReturn(new Location([
            'iso_code' => 'US',
            'state' => 'FL',
            'default' => true,
        ]));

        expect((new TorannGeoResolver($geoip))->resolve('8.8.8.8')?->isResolved())->toBeFalse();
    });

    it('returns null when torann does not return a location', function (): void {
        $geoip = Mockery::mock(GeoIP::class);
        $geoip->shouldReceive('getLocation')->andReturn(null);

        expect((new TorannGeoResolver($geoip))->resolve('8.8.8.8'))->toBeNull();
    });

    it('does not query torann for private addresses', function (): void {
        $geoip = Mockery::mock(GeoIP::class);
        $geoip->shouldNotReceive('getLocation');

        $location = (new TorannGeoResolver($geoip))->resolve('127.0.0.1');

        expect($location?->isPrivate)->toBeTrue()
            ->and($location?->isResolved())->toBeFalse();
    });
});
