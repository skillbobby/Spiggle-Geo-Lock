# Filament Geo Lock

[![Latest Version on Packagist](https://img.shields.io/packagist/v/geolock/filament-geo-lock.svg?style=flat-square)](https://packagist.org/packages/geolock/filament-geo-lock)
[![Tests](https://img.shields.io/github/actions/workflow/status/skillbobby/Spiggle-Geo-Lock/run-tests.yml?label=tests&style=flat-square)](https://github.com/skillbobby/Spiggle-Geo-Lock/actions/workflows/run-tests.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/geolock/filament-geo-lock.svg?style=flat-square)](https://packagist.org/packages/geolock/filament-geo-lock)
[![License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE.md)

**Rule based geographic access control for Filament admin panels.**

Geo Lock blocks (or allows) access to your Filament panels depending on where a visitor
is connecting from and which roles they hold. Rules are managed from a Filament page,
stored in the database, cached for speed and enforced by a middleware - backed by
[`torann/geoip`](https://github.com/Torann/laravel-geoip) for IP resolution.

```text
Request  ->  EnforceGeoLock  ->  resolve IP (torann/geoip + cache)
                                   |
                                   +-> emergency bypass? (role, email, id, IP, gate, env)
                                   +-> private/reserved IP? -> allow
                                   +-> evaluate active rules for the user's roles
                                          blacklist match          -> 403
                                          no whitelist match       -> 403
                                          otherwise                -> next()
```

## Features

- **Whitelist and blacklist rules** - per country, per region (ISO 3166-2), per role.
- **A proper Filament UI** - a collapsible repeater *settings page* for bulk editing, and a
  full *resource* (list, create, edit, delete, filters, drag & drop priority, activate/deactivate).
- **Live location card** on the settings page: it shows exactly what the middleware resolves
  for your own request, and which rule decided.
- **Simulate any IP** against the current rule set straight from the panel.
- **Emergency bypass** so you can never lock yourself out: roles, emails, user IDs, IPs,
  environments, a Gate ability, or your own closure.
- **Fail-open or fail-closed** - configure what happens when an IP cannot be resolved or when
  the geolocation service itself fails (`whitelist_only` is the default: whitelist-bound
  visitors are blocked until the service is back).
- **Trusted proxy hardening** - optionally refuse requests that carry spoofable
  `X-Forwarded-For` headers without a matching TrustedProxy configuration.
- **Events** (`GeoLockAccessDenied`, `GeoLockAccessGranted`, `GeoLockBypassed`) for audit
  logging, Slack/Discord alerts or your own analytics.
- **Built-in audit trail** with a table widget, a chart widget and a stats overview widget,
  plus `geo-lock:prune` retention.
- **Fast** - rules and locations are cached; the whole check stays well inside the 50ms budget.
- **Ships the data** - 249 countries and 5,046 ISO 3166-2 subdivisions bundled, no extra
  package required for the country/region selects.
- **Testable** - `GeoLock::fake([...])`, model factories and a Testbench workbench app.

## Requirements

| Dependency | Version |
| --- | --- |
| PHP | 8.2+ |
| Laravel | 11, 12 or 13 |
| Filament | 4 or 5 |
| [`torann/geoip`](https://github.com/Torann/laravel-geoip) | ^3.0 |
| `spatie/laravel-permission` | optional, recommended (role detection is automatic) |

## Installation

```bash
composer require geolock/filament-geo-lock
```

Publish the configuration, migrations and translations:

```bash
php artisan vendor:publish --tag=geo-lock-config
php artisan vendor:publish --tag=geo-lock-migrations
php artisan vendor:publish --tag=geo-lock-translations   # optional

php artisan migrate
```

Register the plugin on your panel (`app/Providers/Filament/AdminPanelProvider.php`):

```php
use GeoLock\GeoLockPlugin;

public function panel(Panel $panel): Panel
{
    return $panel
        ->default()
        ->id('admin')
        ->path('admin')
        ->plugin(GeoLockPlugin::make());
}
```

The plugin registers the middleware on the panel, plus the settings page, the rules resource
and the widgets. Prefer to do it by hand? Opt out of the automatic middleware registration
in the config (`middleware.auto_register => false`) and add it yourself:

```php
use GeoLock\Http\Middleware\EnforceGeoLock;

$panel->middleware([EnforceGeoLock::class], isPersistent: true);
```

### Configure torann/geoip

Geo Lock delegates the actual IP lookup to `torann/geoip`:

```bash
php artisan vendor:publish --tag=geoip
```

Use a **local MaxMind database** for production so the middleware never waits for an external
API:

```php
// config/geoip.php
'service' => 'maxmind_database',

'services' => [
    'maxmind_database' => [
        'class' => \Torann\GeoIP\Services\MaxMindDatabase::class,
        'database_path' => storage_path('app/geoip.mmdb'),
        'update_url' => 'https://download.maxmind.com/app/geoip_download?edition_id=GeoLite2-City&license_key=%s&suffix=tar.gz',
        'license_key' => env('MAXMIND_LICENSE_KEY'),
    ],
],
```

Anything torann/geoip supports (MaxMind database, MaxMind web service, ipapi, ipdata, ...)
works, and you can always plug in your own resolver - see
[docs/extending.md](docs/extending.md).

## Quick start

Open **Security -> Geo Lock Settings** in your panel and add a rule:

| Field | Value |
| --- | --- |
| Roles | `editor`, `moderator` |
| Rule type | Blacklist |
| Countries | Jamaica (JM), United States (US) |
| Regions | *(empty - the whole country)* |
| Rule active | on |

Editors and moderators can now reach the panel from anywhere **except** Jamaica and the
United States. Everybody else (super admins, other roles, visitors without those roles) is
unaffected - rules only ever apply to the roles you pick.

Switch the rule type to **Whitelist** and the meaning flips: only the selected countries
(and regions) are allowed, everything else is blocked for those roles.

### Rule reference

| Field | Column | Notes |
| --- | --- | --- |
| Roles | `roles` (JSON) | Empty list = the rule applies to *every* role (including guests, if `access.apply_rules_without_roles` is on). |
| Rule type | `type` | `whitelist` or `blacklist`. Blacklist always wins when both match. |
| Countries | `countries` (JSON) | ISO 3166-1 alpha-2 codes. Derived automatically from the selected regions. |
| Regions | `regions` (JSON) | ISO 3166-2 subdivision codes. Empty = the whole country. |
| Priority | `priority` | Higher priority is evaluated first; drag & drop in the table reorders it. |
| Is active | `is_active` | Disable a rule without deleting it. |
| Name / description | `name`, `description` | Purely informational, shown in the UI and the audit trail. |

### How a decision is made

1. The visitor is resolved to a location (`torann/geoip`, cached per IP).
2. The emergency bypass is checked (roles, emails, user IDs, IPs, environment, Gate ability,
   custom closures) - a match grants access immediately.
3. Private/reserved addresses (localhost, RFC1918, ...) are allowed when
   `access.bypass_private_ips` is on, because no GeoIP database can locate them.
4. Active rules that mention one of the visitor's roles are collected (ordered by priority).
5. A matching **blacklist** rule blocks the request. Otherwise, if the visitor is bound by
   **whitelist** rules and none of them matches, the request is blocked.
6. Unresolvable locations and resolver failures follow `access.unresolvable` and
   `access.on_resolver_failure`.
7. Allowed requests continue to your panel; blocked requests receive an HTTP 403
   (JSON payload for JSON requests) and fire `GeoLockAccessDenied`.

Read the full semantics, with worked examples, in [docs/rules.md](docs/rules.md).

## Configuration

Everything lives in `config/geo-lock.php` - here is the shape of it:

```php
return [
    'enabled' => env('GEO_LOCK_ENABLED', true),

    'middleware' => [
        'enabled' => true,
        'auto_register' => true,          // guard every panel automatically
        'persistent' => true,             // keep guarding Livewire requests
        'guard' => null,
        'panels' => [],                   // limit to these panel ids
        'except_panels' => [],
    ],

    'resolver' => [
        'driver' => env('GEO_LOCK_RESOLVER', 'torann'),
        'drivers' => ['torann' => ..., 'null' => ..., 'array' => ...],
        'fallbacks' => [],
        'cache' => ['enabled' => true, 'store' => null, 'ttl' => 86400, 'prefix' => 'geo-lock:location'],
    ],

    'rules' => [
        'model' => GeoLock\Models\GeoLockRule::class,
        'table' => 'geo_lock_rules',
        'cache' => ['enabled' => true, 'store' => null, 'ttl' => 300, 'key' => 'geo-lock:rules'],
    ],

    'access' => [
        'unresolvable' => UnresolvableIpBehavior::Allow,
        'on_resolver_failure' => ResolverFailureBehavior::WhitelistOnly,
        'bypass_private_ips' => true,
        'require_trusted_proxy' => false,
        'apply_rules_without_roles' => true,
        'guest_role' => 'guest',
    ],

    'bypass' => [
        'enabled' => true,
        'roles' => ['super_admin'],
        'emails' => [],
        'user_ids' => [],
        'ips' => [],
        'environments' => [],
        'ability' => 'geo-lock.bypass',
    ],

    'denial' => ['status' => 403, 'message' => null, 'view' => null, 'redirect' => null],

    'audit' => ['enabled' => true, 'table' => 'geo_lock_attempts', 'record_grants' => false, 'record_bypasses' => false, 'keep_days' => 90],

    'roles' => ['provider' => 'auto', 'attribute' => 'roles', 'list' => []],

    'ui' => [/* navigation group, icons, resource/page/widget toggles, authorization */],
];
```

The complete reference, including every environment variable, is in
[docs/configuration.md](docs/configuration.md).

## Artisan commands

```bash
# Evaluate the rules for an IP address (exit code 1 when denied)
php artisan geo-lock:check 203.0.113.7 --role=editor --json

# Diagnose config, database, resolver, cache, trusted proxies and bypass
php artisan geo-lock:doctor

# Trim the audit trail
php artisan geo-lock:prune --days=90 --dry-run
```

## Events

| Event | Fired when |
| --- | --- |
| `GeoLock\Events\GeoLockAccessDenied` | The middleware blocks a request. |
| `GeoLock\Events\GeoLockAccessGranted` | A request is allowed through (including "no applicable rules"). |
| `GeoLock\Events\GeoLockBypassed` | A visitor uses the emergency bypass. |

Every event carries a `GeoLock\Data\GeoLockDecision` (`$event->decision`) with the outcome,
the resolved `GeoLocation`, the matched rule, the roles, the reason and a `message()`.

```php
use GeoLock\Events\GeoLockAccessDenied;
use Illuminate\Support\Facades\Log;

Event::listen(function (GeoLockAccessDenied $event): void {
    Log::warning('Blocked panel access', $event->decision->toArray());
});
```

The plugin ships with a listener that stores denials (and optionally grants/bypasses) in the
`geo_lock_attempts` table, which powers the dashboard widgets.

## The facade

```php
use GeoLock\Facades\GeoLock;

GeoLock::resolve('203.0.113.7');                       // GeoLocation
GeoLock::evaluate($user, '203.0.113.7');               // GeoLockDecision
GeoLock::allows($user, '203.0.113.7');                 // bool
GeoLock::denies($user, '203.0.113.7');                 // bool
GeoLock::bypassReason($user, '203.0.113.7');           // 'role:super_admin' | null
GeoLock::rolesFor($user);                              // ['editor', 'moderator']
GeoLock::rules();                                      // Collection<int, GeoLockRule>
GeoLock::flush();                                      // drop the rule/location caches
```

## Testing

```php
use GeoLock\Facades\GeoLock;
use GeoLock\Models\GeoLockRule;

GeoLock::fake(['203.0.113.7' => ['country_code' => 'JM', 'region_code' => 'JM-01']]);

GeoLockRule::factory()->blacklist()->forCountries(['JM'])->forRoles(['editor'])->create();

GeoLock::denies($editor, '203.0.113.7'); // true
```

Factories, fakes, the Testbench workbench app and everything else is documented in
[docs/testing.md](docs/testing.md).

## Security

- Configure Laravel's `TrustProxies` middleware and enable
  `access.require_trusted_proxy` so visitors cannot spoof `X-Forwarded-For`.
- `access.on_resolver_failure` defaults to `whitelist_only`: if the geolocation service dies,
  visitors bound by a whitelist rule are blocked rather than let in.
- Keep the emergency bypass configured (`bypass.roles` at least) - and prefer the Gate ability
  or your own closure over a hardcoded list when several people need it.
- Audit denials, review the widgets, and prune the table regularly.

The production checklist lives in [docs/security.md](docs/security.md).

## Performance

- The rule set is cached (`rules.cache.ttl`, invalidated automatically whenever a rule is
  saved or deleted).
- Resolved locations are cached per IP (`resolver.cache.ttl`), including "could not resolve"
  results so a broken GeoIP database cannot slow every request down.
- Use a local MaxMind database to keep the lookup in the sub-millisecond range, and a shared
  cache store (Redis/Memcached) when you run more than one server.

## Documentation

| Document | Content |
| --- | --- |
| [docs/installation.md](docs/installation.md) | Requirements, publishing, panel registration, geoip setup. |
| [docs/configuration.md](docs/configuration.md) | Every configuration key and environment variable. |
| [docs/rules.md](docs/rules.md) | Rule model and evaluation semantics with examples. |
| [docs/security.md](docs/security.md) | Trusted proxies, fail-closed behaviour, bypass, audit. |
| [docs/extending.md](docs/extending.md) | Custom resolvers, events, repositories, role directories. |
| [docs/testing.md](docs/testing.md) | Fakes, factories and the workbench app. |
| [docs/troubleshooting.md](docs/troubleshooting.md) | `geo-lock:doctor` and common problems. |

## Compatibility

Filament 4 and Filament 5 share the same public API for everything this plugin uses, so a
single code base supports both (and Laravel 11, 12 and 13). The test suite runs against
Filament 4 and 5 on PHP 8.3 and 8.4.

## Development

```bash
composer install
composer test        # pest
composer lint        # pint
composer analyse     # phpstan (larastan, level 5)

# boot the workbench app with a demo panel at /admin
vendor/bin/testbench serve
```

## Contributing

Pull requests are welcome. Please run `composer check` (lint + analyse + test) before opening
one, and add tests for new behaviour.

## License

MIT. See [LICENSE.md](LICENSE.md).
