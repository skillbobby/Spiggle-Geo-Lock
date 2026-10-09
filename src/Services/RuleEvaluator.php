<?php

declare(strict_types=1);

namespace GeoLock\Services;

use GeoLock\Data\GeoLocation;
use GeoLock\Data\GeoLockDecision;
use GeoLock\Enums\GeoLockOutcome;
use GeoLock\Enums\ResolverFailureBehavior;
use GeoLock\Enums\UnresolvableIpBehavior;
use GeoLock\Models\GeoLockRule;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;

/**
 * Evaluates a set of rules against a resolved location and a set of roles.
 *
 * Evaluation order:
 *  1. every applicable blacklist rule is checked - a single match blocks;
 *  2. when the visitor is bound by whitelist rules, at least one of them has
 *     to match, otherwise the visitor is blocked;
 *  3. rules that do not apply to any of the visitor's roles are ignored.
 */
class RuleEvaluator
{
    /**
     * @param  Collection<int, GeoLockRule>  $rules
     * @param  array<int, string>  $roles
     */
    public function evaluate(
        GeoLocation $location,
        Collection $rules,
        array $roles,
        ?Authenticatable $user = null,
        bool $resolverFailed = false,
    ): GeoLockDecision {
        $applicable = $this->applicableRules($rules, $roles);

        if ($applicable->isEmpty()) {
            return GeoLockDecision::granted($location, user: $user, roles: $roles, reason: 'no_applicable_rules');
        }

        if ($resolverFailed) {
            return $this->resolverFailed($applicable, $location, $roles, $user);
        }

        if (! $location->isResolved()) {
            return $this->unresolvable($applicable, $location, $roles, $user);
        }

        $blacklist = $applicable->filter(static fn (GeoLockRule $rule): bool => $rule->isBlacklist());
        $whitelist = $applicable->filter(static fn (GeoLockRule $rule): bool => $rule->isWhitelist());

        foreach ($blacklist as $rule) {
            if ($rule->matches($location)) {
                return GeoLockDecision::forDenial(
                    GeoLockOutcome::DeniedBlacklist,
                    $location,
                    $rule,
                    $user,
                    $roles,
                    'blacklisted',
                    $applicable->count(),
                );
            }
        }

        if ($whitelist->isEmpty()) {
            return GeoLockDecision::granted(
                $location,
                $blacklist->first(),
                $user,
                $roles,
                $applicable->count(),
                'not_blacklisted',
            );
        }

        foreach ($whitelist as $rule) {
            if ($rule->matches($location)) {
                return GeoLockDecision::granted($location, $rule, $user, $roles, $applicable->count(), 'whitelisted');
            }
        }

        return GeoLockDecision::forDenial(
            GeoLockOutcome::DeniedWhitelist,
            $location,
            $whitelist->first(),
            $user,
            $roles,
            'not_whitelisted',
            $applicable->count(),
        );
    }

    /**
     * The geolocation service failed: apply the configured safety net.
     *
     * @param  Collection<int, GeoLockRule>  $applicable
     * @param  array<int, string>  $roles
     */
    protected function resolverFailed(Collection $applicable, GeoLocation $location, array $roles, ?Authenticatable $user): GeoLockDecision
    {
        $behavior = ResolverFailureBehavior::fromMixed(config('geo-lock.access.on_resolver_failure'));

        $boundByWhitelist = $applicable->contains(
            static fn (GeoLockRule $rule): bool => $rule->isWhitelist(),
        );

        $deny = match ($behavior) {
            ResolverFailureBehavior::FailOpen => false,
            ResolverFailureBehavior::FailClosed => true,
            ResolverFailureBehavior::WhitelistOnly => $boundByWhitelist,
        };

        if (! $deny) {
            return GeoLockDecision::granted(
                $location,
                user: $user,
                roles: $roles,
                evaluatedRules: $applicable->count(),
                reason: 'resolver_failure_fail_open',
            );
        }

        return GeoLockDecision::forDenial(
            GeoLockOutcome::DeniedResolverFailure,
            $location,
            $applicable->first(static fn (GeoLockRule $rule): bool => $rule->isWhitelist()) ?? $applicable->first(),
            $user,
            $roles,
            'resolver_failure',
            $applicable->count(),
            resolverFailed: true,
        );
    }

    /**
     * The address could not be located: apply the configured behaviour.
     *
     * @param  Collection<int, GeoLockRule>  $applicable
     * @param  array<int, string>  $roles
     */
    protected function unresolvable(Collection $applicable, GeoLocation $location, array $roles, ?Authenticatable $user): GeoLockDecision
    {
        $behavior = UnresolvableIpBehavior::fromMixed(config('geo-lock.access.unresolvable'));

        if (! $behavior->shouldDeny()) {
            return GeoLockDecision::granted(
                $location,
                user: $user,
                roles: $roles,
                evaluatedRules: $applicable->count(),
                reason: 'unresolvable_allowed',
            );
        }

        return GeoLockDecision::forDenial(
            GeoLockOutcome::DeniedUnresolvable,
            $location,
            $applicable->first(),
            $user,
            $roles,
            'unresolvable',
            $applicable->count(),
        );
    }

    /**
     * The rules that apply to a visitor holding the given roles.
     *
     * @param  Collection<int, GeoLockRule>  $rules
     * @param  array<int, string>  $roles
     * @return Collection<int, GeoLockRule>
     */
    public function applicableRules(Collection $rules, array $roles): Collection
    {
        return $rules->filter(
            static fn (GeoLockRule $rule): bool => $rule->is_active && $rule->appliesToRoles($roles),
        )->values();
    }
}
