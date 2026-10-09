# Changelog

All notable changes to `filament-geo-lock` will be documented in this file.

## 1.0.0 - unreleased

- Initial release.
- Rule based geographic access control (whitelist / blacklist) per country, ISO 3166-2 region and role.
- `EnforceGeoLock` middleware with automatic panel registration and Livewire persistence.
- `torann/geoip` powered resolution, with cache decorator, fallback chain and `GeoLock::fake()`.
- Filament settings page (repeater), rules resource (table, filters, reorderable priority, activate/deactivate), stats, chart and recent-attempts widgets.
- Emergency bypass (roles, emails, user IDs, IPs, environments, Gate ability, closures).
- Fail-open / fail-closed behaviour for unresolvable IPs and resolver failures, plus trusted-proxy hardening.
- Events, audit trail and the `geo-lock:check`, `geo-lock:doctor` and `geo-lock:prune` commands.
- Bundled ISO 3166-1 / ISO 3166-2 dataset (249 countries, 5,046 subdivisions).
