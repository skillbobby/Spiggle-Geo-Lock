<?php

declare(strict_types=1);

namespace GeoLock\Console\Commands;

use GeoLock\Enums\ResolverFailureBehavior;
use GeoLock\Enums\UnresolvableIpBehavior;
use GeoLock\Models\GeoLockAttempt;
use GeoLock\Models\GeoLockRule;
use GeoLock\Services\BypassInspector;
use GeoLock\Services\GeoLockManager;
use GeoLock\Support\CountryData;
use GeoLock\Support\PanelScope;
use GeoLock\Support\RegionData;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Support\Facades\Schema as SchemaFacade;
use Throwable;

class DoctorCommand extends Command
{
    protected $signature = 'geo-lock:doctor
        {--no-lookup : Skip the live IP lookup}
        {--ip=8.8.8.8 : The IP address used for the live lookup}';

    protected $description = 'Diagnose the geo-lock configuration, database and geolocation service';

    public function handle(GeoLockManager $manager, BypassInspector $bypass): int
    {
        $rows = [];

        $rows[] = $this->row('Geo-lock enabled', (bool) config('geo-lock.enabled', true), config('geo-lock.enabled') ? 'yes' : 'no');
        $rows[] = $this->row('Middleware', (bool) config('geo-lock.middleware.enabled', true), config('geo-lock.middleware.auto_register') ? 'auto-registered on panels' : 'manual registration');
        $rows[] = $this->row('Panel scope', true, PanelScope::matches(PanelScope::currentPanelId()) ? 'all panels' : 'restricted by config');

        $rulesTable = (string) config('geo-lock.rules.table');
        $rulesExist = $this->tableExists($rulesTable);
        $rows[] = $this->row("Rules table ({$rulesTable})", $rulesExist, $rulesExist ? GeoLockRule::query()->count().' rows' : 'missing - run php artisan migrate');

        $attemptsTable = (string) config('geo-lock.audit.table');
        $auditEnabled = (bool) config('geo-lock.audit.enabled', true);
        $attemptsExist = $this->tableExists($attemptsTable);
        $rows[] = $this->row("Audit table ({$attemptsTable})", ! $auditEnabled || $attemptsExist, $auditEnabled ? ($attemptsExist ? GeoLockAttempt::query()->count().' rows' : 'missing - run php artisan migrate') : 'audit disabled');

        $resolver = $manager->resolver();
        $rows[] = $this->row('Resolver driver', true, $resolver->name());

        if (! $this->option('no-lookup')) {
            $ip = (string) $this->option('ip');

            try {
                $location = $resolver->resolve($ip);
                $resolved = $location?->isResolved() ?? false;

                $rows[] = $this->row(
                    "Lookup {$ip}",
                    $resolved,
                    $resolved
                        ? (string) $location?->label()
                        : 'could not resolve - check the torann/geoip service and its database',
                    warning: ! $resolved,
                );
            } catch (Throwable $exception) {
                $rows[] = $this->row("Lookup {$ip}", false, $exception->getMessage());
            }
        }

        $cacheStore = config('geo-lock.resolver.cache.store') ?: config('cache.default');
        $cacheOk = true;
        $cacheDetail = (string) $cacheStore;

        try {
            $store = app(Factory::class)->store(config('geo-lock.resolver.cache.store'));
            $store->put('geo-lock:doctor', 'ok', 5);
            $cacheOk = $store->get('geo-lock:doctor') === 'ok';
            $store->forget('geo-lock:doctor');
        } catch (Throwable $exception) {
            $cacheOk = false;
            $cacheDetail = $exception->getMessage();
        }

        $rows[] = $this->row('Cache store', $cacheOk, $cacheDetail.(config('geo-lock.resolver.cache.enabled') ? ' (locations cached)' : ' (location cache disabled)'));

        $roleDirectory = $manager->roleDirectory();
        $roles = $roleDirectory->availableRoles();
        $rows[] = $this->row('Role provider', true, $roleDirectory->name().' - '.count($roles).' role(s) available');

        $rows[] = $this->row('Unresolvable IPs', true, UnresolvableIpBehavior::fromMixed(config('geo-lock.access.unresolvable'))->getLabel());
        $rows[] = $this->row('Resolver failures', true, ResolverFailureBehavior::fromMixed(config('geo-lock.access.on_resolver_failure'))->getLabel());

        $requireTrustedProxy = (bool) config('geo-lock.access.require_trusted_proxy', false);
        $trustedProxies = request()->getTrustedProxies();
        $rows[] = $this->row(
            'Trusted proxies',
            ! $requireTrustedProxy || $trustedProxies !== [],
            $trustedProxies === []
                ? ($requireTrustedProxy ? 'strict mode is on but no trusted proxy is configured' : 'not configured - set TrustProxies before enabling strict mode')
                : implode(', ', $trustedProxies),
        );

        $bypassRoles = (array) config('geo-lock.bypass.roles', []);
        $bypassUsers = array_filter([
            ...(array) config('geo-lock.bypass.emails', []),
            ...(array) config('geo-lock.bypass.user_ids', []),
            ...(array) config('geo-lock.bypass.ips', []),
        ]);
        $hasBypass = $bypassRoles !== [] || $bypassUsers !== [] || filled(config('geo-lock.bypass.ability'));
        $rows[] = $this->row(
            'Emergency bypass',
            $hasBypass,
            $hasBypass
                ? 'roles: '.(implode(', ', $bypassRoles) ?: '-').' | users: '.(count($bypassUsers) ?: 0).' | ability: '.(config('geo-lock.bypass.ability') ?: '-')
                : 'no bypass configured - a super admin could be locked out',
            warning: ! $hasBypass,
        );

        $rows[] = $this->row('GeoIP dataset', CountryData::exists('US') && RegionData::exists('US-FL'), CountryData::count().' countries, '.RegionData::count().' subdivisions');
        $rows[] = $this->row('torann/geoip service', true, (string) (config('geoip.service') ?: 'auto-detected'));

        $this->newLine();
        $this->table(
            ['Check', 'Status', 'Detail'],
            collect($rows)->map(static fn (array $row): array => array_values($row))->all(),
        );
        $this->newLine();

        $problems = collect($rows)->where('status', '!=', 'OK')->count();

        if ($problems === 0) {
            $this->components->info('Everything looks good.');

            return self::SUCCESS;
        }

        $this->components->warn($problems.' check(s) need attention.');

        return self::FAILURE;
    }

    /**
     * @return array{check: string, status: string, detail: string}
     */
    protected function row(string $check, bool $ok, string $detail, bool $warning = false): array
    {
        return [
            'check' => $check,
            'status' => $ok ? 'OK' : ($warning ? 'WARN' : 'FAIL'),
            'detail' => $detail,
        ];
    }

    protected function tableExists(string $table): bool
    {
        try {
            return SchemaFacade::hasTable($table);
        } catch (Throwable) {
            return false;
        }
    }
}
