<?php

declare(strict_types=1);

use GeoLock\Contracts\GeoLockRuleRepository;
use GeoLock\Enums\RuleType;
use GeoLock\Models\GeoLockRule;
use Illuminate\Support\Facades\DB;

function ruleRepository(): GeoLockRuleRepository
{
    return app(GeoLockRuleRepository::class);
}

it('returns only active rules when asked', function (): void {
    GeoLockRule::factory()->create(['name' => 'active']);
    GeoLockRule::factory()->inactive()->create(['name' => 'inactive']);

    $repository = ruleRepository();

    expect($repository->all(true)->pluck('name')->all())->toBe(['active'])
        ->and($repository->all(false)->count())->toBe(2)
        ->and($repository->count(true))->toBe(1)
        ->and($repository->count(false))->toBe(2);
});

it('creates, updates and deletes rules', function (): void {
    $repository = ruleRepository();

    $rule = $repository->create([
        'name' => 'Created',
        'type' => 'blacklist',
        'countries' => ['jm'],
        'regions' => [],
    ]);

    expect($rule->exists)->toBeTrue()
        ->and($rule->countries)->toBe(['JM']);

    $repository->update($rule, ['name' => 'Updated', 'priority' => 5]);

    expect($rule->fresh()->name)->toBe('Updated')
        ->and($rule->fresh()->priority)->toBe(5);

    expect($repository->delete($rule))->toBeTrue()
        ->and(GeoLockRule::query()->count())->toBe(0)
        ->and($repository->find($rule->getKey()))->toBeNull();
});

it('syncs a whole rule set in one call', function (): void {
    $repository = ruleRepository();

    $keep = $repository->create(['name' => 'Keep', 'type' => 'blacklist', 'countries' => ['US']]);
    $remove = $repository->create(['name' => 'Remove', 'type' => 'blacklist', 'countries' => ['JM']]);

    $repository->sync([
        ['id' => $keep->getKey(), 'name' => 'Kept and updated', 'type' => 'whitelist', 'countries' => ['US']],
        ['name' => 'Brand new', 'type' => 'blacklist', 'countries' => ['GB']],
    ]);

    expect(GeoLockRule::query()->count())->toBe(2)
        ->and($keep->fresh()->name)->toBe('Kept and updated')
        ->and($keep->fresh()->type)->toBe(RuleType::Whitelist)
        ->and($repository->find($remove->getKey()))->toBeNull()
        ->and(GeoLockRule::query()->where('name', 'Brand new')->exists())->toBeTrue();
});

it('normalises the type, countries and regions', function (): void {
    $rule = ruleRepository()->create([
        'name' => 'Normalised',
        'type' => 'WHITELIST',
        'countries' => ['us', 'US', 'gb'],
        'regions' => ['us-fl', 'US-FL'],
    ]);

    expect($rule->type)->toBe(RuleType::Whitelist)
        ->and($rule->countries)->toBe(['US', 'GB'])
        ->and($rule->regions)->toBe(['US-FL']);
});

it('derives the countries from the selected regions', function (): void {
    $rule = ruleRepository()->create([
        'name' => 'Region only',
        'type' => 'blacklist',
        'regions' => ['US-FL', 'JM-01'],
    ]);

    expect($rule->countries)->toBe(['US', 'JM'])
        ->and($rule->regions)->toBe(['US-FL', 'JM-01']);
});

it('flushes the cache when the rule set changes', function (): void {
    config()->set('geo-lock.rules.cache.enabled', true);

    $repository = ruleRepository();

    GeoLockRule::query()->create(['name' => 'Cached', 'type' => 'blacklist', 'countries' => ['US']]);

    expect($repository->all()->count())->toBe(1);

    // Insert straight through the query builder, bypassing the model events,
    // so that only an explicit flush() can refresh the cached rule set.
    DB::table('geo_lock_rules')->insert([
        'name' => 'Direct',
        'description' => null,
        'roles' => json_encode([]),
        'type' => 'blacklist',
        'countries' => json_encode(['JM']),
        'regions' => json_encode([]),
        'is_active' => true,
        'priority' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect($repository->all()->count())->toBe(1);

    $repository->flush();

    expect($repository->all()->count())->toBe(2);
});
