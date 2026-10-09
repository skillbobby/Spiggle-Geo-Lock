# Installation

This guide takes you from a bare Laravel application to a Filament panel that
enforces geo-lock rules. It covers the package requirements, publishing the
package assets, registering the plugin, and configuring the underlying
`torann/geoip` service.

- [Requirements](#requirements)
- [Install the package](#install-the-package)
- [Publish package assets](#publish-package-assets)
- [Run the migrations](#run-the-migrations)
- [Register the plugin on a panel](#register-the-plugin-on-a-panel)
- [Middleware registration](#middleware-registration)
- [Configure torann/geoip](#configure-toranngeoip)
- [Verify the installation](#verify-the-installation)
- [The workbench demo](#the-workbench-demo)

## Requirements

| Requirement | Version | Notes |
| --- | --- | --- |
| PHP | `^8.2` | The package uses readonly classes and enums. |
| Laravel | `^11`, `^12` or `^13` | Via `illuminate/contracts`, `database`, `http` and `support`. |
| Filament | `^4.0` or `^5.0` | The plugin targets the Filament 4/5 schema and panel APIs. |
| `torann/geoip` | `^3.0` | The default resolver; required by `composer.json`. |
| `spatie/laravel-package-tools` | `^1.16` | Used to register config, views, translations and migrations. |

`spatie/laravel-permission` is optional. When it is installed, the role
directory auto-detects it (see [Configuration](configuration.md#roles)). The
package works without any role system.

## Install the package

```bash
composer require geolock/filament-geo-lock
```

The service provider `GeoLock\GeoLockServiceProvider` and the `GeoLock` facade
alias are registered through the package `extra.laravel` manifest, so no manual
provider registration is required in a standard Laravel application.

## Publish package assets

Each asset group has its own tag. Publish only what you intend to customise;
everything else is loaded from the package.

```bash
# config/geo-lock.php
php artisan vendor:publish --tag=geo-lock-config

# resources/views/vendor/geo-lock
php artisan vendor:publish --tag=geo-lock-views

# lang/vendor/geo-lock
php artisan vendor:publish --tag=geo-lock-translations

# database/migrations (the two plugin migrations)
php artisan vendor:publish --tag=geo-lock-migrations
```

| Tag | Publishes | Destination |
| --- | --- | --- |
| `geo-lock-config` | `config/geo-lock.php` | `config/geo-lock.php` |
| `geo-lock-views` | The denial and settings views | `resources/views/vendor/geo-lock` |
| `geo-lock-translations` | `resources/lang/en/geo-lock.php` | `lang/vendor/geo-lock` |
| `geo-lock-migrations` | `create_geo_lock_rules_table`, `create_geo_lock_attempts_table` | `database/migrations` |

You can publish several groups at once with
`--tag=geo-lock-config --tag=geo-lock-migrations`, or use
`--provider="GeoLock\GeoLockServiceProvider"` to publish everything.

## Run the migrations

```bash
php artisan migrate
```

This creates two tables:

- `geo_lock_rules` - the rules managed from the panel (see [Rules](rules.md)).
- `geo_lock_attempts` - the audit trail of decisions (see [Security](security.md#audit-trail)).

The table names are configurable through `geo-lock.rules.table` and
`geo-lock.audit.table`. If you rename them, set the values **before** running
the migrations - the migration classes read the same configuration keys.

## Register the plugin on a panel

Add the plugin to the panel provider that should be protected:

```php
use Filament\Panel;
use Filament\PanelProvider;
use GeoLock\GeoLockPlugin;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->plugin(GeoLockPlugin::make());
    }
}
```

`GeoLockPlugin::make()` registers three things on the panel:

1. The `EnforceGeoLock` middleware (see below).
2. The rules resource and the settings page (when their `ui` toggles are on).
3. The three dashboard widgets (stats, recent attempts, attempts chart).

The plugin exposes fluent toggles if you want a smaller footprint:

```php
GeoLockPlugin::make()
    ->withoutWidgets()
    ->withoutSettingsPage();
```

The navigation group, icon and sort order can be overridden per panel:

```php
GeoLockPlugin::make()
    ->navigationGroup('Access control')
    ->navigationIcon('heroicon-o-lock-closed')
    ->navigationSort(10);
```

These setters write back into the `geo-lock.ui.*` configuration at runtime.

## Middleware registration

### Automatic (default)

With `geo-lock.middleware.auto_register` set to `true` (the default), the
service provider registers the middleware on **every** Filament panel through
`Panel::configureUsing`. You do not need `->plugin()` for the enforcement to
work - the plugin is only required for the UI.

Automatic registration is skipped when:

- `geo-lock.middleware.auto_register` is `false`, or
- `geo-lock.enabled` is `false`, or
- the `Filament\Panel` class is not available.

`MiddlewareRegistry` guarantees the middleware is applied at most once per
panel, even if both the automatic registration and `GeoLockPlugin` are active.

### Manual

To take full control, disable auto-registration and add the middleware
yourself:

```php
// config/geo-lock.php
'middleware' => [
    'auto_register' => false,
    // ...
],
```

```php
use GeoLock\Http\Middleware\EnforceGeoLock;

$panel->middleware([
    EnforceGeoLock::class,
], isPersistent: true);
```

### Scoping to specific panels

`geo-lock.middleware.panels` is an allow-list of panel IDs;
`geo-lock.middleware.except_panels` is a deny-list. `except_panels` wins over
`panels`, and an empty `panels` list means "all panels".

```php
'middleware' => [
    'panels' => ['admin'],                 // only guard the "admin" panel
    'except_panels' => ['public'],         // never guard the "public" panel
],
```

### Persistence

With `geo-lock.middleware.persistent` enabled (default), the middleware is
marked persistent so it also runs on subsequent Livewire requests. This stops a
user from opening the panel from an allowed location and then continuing to
navigate after moving (or spoofing) elsewhere.


## Configure torann/geoip

The default resolver is `torann`. The middleware asks it for the location of the
client IP on every guarded request, so the service must be able to answer
quickly and without network calls.

### Local MaxMind database (recommended)

The fastest and most reliable option is the local MaxMind database service
(`maxmind_database`). A lookup is an in-process file read, which keeps the
middleware inside its 50ms budget and works without outbound network access.

```bash
# publish the torann config (tag: "config")
php artisan vendor:publish --provider="Torann\GeoIP\GeoIPServiceProvider" --tag=config

# download / refresh the local database
php artisan geoip:update
```

```php
// config/geoip.php
'service' => 'maxmind_database',

'services' => [
    'maxmind_database' => [
        'class' => \Torann\GeoIP\Services\MaxMindDatabase::class,
        'database_path' => storage_path('app/geoip.mmdb'),
        'update_url' => sprintf(
            'https://download.maxmind.com/app/geoip_download?edition_id=GeoLite2-City&license_key=%s&suffix=tar.gz',
            env('MAXMIND_LICENSE_KEY'),
        ),
        'locales' => ['en'],
    ],
],
```

The `geoip:update` command (`Torann\GeoIP\Console\Update`) downloads the
database using `MAXMIND_LICENSE_KEY`.

### Web service (fallback / no local file)

If you cannot ship a local database, you can point `geoip.service` at a web
service such as `maxmind_api`, `ipgeolocation`, `ipdata` or `ipfinder`. Each
service has its own credentials under `config/geoip.php > services`.

A web service adds a synchronous HTTP round-trip to the request. That is the
main reason the plugin caches resolved locations (`resolver.cache`), but the
first request for each IP still pays for the network call. Prefer a local
database in production.

### The `geoip.service` key

`geo-lock` never hard-codes the torann service. It delegates to whatever
`config('geoip.service')` resolves to. `TorannGeoResolver` calls
`GeoIP::getLocation($ip)` and treats torann's default location
(`$location->default === true`) as "unknown", so a misconfigured service fails
predictably instead of returning a wrong country.

### Why a local database keeps the middleware inside its budget

The middleware runs on every panel request, including Livewire round-trips. A
local MaxMind lookup is a memory-mapped file read measured in microseconds,
whereas a web service is a blocking HTTPS request measured in hundreds of
milliseconds. With `resolver.cache.enabled` the same IP is only looked up once
per TTL (default 24 hours), so even the local read happens rarely. Combined,
these two settings keep the steady-state cost of the middleware well under the
50ms budget.

See [Extending](extending.md) for `FallbackGeoResolver`, `CacheGeoResolver` and
custom resolver drivers.

## Verify the installation

```bash
php artisan geo-lock:doctor
```

The doctor command checks the configuration, the database tables, the cache
store, the role provider, the trusted-proxy setup and runs a live IP lookup. See
[Troubleshooting](troubleshooting.md#php-artisan-geo-lockdoctor) for a
walkthrough of every check.

## The workbench demo

The repository ships an Orchestra Testbench workbench with an `admin` panel, a
seeded `admin@example.com` user (roles `super_admin`, `editor`, `moderator`)
and an `array` resolver, so you can try the plugin without a real GeoIP
database.

```bash
vendor/bin/testbench serve
```

The command boots the workbench application and serves the panel at `/admin`.
The relevant pieces live in:

| Path | Purpose |
| --- | --- |
| `testbench.yaml` | Providers, migrations, seeders and the `GEO_LOCK_RESOLVER=array` env. |
| `workbench/app/Providers/AdminPanelProvider.php` | The demo panel with `->plugin(GeoLockPlugin::make())`. |
| `workbench/database/seeders/DatabaseSeeder.php` | Creates the `super_admin` / `editor` / `moderator` roles and the demo user. |
| `workbench/database/migrations` | The users and permission tables. |

Because `GEO_LOCK_RESOLVER=array` is set, the workbench resolves locations from
`GeoLock::fake()` only. Use the **Test an IP address** action on the settings
page, or seed fake locations, to see decisions without network access.

Next: [Configuration](configuration.md).

