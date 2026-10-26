<?php

namespace Spiggle\GeoLock\Http\Middleware;

use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Spiggle\GeoLock\Services\AccessDecider;
use Spiggle\GeoLock\Services\DenialRecorder;
use Spiggle\GeoLock\Services\RequestGeoLocator;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class EnforceGeoLock
{
    public function __construct(
        private RequestGeoLocator $locator,
        private AccessDecider $decider,
        private DenialRecorder $recorder,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $this->isRulesPage($request)) {
            return $next($request);
        }

        $location = $this->locator->locate($request);
        $decision = $this->decider->decide($user, $location);

        if ($decision->allowed) {
            return $next($request);
        }

        try {
            $this->recorder->record($user, $location, $decision, $request);
        } catch (Throwable) {
            // A missing log table must not open the panel.
        }

        throw new HttpResponseException(response()->view('geo-lock::locked', [
            'decision' => $decision,
        ], 403));
    }

    /**
     * Livewire checks this middleware again after the snapshot checksum,
     * using the original page route. The route name is the exemption.
     * A snapshot that merely contains the page name is not.
     */
    private function isRulesPage(Request $request): bool
    {
        $name = $request->route()?->getName();

        return is_string($name) && str_ends_with($name, '.pages.geo-lock-rules');
    }
}
