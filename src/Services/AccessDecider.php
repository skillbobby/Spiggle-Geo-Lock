<?php

namespace Spiggle\GeoLock\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Spiggle\GeoLock\Models\GeoLockRule;
use Spiggle\GeoLock\Support\Decision;
use Spiggle\GeoLock\Support\Location;

class AccessDecider
{
    /**
     * Active rules that name one of the user's roles are the only rules that apply.
     * A bypass role is always allowed, so an administrator can still open the panel.
     * A block rule denies a visit that matches its country, and its region when one is listed.
     * When the user has an allow rule, the visit must match at least one of those rules.
     * An unknown country or region does not match. That fails an allow rule and does not trip a block rule.
     */
    public function decide(Authenticatable $user, Location $location): Decision
    {
        if (! config('geo-lock.enabled', true)) {
            return Decision::allow('disabled', $location);
        }

        $roles = $this->roles($user);
        $bypass = array_map('strval', (array) config('geo-lock.bypass_roles', ['super_admin']));

        if (array_intersect($roles, $bypass) !== []) {
            return Decision::allow('bypass', $location);
        }

        $rules = $this->rulesFor($roles);

        if ($rules->isEmpty()) {
            return Decision::allow('no-rule', $location);
        }

        foreach ($rules as $rule) {
            if ($rule->type === 'block' && $this->matches($rule, $location)) {
                return Decision::deny('blocked', $location);
            }
        }

        $allow = $rules->where('type', 'allow');

        if ($allow->isNotEmpty() && ! $allow->contains(fn (GeoLockRule $rule): bool => $this->matches($rule, $location))) {
            return Decision::deny('outside-allow', $location);
        }

        return Decision::allow('matched', $location);
    }

    /**
     * @return list<string>
     */
    public function roles(Authenticatable $user): array
    {
        if (! method_exists($user, 'getRoleNames')) {
            return [];
        }

        return $user->getRoleNames()
            ->map(fn (mixed $name): string => (string) $name)
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $roles
     * @return Collection<int, GeoLockRule>
     */
    private function rulesFor(array $roles): Collection
    {
        if ($roles === []) {
            return new Collection;
        }

        return GeoLockRule::query()
            ->where('is_active', true)
            ->orderBy('sort')
            ->get()
            ->filter(function (GeoLockRule $rule) use ($roles): bool {
                $named = array_map('strval', (array) $rule->roles);

                return array_intersect($roles, $named) !== [];
            })
            ->values();
    }

    private function matches(GeoLockRule $rule, Location $location): bool
    {
        if ($location->country === null) {
            return false;
        }

        $countries = array_map(
            fn (mixed $country): string => strtoupper(trim((string) $country)),
            (array) $rule->countries,
        );

        if (! in_array(strtoupper($location->country), $countries, true)) {
            return false;
        }

        $regions = array_values(array_filter(array_map(
            fn (mixed $region): string => mb_strtolower(trim((string) $region)),
            (array) $rule->regions,
        )));

        if ($regions === []) {
            return true;
        }

        if ($location->region === null || trim($location->region) === '') {
            return false;
        }

        return in_array(mb_strtolower(trim($location->region)), $regions, true);
    }
}
