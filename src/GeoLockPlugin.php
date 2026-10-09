<?php

declare(strict_types=1);

namespace GeoLock;

use Closure;
use Filament\Contracts\Plugin;
use Filament\Panel;
use GeoLock\Filament\Pages\GeoLockSettings;
use GeoLock\Filament\Resources\GeoLockRules\GeoLockRuleResource;
use GeoLock\Filament\Widgets\GeoLockAttemptsChart;
use GeoLock\Filament\Widgets\GeoLockStatsOverview;
use GeoLock\Filament\Widgets\RecentGeoLockAttempts;
use GeoLock\Services\BypassInspector;
use GeoLock\Support\MiddlewareRegistry;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Registers the geo-lock middleware, rules UI and widgets on a panel.
 *
 *     $panel->plugin(GeoLockPlugin::make());
 */
class GeoLockPlugin implements Plugin
{
    protected bool $hasRuleResource = true;

    protected bool $hasSettingsPage = true;

    protected bool $hasWidgets = true;

    public static function make(): static
    {
        return app(static::class);
    }

    public function getId(): string
    {
        return 'geo-lock';
    }

    public function register(Panel $panel): void
    {
        MiddlewareRegistry::apply($panel);

        if ($this->hasRuleResource && config('geo-lock.ui.rule_resource.enabled', true)) {
            $panel->resources([GeoLockRuleResource::class]);
        }

        if ($this->hasSettingsPage && config('geo-lock.ui.settings_page.enabled', true)) {
            $panel->pages([GeoLockSettings::class]);
        }

        if ($this->hasWidgets && config('geo-lock.ui.widgets.enabled', true)) {
            $widgets = array_filter([
                (bool) config('geo-lock.ui.widgets.stats', true) ? GeoLockStatsOverview::class : null,
                (bool) config('geo-lock.ui.widgets.recent_attempts', true) ? RecentGeoLockAttempts::class : null,
                (bool) config('geo-lock.ui.widgets.attempts_chart', true) ? GeoLockAttemptsChart::class : null,
            ]);

            $panel->widgets(array_values($widgets));
        }
    }

    public function boot(Panel $panel): void {}

    public function navigationGroup(?string $group): static
    {
        config()->set('geo-lock.ui.navigation_group', $group);

        return $this;
    }

    public function navigationIcon(string|\BackedEnum|null $icon): static
    {
        config()->set('geo-lock.ui.navigation_icon', $icon);

        return $this;
    }

    public function navigationSort(?int $sort): static
    {
        config()->set('geo-lock.ui.navigation_sort', $sort);

        return $this;
    }

    /**
     * @param  array<int, string>  $roles
     */
    public function bypassRoles(array $roles): static
    {
        app(BypassInspector::class)->addRoles($roles);

        return $this;
    }

    /**
     * Grant the bypass dynamically, e.g. for the support team.
     */
    public function bypassUsing(Closure $callback): static
    {
        app(BypassInspector::class)->bypassUsing($callback);

        return $this;
    }

    public function authorizeUsing(Closure $callback): static
    {
        config()->set('geo-lock.ui.authorization.manage', $callback);

        return $this;
    }

    public function withoutRuleResource(): static
    {
        $this->hasRuleResource = false;

        return $this;
    }

    public function withoutSettingsPage(): static
    {
        $this->hasSettingsPage = false;

        return $this;
    }

    public function withoutWidgets(): static
    {
        $this->hasWidgets = false;

        return $this;
    }
}
