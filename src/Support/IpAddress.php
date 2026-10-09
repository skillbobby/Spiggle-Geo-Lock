<?php

declare(strict_types=1);

namespace GeoLock\Support;

/**
 * Helpers around IP addresses.
 */
final class IpAddress
{
    /**
     * Whether the string is a syntactically valid IP address.
     */
    public static function isValid(mixed $ip): bool
    {
        return is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * Whether the address is a routable public address.
     *
     * Loopback, link-local, private (RFC1918 / RFC4193) and reserved ranges
     * are all considered non public - they can never be resolved by a GeoIP
     * database.
     */
    public static function isPublic(mixed $ip): bool
    {
        if (! self::isValid($ip)) {
            return false;
        }

        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }

    public static function isPrivate(mixed $ip): bool
    {
        return self::isValid($ip) && ! self::isPublic($ip);
    }

    /**
     * Mask the last octet (IPv4) or the last group (IPv6) of an address, so
     * audit logs can be kept without storing a full address.
     */
    public static function anonymize(string $ip): string
    {
        if (! self::isValid($ip)) {
            return $ip;
        }

        if (str_contains($ip, ':')) {
            $parts = explode(':', $ip);
            $parts[count($parts) - 1] = '0';

            return implode(':', $parts);
        }

        $parts = explode('.', $ip);
        $parts[3] = '0';

        return implode('.', $parts);
    }
}
