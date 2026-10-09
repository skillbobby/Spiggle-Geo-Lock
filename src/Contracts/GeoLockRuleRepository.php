<?php

declare(strict_types=1);

namespace GeoLock\Contracts;

use GeoLock\Models\GeoLockRule;
use Illuminate\Support\Collection;

/**
 * Storage for the geo-lock rules.
 */
interface GeoLockRuleRepository
{
    /**
     * Every rule, ordered for evaluation.
     *
     * @return Collection<int, GeoLockRule>
     */
    public function all(bool $activeOnly = true): Collection;

    public function find(int|string $id): ?GeoLockRule;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): GeoLockRule;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(GeoLockRule $rule, array $attributes): GeoLockRule;

    public function delete(GeoLockRule $rule): bool;

    /**
     * Replace the whole rule set, typically from the settings page repeater.
     *
     * Existing rules are matched by their `id` key; unknown rules are created
     * and rules that are missing from the payload are deleted.
     *
     * @param  array<int, array<string, mixed>>  $rules
     */
    public function sync(array $rules): void;

    public function count(bool $activeOnly = false): int;

    /**
     * Forget the cached rule set.
     */
    public function flush(): void;
}
