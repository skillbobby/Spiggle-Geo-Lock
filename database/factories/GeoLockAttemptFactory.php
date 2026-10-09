<?php

declare(strict_types=1);

namespace GeoLock\Database\Factories;

use GeoLock\Enums\GeoLockOutcome;
use GeoLock\Models\GeoLockAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GeoLockAttempt>
 */
class GeoLockAttemptFactory extends Factory
{
    protected $model = GeoLockAttempt::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ip' => fake()->ipv4(),
            'country_code' => 'US',
            'region_code' => 'US-FL',
            'city' => fake()->city(),
            'resolver' => 'array',
            'roles' => ['editor'],
            'outcome' => GeoLockOutcome::DeniedBlacklist,
            'reason' => 'blacklisted',
            'url' => 'https://example.com/admin',
            'method' => 'GET',
            'user_agent' => 'Mozilla/5.0',
        ];
    }

    public function allowed(): static
    {
        return $this->state(['outcome' => GeoLockOutcome::Allowed, 'reason' => 'not_blacklisted']);
    }

    public function denied(): static
    {
        return $this->state(['outcome' => GeoLockOutcome::DeniedWhitelist, 'reason' => 'not_whitelisted']);
    }
}
