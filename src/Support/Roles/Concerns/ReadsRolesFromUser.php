<?php

declare(strict_types=1);

namespace GeoLock\Support\Roles\Concerns;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Traversable;

/**
 * Reads the roles of a user from an attribute, a relation or a method.
 */
trait ReadsRolesFromUser
{
    /**
     * @return array<int, string>
     */
    protected function readRolesFromUser(?Authenticatable $user): array
    {
        if ($user === null) {
            return [];
        }

        $attribute = (string) config('geo-lock.roles.attribute', 'roles');

        $roles = data_get($user, $attribute);

        if ($roles === null && method_exists($user, $attribute)) {
            $roles = $user->{$attribute}();
        }

        return $this->normalizeRoles($roles);
    }

    /**
     * @return array<int, string>
     */
    protected function normalizeRoles(mixed $roles): array
    {
        if ($roles === null) {
            return [];
        }

        if ($roles instanceof Collection) {
            $roles = $roles->all();
        } elseif (is_object($roles) && method_exists($roles, 'pluck')) {
            /** @phpstan-ignore-next-line */
            $roles = $roles->pluck('name')->all();
        } elseif ($roles instanceof Traversable) {
            $roles = iterator_to_array($roles);
        } elseif (is_string($roles)) {
            $roles = preg_split('/[,\s|]+/', $roles) ?: [];
        } elseif (! is_array($roles)) {
            $roles = [$roles];
        }

        $normalized = [];

        foreach ($roles as $role) {
            if (is_object($role)) {
                $role = data_get($role, 'name') ?? data_get($role, 'slug') ?? null;
            }

            if (is_int($role) || is_float($role)) {
                $role = (string) $role;
            }

            if (! is_string($role) || Str::of($role)->trim()->isEmpty()) {
                continue;
            }

            $normalized[] = Str::of($role)->trim()->value();
        }

        return array_values(array_unique($normalized));
    }
}
