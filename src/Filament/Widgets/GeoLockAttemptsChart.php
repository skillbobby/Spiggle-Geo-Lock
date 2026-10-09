<?php

declare(strict_types=1);

namespace GeoLock\Filament\Widgets;

use Filament\Widgets\ChartWidget;
use GeoLock\Enums\GeoLockOutcome;
use GeoLock\Models\GeoLockAttempt;
use GeoLock\Support\Authorizer;
use Illuminate\Support\Carbon;
use Throwable;

class GeoLockAttemptsChart extends ChartWidget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    public ?string $filter = '14';

    public static function canView(): bool
    {
        return Authorizer::canManage(Authorizer::user());
    }

    public function getHeading(): ?string
    {
        return __('geo-lock::geo-lock.widgets.chart.heading');
    }

    protected function getType(): string
    {
        return 'line';
    }

    /**
     * @return array<string, string>
     */
    protected function getFilters(): ?array
    {
        return [
            '7' => __('geo-lock::geo-lock.widgets.chart.filters.week'),
            '14' => __('geo-lock::geo-lock.widgets.chart.filters.fortnight'),
            '30' => __('geo-lock::geo-lock.widgets.chart.filters.month'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $days = (int) ($this->filter ?? 14);
        $start = now()->subDays($days - 1)->startOfDay();

        $labels = [];
        $blocked = [];
        $allowed = [];

        $counts = $this->counts($start);

        for ($offset = 0; $offset < $days; $offset++) {
            $date = Carbon::parse($start)->addDays($offset)->toDateString();

            $labels[] = Carbon::parse($date)->format('M j');
            $blocked[] = $counts['denied'][$date] ?? 0;
            $allowed[] = $counts['allowed'][$date] ?? 0;
        }

        return [
            'datasets' => [
                [
                    'label' => __('geo-lock::geo-lock.widgets.chart.datasets.blocked'),
                    'data' => $blocked,
                    'borderColor' => 'rgb(220, 38, 38)',
                    'backgroundColor' => 'rgba(220, 38, 38, 0.15)',
                    'tension' => 0.3,
                ],
                [
                    'label' => __('geo-lock::geo-lock.widgets.chart.datasets.allowed'),
                    'data' => $allowed,
                    'borderColor' => 'rgb(22, 163, 74)',
                    'backgroundColor' => 'rgba(22, 163, 74, 0.15)',
                    'tension' => 0.3,
                ],
            ],
            'labels' => $labels,
        ];
    }

    /**
     * @return array{denied: array<string, int>, allowed: array<string, int>}
     */
    protected function counts(Carbon $start): array
    {
        $result = ['denied' => [], 'allowed' => []];

        try {
            $attempts = GeoLockAttempt::query()
                ->where('created_at', '>=', $start)
                ->get(['outcome', 'created_at']);
        } catch (Throwable) {
            return $result;
        }

        foreach ($attempts as $attempt) {
            $date = $attempt->created_at?->toDateString();

            if ($date === null) {
                continue;
            }

            $bucket = $attempt->outcome instanceof GeoLockOutcome && $attempt->outcome->isDenied() ? 'denied' : 'allowed';

            $result[$bucket][$date] = ($result[$bucket][$date] ?? 0) + 1;
        }

        return $result;
    }
}
