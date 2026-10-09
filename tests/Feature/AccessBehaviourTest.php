<?php

declare(strict_types=1);

use GeoLock\Contracts\GeoResolver;
use GeoLock\Data\GeoLocation;
use GeoLock\Enums\GeoLockOutcome;
use GeoLock\Facades\GeoLock;
use GeoLock\Models\GeoLockRule;
use Illuminate\Support\Facades\Gate;

function throwingResolver(): GeoResolver
{
    return new class implements GeoResolver
    {
        public function resolve(string $ip): ?GeoLocation
        {
            throw new RuntimeException('geoip unavailable');
        }

        public function name(): string
        {
            return 'throwing';
        }
    };
}

describe('unresolvable addresses', function (): void {
    beforeEach(function (): void {
        GeoLockRule::factory()->blacklist()->forCountries(['JM'])->forEveryRole()->create();
        fakeGeo([]);
    });

    it('allows when access.unresolvable=allow', function (): void {
        config()->set('geo-lock.access.unresolvable', 'allow');

        $decision = GeoLock::evaluate(null, '8.8.8.8');

        expect($decision->allowed())->toBeTrue()
            ->and($decision->reason)->toBe('unresolvable_allowed');
    });

    it('denies with DeniedUnresolvable when access.unresolvable=deny', function (): void {
        config()->set('geo-lock.access.unresolvable', 'deny');

        $decision = GeoLock::evaluate(null, '8.8.8.8');

        expect($decision->outcome)->toBe(GeoLockOutcome::DeniedUnresolvable)
            ->and($decision->denied())->toBeTrue();
    });
});

describe('resolver failures', function (): void {
    beforeEach(function (): void {
        GeoLock::useResolver(throwingResolver());
    });

    it('blocks whitelist-bound visitors under whitelist_only', function (): void {
        config()->set('geo-lock.access.on_resolver_failure', 'whitelist_only');
        GeoLockRule::factory()->whitelist()->forCountries(['US'])->forEveryRole()->create();

        $decision = GeoLock::evaluate(null, '8.8.8.8');

        expect($decision->outcome)->toBe(GeoLockOutcome::DeniedResolverFailure)
            ->and($decision->resolverFailed)->toBeTrue();
    });

    it('keeps blacklist-only visitors under whitelist_only', function (): void {
        config()->set('geo-lock.access.on_resolver_failure', 'whitelist_only');
        GeoLockRule::factory()->blacklist()->forCountries(['JM'])->forEveryRole()->create();

        $decision = GeoLock::evaluate(null, '8.8.8.8');

        expect($decision->allowed())->toBeTrue()
            ->and($decision->reason)->toBe('resolver_failure_fail_open');
    });

    it('blocks every bound visitor under fail_closed', function (): void {
        config()->set('geo-lock.access.on_resolver_failure', 'fail_closed');
        GeoLockRule::factory()->blacklist()->forCountries(['JM'])->forEveryRole()->create();

        expect(GeoLock::evaluate(null, '8.8.8.8')->outcome)->toBe(GeoLockOutcome::DeniedResolverFailure);
    });

    it('never blocks under fail_open', function (): void {
        config()->set('geo-lock.access.on_resolver_failure', 'fail_open');
        GeoLockRule::factory()->whitelist()->forCountries(['US'])->forEveryRole()->create();

        expect(GeoLock::evaluate(null, '8.8.8.8')->allowed())->toBeTrue();
    });
});

describe('private IPs', function (): void {
    it('bypasses a private address when bypass_private_ips is on', function (): void {
        config()->set('geo-lock.access.bypass_private_ips', true);

        $decision = GeoLock::evaluate(null, '127.0.0.1');

        expect($decision->outcome)->toBe(GeoLockOutcome::Bypassed)
            ->and($decision->reason)->toBe('private_ip');
    });

    it('does not bypass a private address when bypass_private_ips is off', function (): void {
        config()->set('geo-lock.access.bypass_private_ips', false);

        $decision = GeoLock::evaluate(null, '127.0.0.1');

        expect($decision->isBypassed())->toBeFalse()
            ->and($decision->outcome)->toBe(GeoLockOutcome::NoRules);
    });
});

describe('emergency bypass', function (): void {
    it('bypasses by role', function (): void {
        config()->set('geo-lock.bypass.roles', ['super_admin']);
        $user = $this->createUser([], ['super_admin']);

        $decision = GeoLock::evaluate($user, '8.8.8.8');

        expect($decision->outcome)->toBe(GeoLockOutcome::Bypassed)
            ->and($decision->reason)->toBe('role:super_admin');
    });

    it('bypasses by email', function (): void {
        config()->set('geo-lock.bypass.roles', []);
        config()->set('geo-lock.bypass.emails', ['boss@example.com']);
        $user = $this->createUser(['email' => 'boss@example.com']);

        expect(GeoLock::evaluate($user, '8.8.8.8')->reason)->toBe('email');
    });

    it('bypasses by user id', function (): void {
        config()->set('geo-lock.bypass.roles', []);
        $user = $this->createUser();
        config()->set('geo-lock.bypass.user_ids', [(string) $user->getKey()]);

        expect(GeoLock::evaluate($user, '8.8.8.8')->reason)->toBe('user_id');
    });

    it('bypasses by ip', function (): void {
        config()->set('geo-lock.bypass.roles', []);
        config()->set('geo-lock.bypass.ips', ['8.8.8.8']);

        expect(GeoLock::evaluate(null, '8.8.8.8')->reason)->toBe('ip');
    });

    it('bypasses through a gate ability', function (): void {
        config()->set('geo-lock.bypass.roles', []);
        config()->set('geo-lock.bypass.ability', 'geo-lock.bypass');
        Gate::define('geo-lock.bypass', fn ($user): bool => true);
        $user = $this->createUser();

        expect(GeoLock::evaluate($user, '8.8.8.8')->reason)->toBe('ability:geo-lock.bypass');
    });

    it('bypasses in a configured environment', function (): void {
        config()->set('geo-lock.bypass.environments', ['testing']);

        expect(GeoLock::evaluate(null, '8.8.8.8')->reason)->toBe('environment:testing');
    });

    it('does not bypass when the bypass is disabled', function (): void {
        config()->set('geo-lock.bypass.enabled', false);
        $user = $this->createUser([], ['super_admin']);

        expect(GeoLock::evaluate($user, '8.8.8.8')->isBypassed())->toBeFalse();
    });
});

it('short-circuits when geo-lock is disabled', function (): void {
    config()->set('geo-lock.enabled', false);

    $decision = GeoLock::evaluate(null, '8.8.8.8');

    expect($decision->outcome)->toBe(GeoLockOutcome::Disabled)
        ->and($decision->allowed())->toBeTrue();
});

it('gives guests the configured guest role', function (): void {
    expect(GeoLock::rolesFor(null))->toBe(['guest']);

    config()->set('geo-lock.access.guest_role', '');

    expect(GeoLock::rolesFor(null))->toBe([]);
});
