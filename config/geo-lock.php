<?php

declare(strict_types=1);

use GeoLock\Enums\ResolverFailureBehavior;
use GeoLock\Enums\UnresolvableIpBehavior;
use GeoLock\Models\GeoLockRule;
use GeoLock\Resolvers\ArrayGeoResolver;
use GeoLock\Resolvers\NullGeoResolver;
use GeoLock\Resolvers\TorannGeoResolver;

return [

    /*
    |--------------------------------------------------------------------------
    | Geo-lock enabled
    |--------------------------------------------------------------------------
    |
    | A global kill switch. When disabled, the middleware becomes a no-op and
    | no IP resolution or rule evaluation is performed. Rules can still be
    | managed from the admin panel.
    |
    */

    'enabled' => env('GEO_LOCK_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Middleware
    |--------------------------------------------------------------------------
    |
    | The `EnforceGeoLock` middleware is registered on every Filament panel
    | route. When `auto_register` is enabled it is applied to all panels
    | (filtered by `panels` / `except_panels`); you may also opt out and add
    | the middleware manually, or use the `GeoLockPlugin` on selected panels.
    |
    | `persistent` keeps the middleware on subsequent Livewire requests, so a
    | user cannot navigate around the panel after their session was opened
    | from an allowed location.
    |
    */

    'middleware' => [
        'enabled' => true,
        'auto_register' => true,
        'persistent' => true,
        'guard' => null,
        'panels' => [],
        'except_panels' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | IP resolution
    |--------------------------------------------------------------------------
    |
    | `driver` is the resolver used to turn an IP address into a location.
    | Available drivers: "torann" (torann/geoip), "null" (never resolves).
    | You may register your own driver by pointing the key at a class-string
    | that implements GeoLock\Contracts\GeoResolver.
    |
    | `fallbacks` are tried in order when the primary driver cannot resolve an
    | address. Resolved locations can be cached to keep the middleware well
    | inside its 50ms budget.
    |
    */

    'resolver' => [
        'driver' => env('GEO_LOCK_RESOLVER', 'torann'),

        'drivers' => [
            'torann' => TorannGeoResolver::class,
            'null' => NullGeoResolver::class,
            'array' => ArrayGeoResolver::class,
        ],

        'fallbacks' => [],

        'cache' => [
            'enabled' => env('GEO_LOCK_CACHE_ENABLED', true),
            'store' => env('GEO_LOCK_CACHE_STORE'),
            'ttl' => (int) env('GEO_LOCK_CACHE_TTL', 60 * 60 * 24),
            'prefix' => 'geo-lock:location',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Rules
    |--------------------------------------------------------------------------
    |
    | Rules live in the database so they can be managed at runtime. The rule
    | set is cached because it is read on (nearly) every request.
    |
    */

    'rules' => [
        'model' => GeoLockRule::class,
        'table' => 'geo_lock_rules',

        'cache' => [
            'enabled' => env('GEO_LOCK_CACHE_ENABLED', true),
            'store' => env('GEO_LOCK_CACHE_STORE'),
            'ttl' => (int) env('GEO_LOCK_RULES_CACHE_TTL', 300),
            'key' => 'geo-lock:rules',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Access behaviour
    |--------------------------------------------------------------------------
    |
    | `unresolvable`: what to do when the visitor's country cannot be
    | determined (unknown IP, missing database, ...). "allow" (fail open) is
    | friendlier for local development, "deny" (fail closed) is the strict
    | production posture.
    |
    | `on_resolver_failure`: what to do when the resolver itself throws (geo
    | database missing, remote API unreachable, ...). "whitelist_only" blocks
    | only visitors who are bound by a whitelist rule, which is the safest
    | default; "fail_closed" blocks every bound visitor and "fail_open" never
    | blocks.
    |
    | `bypass_private_ips` skips evaluation for localhost and RFC1918/RFC4193
    | addresses - these can never be resolved by a GeoIP database.
    |
    | `require_trusted_proxy` refuses requests that carry proxy headers
    | (X-Forwarded-For, ...) without a matching TrustedProxy configuration.
    | This prevents a visitor from spoofing their own location. Enable it in
    | production, and configure `TrustProxies` in your application.
    |
    */

    'access' => [
        'unresolvable' => UnresolvableIpBehavior::Allow,
        'on_resolver_failure' => ResolverFailureBehavior::WhitelistOnly,
        'bypass_private_ips' => true,
        'require_trusted_proxy' => (bool) env('GEO_LOCK_REQUIRE_TRUSTED_PROXY', false),
        'apply_rules_without_roles' => true,
        'guest_role' => 'guest',
    ],

    /*
    |--------------------------------------------------------------------------
    | Emergency bypass
    |--------------------------------------------------------------------------
    |
    | The escape hatch that keeps an administrator from locking themselves out
    | of their own panel. Every check below is evaluated before the rules are,
    | and any match grants access (a `GeoLockBypassed` event is fired).
    |
    | `ability` is checked against the Gate, which allows applications to grant
    | the bypass dynamically (e.g. "geo-lock.bypass" for support staff).
    |
    */

    'bypass' => [
        'enabled' => true,
        'roles' => ['super_admin'],
        'emails' => [],
        'user_ids' => [],
        'ips' => [],
        'environments' => [],
        'ability' => 'geo-lock.bypass',
    ],

    /*
    |--------------------------------------------------------------------------
    | Denial response
    |--------------------------------------------------------------------------
    |
    | What a blocked visitor receives. `view` may be any Blade view (it is
    | rendered with a `$decision` variable), `redirect` may be a URL or a route
    | name. JSON requests always receive a JSON payload with the decision.
    |
    */

    'denial' => [
        'status' => 403,
        'message' => null,
        'view' => null,
        'redirect' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit trail
    |--------------------------------------------------------------------------
    |
    | Every denial (and optionally every grant or bypass) can be persisted for
    | auditing, dashboards and alerting. Use `geo-lock:prune` to trim the table.
    |
    */

    'audit' => [
        'enabled' => true,
        'table' => 'geo_lock_attempts',
        'record_grants' => false,
        'record_bypasses' => false,
        'keep_days' => 90,
    ],

    /*
    |--------------------------------------------------------------------------
    | Roles
    |--------------------------------------------------------------------------
    |
    | `provider` decides how the roles of the current user are discovered:
    | "auto" (spatie/laravel-permission when installed, then the attribute
    | below), "spatie", "attribute", "config" or "none".
    |
    */

    'roles' => [
        'provider' => 'auto',
        'attribute' => 'roles',
        'list' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | User interface
    |--------------------------------------------------------------------------
    |
    | Where the rules UI lives inside the panel, and who may open it. The
    | `authorization.manage` value may be a Gate ability name, a boolean or a
    | closure receiving the current user.
    |
    */

    'ui' => [
        'navigation_group' => 'Security',
        'navigation_icon' => 'heroicon-o-globe-alt',
        'navigation_sort' => null,

        'rule_resource' => [
            'enabled' => true,
            'label' => null,
            'slug' => null,
        ],

        'settings_page' => [
            'enabled' => true,
            'slug' => 'geo-lock',
        ],

        'widgets' => [
            'enabled' => true,
            'stats' => true,
            'recent_attempts' => true,
            'attempts_chart' => true,
        ],

        'authorization' => [
            'manage' => null,
            'bypass' => null,
        ],
    ],

];
