# Security

This page explains the security-relevant behaviour of the middleware: how the
client IP is trusted, how the plugin behaves when something fails, the
emergency bypass, private-address handling, and the audit trail.

- [Trusted proxies and header spoofing](#trusted-proxies-and-header-spoofing)
- [Fail-closed behaviour](#fail-closed-behaviour)
- [Emergency bypass](#emergency-bypass)
- [Private and reserved IP handling](#private-and-reserved-ip-handling)
- [Audit trail](#audit-trail)
- [Production checklist](#production-checklist)

## Trusted proxies and header spoofing

A visitor can send arbitrary `X-Forwarded-For` headers. If the application
trusts them without a matching proxy configuration, the visitor can claim any
location and defeat geo-locking entirely.

`EnforceGeoLock` resolves the client IP with
`$request->getClientIp() ?: $request->ip()` (`GeoLockManager::clientIp()`). The
value Laravel returns depends on the `TrustProxies` middleware: forwarded
headers are only honoured when the immediate peer is a trusted proxy.

### `access.require_trusted_proxy`

When enabled, `GeoLockManager::isUntrustedProxyRequest()` inspects the request:

- It returns `false` immediately if `access.require_trusted_proxy` is off.
- It looks for any of `X-Forwarded-For`, `X-Real-IP`, `Forwarded` or
  `CF-Connecting-IP`.
- If one of those headers is present **and** `$request->getTrustedProxies()` is
  empty, the request is treated as spoofed.

A spoofed request is denied with outcome `DeniedUntrustedProxy` and reason
`untrusted_proxy`, before any rule evaluation. The reported location is
`GeoLocation::unknown($ip, 'untrusted-proxy')`.

### Configuring `TrustProxies`

Enable strict mode only once your application knows which proxies to trust:

```env
GEO_LOCK_REQUIRE_TRUSTED_PROXY=true
```

```php
// app/Http/Middleware/TrustProxies.php
protected $proxies = [
    '10.0.0.0/8',          // internal load balancers
    '172.16.0.0/12',
    // or the specific addresses / ranges of your CDN or reverse proxy
];

protected $headers =
    Request::HEADER_X_FORWARDED_FOR |
    Request::HEADER_X_FORWARDED_HOST |
    Request::HEADER_X_FORWARDED_PORT |
    Request::HEADER_X_FORWARDED_PROTO;
```

Notes:

- The check is `getTrustedProxies() === []`. A wildcard `'*'` configuration is
  non-empty, so it satisfies the check, but trusting `*` in production reopens
  the spoofing hole. List concrete ranges instead.
- Behind a CDN, add the CDN's egress ranges and make sure the CDN strips or
  overwrites client-supplied forwarding headers.
- `php artisan geo-lock:doctor` reports the configured trusted proxies and warns
  when strict mode is on but none are set.

## Fail-closed behaviour

Two independent configuration keys control what happens when the plugin cannot
make a confident decision.

### `access.unresolvable`

Applied when at least one rule applies but the resolved location has no country
code (e.g. an address absent from the database).

| Value | Behaviour | Outcome / reason |
| --- | --- | --- |
| `allow` (default) | Grant access; friendlier for local development. | granted, `unresolvable_allowed` |
| `deny` | Deny access (fail closed). | `DeniedUnresolvable`, `unresolvable` |

### `access.on_resolver_failure`

Applied when the resolver itself **throws** - a missing GeoIP database, an
unreachable API, an unexpected exception. The exception is reported
(`report($exception)`) and the configured safety net runs.

| Value | Behaviour | Who is blocked |
| --- | --- | --- |
| `whitelist_only` (default) | Block only visitors bound by an applicable whitelist rule; blacklist-only visitors keep their access. | `$boundByWhitelist` |
| `fail_closed` | Block every visitor bound by any applicable rule. | all bound visitors |
| `fail_open` | Never block on resolver failure; geo-locking is suspended. | nobody |

Denials use outcome `DeniedResolverFailure`, reason `resolver_failure`, and set
`resolverFailed: true` on the decision. Grants use reason
`resolver_failure_fail_open`.

The enum `GeoLock\Enums\ResolverFailureBehavior` also accepts booleans via
`fromMixed()`: `true` maps to `whitelist_only`, `false` to `fail_open`.

Both behaviours are only reached when at least one rule applies to the visitor.
If the applicable rule set is empty, access is granted with `NoRules` before the
failure checks run.

For production, prefer `deny` + `whitelist_only` (or `fail_closed`) so a broken
GeoIP database cannot silently open the panel.

## Emergency bypass

The bypass is the escape hatch that keeps an administrator from locking
themselves out of their own panel. It is evaluated in
`GeoLockManager::evaluate()` **before** the rules, and any match grants access
with outcome `Bypassed` and fires `GeoLockBypassed`.

### Configuration checks

`BypassInspector::bypassReason()` evaluates the checks in this order and returns
the first reason that matches:

| Order | Check | Config key | Reason string |
| --- | --- | --- | --- |
| 1 | Application environment | `bypass.environments` | `environment:<env>` |
| 2 | Client IP | `bypass.ips` | `ip` |
| 3 | Authenticated user ID | `bypass.user_ids` | `user_id` |
| 4 | User email (case-insensitive) | `bypass.emails` | `email` |
| 5 | Roles (config + plugin-added) | `bypass.roles` | `role:<names>` |
| 6 | Registered callbacks | - | `callback:<index>` or the returned string |
| 7 | Gate ability | `bypass.ability` | `ability:<name>` |

The environment and IP checks run even for guests. The rest require an
authenticated user. If `bypass.enabled` is `false`, no check runs at all.

### Plugin helpers

```php
use GeoLock\GeoLockPlugin;

GeoLockPlugin::make()
    ->bypassRoles(['super_admin', 'support'])      // add roles on top of bypass.roles
    ->bypassUsing(function ($user, ?string $ip): bool|string {
        // return true, or a string reason, to grant the bypass
        return $user?->email === 'oncall@example.com';
    });
```

- `bypassRoles(array $roles)` calls `BypassInspector::addRoles()`; the roles are
  merged with `bypass.roles`.
- `bypassUsing(Closure $callback)` registers a callback that receives the
  current user and the request IP and may return `true` (bypassed, reason
  `callback:<index>`) or a non-empty string (used verbatim as the reason).

### Gate ability

`bypass.ability` defaults to `geo-lock.bypass` and is checked with
`Gate::forUser($user)->allows($ability)`. This lets an application grant the
bypass dynamically:

```php
Gate::define('geo-lock.bypass', fn ($user) => $user->hasRole('support'));
```

### Plugin authorization vs. bypass

`ui.authorization.bypass` (read by `Authorizer::canBypass()`) is a separate
concept: it controls *who may manage the UI*, not who bypasses the rules. The
rules bypass is the one described above.

## Private and reserved IP handling

`GeoLock\Support\IpAddress` classifies addresses with `filter_var()`:

- `isPublic()` uses `FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE`, so
  loopback, link-local, RFC1918 (IPv4) and RFC4193 (IPv6) addresses are all
  non-public.
- `isPrivate()` is true for any syntactically valid address that is not public.

With `access.bypass_private_ips` enabled (the default), a private client is
granted a bypass with reason `private_ip` - a GeoIP database can never resolve
such an address, so blocking on it would be meaningless.

The resolvers cooperate:

- `TorannGeoResolver` and `NullGeoResolver` return
  `GeoLocation::unknown($ip, ..., isPrivate: true)` for non-public addresses.
- `CacheGeoResolver` skips the cache for non-public addresses (the check is free
  and caching a private IP is pointless).
- `IpAddress::anonymize()` can mask the last octet (IPv4) or group (IPv6) of an
  address for logging.

If you must evaluate private addresses (for example in an internal network where
the proxy forwards a public address), set `access.bypass_private_ips` to `false`
and make sure your resolver can actually resolve them.

## Audit trail

Every denial is recorded by default; grants and bypasses are opt-in. The
lifecycle events are dispatched by `GeoLockManager::fireEvents()`:

| Event | When |
| --- | --- |
| `GeoLock\Events\GeoLockAccessDenied` | The decision is denied. |
| `GeoLock\Events\GeoLockAccessGranted` | The decision is allowed (and not `Disabled`). |
| `GeoLock\Events\GeoLockBypassed` | The decision was bypassed. |

`GeoLock\Listeners\RecordGeoLockAttempt` listens to all three and persists a row
when the corresponding `audit` toggle is on. It wraps the write in a try/catch,
so auditing can never break a request.

`GeoLockAttempt` (`geo_lock_attempts`) stores: `ip`, `country_code`,
`region_code`, `city`, `resolver`, `user_id`/`user_type` (nullable morphs),
`roles`, `outcome`, `reason`, `rule_id`, `url`, `method`, `user_agent` and
timestamps. Useful scopes:

| Scope | Effect |
| --- | --- |
| `denied()` | Rows whose outcome is not allowed/bypassed/no-rules/disabled. |
| `allowed()` | Rows with outcome allowed, bypassed or no-rules. |
| `latestFirst()` | `latest('id')`. |

### Pruning

```bash
# Use the configured retention (audit.keep_days, default 90).
php artisan geo-lock:prune

# Override the retention.
php artisan geo-lock:prune --days=30

# Report without deleting.
php artisan geo-lock:prune --dry-run
```

The command deletes attempts older than `now()->subDays($days)` and refuses a
non-positive `--days`. Schedule it in `routes/console.php` or the console kernel:

```php
Schedule::command('geo-lock:prune')->daily();
```

## Production checklist

- [ ] `GEO_LOCK_RESOLVER=torann` with a **local MaxMind database**
      (`geoip.service = maxmind_database`) and `php artisan geoip:update` in
      your deploy pipeline.
- [ ] `resolver.cache.enabled` and `rules.cache.enabled` on a persistent shared
      store (Redis/Memcached), not `array` or `file` in a multi-server setup.
- [ ] `access.unresolvable` set to `deny` (fail closed).
- [ ] `access.on_resolver_failure` set to `whitelist_only` or `fail_closed`.
- [ ] `GEO_LOCK_REQUIRE_TRUSTED_PROXY=true` and `TrustProxies` configured with
      explicit ranges (no `'*'`).
- [ ] `bypass.enabled` true with at least one of `bypass.roles` / `bypass.ips`
      / `bypass.emails` set, so an administrator cannot be locked out.
- [ ] `audit.enabled` true; decide whether grants/bypasses must be recorded.
- [ ] `geo-lock:prune` scheduled.
- [ ] `php artisan geo-lock:doctor` reports no failures.
- [ ] At least one super admin verified against the **Test an IP address**
      action before enabling strict rules.

Next: [Extending](extending.md).
