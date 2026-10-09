<?php

declare(strict_types=1);

namespace GeoLock\Resolvers;

use GeoLock\Contracts\GeoResolver;
use GeoLock\Data\GeoLocation;
use GeoLock\Support\IpAddress;
use GeoLock\Support\RegionData;
use Torann\GeoIP\GeoIP;
use Torann\GeoIP\Location;

/**
 * Resolves IP addresses through the `torann/geoip` package.
 *
 * torann/geoip returns the subdivision code without its country prefix
 * ("FL"), so it is normalized into a full ISO 3166-2 code ("US-FL") using the
 * bundled dataset, with the human readable state name as a fallback.
 */
final class TorannGeoResolver implements GeoResolver
{
    public function __construct(
        protected GeoIP $geoip,
    ) {}

    public function resolve(string $ip): ?GeoLocation
    {
        if (! IpAddress::isPublic($ip)) {
            return GeoLocation::unknown($ip, $this->name(), isPrivate: IpAddress::isPrivate($ip));
        }

        $location = $this->geoip->getLocation($ip);

        if (! $location instanceof Location) {
            return null;
        }

        // torann/geoip falls back to a hardcoded default location ("default"
        // is true) whenever it cannot locate the address.
        if ((bool) ($location->default ?? true)) {
            return GeoLocation::unknown($ip, $this->name());
        }

        $country = strtoupper((string) ($location->iso_code ?? '')) ?: null;

        if ($country === null) {
            return GeoLocation::unknown($ip, $this->name());
        }

        return new GeoLocation(
            ip: $ip,
            countryCode: $country,
            regionCode: RegionData::match(
                $country,
                (string) ($location->state ?? ''),
                (string) ($location->state_name ?? ''),
            ),
            city: filled($location->city ?? null) ? (string) $location->city : null,
            postalCode: filled($location->postal_code ?? null) ? (string) $location->postal_code : null,
            latitude: is_numeric($location->lat ?? null) ? (float) $location->lat : null,
            longitude: is_numeric($location->lon ?? null) ? (float) $location->lon : null,
            resolver: $this->name(),
        );
    }

    public function name(): string
    {
        return 'torann';
    }
}
