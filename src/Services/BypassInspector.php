<?php

declare(strict_types=1);

namespace GeoLock\Services;

use Closure;
use GeoLock\Contracts\RoleDirectory;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Arr;

/**
 * The emergency bypass: the checks that keep an administrator from locking
 * themselves (or the support team) out of the panel.
 */
class BypassInspector
{
    /**
     * @param  array<int, string>  $extraRoles  roles granted by the Filament plugin
     */
    public function __construct(
        protected RoleDirectory $roles,
        protected Gate $gate,
        protected array $extraRoles = [],
    ) {}

    /**
     * @var array<int, Closure>
     */
    protected array $callbacks = [];

    /**
     * Returns the reason of the bypass, or null when the visitor is not
     * allowed to bypass the rules.
     */
    public function bypassReason(?Authenticatable $user, ?string $ip = null): ?string
    {
        if (! config('geo-lock.bypass.enabled', true)) {
            return null;
        }

        if (in_array(app()->environment(), $this->list('environments'), strict: true)) {
            return 'environment:'.app()->environment();
        }

        if ($ip !== null && in_array($ip, $this->list('ips'), strict: true)) {
            return 'ip';
        }

        if ($user === null) {
            return null;
        }

        $identifier = $user->getAuthIdentifier();

        if ($identifier !== null && in_array((string) $identifier, $this->list('user_ids'), strict: true)) {
            return 'user_id';
        }

        $email = data_get($user, 'email');

        if (is_string($email) && filled($email) && in_array(mb_strtolower($email), array_map(mb_strtolower(...), $this->list('emails')), strict: true)) {
            return 'email';
        }

        $bypassRoles = array_values(array_unique([...$this->list('roles'), ...$this->extraRoles]));

        if ($bypassRoles !== []) {
            $matching = array_intersect($this->roles->rolesFor($user), $bypassRoles);

            if ($matching !== []) {
                return 'role:'.implode(',', $matching);
            }
        }

        foreach ($this->callbacks as $index => $callback) {
            $result = $callback($user, $ip);

            if ($result === true) {
                return 'callback:'.$index;
            }

            if (is_string($result) && $result !== '') {
                return $result;
            }
        }

        $ability = config('geo-lock.bypass.ability');

        if (is_string($ability) && filled($ability) && $this->gate->forUser($user)->allows($ability)) {
            return 'ability:'.$ability;
        }

        return null;
    }

    public function canBypass(?Authenticatable $user, ?string $ip = null): bool
    {
        return $this->bypassReason($user, $ip) !== null;
    }

    /**
     * Grant the bypass to additional roles, e.g. from the Filament plugin.
     *
     * @param  array<int, string>  $roles
     */
    public function addRoles(array $roles): static
    {
        $this->extraRoles = array_values(array_unique([...$this->extraRoles, ...$roles]));

        return $this;
    }

    /**
     * Register a custom bypass rule. The callback receives the current user
     * and the request IP, and may return true (bypassed) or a string reason.
     */
    public function bypassUsing(Closure $callback): static
    {
        $this->callbacks[] = $callback;

        return $this;
    }

    /**
     * @return array<int, Closure>
     */
    public function callbacks(): array
    {
        return $this->callbacks;
    }

    /**
     * @return array<int, string>
     */
    public function extraRoles(): array
    {
        return $this->extraRoles;
    }

    /**
     * @return array<int, string>
     */
    protected function list(string $key): array
    {
        $values = Arr::wrap(config('geo-lock.bypass.'.$key, []));

        return array_values(array_filter(array_map(
            static fn (mixed $value): string => is_scalar($value) ? (string) $value : '',
            $values,
        ), static fn (string $value): bool => $value !== ''));
    }
}
