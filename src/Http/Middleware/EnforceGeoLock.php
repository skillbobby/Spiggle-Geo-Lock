<?php

declare(strict_types=1);

namespace GeoLock\Http\Middleware;

use Closure;
use Filament\Facades\Filament;
use GeoLock\Data\GeoLocation;
use GeoLock\Data\GeoLockDecision;
use GeoLock\Enums\GeoLockOutcome;
use GeoLock\Services\GeoLockManager;
use GeoLock\Support\PanelScope;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Enforces the geo-lock rules on every request of a Filament panel.
 *
 * Apply it to a panel with the `GeoLockPlugin`, or manually:
 *
 *     $panel->middleware([EnforceGeoLock::class]);
 */
class EnforceGeoLock
{
    public function __construct(
        protected GeoLockManager $manager,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->shouldEnforce($request)) {
            return $next($request);
        }

        $user = $this->resolveUser($request);
        $ip = $this->manager->clientIp($request);
        $roles = $this->manager->rolesFor($user);

        if ($this->manager->isUntrustedProxyRequest($request)) {
            $decision = GeoLockDecision::forDenial(
                GeoLockOutcome::DeniedUntrustedProxy,
                GeoLocation::unknown($ip, 'untrusted-proxy'),
                user: $user,
                roles: $roles,
                reason: 'untrusted_proxy',
            );
        } else {
            $decision = $this->manager->evaluate($user, $ip, $request);
        }

        $this->manager->fireEvents($decision);

        if ($decision->denied()) {
            return $this->deny($request, $decision);
        }

        return $next($request);
    }

    protected function shouldEnforce(Request $request): bool
    {
        if (! config('geo-lock.enabled', true)) {
            return false;
        }

        if (! config('geo-lock.middleware.enabled', true)) {
            return false;
        }

        return PanelScope::matches(PanelScope::currentPanelId());
    }

    protected function resolveUser(Request $request): ?Authenticatable
    {
        if ($guard = config('geo-lock.middleware.guard')) {
            return auth()->guard($guard)->user() ?? $request->user();
        }

        if ($panel = PanelScope::currentPanelId()) {
            try {
                if ($user = Filament::getPanel($panel)->auth()->user()) {
                    return $user;
                }
            } catch (Throwable) {
                // Fall through to the default guard.
            }
        }

        return $request->user();
    }

    protected function deny(Request $request, GeoLockDecision $decision): Response
    {
        $status = (int) config('geo-lock.denial.status', 403);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $decision->message(),
                ...$decision->toArray(),
            ], $status);
        }

        if ($redirect = config('geo-lock.denial.redirect')) {
            return redirect()->to($this->resolveRedirect($redirect));
        }

        if ($view = config('geo-lock.denial.view')) {
            return response()->view((string) $view, ['decision' => $decision], $status);
        }

        abort($status, $decision->message());
    }

    protected function resolveRedirect(mixed $redirect): string
    {
        $redirect = (string) $redirect;

        return Route::has($redirect) ? route($redirect) : $redirect;
    }
}
