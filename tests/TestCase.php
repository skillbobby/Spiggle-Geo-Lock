<?php

declare(strict_types=1);

namespace GeoLock\Tests;

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\Facades\Filament;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use GeoLock\GeoLockServiceProvider;
use GeoLock\Support\MiddlewareRegistry;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Foundation\Application;
use Kirschbaum\PowerJoins\PowerJoinsServiceProvider;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase as Orchestra;
use RyanChandler\BladeCaptureDirective\BladeCaptureDirectiveServiceProvider;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionServiceProvider;
use Torann\GeoIP\GeoIPServiceProvider;
use Workbench\App\Models\User;
use Workbench\App\Providers\AdminPanelProvider;
use Workbench\App\Providers\WorkbenchServiceProvider;

abstract class TestCase extends Orchestra
{
    use WithWorkbench;

    /**
     * Discover the service providers of the installed packages (Filament,
     * Livewire, spatie/laravel-permission, ...).
     */
    protected $enablesPackageDiscoveries = false;

    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            static fn (string $modelName): string => 'GeoLock\\Database\\Factories\\'.class_basename($modelName).'Factory',
        );

        // `MiddlewareRegistry` memoizes the panels it guarded in a static
        // property, which would otherwise leak across the fresh application of
        // every test and stop the middleware from being registered again.
        MiddlewareRegistry::flush();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /**
     * Testbench disables package discovery, so the providers of the packages
     * the plugin integrates with are registered explicitly.
     *
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            BladeHeroiconsServiceProvider::class,
            BladeIconsServiceProvider::class,
            PowerJoinsServiceProvider::class,
            BladeCaptureDirectiveServiceProvider::class,
            LivewireServiceProvider::class,
            SupportServiceProvider::class,
            ActionsServiceProvider::class,
            FormsServiceProvider::class,
            InfolistsServiceProvider::class,
            NotificationsServiceProvider::class,
            SchemasServiceProvider::class,
            TablesServiceProvider::class,
            WidgetsServiceProvider::class,
            FilamentServiceProvider::class,
            PermissionServiceProvider::class,
            GeoIPServiceProvider::class,
            GeoLockServiceProvider::class,
            WorkbenchServiceProvider::class,
            AdminPanelProvider::class,
        ];
    }

    /**
     * The plugin migrations are publishable, so they are loaded explicitly in
     * the test suite.
     */
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../workbench/database/migrations');
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('geo-lock.resolver.driver', 'array');
        $app['config']->set('geo-lock.resolver.cache.enabled', false);
        $app['config']->set('geo-lock.rules.cache.enabled', false);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, string>  $roles
     */
    protected function createUser(array $attributes = [], array $roles = []): User
    {
        $user = User::query()->create([
            'name' => 'Test User',
            'email' => fake()->unique()->safeEmail(),
            'password' => bcrypt('password'),
            ...$attributes,
        ]);

        if ($roles !== []) {
            foreach ($roles as $role) {
                Role::findOrCreate($role, 'web');
            }

            $user->syncRoles($roles);
        }

        return $user;
    }
}
