<?php

declare(strict_types=1);

use GeoLock\Contracts\GeoLockRuleRepository;
use GeoLock\Data\GeoLocation;
use GeoLock\Data\GeoLockDecision;
use GeoLock\Enums\GeoLockOutcome;
use GeoLock\Models\GeoLockRule;
use GeoLock\Services\RuleEvaluator;
use Illuminate\Support\Collection;

/**
 * @param  array<string, mixed>  $attributes
 */
function geoRule(array $attributes = []): GeoLockRule
{
    return GeoLockRule::factory()->make($attributes);
}

function geoLocation(string $country, ?string $region = null, string $ip = '8.8.8.8'): GeoLocation
{
    return new GeoLocation(ip: $ip, countryCode: $country, regionCode: $region);
}

/**
 * @param  array<int, GeoLockRule>  $rules
 * @param  array<int, string>  $roles
 */
function evaluateGeoRules(GeoLocation $location, array $rules, array $roles = []): GeoLockDecision
{
    return app(RuleEvaluator::class)->evaluate($location, new Collection($rules), $roles);
}

describe('blacklist rules', function (): void {
    it('blocks a matching country', function (): void {
        $rule = geoRule(['type' => 'blacklist', 'countries' => ['JM'], 'roles' => []]);

        $decision = evaluateGeoRules(geoLocation('JM'), [$rule], ['guest']);

        expect($decision->outcome)->toBe(GeoLockOutcome::DeniedBlacklist)
            ->and($decision->denied())->toBeTrue()
            ->and($decision->rule)->toBe($rule);
    });

    it('blocks only the configured region', function (): void {
        $rule = geoRule(['type' => 'blacklist', 'countries' => ['US'], 'regions' => ['US-FL'], 'roles' => []]);

        expect(evaluateGeoRules(geoLocation('US', 'US-FL'), [$rule], ['guest'])->outcome)
            ->toBe(GeoLockOutcome::DeniedBlacklist)
            ->and(evaluateGeoRules(geoLocation('US', 'US-TX'), [$rule], ['guest'])->outcome)
            ->toBe(GeoLockOutcome::Allowed);
    });

    it('covers the whole country when the region list is empty', function (): void {
        $rule = geoRule(['type' => 'blacklist', 'countries' => ['US'], 'regions' => [], 'roles' => []]);

        expect(evaluateGeoRules(geoLocation('US', 'US-TX'), [$rule], ['guest'])->outcome)
            ->toBe(GeoLockOutcome::DeniedBlacklist);
    });
});

describe('whitelist rules', function (): void {
    it('allows a listed country and blocks every other', function (): void {
        $rule = geoRule(['type' => 'whitelist', 'countries' => ['US'], 'roles' => []]);

        expect(evaluateGeoRules(geoLocation('US'), [$rule], ['guest'])->outcome)
            ->toBe(GeoLockOutcome::Allowed)
            ->and(evaluateGeoRules(geoLocation('JM'), [$rule], ['guest'])->outcome)
            ->toBe(GeoLockOutcome::DeniedWhitelist);
    });

    it('lets the blacklist win over the whitelist', function (): void {
        $whitelist = geoRule(['type' => 'whitelist', 'countries' => ['US'], 'roles' => []]);
        $blacklist = geoRule(['type' => 'blacklist', 'countries' => ['US'], 'roles' => []]);

        expect(evaluateGeoRules(geoLocation('US'), [$whitelist, $blacklist], ['guest'])->outcome)
            ->toBe(GeoLockOutcome::DeniedBlacklist);
    });
});

describe('role scoping', function (): void {
    it('applies rules without roles to everybody', function (): void {
        $rule = geoRule(['type' => 'blacklist', 'countries' => ['JM'], 'roles' => []]);

        expect(evaluateGeoRules(geoLocation('JM'), [$rule], ['guest'])->outcome)
            ->toBe(GeoLockOutcome::DeniedBlacklist);
    });

    it('ignores a role-scoped rule for other roles', function (): void {
        $rule = geoRule(['type' => 'blacklist', 'countries' => ['JM'], 'roles' => ['admin']]);

        $decision = evaluateGeoRules(geoLocation('JM'), [$rule], ['editor']);

        expect($decision->outcome)->toBe(GeoLockOutcome::NoRules)
            ->and($decision->allowed())->toBeTrue();
    });

    it('respects the apply_rules_without_roles=false configuration', function (): void {
        config()->set('geo-lock.access.apply_rules_without_roles', false);

        $rule = geoRule(['type' => 'blacklist', 'countries' => ['JM'], 'roles' => []]);

        expect(evaluateGeoRules(geoLocation('JM'), [$rule], ['guest'])->outcome)
            ->toBe(GeoLockOutcome::NoRules);
    });
});

describe('rule state and ordering', function (): void {
    it('ignores inactive rules', function (): void {
        $rule = geoRule(['type' => 'blacklist', 'countries' => ['JM'], 'roles' => [], 'is_active' => false]);

        expect(evaluateGeoRules(geoLocation('JM'), [$rule], ['guest'])->outcome)
            ->toBe(GeoLockOutcome::NoRules);
    });

    it('respects priority ordering when two rules conflict', function (): void {
        GeoLockRule::factory()->blacklist()->forCountries(['US'])->forEveryRole()->priority(1)->create(['name' => 'low']);
        GeoLockRule::factory()->blacklist()->forCountries(['US'])->forEveryRole()->priority(10)->create(['name' => 'high']);

        $rules = app(GeoLockRuleRepository::class)->all();

        $decision = evaluateGeoRules(geoLocation('US'), $rules->all(), ['guest']);

        expect($decision->outcome)->toBe(GeoLockOutcome::DeniedBlacklist)
            ->and($decision->rule?->name)->toBe('high');
    });
});
