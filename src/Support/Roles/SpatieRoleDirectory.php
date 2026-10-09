<?php

declare(strict_types=1);

namespace GeoLock\Support\Roles;

use GeoLock\Contracts\RoleDirectory;
use GeoLock\Support\Roles\Concerns\ReadsRolesFromUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Spatie\Permission\Contracts\HasRoles;
use Throwable;

/**
 * Reads roles from spatie/laravel-permission.
 */
class SpatieRoleDirectory implements RoleDirectory
{
    use ReadsRolesFromUser;

    /**
     * @var array<string, string>|null
     */
    protected ?array $cachedRoles = null;

    public function rolesFor(?Authenticatable $user): array
    {
        if ($user instanceof HasRoles) {
            return $this->normalizeRoles($user->getRoleNames());
        }

        return $this->readRolesFromUser($user);
    }

    public function availableRoles(): array
    {
        if ($this->cachedRoles !== null) {
            return $this->cachedRoles;
        }

        $roles = [];

        try {
            $model = config('permission.models.role', 'Spatie\\Permission\\Models\\Role');

            if (is_string($model) && class_exists($model)) {
                /** @var array<int, string> $names */
                $names = $model::query()->orderBy('name')->pluck('name')->all();

                foreach ($names as $name) {
                    $roles[(string) $name] = (string) $name;
                }
            }
        } catch (Throwable) {
            // The permission tables may not be migrated yet - fall back to the
            // configured list below.
        }

        foreach ((array) config('geo-lock.roles.list', []) as $role) {
            if (is_string($role) && $role !== '') {
                $roles[$role] = $role;
            }
        }

        ksort($roles);

        return $this->cachedRoles = $roles;
    }

    public function name(): string
    {
        return 'spatie';
    }
}
