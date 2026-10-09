<?php

declare(strict_types=1);

namespace GeoLock\Database\Factories;

use GeoLock\Enums\RuleType;
use GeoLock\Models\GeoLockRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GeoLockRule>
 */
class GeoLockRuleFactory extends Factory
{
    protected $model = GeoLockRule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(3, true),
            'description' => null,
            'roles' => ['editor'],
            'type' => RuleType::Blacklist,
            'countries' => ['US'],
            'regions' => [],
            'is_active' => true,
            'priority' => 0,
        ];
    }

    public function whitelist(): static
    {
        return $this->state(['type' => RuleType::Whitelist]);
    }

    public function blacklist(): static
    {
        return $this->state(['type' => RuleType::Blacklist]);
    }

    /**
     * @param  array<int, string>  $countries
     */
    public function forCountries(array $countries): static
    {
        return $this->state(['countries' => $countries]);
    }

    /**
     * @param  array<int, string>  $regions
     */
    public function forRegions(array $regions): static
    {
        return $this->state(['regions' => $regions]);
    }

    /**
     * @param  array<int, string>  $roles
     */
    public function forRoles(array $roles): static
    {
        return $this->state(['roles' => $roles]);
    }

    public function forEveryRole(): static
    {
        return $this->state(['roles' => []]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    public function priority(int $priority): static
    {
        return $this->state(['priority' => $priority]);
    }
}
