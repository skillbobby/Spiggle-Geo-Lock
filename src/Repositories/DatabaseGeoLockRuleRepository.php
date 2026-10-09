<?php

declare(strict_types=1);

namespace GeoLock\Repositories;

use GeoLock\Contracts\GeoLockRuleRepository;
use GeoLock\Enums\RuleType;
use GeoLock\Models\GeoLockRule;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use GeoLock\Support\RegionData;

/**
 * The database backed rule repository, with an optional cache layer.
 */
class DatabaseGeoLockRuleRepository implements GeoLockRuleRepository
{
    public function __construct(
        protected CacheFactory $cache,
    ) {}

    public function all(bool $activeOnly = true): Collection
    {
        if (! $this->cachingEnabled()) {
            return $this->query($activeOnly);
        }

        $key = $this->cacheKey($activeOnly);
        $cached = $this->cache()->get($key);

        if (is_array($cached)) {
            /** @var Collection<int, GeoLockRule> $rules */
            $rules = $this->modelClass()::hydrate($cached);

            return $rules;
        }

        $rules = $this->query($activeOnly);

        $this->cache()->put(
            $key,
            $rules->map(static fn (GeoLockRule $rule): array => $rule->getAttributes())->all(),
            $this->cacheTtl(),
        );

        return $rules;
    }

    public function find(int|string $id): ?GeoLockRule
    {
        return $this->modelClass()::query()->find($id);
    }

    public function create(array $attributes): GeoLockRule
    {
        $rule = $this->modelClass()::query()->create($this->normalize($attributes));

        $this->flush();

        return $rule;
    }

    public function update(GeoLockRule $rule, array $attributes): GeoLockRule
    {
        $rule->fill($this->normalize($attributes));
        $rule->save();

        $this->flush();

        return $rule;
    }

    public function delete(GeoLockRule $rule): bool
    {
        $deleted = (bool) $rule->delete();

        $this->flush();

        return $deleted;
    }

    public function sync(array $rules): void
    {
        $model = $this->modelClass();
        $existing = $model::query()->get()->keyBy(static fn (GeoLockRule $rule): string => (string) $rule->getKey());

        $kept = [];

        foreach ($rules as $data) {
            if (! is_array($data)) {
                continue;
            }

            $id = $data['id'] ?? null;
            $attributes = $this->normalize($data);
            $rule = filled($id) ? $existing->get((string) $id) : null;

            if ($rule instanceof GeoLockRule) {
                $rule->fill($attributes)->save();
            } else {
                $rule = $model::query()->create($attributes);
            }

            $kept[] = (string) $rule->getKey();
        }

        $existing
            ->reject(static fn (GeoLockRule $rule): bool => in_array((string) $rule->getKey(), $kept, strict: true))
            ->each(static fn (GeoLockRule $rule) => $rule->delete());

        $this->flush();
    }

    public function count(bool $activeOnly = false): int
    {
        $query = $this->modelClass()::query();

        if ($activeOnly) {
            $query->active();
        }

        return $query->count();
    }

    public function flush(): void
    {
        if (! $this->cachingEnabled()) {
            return;
        }

        foreach ([true, false] as $activeOnly) {
            $this->cache()->forget($this->cacheKey($activeOnly));
        }
    }

    /**
     * Only the attributes a rule may hold, normalized and deduplicated.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function normalize(array $attributes): array
    {
        $data = Arr::only($attributes, [
            'name', 'description', 'roles', 'type', 'countries', 'regions', 'is_active', 'priority',
        ]);

        if (array_key_exists('name', $data)) {
            $data['name'] = filled($data['name']) ? (string) $data['name'] : null;
        }

        if (array_key_exists('description', $data)) {
            $data['description'] = filled($data['description']) ? (string) $data['description'] : null;
        }

        if (array_key_exists('type', $data)) {
            $data['type'] = (RuleType::fromMixed($data['type']) ?? RuleType::Blacklist)->value;
        }

        if (array_key_exists('is_active', $data)) {
            $data['is_active'] = (bool) $data['is_active'];
        }

        if (array_key_exists('priority', $data)) {
            $data['priority'] = (int) $data['priority'];
        }

        if (array_key_exists('roles', $data)) {
            $data['roles'] = $this->normalizeList($data['roles']);
        }

        if (array_key_exists('countries', $data) || array_key_exists('regions', $data)) {
            $countries = $this->normalizeList($data['countries'] ?? [], upper: true);
            $regions = $this->normalizeList($data['regions'] ?? [], upper: true);

            // Keep the country list in sync with the selected subdivisions.
            foreach ($regions as $region) {
                if ($country = RegionData::countryOf($region)) {
                    $countries[] = $country;
                }
            }

            $data['countries'] = array_values(array_unique($countries));
            $data['regions'] = $regions;
        }

        return $data;
    }

    /**
     * @return array<int, string>
     */
    protected function normalizeList(mixed $values, bool $upper = false): array
    {
        if ($values instanceof Collection) {
            $values = $values->all();
        }

        if (is_string($values)) {
            $values = preg_split('/[,\s]+/', $values) ?: [];
        }

        if (! is_array($values)) {
            return [];
        }

        $normalized = [];

        foreach ($values as $value) {
            if (! is_scalar($value)) {
                continue;
            }

            $value = Str::of((string) $value)->trim()->value();

            if ($value === '') {
                continue;
            }

            $normalized[] = $upper ? Str::upper($value) : $value;
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @return Collection<int, GeoLockRule>
     */
    protected function query(bool $activeOnly): Collection
    {
        $query = $this->modelClass()::query()->forEvaluation();

        if ($activeOnly) {
            $query->active();
        }

        return $query->get();
    }

    /**
     * @return class-string<GeoLockRule>
     */
    protected function modelClass(): string
    {
        /** @var class-string<GeoLockRule> $model */
        $model = config('geo-lock.rules.model', GeoLockRule::class);

        return $model;
    }

    protected function cachingEnabled(): bool
    {
        return (bool) config('geo-lock.rules.cache.enabled', true);
    }

    protected function cacheTtl(): int
    {
        return (int) config('geo-lock.rules.cache.ttl', 300);
    }

    protected function cacheKey(bool $activeOnly): string
    {
        return ((string) config('geo-lock.rules.cache.key', 'geo-lock:rules')) . ($activeOnly ? ':active' : ':all');
    }

    protected function cache(): CacheRepository
    {
        return $this->cache->store(config('geo-lock.rules.cache.store'));
    }
}
