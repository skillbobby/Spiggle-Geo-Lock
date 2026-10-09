<?php

declare(strict_types=1);

namespace GeoLock\Tests;

use Filament\Facades\Filament;
use GeoLock\GeoLockServiceProvider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase as Orchestra;
use Spatie\Permission\Models\Role;
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
            static fn (string $modelName): string => 'GeoLock\\Database\\Factories\\' . class_basename($modelName) . 'Factory',
        );

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /**
     * Testbench disables package discovery, so the providers of the packages
     * the plugin integrates with are registered explicitly.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            \BladeUI\Heroicons\BladeHeroiconsServiceProvider::class,
            \BladeUI\Icons\BladeIconsServiceProvider::class,
            \Kirschbaum\PowerJoins\PowerJoinsServiceProvider::class,
            \RyanChandler\BladeCaptureDirective\BladeCaptureDirectiveServiceProvider::class,
            \Livewire\LivewireServiceProvider::class,
            \Filament\Support\SupportServiceProvider::class,
            \Filament\Actions\ActionsServiceProvider::class,
            \Filament\Forms\FormsServiceProvider::class,
            \Filament\Infolists\InfolistsServiceProvider::class,
            \Filament\Notifications\NotificationsServiceProvider::class,
            \Filament\Schemas\SchemasServiceProvider::class,
            \Filament\Tables\TablesServiceProvider::class,
            \Filament\Widgets\WidgetsServiceProvider::class,
            \Filament\FilamentServiceProvider::class,
            \Spatie\Permission\PermissionServiceProvider::class,
            \Torann\GeoIP\GeoIPServiceProvider::class,
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
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->loadMigrationsFrom(__DIR__ . '/../workbench/database/migrations');
    }

    /**
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
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
