<?php

declare(strict_types=1);

namespace GeoLock\Support\Roles;

use GeoLock\Contracts\RoleDirectory;
use Spatie\Permission\Contracts\HasRoles;

/**
 * Builds the role directory configured in `geo-lock.roles.provider`.
 */
class RoleDirectoryFactory
{
    public static function make(?string $provider = null): RoleDirectory
    {
        $provider = $provider ?? (string) config('geo-lock.roles.provider', 'auto');

        return match ($provider) {
            'spatie' => new SpatieRoleDirectory,
            'attribute' => new AttributeRoleDirectory,
            'config' => new ConfigRoleDirectory,
            'none' => new NullRoleDirectory,
            default => self::auto(),
        };
    }

    public static function auto(): RoleDirectory
    {
        if (interface_exists(HasRoles::class)) {
            return new SpatieRoleDirectory;
        }

        return new ConfigRoleDirectory;
    }
}
