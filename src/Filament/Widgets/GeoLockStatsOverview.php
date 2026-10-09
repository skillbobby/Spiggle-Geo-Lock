<?php

declare(strict_types=1);

namespace GeoLock\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use GeoLock\Models\GeoLockAttempt;
use GeoLock\Models\GeoLockRule;
use GeoLock\Support\Authorizer;
use Throwable;

class GeoLockStatsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return Authorizer::canManage(Authorizer::user());
    }

    protected function getHeading(): ?string
    {
        return __('geo-lock::geo-lock.widgets.stats.heading');
    }

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        return [
            Stat::make(__('geo-lock::geo-lock.widgets.stats.active_rules'), $this->countRules())
                ->description(__('geo-lock::geo-lock.widgets.stats.active_rules_description'))
                ->descriptionIcon('heroicon-m-shield-check')
                ->color('success'),

            Stat::make(__('geo-lock::geo-lock.widgets.stats.blocked_today'), $this->countAttempts(fn ($query) => $query->where('created_at', '>=', now()->startOfDay())))
                ->description(__('geo-lock::geo-lock.widgets.stats.blocked_today_description'))
                ->descriptionIcon('heroicon-m-no-symbol')
                ->color('danger'),

            Stat::make(__('geo-lock::geo-lock.widgets.stats.blocked_week'), $this->countAttempts(fn ($query) => $query->where('created_at', '>=', now()->subDays(7))))
                ->description(__('geo-lock::geo-lock.widgets.stats.blocked_week_description'))
                ->descriptionIcon('heroicon-m-calendar-days')
                ->color('warning'),

            Stat::make(__('geo-lock::geo-lock.widgets.stats.countries'), $this->countCountries())
                ->description(__('geo-lock::geo-lock.widgets.stats.countries_description'))
                ->descriptionIcon('heroicon-m-globe-alt')
                ->color('info'),
        ];
    }

    protected function countRules(): int|string
    {
        try {
            return GeoLockRule::query()->active()->count();
        } catch (Throwable) {
            return '-';
        }
    }

    protected function countAttempts(callable $scope): int|string
    {
        try {
            return $scope(GeoLockAttempt::query()->denied())->count();
        } catch (Throwable) {
            return '-';
        }
    }

    protected function countCountries(): int|string
    {
        try {
            return GeoLockAttempt::query()
                ->denied()
                ->where('created_at', '>=', now()->subDays(7))
                ->whereNotNull('country_code')
                ->distinct()
                ->count('country_code');
        } catch (Throwable) {
            return '-';
        }
    }
}
