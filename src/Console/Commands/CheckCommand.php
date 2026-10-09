<?php

declare(strict_types=1);

namespace GeoLock\Console\Commands;

use GeoLock\Models\GeoLockRule;
use GeoLock\Services\GeoLockManager;
use GeoLock\Support\CountryData;
use GeoLock\Support\RegionData;
use Illuminate\Console\Command;

class CheckCommand extends Command
{
    protected $signature = 'geo-lock:check
        {ip? : The IP address to evaluate}
        {--user= : Evaluate as this user id}
        {--role=* : Evaluate as these roles}
        {--all : List every rule that applies}
        {--json : Output the decision as JSON}';

    protected $description = 'Evaluate the geo-lock rules for an IP address';

    public function handle(GeoLockManager $manager): int
    {
        $ip = (string) ($this->argument('ip') ?: $manager->clientIp(request()));
        $user = $this->resolveUser();
        $roles = array_values(array_filter((array) $this->option('role'), filled(...)));

        $location = $manager->resolve($ip);
        $decision = $manager->evaluate(
            $user,
            $ip,
            request(),
            $roles === [] ? null : $roles,
            applyBypass: false,
        );

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'ip' => $ip,
                'location' => $location->toArray(),
                'roles' => $decision->roles,
                'outcome' => $decision->outcome->value,
                'allowed' => $decision->allowed(),
                'reason' => $decision->reason,
                'rule_id' => $decision->rule?->getKey(),
                'message' => $decision->message(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $decision->denied() ? self::FAILURE : self::SUCCESS;
        }

        $this->newLine();
        $this->table(['Key', 'Value'], [
            ['IP address', $ip],
            ['Resolved location', $location->label()],
            ['Country', $location->countryLabel() ?? CountryData::name($location->countryCode) ?? '-'],
            ['Region', $location->regionLabel() ?? ($location->regionCode ? RegionData::label($location->regionCode) : '-')],
            ['Resolver', $location->resolver],
            ['Roles', implode(', ', $decision->roles) ?: '-'],
            ['Outcome', $decision->outcome->getLabel()],
            ['Matched rule', $decision->rule?->label() ?? '-'],
            ['Applicable rules', (string) $decision->evaluatedRules],
            ['Message', $decision->message()],
        ]);

        if ($this->option('all')) {
            $rules = $manager->rules();

            $this->newLine();
            $this->table(
                ['ID', 'Name', 'Type', 'Roles', 'Countries', 'Regions', 'Active'],
                $rules->map(fn (GeoLockRule $rule): array => [
                    $rule->getKey(),
                    $rule->label(),
                    $rule->type->value,
                    implode(', ', $rule->roleLabels()) ?: '*',
                    implode(', ', $rule->countries ?? []) ?: '-',
                    implode(', ', $rule->regions ?? []) ?: '-',
                    $rule->is_active ? 'yes' : 'no',
                ])->all(),
            );
        }

        $this->newLine();

        $this->components->info(sprintf(
            'Access is %s for %s.',
            $decision->allowed() ? 'ALLOWED' : 'DENIED',
            $location->label(),
        ));

        return $decision->denied() ? self::FAILURE : self::SUCCESS;
    }

    protected function resolveUser(): ?\Illuminate\Contracts\Auth\Authenticatable
    {
        $id = $this->option('user');

        if (blank($id)) {
            return null;
        }

        $model = config('auth.providers.users.model');

        if (! is_string($model) || ! class_exists($model)) {
            $this->components->warn('No user model is configured (auth.providers.users.model).');

            return null;
        }

        $user = $model::query()->find($id);

        if (! $user instanceof \Illuminate\Contracts\Auth\Authenticatable) {
            $this->components->warn("User [{$id}] was not found.");

            return null;
        }

        return $user;
    }
}
