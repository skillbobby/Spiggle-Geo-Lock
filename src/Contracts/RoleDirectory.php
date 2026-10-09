<?php

declare(strict_types=1);

namespace GeoLock\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Discovers the roles of the current user, whatever the application uses to
 * store them (spatie/laravel-permission, an attribute, a config list, ...).
 */
interface RoleDirectory
{
    /**
     * @return array<int, string>
     */
    public function rolesFor(?Authenticatable $user): array;

    /**
     * The selectable roles for the rule form.
     *
     * @return array<string, string> role name => label
     */
    public function availableRoles(): array;

    /**
     * A short name used in the `geo-lock:doctor` output.
     */
    public function name(): string;
}
