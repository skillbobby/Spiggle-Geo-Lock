<?php

declare(strict_types=1);

namespace GeoLock\Support\Roles;

use GeoLock\Contracts\RoleDirectory;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * A role directory that never finds any role.
 *
 * Rules without roles still apply to everybody, so the plugin keeps working
 * in applications that do not have a role system at all.
 */
class NullRoleDirectory implements RoleDirectory
{
    public function rolesFor(?Authenticatable $user): array
    {
        return [];
    }

    public function availableRoles(): array
    {
        $roles = [];

        foreach ((array) config('geo-lock.roles.list', []) as $role) {
            if (is_string($role) && $role !== '') {
                $roles[$role] = $role;
            }
        }

        return $roles;
    }

    public function name(): string
    {
        return 'none';
    }
}
