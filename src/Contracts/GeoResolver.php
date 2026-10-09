<?php

declare(strict_types=1);

namespace GeoLock\Contracts;

use GeoLock\Data\GeoLocation;

/**
 * Turns an IP address into a {@see GeoLocation}.
 *
 * Implement this contract to plug any geolocation provider into the plugin.
 */
interface GeoResolver
{
    /**
     * Resolve the given IP address, or return null when it cannot be resolved.
     *
     * Implementations may throw; the manager treats a throw as a resolver
     * failure and applies the configured fail-open/fail-closed behaviour.
     */
    public function resolve(string $ip): ?GeoLocation;

    /**
     * A short name used in logs, audit entries and the `geo-lock:doctor` output.
     */
    public function name(): string;
}
