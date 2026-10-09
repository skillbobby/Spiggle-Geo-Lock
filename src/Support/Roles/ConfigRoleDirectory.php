<?php

declare(strict_types=1);

namespace GeoLock\Support\Roles;

/**
 * Reads roles from the user attribute, and offers the roles listed in
 * `geo-lock.roles.list` in the rule form.
 */
class ConfigRoleDirectory extends AttributeRoleDirectory
{
    public function name(): string
    {
        return 'config';
    }
}
