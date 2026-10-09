<?php

declare(strict_types=1);

namespace GeoLock\Data;

use GeoLock\Enums\GeoLockOutcome;
use GeoLock\Models\GeoLockRule;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * The result of evaluating the active geo-lock rules for a single request.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class GeoLockDecision implements Arrayable, JsonSerializable
{
    /**
     * @param  array<int, string>  $roles
     */
    public function __construct(
        public GeoLockOutcome $outcome,
        public GeoLocation $location,
        public ?GeoLockRule $rule = null,
        public ?Authenticatable $user = null,
        public array $roles = [],
        public ?string $reason = null,
        public int $evaluatedRules = 0,
        public bool $resolverFailed = false,
    ) {}

    public static function bypassed(GeoLocation $location, string $reason, ?Authenticatable $user = null, array $roles = []): self
    {
        return new self(
            outcome: GeoLockOutcome::Bypassed,
            location: $location,
            user: $user,
            roles: $roles,
            reason: $reason,
        );
    }

    public static function granted(GeoLocation $location, ?GeoLockRule $rule = null, ?Authenticatable $user = null, array $roles = [], int $evaluatedRules = 0, ?string $reason = null): self
    {
        return new self(
            outcome: $rule === null ? GeoLockOutcome::NoRules : GeoLockOutcome::Allowed,
            location: $location,
            rule: $rule,
            user: $user,
            roles: $roles,
            reason: $reason,
            evaluatedRules: $evaluatedRules,
        );
    }

    /**
     * @param  array<int, string>  $roles
     */
    public static function forDenial(
        GeoLockOutcome $outcome,
        GeoLocation $location,
        ?GeoLockRule $rule = null,
        ?Authenticatable $user = null,
        array $roles = [],
        ?string $reason = null,
        int $evaluatedRules = 0,
        bool $resolverFailed = false,
    ): self {
        return new self(
            outcome: $outcome,
            location: $location,
            rule: $rule,
            user: $user,
            roles: $roles,
            reason: $reason,
            evaluatedRules: $evaluatedRules,
            resolverFailed: $resolverFailed,
        );
    }

    public function allowed(): bool
    {
        return $this->outcome->isAllowed();
    }

    public function denied(): bool
    {
        return $this->outcome->isDenied();
    }

    public function isBypassed(): bool
    {
        return $this->outcome->isBypassed();
    }

    /**
     * The message shown to a blocked visitor.
     */
    public function message(): string
    {
        $configured = config('geo-lock.denial.message');

        if (filled($configured)) {
            return (string) $configured;
        }

        return match ($this->outcome) {
            GeoLockOutcome::DeniedBlacklist => __('geo-lock::geo-lock.denial.blacklist', [
                'country' => $this->location->countryLabel() ?? $this->location->ip,
            ]),
            GeoLockOutcome::DeniedWhitelist => __('geo-lock::geo-lock.denial.whitelist'),
            GeoLockOutcome::DeniedUnresolvable => __('geo-lock::geo-lock.denial.unresolvable'),
            GeoLockOutcome::DeniedResolverFailure => __('geo-lock::geo-lock.denial.resolver_failure'),
            GeoLockOutcome::DeniedUntrustedProxy => __('geo-lock::geo-lock.denial.untrusted_proxy'),
            default => __('geo-lock::geo-lock.denial.generic'),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'outcome' => $this->outcome->value,
            'allowed' => $this->allowed(),
            'reason' => $this->reason,
            'message' => $this->message(),
            'rule_id' => $this->rule?->getKey(),
            'rule_name' => $this->rule?->name,
            'roles' => $this->roles,
            'user_id' => $this->user?->getAuthIdentifier(),
            'evaluated_rules' => $this->evaluatedRules,
            'resolver_failed' => $this->resolverFailed,
            'location' => $this->location->toArray(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
