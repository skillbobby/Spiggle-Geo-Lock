<?php

declare(strict_types=1);

namespace GeoLock\Resolvers;

use GeoLock\Contracts\GeoResolver;
use GeoLock\Data\GeoLocation;
use GeoLock\Support\IpAddress;

/**
 * A resolver that never resolves anything.
 *
 * Useful in local environments (no GeoIP database required) and in tests
 * where every request should be treated as "location unknown".
 */
final class NullGeoResolver implements GeoResolver
{
    public function resolve(string $ip): GeoLocation
    {
        return GeoLocation::unknown($ip, $this->name(), isPrivate: IpAddress::isPrivate($ip));
    }

    public function name(): string
    {
        return 'null';
    }
}
