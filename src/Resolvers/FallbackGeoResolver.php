<?php

declare(strict_types=1);

namespace GeoLock\Resolvers;

use GeoLock\Contracts\GeoResolver;
use GeoLock\Data\GeoLocation;
use Throwable;

/**
 * Tries several resolvers in order and returns the first location that could
 * actually be resolved to a country.
 *
 * Configure it through `geo-lock.resolver.fallbacks`, for example to fall back
 * to a second GeoIP service when the local database is missing.
 */
final class FallbackGeoResolver implements GeoResolver
{
    /**
     * @param  array<int, GeoResolver>  $resolvers
     */
    public function __construct(
        protected array $resolvers,
    ) {}

    public function resolve(string $ip): ?GeoLocation
    {
        $last = null;

        foreach ($this->resolvers as $resolver) {
            try {
                $location = $resolver->resolve($ip);
            } catch (Throwable $exception) {
                $last ??= GeoLocation::unknown($ip, $resolver->name());

                continue;
            }

            if ($location instanceof GeoLocation && $location->isResolved()) {
                return $location;
            }

            $last ??= $location;
        }

        return $last;
    }

    public function name(): string
    {
        return 'fallback(' . implode(',', array_map(
            static fn (GeoResolver $resolver): string => $resolver->name(),
            $this->resolvers,
        )) . ')';
    }

    /**
     * @return array<int, GeoResolver>
     */
    public function resolvers(): array
    {
        return $this->resolvers;
    }
}
