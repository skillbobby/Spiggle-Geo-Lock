# Configuration

All package options live in `config/geo-lock.php`. Publish it first:

```bash
php artisan vendor:publish --tag=geo-lock-config
```

The file is organised into ten sections: `enabled`, `middleware`, `resolver`,
`rules`, `access`, `bypass`, `denial`, `audit`, `roles` and `ui`. Defaults shown
below are the values shipped in the package.

- [Environment variables](#environment-variables)
- [enabled](#enabled)
- [middleware](#middleware)
- [resolver](#resolver)
- [rules](#rules)
- [access](#access)
- [bypass](#bypass)
- [denial](#denial)
- [audit](#audit)
- [roles](#roles)
- [ui](#ui)

## Environment variables

The config file reads exactly these environment variables. Every other key is
edited directly in the published config file.

| Variable | Used by | Type | Default |
| --- | --- | --- | --- |
| `GEO_LOCK_ENABLED` | `enabled` | bool | `true` |
| `GEO_LOCK_RESOLVER` | `resolver.driver` | string | `torann` |
| `GEO_LOCK_CACHE_ENABLED` | `resolver.cache.enabled`, `rules.cache.enabled` | bool | `true` |
| `GEO_LOCK_CACHE_STORE` | `resolver.cache.store`, `rules.cache.store` | string / null | `null` (default store) |
| `GEO_LOCK_CACHE_TTL` | `resolver.cache.ttl` | int (seconds) | `86400` |
| `GEO_LOCK_RULES_CACHE_TTL` | `rules.cache.ttl` | int (seconds) | `300` |
| `GEO_LOCK_REQUIRE_TRUSTED_PROXY` | `access.require_trusted_proxy` | bool | `false` |

## enabled

| Key | Type | Default | Effect |
| --- | --- | --- | --- |
| `enabled` | bool | `true` | Global kill switch. When `false`, the middleware returns the request untouched (`GeoLockOutcome::Disabled`), no IP resolution or rule evaluation happens, and auto-registration on panels is skipped. Rules remain editable in the panel. |

## middleware

| Key | Type | Default | Effect |
| --- | --- | --- | --- |
| `middleware.enabled` | bool | `true` | Second kill switch, checked by `EnforceGeoLock` in addition to `enabled`. |
| `middleware.auto_register` | bool | `true` | When `true`, the service provider applies `EnforceGeoLock` to every Filament panel via `Panel::configureUsing`. Set to `false` to register the middleware manually. |
| `middleware.persistent` | bool | `true` | Marks the middleware persistent so it also runs on Livewire requests, not just the initial page load. |
| `middleware.guard` | string / null | `null` | The auth guard used to resolve the current user. When `null`, the current Filament panel's guard is used, then the default guard. |
| `middleware.panels` | array of string | `[]` | Allow-list of panel IDs the middleware should guard. Empty means "all panels". |
| `middleware.except_panels` | array of string | `[]` | Deny-list of panel IDs. Takes precedence over `panels`. |

See [Installation](installation.md#middleware-registration) for registration
examples and `PanelScope` semantics.

## resolver

| Key | Type | Default | Effect |
| --- | --- | --- | --- |
| `resolver.driver` | string | `torann` (`GEO_LOCK_RESOLVER`) | The active resolver. May be a key from `resolver.drivers` or a class-string implementing `GeoLock\Contracts\GeoResolver`. |
| `resolver.drivers` | array<string, class-string> | `torann`, `null`, `array` | Named resolver drivers. Built-ins: `GeoLock\Resolvers\TorannGeoResolver`, `NullGeoResolver`, `ArrayGeoResolver`. |
| `resolver.fallbacks` | array of string | `[]` | Driver names tried, in order, when the primary driver does not resolve a country. Wrapped in `FallbackGeoResolver`. |
| `resolver.cache.enabled` | bool | `true` (`GEO_LOCK_CACHE_ENABLED`) | Wraps the resolver in `CacheGeoResolver`. |
| `resolver.cache.store` | string / null | `null` (`GEO_LOCK_CACHE_STORE`) | Cache store name. `null` uses the application default store. |
| `resolver.cache.ttl` | int (seconds) | `86400` (`GEO_LOCK_CACHE_TTL`) | How long a resolved location is cached. |
| `resolver.cache.prefix` | string | `geo-lock:location` | Cache key prefix; the full key is `prefix . ':' . md5($ip)`. |

Unresolved addresses are cached too (as `GeoLocation::unknown`), which prevents
hammering a broken database or an unreachable API. Private and reserved
addresses bypass the cache entirely.

## rules

| Key | Type | Default | Effect |
| --- | --- | --- | --- |
| `rules.model` | class-string | `GeoLock\Models\GeoLockRule::class` | The Eloquent model backing the rules. Must extend the shipped model or implement the same contract. |
| `rules.table` | string | `geo_lock_rules` | The rules table name, read by both the model and the migration. |
| `rules.cache.enabled` | bool | `true` (`GEO_LOCK_CACHE_ENABLED`) | Caches the loaded rule set, which is read on (nearly) every request. |
| `rules.cache.store` | string / null | `null` (`GEO_LOCK_CACHE_STORE`) | Cache store for the rule set. |
| `rules.cache.ttl` | int (seconds) | `300` (`GEO_LOCK_RULES_CACHE_TTL`) | Rule-set cache lifetime. Saving or deleting a rule flushes it automatically. |
| `rules.cache.key` | string | `geo-lock:rules` | Base cache key; the repository appends `:active` or `:all`. |

## access

| Key | Type | Default | Effect |
| --- | --- | --- | --- |
| `access.unresolvable` | `UnresolvableIpBehavior` / string | `UnresolvableIpBehavior::Allow` (`allow`) | What to do when the country cannot be determined. `allow` fails open, `deny` fails closed. |
| `access.on_resolver_failure` | `ResolverFailureBehavior` / string | `ResolverFailureBehavior::WhitelistOnly` (`whitelist_only`) | What to do when the resolver throws. `whitelist_only` blocks only whitelist-bound visitors, `fail_closed` blocks every bound visitor, `fail_open` never blocks. |
| `access.bypass_private_ips` | bool | `true` | Skips evaluation for loopback, link-local, RFC1918 and RFC4193 addresses, which a GeoIP database can never resolve. The decision is recorded as a bypass with reason `private_ip`. |
| `access.require_trusted_proxy` | bool | `false` (`GEO_LOCK_REQUIRE_TRUSTED_PROXY`) | Refuses requests that carry proxy headers without a matching TrustedProxy configuration. |
| `access.apply_rules_without_roles` | bool | `true` | Whether rules with an empty `roles` list apply to everybody. When `false`, such rules are ignored. |
| `access.guest_role` | string | `guest` | The synthetic role assigned to unauthenticated visitors. An empty string means "no roles". |

The enum-valued keys accept either the enum case, the string value, or a boolean
for the two-state enums (`UnresolvableIpBehavior`: `true` = `deny`;
`ResolverFailureBehavior`: `true` = `whitelist_only`, `false` = `fail_open`).

See [Security](security.md) for the failure modes and [Rules](rules.md) for how
roles drive applicability.


## bypass

The emergency bypass is evaluated before the rules. Any match grants access and
fires `GeoLockBypassed`.

| Key | Type | Default | Effect |
| --- | --- | --- | --- |
| `bypass.enabled` | bool | `true` | Master switch for all bypass checks. When `false`, `bypassReason()` always returns `null`. |
| `bypass.roles` | array of string | `['super_admin']` | Roles that bypass. Reason `role:<names>`. |
| `bypass.emails` | array of string | `[]` | User email addresses that bypass (case-insensitive). Reason `email`. |
| `bypass.user_ids` | array of string | `[]` | Auth identifiers that bypass. Reason `user_id`. |
| `bypass.ips` | array of string | `[]` | Client IPs that bypass. Reason `ip`. Checked even for guests. |
| `bypass.environments` | array of string | `[]` | Application environments that bypass entirely. Reason `environment:<env>`. |
| `bypass.ability` | string / null | `geo-lock.bypass` | A Gate ability checked for the current user. Reason `ability:<name>`. |

Check order: `environments` -> `ips` -> `user_ids` -> `emails` -> `roles` ->
registered callbacks -> `ability`. The first match wins.

## denial

| Key | Type | Default | Effect |
| --- | --- | --- | --- |
| `denial.status` | int | `403` | HTTP status used for every denial response. |
| `denial.message` | string / null | `null` | Overrides the translated message for **all** denial outcomes. `null` uses the per-outcome translations. |
| `denial.view` | string / null | `null` | Blade view rendered for HTML denials, receiving a `$decision` variable (a `GeoLockDecision`). |
| `denial.redirect` | string / null | `null` | URL or route name to redirect HTML denials to. Takes precedence over `denial.view`. |

Response resolution order in `EnforceGeoLock::deny()`:

1. JSON requests always receive a JSON body with the full decision.
2. `denial.redirect` (route names are resolved with `Route::has()`).
3. `denial.view`.
4. `abort($status, $decision->message())`.

## audit

| Key | Type | Default | Effect |
| --- | --- | --- | --- |
| `audit.enabled` | bool | `true` | Records decisions to the audit table. When `false`, `RecordGeoLockAttempt` returns immediately. |
| `audit.table` | string | `geo_lock_attempts` | The audit table name, read by the model and the migration. |
| `audit.record_grants` | bool | `false` | Also persist `GeoLockAccessGranted` decisions. |
| `audit.record_bypasses` | bool | `false` | Also persist `GeoLockBypassed` decisions. |
| `audit.keep_days` | int | `90` | Default retention used by `geo-lock:prune` when `--days` is omitted. |

Denials are always recorded when auditing is enabled. Prune with
`php artisan geo-lock:prune`.

## roles

| Key | Type | Default | Effect |
| --- | --- | --- | --- |
| `roles.provider` | string | `auto` | How roles are discovered: `auto`, `spatie`, `attribute`, `config` or `none`. `auto` uses `spatie/laravel-permission` when its `HasRoles` contract exists, otherwise the attribute directory. |
| `roles.attribute` | string | `roles` | The user attribute (or zero-argument method) read by the attribute/config directories. |
| `roles.list` | array of string | `[]` | Fallback list of role names offered in the rule form when no live source is available. |

The resolved directory is bound as `GeoLock\Contracts\RoleDirectory`. See
[Extending](extending.md#custom-role-directories) for a custom implementation.

## ui

| Key | Type | Default | Effect |
| --- | --- | --- | --- |
| `ui.navigation_group` | string / null | `Security` | Navigation group for the resource, settings page and widgets. |
| `ui.navigation_icon` | string / enum / null | `heroicon-o-globe-alt` | Navigation icon. |
| `ui.navigation_sort` | int / null | `null` | Navigation sort order. `null` keeps Filament's default. |
| `ui.rule_resource.enabled` | bool | `true` | Register the rules resource on the panel. |
| `ui.rule_resource.label` | string / null | `null` | Navigation label; `null` uses the `navigation.rules` translation. |
| `ui.rule_resource.slug` | string / null | `null` | Present in the config file but not read by the current code; the resource keeps Filament's default slug. |
| `ui.settings_page.enabled` | bool | `true` | Register the bulk settings page. |
| `ui.settings_page.slug` | string | `geo-lock` | URL slug of the settings page. |
| `ui.widgets.enabled` | bool | `true` | Master switch for the three widgets. |
| `ui.widgets.stats` | bool | `true` | Register the stats overview widget. |
| `ui.widgets.recent_attempts` | bool | `true` | Register the recent-attempts table widget (also shown as a footer widget on the settings page). |
| `ui.widgets.attempts_chart` | bool | `true` | Register the line chart widget. |
| `ui.authorization.manage` | mixed | `null` | Who may manage rules. `null`/`''` = everybody, `bool` = literal, `string` = Gate ability, `Closure` = `fn (?Authenticatable $user): bool`. |
| `ui.authorization.bypass` | mixed | `null` | Read by `Authorizer::canBypass()`. No built-in UI surface calls it yet; available for application code. |

See [Extending](extending.md#the-authorizer) for the authorization resolution
rules and [Troubleshooting](troubleshooting.md#the-settings-page-returns-403) for
the common 403 case.

