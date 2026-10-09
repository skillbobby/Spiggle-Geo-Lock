<?php

declare(strict_types=1);

namespace GeoLock\Resolvers;

use GeoLock\Contracts\GeoResolver;
use GeoLock\Data\GeoLocation;
use GeoLock\Support\IpAddress;
use GeoLock\Support\RegionData;

/**
 * An in-memory resolver backed by a map of IP addresses.
 *
 * This is what powers `GeoLock::fake()`, the "test an IP address" action in
 * the panel and the test-suite fixtures.
 */
final class ArrayGeoResolver implements GeoResolver
{
    /**
     * @param  array<string, array<string, mixed>|GeoLocation|string>  $locations
     */
    public function __construct(
        protected array $locations = [],
        protected string $name = 'array',
    ) {}

    public function resolve(string $ip): GeoLocation
    {
        $location = $this->locations[$ip] ?? $this->locations['*'] ?? null;

        if ($location instanceof GeoLocation) {
            return $location;
        }

        if (is_string($location)) {
            $location = ['country_code' => $location];
        }

        if (! is_array($location)) {
            return GeoLocation::unknown($ip, $this->name(), isPrivate: IpAddress::isPrivate($ip));
        }

        $country = strtoupper((string) ($location['country_code'] ?? $location['country'] ?? $location['iso_code'] ?? '')) ?: null;

        if ($country === null) {
            return GeoLocation::unknown($ip, $this->name(), isPrivate: IpAddress::isPrivate($ip));
        }

        return new GeoLocation(
            ip: $ip,
            countryCode: $country,
            regionCode: RegionData::match(
                $country,
                (string) ($location['region_code'] ?? $location['state'] ?? ''),
                (string) ($location['region_name'] ?? $location['state_name'] ?? ''),
            ) ?? ($location['region_code'] ?? null),
            city: $location['city'] ?? null,
            postalCode: $location['postal_code'] ?? null,
            latitude: isset($location['latitude']) ? (float) $location['latitude'] : null,
            longitude: isset($location['longitude']) ? (float) $location['longitude'] : null,
            resolver: $this->name,
        );
    }

    /**
     * @param  array<string, mixed>|GeoLocation|string  $location
     */
    public function put(string $ip, array|GeoLocation|string $location): self
    {
        $this->locations[$ip] = $location;

        return $this;
    }

    /**
     * @param  array<string, array<string, mixed>|GeoLocation|string>  $locations
     */
    public function fill(array $locations): self
    {
        $this->locations = [...$this->locations, ...$locations];

        return $this;
    }

    public function forget(string $ip): self
    {
        unset($this->locations[$ip]);

        return $this;
    }

    public function isEmpty(): bool
    {
        return $this->locations === [];
    }

    public function name(): string
    {
        return $this->name;
    }
}
