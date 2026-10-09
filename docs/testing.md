# Testing

The plugin is designed to be tested without network access. This page documents
`GeoLock::fake()`, the model factories, the testbench workbench and how to run
the suite.

- [Faking locations](#faking-locations)
- [ArrayGeoResolver shapes](#arraygeoresolver-shapes)
- [Model factories](#model-factories)
- [Swapping the resolver](#swapping-the-resolver)
- [The workbench setup](#the-workbench-setup)
- [Running the suite](#running-the-suite)

## Faking locations

`GeoLock::fake()` swaps the configured resolver for an in-memory
`GeoLock\Resolvers\ArrayGeoResolver` for the rest of the request. It returns the
resolver, so you can mutate it further:

```php
use GeoLock\Facades\GeoLock;

GeoLock::fake([
    '8.8.8.8' => ['country_code' => 'US', 'region_code' => 'US-FL'],
    '1.1.1.1' => 'JM',                                   // string = country code
    '203.0.113.7' => ['country_code' => 'GB', 'city' => 'London'],
]);

$decision = GeoLock::evaluate(null, '8.8.8.8');

expect($decision->allowed())->toBeTrue()
    ->and($decision->location->regionCode)->toBe('US-FL');
```

The in-memory resolver is memoised per request, so all subsequent calls to
`GeoLock::resolve()` / `evaluate()` use the fake until you reset it:

```php
GeoLock::resetResolver();
```

In the plugin's own test suite a `fakeGeo()` helper is registered in
`tests/Pest.php`:

```php
function fakeGeo(array $locations = []): void
{
    GeoLock::fake($locations);
}
```

## ArrayGeoResolver shapes

The constructor (and `put()` / `fill()`) accept a map of `ip => location`. Each
value may be:

| Shape | Result |
| --- | --- |
| `GeoLocation` instance | Returned as-is (the `ip` is not rewritten). |
| `string` | Treated as a country code: `['country_code' => $value]`. |
| `array` | Built into a `GeoLocation` (see the key table below). |
| anything else / `null` | `GeoLocation::unknown($ip, 'array', isPrivate: ...)`. |

Recognised array keys:

| Key | Aliases | Maps to |
| --- | --- | --- |
| `country_code` | `country`, `iso_code` | `GeoLocation::$countryCode` (uppercased). Required - without it the result is "unknown". |
| `region_code` | `state` | `GeoLocation::$regionCode`, normalised through `RegionData::match()` and falling back to the raw value. |
| `region_name` | `state_name` | Used only to match a subdivision code when the code is unknown. |
| `city` | - | `GeoLocation::$city`. |
| `postal_code` | - | `GeoLocation::$postalCode`. |
| `latitude` | - | `GeoLocation::$latitude` (cast to float when set). |
| `longitude` | - | `GeoLocation::$longitude` (cast to float when set). |

The resolver also supports a wildcard entry keyed `'*'`, used for any IP that
has no explicit entry:

```php
GeoLock::fake([
    '*' => ['country_code' => 'US', 'region_code' => 'US-FL'],
]);
```

```php
$resolver = GeoLock::fake();

$resolver->put('8.8.8.8', ['country_code' => 'US']);
$resolver->fill(['1.1.1.1' => 'CA']);
$resolver->forget('8.8.8.8');
$resolver->isEmpty();
$resolver->name();   // "array"
```

Because `GEO_LOCK_RESOLVER=array` is set for the workbench and the test suite,
no accidental network lookups can happen there.

## Model factories

Both models ship with factories under `GeoLock\Database\Factories`, resolved
automatically by `GeoLockRule::factory()` / `GeoLockAttempt::factory()`. The
package's `TestCase` also registers
`Factory::guessFactoryNamesUsing()` so `Model::factory()` works for any model.

### `GeoLockRule::factory()`

Default attributes: a unique three-word `name`, `description` `null`,
`roles` `['editor']`, `type` `blacklist`, `countries` `['US']`, `regions` `[]`,
`is_active` `true`, `priority` `0`.

| State | Sets |
| --- | --- |
| `whitelist()` | `type` = `whitelist`. |
| `blacklist()` | `type` = `blacklist`. |
| `forCountries(array $countries)` | `countries` = the given list. |
| `forRegions(array $regions)` | `regions` = the given list. |
| `forRoles(array $roles)` | `roles` = the given list. |
| `forEveryRole()` | `roles` = `[]` (applies to everybody). |
| `inactive()` | `is_active` = `false`. |
| `priority(int $priority)` | `priority` = the given value. |

```php
use GeoLock\Models\GeoLockRule;

GeoLockRule::factory()
    ->whitelist()
    ->forRoles(['editor'])
    ->forCountries(['US'])
    ->forRegions(['US-FL', 'US-GA'])
    ->priority(10)
    ->create(['name' => 'Editors: FL / GA']);

GeoLockRule::factory()->blacklist()->forEveryRole()->forCountries(['JM'])->create();
GeoLockRule::factory()->inactive()->create();
```

Note: factories bypass the repository's normalisation, so `countries` is not
derived from `regions`. Set both explicitly when a test depends on the country
list.

### `GeoLockAttempt::factory()`

Default attributes: a random IPv4 `ip`, `country_code` `US`, `region_code`
`US-FL`, a random `city`, `resolver` `array`, `roles` `['editor']`, `outcome`
`DeniedBlacklist`, `reason` `blacklisted`, `url` `https://example.com/admin`,
`method` `GET`, `user_agent` `Mozilla/5.0`.

| State | Sets |
| --- | --- |
| `allowed()` | `outcome` = `Allowed`, `reason` = `not_blacklisted`. |
| `denied()` | `outcome` = `DeniedWhitelist`, `reason` = `not_whitelisted`. |

```php
use GeoLock\Models\GeoLockAttempt;

GeoLockAttempt::factory()->allowed()->count(5)->create();
GeoLockAttempt::factory()->denied()->create(['country_code' => 'JM']);

// Scopes:
GeoLockAttempt::query()->denied()->count();
GeoLockAttempt::query()->allowed()->latestFirst()->get();
```

## Swapping the resolver

`GeoLock::useResolver()` replaces the resolver for the remainder of the request
without going through the fake helper, which is useful when you want to assert
against a specific resolver instance or inject a stub that throws:

```php
use GeoLock\Contracts\GeoResolver;
use GeoLock\Data\GeoLocation;
use GeoLock\Facades\GeoLock;

$resolver = new class implements GeoResolver
{
    public function resolve(string $ip): ?GeoLocation
    {
        throw new \RuntimeException('database missing');
    }

    public function name(): string
    {
        return 'failing';
    }
};

GeoLock::useResolver($resolver);

// With access.on_resolver_failure = whitelist_only, whitelist-bound visitors
// are now denied with DeniedResolverFailure.
$decision = GeoLock::evaluate($editorUser, '203.0.113.7');
```

`GeoLock::useResolver()` also clears the per-request location memo.
`GeoLock::resetResolver()` restores the configured resolver.

## The workbench setup

The package uses [Orchestra Testbench](https://packages.tools/testbench) with a
`workbench/` application, so tests exercise the plugin against a real Filament
panel.

`testbench.yaml`:

```yaml
laravel: '@testbench'

providers:
  - GeoLock\GeoLockServiceProvider
  - Workbench\App\Providers\WorkbenchServiceProvider
  - Workbench\App\Providers\AdminPanelProvider

migrations:
  - workbench/database/migrations

seeders:
  - Workbench\Database\Seeders\DatabaseSeeder

env:
  - APP_ENV=local
  - GEO_LOCK_RESOLVER=array

workbench:
  start: '/admin'
  install: true
  discovers:
    web: true
    commands: true
    views: true
    factories: true
  build:
    - create-sqlite-db
    - db-wipe
    - migrate-fresh
```

Key pieces:

| File | Purpose |
| --- | --- |
| `workbench/app/Providers/AdminPanelProvider.php` | The `admin` panel at `/admin` with `->plugin(GeoLockPlugin::make())` and the `web` guard. |
| `workbench/app/Models/User.php` | A user model using spatie's `HasRoles`. |
| `workbench/database/seeders/DatabaseSeeder.php` | Creates `super_admin`, `editor`, `moderator` and `admin@example.com` (password `password`). |
| `workbench/database/migrations` | Users and permission tables. |

The package's `GeoLock\Tests\TestCase` extends
`Orchestra\Testbench\TestCase` and uses `WithWorkbench`. Because Testbench
disables package discovery, `getPackageProviders()` registers Filament, Livewire,
spatie/laravel-permission, `torann/geoip`, the plugin provider and the workbench
providers explicitly. `defineEnvironment()` sets the `testing` database, the
`array` cache and session drivers, and disables both caches:

```php
$app['config']->set('geo-lock.resolver.driver', 'array');
$app['config']->set('geo-lock.resolver.cache.enabled', false);
$app['config']->set('geo-lock.rules.cache.enabled', false);
```

`defineDatabaseMigrations()` loads the plugin migrations and the workbench
migrations. `setUp()` also pins the current Filament panel to `admin`:

```php
Filament::setCurrentPanel(Filament::getPanel('admin'));
```

A `createUser()` helper is available on the base test case:

```php
$user = $this->createUser(['email' => 'ops@example.com'], ['editor']);
```

`tests/Pest.php` binds the base test case to the `Feature` and `Unit`
directories and registers the `fakeGeo()` helper.

A complete feature test looks like this:

```php
use GeoLock\Models\GeoLockRule;

it('denies a blacklisted visitor', function (): void {
    GeoLockRule::factory()->blacklist()->forCountries(['JM'])->forEveryRole()->create();

    fakeGeo(['8.8.8.8' => ['country_code' => 'JM']]);

    $decision = \GeoLock\Facades\GeoLock::evaluate(null, '8.8.8.8');

    expect($decision->outcome)->toBe(\GeoLock\Enums\GeoLockOutcome::DeniedBlacklist)
        ->and($decision->denied())->toBeTrue();
});
```

## Running the suite

```bash
# The whole suite (the project default).
vendor/bin/pest

# Without coverage, which is what CI uses.
vendor/bin/pest --no-coverage

# A single file or test.
vendor/bin/pest tests/Feature/SmokeTest.php
vendor/bin/pest --filter="denies a blacklisted visitor"
```

The `composer.json` scripts wrap the common tasks:

| Command | Runs |
| --- | --- |
| `composer test` | `pest`. |
| `composer test:coverage` | `pest --coverage`. |
| `composer lint` | `pint`. |
| `composer lint:check` | `pint --test`. |
| `composer analyse` | `phpstan analyse` (level 5 over `src`). |
| `composer check` | `lint:check`, `analyse`, `test`. |

`phpunit.xml.dist` sets `GEO_LOCK_RESOLVER=array` and array drivers for the
cache/session, so the suite never performs a real GeoIP lookup.

Next: [Troubleshooting](troubleshooting.md).
