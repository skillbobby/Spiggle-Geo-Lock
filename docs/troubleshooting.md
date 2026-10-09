# Troubleshooting

Start with the two diagnostic commands, then jump to the matching symptom.

- [`php artisan geo-lock:doctor`](#php-artisan-geo-lockdoctor)
- [`php artisan geo-lock:check`](#php-artisan-geo-lockcheck)
- [Everyone is blocked](#everyone-is-blocked)
- [Nobody is blocked](#nobody-is-blocked)
- [The panel is locked for me](#the-panel-is-locked-for-me)
- [Location is always unknown](#location-is-always-unknown)
- [The settings page returns 403](#the-settings-page-returns-403)
- [Cache not refreshing](#cache-not-refreshing)
- [Audit table growth](#audit-table-growth)

## `php artisan geo-lock:doctor`

Diagnoses the configuration, database, cache, roles and geolocation service. It
prints a table of checks with a status of `OK`, `WARN` or `FAIL`, then reports
how many checks need attention. The exit code is non-zero when any check is not
`OK` (a `WARN` counts as a problem).

```bash
php artisan geo-lock:doctor
php artisan geo-lock:doctor --no-lookup          # skip the live IP lookup
php artisan geo-lock:doctor --ip=203.0.113.7     # look up a different address
```

| Check | OK when | What to do when it is not OK |
| --- | --- | --- |
| **Geo-lock enabled** | `geo-lock.enabled` is `true`. | Set `GEO_LOCK_ENABLED=true` (or `enabled => true`) if you expect enforcement. |
| **Middleware** | `geo-lock.middleware.enabled` is `true`. The detail says whether the middleware is auto-registered or registered manually. | Enable `middleware.enabled`, or confirm you registered `EnforceGeoLock` yourself when `auto_register` is `false`. |
| **Panel scope** | Always `OK`; the detail says `all panels` or `restricted by config`. | If it says restricted but you expected all panels, check `middleware.panels` / `middleware.except_panels`. |
| **Rules table (`geo_lock_rules`)** | The table exists; the detail shows the row count. | `php artisan migrate` (publish migrations first if needed). If you renamed the table, make sure `rules.table` matches the migrated name. |
| **Audit table (`geo_lock_attempts`)** | The table exists, or auditing is disabled. | `php artisan migrate`, or set `audit.enabled => false` if you do not want the trail. |
| **Resolver driver** | Always `OK`; the detail shows `resolver->name()`. | Confirm it matches your intended `resolver.driver` (`torann`, `null`, `array`, or your custom name). |
| **Lookup `<ip>`** | The address resolved to a country. | `WARN` means the resolver returned "unknown" - see [Location is always unknown](#location-is-always-unknown). `FAIL` shows the thrown exception message. Use `--no-lookup` if the environment has no network/database on purpose. |
| **Cache store** | A put/get/forget round-trip succeeds. The detail names the store and whether locations are cached. | A `FAIL` shows the cache exception. Check `resolver.cache.store` / `rules.cache.store` and the cache driver credentials. |
| **Role provider** | Always `OK`; the detail shows the directory name and the number of roles it offers. | Zero roles with a non-`none` provider usually means the permission tables are empty or not migrated, or `roles.list` is empty. |
| **Unresolvable IPs** | Always `OK`; shows the `access.unresolvable` label. | Informational - align it with your desired fail-open/closed posture. |
| **Resolver failures** | Always `OK`; shows the `access.on_resolver_failure` label. | Informational - see [Security](security.md#fail-closed-behaviour). |
| **Trusted proxies** | No trusted proxy is required, or at least one is configured. | `FAIL` when `GEO_LOCK_REQUIRE_TRUSTED_PROXY=true` but `getTrustedProxies()` is empty: configure `TrustProxies`. The detail also warns when strict mode is off and no proxy is configured. |
| **Emergency bypass** | At least one of `bypass.roles` / `bypass.emails` / `bypass.user_ids` / `bypass.ips` / `bypass.ability` is set. | `WARN` means no bypass is configured - "a super admin could be locked out". Add a role, IP, email or ability. |
| **GeoIP dataset** | The bundled country and region datasets load (`US` and `US-FL` exist). | A `FAIL` means `resources/data/*.json` is missing or unreadable - reinstall the package. |
| **torann/geoip service** | Always `OK`; shows `config('geoip.service')` or `auto-detected`. | Informational - use it to confirm which torann service is active. |

## `php artisan geo-lock:check`

Evaluates the rules for a single IP address (and optionally a user or roles) and
prints the decision. Unlike the middleware, it evaluates with
`applyBypass: false`, so the result reflects the rules only.

```bash
php artisan geo-lock:check 203.0.113.7
php artisan geo-lock:check 203.0.113.7 --role=editor
php artisan geo-lock:check 203.0.113.7 --user=42
php artisan geo-lock:check 203.0.113.7 --all      # also list every rule
php artisan geo-lock:check 203.0.113.7 --json
```

| Argument / option | Purpose |
| --- | --- |
| `ip` (optional) | The address to evaluate. Defaults to the current request's client IP. |
| `--user=` | Evaluate as this user ID (looked up through `auth.providers.users.model`). |
| `--role=*` | Evaluate as these roles. Repeatable. When omitted, the user's real roles are used. |
| `--all` | Append a table of every rule. |
| `--json` | Emit the decision as JSON. |

The human table shows the IP, resolved location, country, region, resolver,
roles, outcome, matched rule, number of applicable rules and the denial message.
The command exits non-zero when the decision is a denial, which makes it usable
in scripts.

`--json` prints a compact payload:

```json
{
    "ip": "203.0.113.7",
    "location": { "ip": "203.0.113.7", "country_code": "JM", "...": "..." },
    "roles": ["editor"],
    "outcome": "denied_blacklist",
    "allowed": false,
    "reason": "blacklisted",
    "rule_id": 4,
    "message": "Access to this panel is not allowed from Jamaica (JM)."
}
```

## Everyone is blocked

Symptoms: every visitor, including administrators, gets the denial page.

Check, in order:

1. **A global whitelist exists.** A whitelist rule with an empty `roles` list
   binds every visitor, and anyone outside its locations is denied. Run
   `php artisan geo-lock:check <ip> --all` to see which rule matched.
2. **Fail-closed on unresolvable.** If `access.unresolvable` is `deny` and the
   resolver cannot locate addresses, everyone is denied with
   `DeniedUnresolvable`. Confirm with the doctor's **Lookup** check.
3. **Fail-closed on resolver failure.** `access.on_resolver_failure =
   fail_closed` denies every bound visitor whenever the resolver throws. Check
   the logs for a reported exception.
4. **Strict trusted proxy.** `GEO_LOCK_REQUIRE_TRUSTED_PROXY=true` without a
   `TrustProxies` configuration denies every request that carries forwarded
   headers (`DeniedUntrustedProxy`). See
   [Trusted proxies](security.md#trusted-proxies-and-header-spoofing).
5. **No bypass configured.** If the doctor warns about the emergency bypass, no
   one can get in. Add a role, IP or email to `bypass`.

Immediate recovery: set `GEO_LOCK_ENABLED=false` (or `geo-lock.enabled =>
false`) to disable enforcement, fix the rules, then re-enable. Rules remain
editable while geo-locking is off.

## Nobody is blocked

Symptoms: rules exist but nobody is ever denied.

- **Rules are inactive.** Only `is_active` rules are evaluated. Check the
  `is_active` column or the doctor's rules-table count versus the panel.
- **No role match.** A rule scoped to `roles: ['editor']` only binds editors. If
  your users hold different roles, or the role directory is misconfigured
  (doctor **Role provider**), no rule applies and the outcome is `NoRules`.
- **The visitor is a guest and the rule has roles.** Guests get the
  `access.guest_role` (default `guest`); a rule for `editor` does not bind them.
- **Whitelist semantics.** A whitelist only denies when the visitor is bound by
  it. If no whitelist rule applies to a visitor, they are allowed
  (`not_blacklisted`).
- **Bypass is granting access.** A `super_admin` in `bypass.roles`, or a
  matching IP/email/environment, is bypassed before the rules. Check
  `geo-lock:check` output (which ignores the bypass) against the middleware.
- **Auto-registration is off.** If `middleware.auto_register` is `false` and you
  never added `EnforceGeoLock` to the panel, the middleware never runs. The
  doctor's **Middleware** detail shows which mode is active.
- **The panel is excluded.** `middleware.except_panels` may contain the panel,
  or `middleware.panels` may omit it.

## The panel is locked for me

If you have locked yourself out:

1. Use an allowed network or VPN, or
2. Add yourself to the emergency bypass - `bypass.roles` (default
   `super_admin`), `bypass.emails`, `bypass.user_ids` or `bypass.ips` - or grant
   the Gate ability named by `bypass.ability` (`geo-lock.bypass` by default), or
3. Set `GEO_LOCK_ENABLED=false` temporarily.

See [Emergency bypass](security.md#emergency-bypass) for the full check order
and the `GeoLockPlugin::bypassRoles()` / `bypassUsing()` helpers. Note that the
bypass is evaluated *before* the rules, so a `super_admin` is never blocked as
long as they keep the role configured in `bypass.roles`.

## Location is always unknown

The doctor's **Lookup** check is the fastest way to confirm this.

- **Web service without credentials / network.** If `geoip.service` points at a
  web service (`maxmind_api`, `ipgeolocation`, `ipdata`, `ipfinder`), a missing
  key or blocked outbound request makes every lookup fail. Publish
  `config/geoip.php` and verify the service credentials.
- **Local database missing.** With `maxmind_database`, a missing or stale
  `storage/app/geoip.mmdb` returns torann's default location, which
  `TorannGeoResolver` treats as unknown. Run `php artisan geoip:update`.
- **Private IP behind a proxy.** If the proxy forwards private addresses (or the
  application does not trust the proxy), `getClientIp()` returns a private
  address. Those are always "unknown" and are bypassed by
  `access.bypass_private_ips`. Configure `TrustProxies` so the real client IP is
  used, and verify with `geo-lock:doctor`'s **Trusted proxies** check.
- **Resolver misconfigured.** If `resolver.driver` names an unknown class, the
  provider silently falls back to `NullGeoResolver`, which never resolves. The
  doctor's **Resolver driver** detail would read `null`.
- **Unresolved results are cached.** An "unknown" location is cached for
  `resolver.cache.ttl` (24 hours by default), so a broken service can keep
  returning unknown even after it is fixed. Flush the cache (see below).

## The settings page returns 403

`GeoLockSettings::canAccess()` (and every `can*()` on the rules resource) calls
`Authorizer::canManage()`, which reads `geo-lock.ui.authorization.manage`:

| Value | Result |
| --- | --- |
| `null` / `''` | Everyone (including guests) may access. |
| `true` / `false` | Literal. |
| `string` | A Gate ability; denied when the user is `null` or the gate returns false. |
| `Closure` | Called with the user; falsy return denies. |

A 403 usually means the key is set to a Gate ability that the current user does
not pass, or to a closure returning `false`. Either grant the ability:

```php
Gate::define('manage-geo-lock', fn ($user) => $user->hasRole('super_admin'));
```

or relax the configuration:

```php
'ui' => [
    'authorization' => [
        'manage' => null,   // everyone
    ],
],
```

The rules resource and the three widgets are hidden for users who cannot
manage, so a missing menu entry alongside a 403 points at the same setting.

## Cache not refreshing

The plugin caches two things, both controlled by the `cache` blocks:

| Cache | Key | TTL | Invalidated by |
| --- | --- | --- | --- |
| Resolved locations | `geo-lock:location:<md5(ip)>` | `resolver.cache.ttl` (24h) | `GeoLock::forgetLocation($ip)`, `GeoLock::flush()`, or expiry. |
| Rule set | `geo-lock:rules:active` / `geo-lock:rules:all` | `rules.cache.ttl` (300s) | Saving or deleting a `GeoLockRule` (model events), or `flush()`. |

If a rule change does not take effect:

- **The change bypassed the model.** `GeoLockRule::saved` / `deleted` flush the
  rule cache, but raw SQL, a seeder using `DB::table()->insert()`, or a write
  from another process does not fire those events. Wait for the TTL
  (`GEO_LOCK_RULES_CACHE_TTL`, default 300s) or flush manually.
- **Different cache store per process.** If the rules are written by one
  process but read by another that uses a different `cache.store` (for example
  `array` vs `redis`), the flush lands in the wrong store. Make sure
  `rules.cache.store` is a shared, persistent store in production.
- **The location cache is stale.** If a location was previously resolved
  incorrectly (or as unknown) and cached, a rule change will not re-resolve it.
  Flush the location cache for the affected IP.

Flush from the panel: open the settings page and use the **Flush cache** action
(`GeoLockManager::flush()` clears both the per-request memo and the rule cache).
Flush from code:

```php
\GeoLock\Facades\GeoLock::flush();
\GeoLock\Facades\GeoLock::forgetLocation('203.0.113.7');
```

Flush from the CLI:

```bash
php artisan cache:forget geo-lock:rules:active
php artisan cache:forget geo-lock:rules:all
```

Disabling the caches entirely (`resolver.cache.enabled` and
`rules.cache.enabled` to `false`) is a valid debugging step but adds a GeoIP
lookup and a query to every request.

## Audit table growth

Every denial writes a row to `geo_lock_attempts`, which grows quickly on a busy
or attacked panel.

- **Prune regularly.** `php artisan geo-lock:prune` deletes rows older than
  `audit.keep_days` (default 90). Schedule it:

  ```php
  Schedule::command('geo-lock:prune')->daily();
  ```

  Preview first with `php artisan geo-lock:prune --dry-run`, or override the
  window with `--days=30`.

- **Stop recording what you do not need.** `audit.record_grants` and
  `audit.record_bypasses` default to `false`, so only denials are stored. Leave
  them off unless you need the extra volume.

- **Disable auditing entirely.** Set `audit.enabled => false` if you rely on
  your own logging. The doctor then reports the audit table as `audit disabled`
  and does not require it to exist.

- **Indexes.** The migration already indexes `ip`, `outcome`, `rule_id`,
  `created_at` and `country_code`, which covers the dashboard widgets' queries.
  If you add custom reporting queries, add matching indexes.

- **Consider anonymisation.** `GeoLock\Support\IpAddress::anonymize()` masks the
  last octet/group of an address; you can apply it in a listener if storing full
  addresses conflicts with your privacy policy.
