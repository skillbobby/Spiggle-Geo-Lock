# Extending

The plugin is built around a small set of contracts and events. This page shows
how to plug in a custom geolocation provider, react to decisions, replace the
storage layer, customise the denial response and control the UI authorization.

- [Custom GeoResolver](#custom-georesolver)
- [Fallback resolvers](#fallback-resolvers)
- [Decorating with CacheGeoResolver](#decorating-with-cachegeoresolver)
- [Listening to events](#listening-to-events)
- [Replacing the rule repository](#replacing-the-rule-repository)
- [Custom role directories](#custom-role-directories)
- [The GeoLock facade](#the-geolock-facade)
- [Customising the denial response](#customising-the-denial-response)
- [The Authorizer](#the-authorizer)

## Custom GeoResolver

Implement `GeoLock\Contracts\GeoResolver` to plug any geolocation provider into
the plugin:

```php
namespace App\Geo;

use GeoLock\Contracts\GeoResolver;
use GeoLock\Data\GeoLocation;
use GeoLock\Support\IpAddress;

final class IpInfoResolver implements GeoResolver
{
    public function __construct(
        private readonly string $token,
    ) {}

    public function resolve(string $ip): ?GeoLocation
    {
        if (! IpAddress::isPublic($ip)) {
            return GeoLocation::unknown($ip, $this->name(), isPrivate: true);
        }

        $response = \Illuminate\Support\Facades\Http::withToken($this->token)
            ->timeout(2)
            ->get("https://ipinfo.io/{$ip}/json");

        if ($response->failed()) {
            return null; // the manager treats null as "could not resolve"
        }

        $country = strtoupper((string) $response->json('country'));

        if ($country === '') {
            return null;
        }

        return new GeoLocation(
            ip: $ip,
            countryCode: $country,
            regionCode: $response->json('region') ? "{$country}-{$response->json('region')}" : null,
            city: $response->json('city'),
            postalCode: $response->json('postal'),
            resolver: $this->name(),
        );
    }

    public function name(): string
    {
        return 'ipinfo';
    }
}
```

Contract rules:

- `resolve()` returns a `GeoLocation`, or `null` when the address cannot be
  resolved. Returning `null` is not an error - it feeds the `unresolvable`
  behaviour.
- `resolve()` **may throw**. The manager catches the exception, reports it, and
  applies `access.on_resolver_failure`.
- `name()` is a short identifier used in audit rows, logs and the doctor output.
- Mark non-public addresses with `isPrivate: true` so
  `access.bypass_private_ips` can skip them.

Register it through the config:

```php
// config/geo-lock.php
'resolver' => [
    'driver' => 'ipinfo',                       // or env('GEO_LOCK_RESOLVER')

    'drivers' => [
        'ipinfo' => App\Geo\IpInfoResolver::class,
        'torann' => GeoLock\Resolvers\TorannGeoResolver::class,
        'null' => GeoLock\Resolvers\NullGeoResolver::class,
        'array' => GeoLock\Resolvers\ArrayGeoResolver::class,
    ],
    // ...
],
```

If your resolver needs constructor dependencies, bind it in a service provider
and let the container build it (the plugin resolves the class through
`$this->app->make()`). You can also point `resolver.driver` directly at a
class-string; the provider falls back to treating the value as the class name.
If the class cannot be found or does not implement the contract, a
`NullGeoResolver` is used.

## Fallback resolvers

`resolver.fallbacks` lists additional drivers tried, in order, when the primary
one does not return a resolved country:

```php
'resolver' => [
    'driver' => 'torann',
    'fallbacks' => ['ipinfo'],   // web service used only if the local DB misses
    'drivers' => [
        'torann' => GeoLock\Resolvers\TorannGeoResolver::class,
        'ipinfo' => App\Geo\IpInfoResolver::class,
    ],
],
```

The provider wraps everything in `GeoLock\Resolvers\FallbackGeoResolver`, which
returns the first resolved location. Exceptions in a fallback are swallowed (the
previous location is kept) so a failing fallback does not break the chain. Its
`name()` is `fallback(torann,ipinfo)`.

## Decorating with CacheGeoResolver

When `resolver.cache.enabled` is `true`, the provider automatically wraps the
(final) resolver in `GeoLock\Resolvers\CacheGeoResolver`. You rarely need to do
this yourself, but you can compose it manually - for example to cache only in
one environment, or to wrap a resolver that is not built by the provider:

```php
use GeoLock\Resolvers\CacheGeoResolver;

$resolver = new CacheGeoResolver(
    resolver: new App\Geo\IpInfoResolver($token),
    cache: app(\Illuminate\Contracts\Cache\Factory::class),
    store: null,                        // default store
    ttl: 60 * 60 * 12,                  // 12 hours
    prefix: 'geo-lock:location',
);
```

`CacheGeoResolver` caches the array form of the `GeoLocation` under
`prefix . ':' . md5($ip)`, including unresolved locations, and skips private
addresses. Its `inner()` method returns the decorated resolver, `name()`
delegates to it, and `forget($ip)` evicts a single entry.
`GeoLock::flush()` clears the request-level memo and the rule cache; use the
settings page's **Flush cache** action for the same effect.

## Listening to events

Three events are dispatched around every guarded request. Each carries a public
`GeoLockDecision $decision`:

| Event | Fired when |
| --- | --- |
| `GeoLock\Events\GeoLockAccessDenied` | A request is denied. |
| `GeoLock\Events\GeoLockAccessGranted` | A request is allowed. |
| `GeoLock\Events\GeoLockBypassed` | A request is bypassed. |

Example listener that posts denials to a Slack incoming webhook:

```php
namespace App\Listeners;

use GeoLock\Events\GeoLockAccessDenied;
use Illuminate\Support\Facades\Http;

class AlertOnGeoLockDenial
{
    public function handle(GeoLockAccessDenied $event): void
    {
        $decision = $event->decision;

        Http::post(config('services.slack.webhook'), [
            'text' => sprintf(
                'Geo-lock denied %s from %s (%s) - rule: %s',
                $decision->user?->getAuthIdentifier() ?? 'guest',
                $decision->location->label(),
                $decision->location->ip,
                $decision->rule?->label() ?? '-',
            ),
        ]);
    }
}
```

Register it in your `EventServiceProvider`:

```php
protected $listen = [
    \GeoLock\Events\GeoLockAccessDenied::class => [
        \App\Listeners\AlertOnGeoLockDenial::class,
    ],
];
```

The `GeoLockDecision` exposes `outcome` (`GeoLockOutcome`), `location`
(`GeoLocation`), `rule`, `user`, `roles`, `reason`, `evaluatedRules`,
`resolverFailed`, plus `allowed()`, `denied()`, `isBypassed()`, `message()` and
`toArray()`.

To also record grants or bypasses in the audit table, set
`audit.record_grants` / `audit.record_bypasses` to `true` (see
[Configuration](configuration.md#audit)).

## Replacing the rule repository

Rules are read through `GeoLock\Contracts\GeoLockRuleRepository`. Bind your own
implementation in a service provider to store rules somewhere other than the
database (config, a remote service, a tenant-scoped table):

```php
use GeoLock\Contracts\GeoLockRuleRepository;
use App\Repositories\RemoteRuleRepository;

public function register(): void
{
    $this->app->bind(GeoLockRuleRepository::class, RemoteRuleRepository::class);
}
```

The contract requires: `all(bool $activeOnly = true): Collection`,
`find(int|string $id)`, `create(array $attributes)`, `update(GeoLockRule $rule,
array $attributes)`, `delete(GeoLockRule $rule)`, `sync(array $rules)`,
`count(bool $activeOnly = false)` and `flush()`.

`all()` must return `GeoLockRule` models (or a subclass). Return them ordered for
evaluation (`priority` descending, then `id`) so the reported matched rule is
consistent. `sync()` is what the settings page calls: rules are matched by their
`id` key, unknown entries are created and entries missing from the payload are
deleted. Call `flush()` after any write.

The shipped `DatabaseGeoLockRuleRepository` also normalises input: it casts
`type` through `RuleType::fromMixed()`, casts `is_active` and `priority`, splits
string role/country lists, uppercases codes, and derives countries from region
codes. Reuse or reproduce that behaviour in a custom repository.

## Custom role directories

Roles are discovered through `GeoLock\Contracts\RoleDirectory`. The shipped
implementations are selected by `roles.provider` (`spatie`, `attribute`,
`config`, `none`, or `auto`). For anything else, bind the contract:

```php
namespace App\Geo;

use GeoLock\Contracts\RoleDirectory;
use Illuminate\Contracts\Auth\Authenticatable;

final class TeamRoleDirectory implements RoleDirectory
{
    public function rolesFor(?Authenticatable $user): array
    {
        if ($user === null) {
            return [];
        }

        return $user->teams()->pluck('role')->unique()->values()->all();
    }

    public function availableRoles(): array
    {
        return [
            'owner' => 'owner',
            'editor' => 'editor',
            'viewer' => 'viewer',
        ];
    }

    public function name(): string
    {
        return 'teams';
    }
}
```

```php
$this->app->bind(\GeoLock\Contracts\RoleDirectory::class, \App\Geo\TeamRoleDirectory::class);
```

`rolesFor()` returns the visitor's role names; it is called for both the
middleware and the bypass check. `availableRoles()` returns `role => label`
pairs used by the rule form and the role filters; return an empty array to make
the form fall back to a free-text tags input. `name()` appears in the doctor
output.

Note that `GeoLockManager::rolesFor(null)` short-circuits for guests and returns
`[config('geo-lock.access.guest_role')]` (default `['guest']`) without calling
the directory, so a custom directory never has to handle `null` in the
middleware path - but it should still be null-safe.

## The GeoLock facade

`GeoLock\Facades\GeoLock` proxies the `GeoLockManager` singleton (aliased as
`geo-lock`). The documented API:

| Call | Returns | Purpose |
| --- | --- | --- |
| `GeoLock::resolve(string $ip)` | `GeoLocation` | Resolve an IP, memoised for the request. |
| `GeoLock::evaluate(?Authenticatable $user = null, ?string $ip = null, ?Request $request = null, ?array $roles = null, bool $applyBypass = true)` | `GeoLockDecision` | Full evaluation, including the bypass unless `$applyBypass` is `false`. |
| `GeoLock::allows(...)` | `bool` | `evaluate(...)->allowed()`. |
| `GeoLock::denies(...)` | `bool` | `evaluate(...)->denied()`. |
| `GeoLock::bypassReason(?Authenticatable $user, ?string $ip = null)` | `string\|null` | The bypass reason, or `null`. |
| `GeoLock::rolesFor(?Authenticatable $user)` | `array<int, string>` | Roles, with the guest role for `null`. |
| `GeoLock::fake(array $locations = [])` | `ArrayGeoResolver` | Swap in an in-memory resolver. See [Testing](testing.md). |
| `GeoLock::flush()` | `void` | Clear the request memo and the rule cache. |
| `GeoLock::rules(bool $activeOnly = true)` | `Collection` | The loaded rules. |

Additional manager methods reachable through the facade (not listed in its
docblock but available via `__callStatic`):

| Call | Purpose |
| --- | --- |
| `GeoLock::useResolver(GeoResolver $resolver)` | Use another resolver for the rest of the request. |
| `GeoLock::resetResolver()` | Restore the configured resolver. |
| `GeoLock::repository()` | The `GeoLockRuleRepository`. |
| `GeoLock::roleDirectory()` | The `RoleDirectory`. |
| `GeoLock::clientIp(?Request $request = null)` | The resolved client IP. |
| `GeoLock::forgetLocation(string $ip)` | Forget a memoised location (and its cache entry). |
| `GeoLock::isEnabled()` | The `enabled` config flag. |
| `GeoLock::isUntrustedProxyRequest(?Request $request = null)` | The proxy-header check. |

```php
use GeoLock\Facades\GeoLock;

if (GeoLock::denies($user, '203.0.113.7')) {
    // ...
}

$location = GeoLock::resolve('203.0.113.7');
$location->countryCode();   // e.g. "US"
$location->label();         // e.g. "Miami, Florida, United States"
```

## Customising the denial response

`EnforceGeoLock::deny()` builds the response in a fixed order.

### JSON requests

Any request with `Accept: application/json` always receives JSON, regardless of
the `denial` configuration:

```json
{
    "message": "Access to this panel is not allowed from Jamaica (JM).",
    "outcome": "denied_blacklist",
    "allowed": false,
    "reason": "blacklisted",
    "message": "...",
    "rule_id": 4,
    "rule_name": "Block Jamaica",
    "roles": ["editor"],
    "user_id": 12,
    "evaluated_rules": 2,
    "resolver_failed": false,
    "location": {
        "ip": "203.0.113.7",
        "country_code": "JM",
        "country_name": "Jamaica",
        "region_code": "JM-01",
        "region_name": "Kingston",
        "city": "Kingston",
        "postal_code": null,
        "latitude": 17.997,
        "longitude": -76.793,
        "resolver": "torann",
        "is_private": false,
        "is_resolved": true
    }
}
```

The status code comes from `denial.status` (default 403).

### HTML requests

1. `denial.redirect` - a URL or a route name. Route names are detected with
   `Route::has()` and resolved with `route()`.
2. `denial.view` - any Blade view, rendered with a `$decision` variable:

```php
// config/geo-lock.php
'denial' => [
    'status' => 403,
    'message' => null,
    'view' => 'errors.geo-locked',
    'redirect' => null,
],
```

```blade
{{-- resources/views/errors/geo-locked.blade.php --}}
<!DOCTYPE html>
<html lang="en">
<head><title>Access denied</title></head>
<body>
    <h1>{{ $decision->message() }}</h1>
    <p>Outcome: {{ $decision->outcome->value }}</p>
    <p>Your location: {{ $decision->location->label() }}</p>
</body>
</html>
```

3. Otherwise `abort($status, $decision->message())`.

### Messages

`denial.message`, when set, overrides the message for **every** outcome. When it
is `null`, `GeoLockDecision::message()` selects a translated string per outcome
(`denial.blacklist`, `denial.whitelist`, `denial.unresolvable`,
`denial.resolver_failure`, `denial.untrusted_proxy`, `denial.generic`). Publish
the translations with `--tag=geo-lock-translations` to override the wording.

## The Authorizer

`GeoLock\Support\Authorizer` decides whether the current user may manage rules.
It is consulted by `GeoLockSettings::canAccess()`, every `can*()` method on
`GeoLockRuleResource`, the toggle action and the three widgets. The rule comes
from `geo-lock.ui.authorization.manage`:

| Value | Behaviour |
| --- | --- |
| `null` or `''` | Everybody may manage rules (including guests). |
| `true` / `false` | Literal answer. |
| `string` | A Gate ability checked with `Gate::forUser($user)->allows($ability)`. |
| `Closure` | Called with the user; the return value is cast to `bool`. |

```php
// config/geo-lock.php
'ui' => [
    'authorization' => [
        'manage' => 'manage-geo-lock',   // Gate ability
    ],
],
```

```php
// or from the panel provider:
GeoLockPlugin::make()
    ->authorizeUsing(fn ($user): bool => $user?->hasRole('super_admin') ?? false);
```

`Authorizer::user()` prefers the current Filament panel's authenticated user and
falls back to the default guard, so the checks work both inside and outside the
panel. Any value the authorizer does not understand resolves to `false`
(deny).

`ui.authorization.bypass` is read by `Authorizer::canBypass()` using the same
resolution rules; no built-in UI calls it yet, so it is available for
application code that wants to expose its own bypass-management surface.

Next: [Testing](testing.md).
