<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Illuminate\Support\ServiceProvider;

class WorkbenchServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        // The package migrations are publishable, so the demo app loads them
        // straight from the package.
        $this->loadMigrationsFrom(__DIR__ . '/../../../database/migrations');
    }
}
