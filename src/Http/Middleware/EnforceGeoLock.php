<?php

namespace Spiggle\GeoLock\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Spiggle\GeoLock\Services\AccessDecider;
use Spiggle\GeoLock\Services\RequestGeoLocator;
use Symfony\Component\HttpFoundation\Response;

class EnforceGeoLock
{
    public function __construct(
        private RequestGeoLocator $locator,
        private AccessDecider $decider,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $this->isRulesPage($request)) {
            return $next($request);
        }

        $decision = $this->decider->decide($user, $this->locator->locate($request));

        if ($decision->allowed) {
            return $next($request);
        }

        return response()->view('geo-lock::locked', [
            'decision' => $decision,
        ], 403);
    }

    private function isRulesPage(Request $request): bool
    {
        $name = $request->route()?->getName();

        if (is_string($name) && str_ends_with($name, '.pages.geo-lock-rules')) {
            return true;
        }

        if (! $request->is('livewire/*')) {
            return false;
        }

        foreach ((array) $request->input('components', []) as $component) {
            $snapshot = $component['snapshot'] ?? '';

            if (is_string($snapshot) && str_contains($snapshot, 'GeoLockRules')) {
                return true;
            }
        }

        return false;
    }
}
