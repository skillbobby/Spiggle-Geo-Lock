# Rules

A geo-lock rule binds a set of **roles** to a set of **locations** (countries
and optionally ISO 3166-2 subdivisions) and declares what that binding means
(whitelist or blacklist).

- [The rule model](#the-rule-model)
- [Evaluation](#evaluation)
- [Priority ordering](#priority-ordering)
- [Worked examples](#worked-examples)
- [Region semantics](#region-semantics)
- [Countries derived from regions](#countries-derived-from-regions)

## The rule model

Rules are stored in the `geo_lock_rules` table (`geo-lock.rules.table`) and
represented by `GeoLock\Models\GeoLockRule`.

| Column | Type | Cast | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | bigint | - | auto | Primary key. |
| `name` | string(255) | - | `null` | Human label; falls back to `Rule #:id`. |
| `description` | text | - | `null` | Free text. |
| `roles` | json | `array` | `null` | Role names the rule applies to. Empty/`null` = every role. |
| `type` | string | `RuleType` | `blacklist` | `whitelist` or `blacklist`. |
| `countries` | json | `array` | `null` | ISO 3166-1 alpha-2 codes, stored uppercase. |
| `regions` | json | `array` | `null` | ISO 3166-2 codes, stored uppercase. Empty = whole country. |
| `is_active` | boolean | `boolean` | `true` | Inactive rules are ignored during evaluation. |
| `priority` | integer | `integer` | `0` | Higher priority is evaluated first. |
| `created_at`, `updated_at` | timestamp | `datetime` | - | Standard timestamps. |

The migration indexes `is_active`, `type` and `priority`. `GeoLockRule` is
unguarded (`$guarded = []`) and exposes:

| Method | Purpose |
| --- | --- |
| `isWhitelist()` / `isBlacklist()` | Type check against the `RuleType` enum. |
| `appliesToRoles(array $roles): bool` | Whether the rule binds a visitor holding `$roles`. |
| `matches(GeoLocation $location): bool` | Whether the location falls inside the rule scope. |
| `matchesCountry(?string $countryCode): bool` | Country-only check (case-insensitive). |
| `countryLabels()` / `regionLabels()` | Human labels for the UI. |
| `roleLabels(): array` | The configured roles. |
| `label(): string` | `name` or the `rules.unnamed` translation. |
| `scopeSummary(): string` | First three regions (or countries), truncated with `+n`. |
| `attempts()` | `hasMany` relation to `GeoLockAttempt` on `rule_id`. |

Query scopes:

| Scope | Effect |
| --- | --- |
| `active()` | `where('is_active', true)`. |
| `forEvaluation()` | `orderByDesc('priority')->orderBy('id')`. |
| `forRoles(array $roles)` | `whereNull('roles')` OR `whereJsonContains('roles', $role)` for each role. |

The model flushes the rule cache (`GeoLockRuleRepository::flush()`) on `saved`
and `deleted`, so edits are visible immediately even with caching enabled.

## Evaluation

Evaluation is performed by `GeoLock\Services\RuleEvaluator::evaluate()` after
the middleware has resolved the client IP and the visitor's roles. The steps
are:

1. **Applicable rules.** Keep only rules that are `is_active` **and** whose
   `appliesToRoles($roles)` returns `true`. A rule with an empty `roles` list
   applies to every role only when `geo-lock.access.apply_rules_without_roles`
   is `true` (the default).
2. **No applicable rules.** If none apply, access is granted with outcome
   `NoRules` and reason `no_applicable_rules`.
3. **Resolver failure.** If the resolver threw, the
   `access.on_resolver_failure` behaviour decides (see [Security](security.md)).
4. **Unresolvable.** If the location has no country code, the
   `access.unresolvable` behaviour decides.
5. **Blacklist wins.** Every applicable blacklist rule is tested with
   `matches($location)`. The first match denies with outcome
   `DeniedBlacklist` and reason `blacklisted`.
6. **Whitelist requirement.** If no applicable whitelist rule exists, access is
   granted with reason `not_blacklisted`. Otherwise at least one applicable
   whitelist rule must match; the first match grants with reason `whitelisted`.
7. **Whitelist miss.** If whitelist rules exist but none match, access is denied
   with outcome `DeniedWhitelist` and reason `not_whitelisted`.

`GeoLockRule::matches()` requires the location to be resolved and the country to
be listed. When `regions` is empty the country match is enough; otherwise the
resolved `regionCode` must be one of the configured regions.

The bypass (`GeoLockBypassed`) and the global disable (`Disabled`) are handled
*before* the evaluator runs, in `GeoLockManager::evaluate()`.

## Priority ordering

The repository loads rules with `forEvaluation()`, i.e. `priority` descending
and then `id` ascending. Priority therefore decides which rule is reported as
the **matched rule** when several would match:

- A higher-priority blacklist rule is checked (and can deny) before a
  lower-priority one.
- Among whitelist rules, the highest-priority match is reported as the granting
  rule.

Priority does **not** let a whitelist beat a blacklist: blacklist rules are
always evaluated first, so any matching blacklist denies regardless of priority.
Use priority to control reporting and to order rules within the same type, and
use separate rules (scoped by role) to express "allow X but block Y".

## Worked examples

### Block a country for one role

```php
GeoLockRule::factory()
    ->blacklist()
    ->forRoles(['editor'])
    ->forCountries(['JM'])
    ->create(['name' => 'No editors from Jamaica']);
```

- An `editor` in `JM` is denied (`DeniedBlacklist`, `blacklisted`).
- An `editor` in `US` is allowed (`not_blacklisted`).
- A `super_admin` in `JM` is unaffected: the rule does not apply to them, so if
  it is the only rule the outcome is `NoRules` (allowed).

### Allow only two regions for editors

```php
GeoLockRule::factory()
    ->whitelist()
    ->forRoles(['editor'])
    ->forCountries(['US'])
    ->forRegions(['US-FL', 'US-GA'])
    ->create(['name' => 'Editors: FL and GA only']);
```

- An `editor` resolving to `US-FL` or `US-GA` is allowed (`whitelisted`).
- An `editor` resolving to `US-TX` is denied (`DeniedWhitelist`,
  `not_whitelisted`).
- An `editor` in `GB` is denied as well: whitelist rules bind the editor role,
  and no applicable whitelist matches.
- A non-editor is not bound by this rule. With no other rule in play they get
  `NoRules` (allowed). Add a global whitelist if non-editors must also be
  restricted.

### Per-role whitelist plus a global blacklist

```php
// Bind editors to Florida and Georgia.
GeoLockRule::factory()
    ->whitelist()
    ->forRoles(['editor'])
    ->forRegions(['US-FL', 'US-GA'])
    ->priority(10)
    ->create(['name' => 'Editors: FL / GA']);

// Block one country for every role.
GeoLockRule::factory()
    ->blacklist()
    ->forEveryRole()
    ->forCountries(['CU'])
    ->priority(20)
    ->create(['name' => 'Block Cuba']);
```

Decisions:

| Visitor | Country / region | Result | Reason |
| --- | --- | --- | --- |
| `editor` | `CU` | `DeniedBlacklist` | `blacklisted` (blacklist checked first, regardless of priority) |
| `editor` | `US-FL` | `Allowed` | `whitelisted` |
| `editor` | `US-TX` | `DeniedWhitelist` | `not_whitelisted` |
| `super_admin` | `US-TX` | `Allowed` | `not_blacklisted` (whitelist does not bind them) |
| `super_admin` | `CU` | `DeniedBlacklist` | `blacklisted` (the blacklist applies to every role) |

Because `bypass.roles` defaults to `['super_admin']`, a `super_admin` would in
practice be granted the emergency bypass *before* these rules are evaluated.
Remove `super_admin` from `bypass.roles` (and rely on `bypass.ips` or
`bypass.emails`) if you want the blacklist to apply to them. See
[Security](security.md#emergency-bypass).

## Region semantics

- Regions are **ISO 3166-2** subdivision codes, always stored uppercase, e.g.
  `US-FL` (Florida), `JM-01` (Kingston), `CA-ON` (Ontario).
- `regions` is optional. **An empty region list means the whole country.** A
  rule with `countries: ['US']` and `regions: []` covers every US subdivision.
- A non-empty `regions` list restricts the rule to those subdivisions. If the
  visitor's location has no resolved `regionCode`, the rule cannot match on
  region and is treated as a miss.
- Country and region codes are matched case-insensitively on read, but are
  normalised to uppercase on write.
- Resolvers return subdivision codes in provider-specific formats.
  `TorannGeoResolver` and `ArrayGeoResolver` run them through
  `RegionData::match($country, $code, $name)`, which tries `FL`, `US-FL`, `FL`
  (suffix) and the human-readable state name before falling back to a
  `COUNTRY-CODE` guess. This normalisation is why a rule always stores the
  canonical `US-FL` form.

The bundled datasets (`resources/data/countries.json`, 249 countries, and
`resources/data/regions.json`, 5046 subdivisions across 200 countries) power the
panel pickers and the `RegionData` / `CountryData` helpers.

## Countries derived from regions

When a rule is saved through the repository
(`DatabaseGeoLockRuleRepository::normalize()`), the country list is kept in sync
with the selected subdivisions. For every region code, `RegionData::countryOf()`
extracts the part before the `-` and adds it to `countries`. The resulting list
is uppercased and de-duplicated.

This means you can submit only regions and still get a usable country list:

```php
$repository->create([
    'type' => 'blacklist',
    'roles' => ['editor'],
    'countries' => [],
    'regions' => ['US-FL', 'US-GA'],
]);

// Stored as:
// countries: ['US']
// regions:   ['US-FL', 'US-GA']
```

The same normalisation runs on the settings-page repeater (via `sync()`) and on
the resource form, so `countries` is always populated even if the operator only
picked subdivisions. This is also what lets the panel's rules table show the
country column for region-only rules.

Next: [Security](security.md).
