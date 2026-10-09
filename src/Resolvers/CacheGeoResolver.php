<?php

declare(strict_types=1);

namespace GeoLock\Resolvers;

use GeoLock\Contracts\GeoResolver;
use GeoLock\Data\GeoLocation;
use GeoLock\Support\IpAddress;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Caches the locations resolved by another resolver.
 *
 * A GeoIP lookup is the most expensive part of the middleware, so caching it
 * keeps the request overhead well below the 50ms budget. Unresolved addresses
 * are cached too (that is what the Torann default location usually is), which
 * avoids hammering a broken database or an unreachable API.
 */
final class CacheGeoResolver implements GeoResolver
{
    public function __construct(
        protected GeoResolver $resolver,
        protected CacheFactory $cache,
        protected ?string $store = null,
        protected int $ttl = 86400,
        protected string $prefix = 'geo-lock:location',
    ) {}

    public function resolve(string $ip): ?GeoLocation
    {
        // Private addresses are never resolved by a GeoIP database, and the
        // check is free - no point in caching them.
        if (! IpAddress::isPublic($ip)) {
            return $this->resolver->resolve($ip);
        }

        $key = $this->key($ip);

        $cached = $this->cache()->get($key);

        if (is_array($cached)) {
            return GeoLocation::fromArray($cached);
        }

        $location = $this->resolver->resolve($ip);

        if ($location instanceof GeoLocation) {
            $this->cache()->put($key, $location->toArray(), $this->ttl);
        }

        return $location;
    }

    public function name(): string
    {
        return $this->resolver->name();
    }

    /**
     * The resolver that is being decorated.
     */
    public function inner(): GeoResolver
    {
        return $this->resolver;
    }

    public function forget(string $ip): void
    {
        $this->cache()->forget($this->key($ip));
    }

    private function key(string $ip): string
    {
        return $this->prefix . ':' . md5($ip);
    }

    private function cache(): CacheRepository
    {
        return $this->cache->store($this->store);
    }
}
