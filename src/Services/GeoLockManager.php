<?php

declare(strict_types=1);

namespace GeoLock\Services;

use GeoLock\Contracts\GeoLockRuleRepository;
use GeoLock\Contracts\GeoResolver;
use GeoLock\Contracts\RoleDirectory;
use GeoLock\Data\GeoLocation;
use GeoLock\Data\GeoLockDecision;
use GeoLock\Enums\GeoLockOutcome;
use GeoLock\Events\GeoLockAccessDenied;
use GeoLock\Events\GeoLockAccessGranted;
use GeoLock\Events\GeoLockBypassed;
use GeoLock\Models\GeoLockRule;
use GeoLock\Resolvers\CacheGeoResolver;
use GeoLock\Support\IpAddress;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Throwable;

/**
 * The geo-lock engine: resolves the visitor, applies the bypass checks and
 * evaluates the configured rules.
 */
class GeoLockManager
{
    /**
     * Locations resolved during this request.
     *
     * @var array<string, array{0: GeoLocation, 1: bool}>
     */
    protected array $locations = [];

    protected ?GeoResolver $resolverOverride = null;

    public function __construct(
        protected GeoResolver $resolver,
        protected GeoLockRuleRepository $repository,
        protected RoleDirectory $roleDirectory,
        protected BypassInspector $bypassInspector,
        protected RuleEvaluator $evaluator,
        protected Dispatcher $events,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) config('geo-lock.enabled', true);
    }

    public function resolver(): GeoResolver
    {
        return $this->resolverOverride ?? $this->resolver;
    }

    /**
     * Use another resolver for the remainder of the request.
     */
    public function useResolver(GeoResolver $resolver): static
    {
        $this->resolverOverride = $resolver;
        $this->locations = [];

        return $this;
    }

    public function resetResolver(): static
    {
        $this->resolverOverride = null;
        $this->locations = [];

        return $this;
    }

    public function repository(): GeoLockRuleRepository
    {
        return $this->repository;
    }

    public function roleDirectory(): RoleDirectory
    {
        return $this->roleDirectory;
    }

    /**
     * @return Collection<int, GeoLockRule>
     */
    public function rules(bool $activeOnly = true): Collection
    {
        return $this->repository->all($activeOnly);
    }

    /**
     * @return array<int, string>
     */
    public function rolesFor(?Authenticatable $user): array
    {
        if ($user === null) {
            $guestRole = (string) config('geo-lock.access.guest_role', 'guest');

            return $guestRole === '' ? [] : [$guestRole];
        }

        return $this->roleDirectory->rolesFor($user);
    }

    /**
     * Resolve an IP address, memoized for the current request.
     */
    public function resolve(string $ip): GeoLocation
    {
        return $this->locate($ip)[0];
    }

    public function forgetLocation(string $ip): void
    {
        unset($this->locations[$ip]);

        $resolver = $this->resolver();

        if ($resolver instanceof CacheGeoResolver) {
            $resolver->forget($ip);
        }
    }

    /**
     * Evaluate the rules for a visitor.
     *
     * @param  array<int, string>|null  $roles  override the roles of the visitor
     */
    public function evaluate(
        ?Authenticatable $user = null,
        ?string $ip = null,
        ?Request $request = null,
        ?array $roles = null,
        bool $applyBypass = true,
    ): GeoLockDecision {
        $request ??= request();

        $ip = $ip ?? $this->clientIp($request);
        $roles ??= $this->rolesFor($user);

        [$location, $resolverFailed] = $this->locate($ip);

        if (! $this->isEnabled()) {
            return new GeoLockDecision(
                outcome: GeoLockOutcome::Disabled,
                location: $location,
                user: $user,
                roles: $roles,
                reason: 'disabled',
            );
        }

        if ($applyBypass) {
            if ($reason = $this->bypassInspector->bypassReason($user, $ip)) {
                return GeoLockDecision::bypassed($location, $reason, $user, $roles);
            }

            if (config('geo-lock.access.bypass_private_ips', true) && $location->isPrivate) {
                return GeoLockDecision::bypassed($location, 'private_ip', $user, $roles);
            }
        }

        return $this->evaluator->evaluate(
            $location,
            $this->rules(),
            $roles,
            $user,
            $resolverFailed,
        );
    }

    /**
     * @param  array<int, string>|null  $roles
     */
    public function allows(?Authenticatable $user = null, ?string $ip = null, ?Request $request = null, ?array $roles = null): bool
    {
        return $this->evaluate($user, $ip, $request, $roles)->allowed();
    }

    /**
     * @param  array<int, string>|null  $roles
     */
    public function denies(?Authenticatable $user = null, ?string $ip = null, ?Request $request = null, ?array $roles = null): bool
    {
        return $this->evaluate($user, $ip, $request, $roles)->denied();
    }

    public function bypassReason(?Authenticatable $user, ?string $ip = null): ?string
    {
        return $this->bypassInspector->bypassReason($user, $ip);
    }

    /**
     * Whether the request carries proxy headers without a matching
     * TrustedProxy configuration - which would let a visitor spoof their own
     * location.
     */
    public function isUntrustedProxyRequest(?Request $request = null): bool
    {
        if (! config('geo-lock.access.require_trusted_proxy', false)) {
            return false;
        }

        $request ??= request();

        $hasForwardedHeaders = $request->headers->has('X-Forwarded-For')
            || $request->headers->has('X-Real-IP')
            || $request->headers->has('Forwarded')
            || $request->headers->has('CF-Connecting-IP');

        return $hasForwardedHeaders && $request->getTrustedProxies() === [];
    }

    public function clientIp(?Request $request = null): string
    {
        $request ??= request();

        $ip = $request->getClientIp() ?: $request->ip();

        return is_string($ip) && $ip !== '' ? $ip : '0.0.0.0';
    }

    /**
     * Fire the lifecycle events for a decision.
     */
    public function fireEvents(GeoLockDecision $decision): void
    {
        if ($decision->denied()) {
            $this->events->dispatch(new GeoLockAccessDenied($decision));

            return;
        }

        if ($decision->isBypassed()) {
            $this->events->dispatch(new GeoLockBypassed($decision));

            return;
        }

        if ($decision->outcome !== GeoLockOutcome::Disabled) {
            $this->events->dispatch(new GeoLockAccessGranted($decision));
        }
    }

    /**
     * @return array{0: GeoLocation, 1: bool}
     */
    protected function locate(string $ip): array
    {
        if (isset($this->locations[$ip])) {
            return $this->locations[$ip];
        }

        $failed = false;

        try {
            $location = $this->resolver()->resolve($ip);
        } catch (Throwable $exception) {
            $failed = true;
            $location = null;

            report($exception);
        }

        $location ??= GeoLocation::unknown(
            $ip,
            $this->resolver()->name(),
            isPrivate: IpAddress::isPrivate($ip),
        );

        return $this->locations[$ip] = [$location, $failed];
    }

    public function flush(): void
    {
        $this->locations = [];
        $this->repository->flush();
    }
}
