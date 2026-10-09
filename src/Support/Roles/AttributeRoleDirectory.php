<?php

declare(strict_types=1);

namespace GeoLock\Support\Roles;

use GeoLock\Contracts\RoleDirectory;
use GeoLock\Support\Roles\Concerns\ReadsRolesFromUser;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Reads roles from an attribute (or relation) on the user model, e.g.
 * `$user->roles` where roles is an array column or a relation.
 */
class AttributeRoleDirectory implements RoleDirectory
{
    use ReadsRolesFromUser;

    public function rolesFor(?Authenticatable $user): array
    {
        return $this->readRolesFromUser($user);
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
        return 'attribute';
    }
}
