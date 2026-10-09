<?php

declare(strict_types=1);

use GeoLock\Models\GeoLockAttempt;
use GeoLock\Models\GeoLockRule;
use Illuminate\Support\Facades\Artisan;

it('returns FAILURE from geo-lock:check when the IP is denied', function (): void {
    GeoLockRule::factory()->blacklist()->forCountries(['JM'])->forEveryRole()->create();
    fakeGeo(['8.8.8.8' => ['country_code' => 'JM']]);

    $this->artisan('geo-lock:check', ['ip' => '8.8.8.8'])->assertExitCode(1);
});

it('returns SUCCESS from geo-lock:check when the IP is allowed', function (): void {
    GeoLockRule::factory()->blacklist()->forCountries(['JM'])->forEveryRole()->create();
    fakeGeo(['8.8.8.8' => ['country_code' => 'US']]);

    $this->artisan('geo-lock:check', ['ip' => '8.8.8.8'])->assertExitCode(0);
});

it('outputs valid JSON with the decision', function (): void {
    GeoLockRule::factory()->blacklist()->forCountries(['JM'])->forEveryRole()->create();
    fakeGeo(['8.8.8.8' => ['country_code' => 'JM']]);

    $exitCode = Artisan::call('geo-lock:check', ['ip' => '8.8.8.8', '--json' => true]);

    expect($exitCode)->toBe(1);

    $decoded = json_decode(trim(Artisan::output()), true);

    expect($decoded)->toBeArray()
        ->and($decoded['outcome'])->toBe('denied_blacklist')
        ->and($decoded['allowed'])->toBeFalse()
        ->and($decoded['ip'])->toBe('8.8.8.8');
});

it('scopes the evaluation to the requested roles', function (): void {
    GeoLockRule::factory()->blacklist()->forCountries(['JM'])->forRoles(['editor'])->create();
    fakeGeo(['8.8.8.8' => ['country_code' => 'JM']]);

    $this->artisan('geo-lock:check', ['ip' => '8.8.8.8', '--role' => ['admin']])->assertExitCode(0);
    $this->artisan('geo-lock:check', ['ip' => '8.8.8.8', '--role' => ['editor']])->assertExitCode(1);
});

it('runs the doctor command and prints the table', function (): void {
    $this->artisan('geo-lock:doctor', ['--no-lookup' => true])
        ->expectsOutputToContain('Geo-lock enabled')
        ->assertExitCode(0);
});

it('prunes attempts older than the given number of days', function (): void {
    GeoLockAttempt::factory()->create(['created_at' => now()->subDays(5)]);
    GeoLockAttempt::factory()->create(['created_at' => now()->subDay()]);

    $this->artisan('geo-lock:prune', ['--days' => 1])->assertExitCode(0);

    expect(GeoLockAttempt::query()->count())->toBe(1)
        ->and(GeoLockAttempt::query()->first()->created_at->greaterThan(now()->subDays(2)))->toBeTrue();
});

it('keeps attempts during a dry run', function (): void {
    GeoLockAttempt::factory()->create(['created_at' => now()->subDays(5)]);

    $this->artisan('geo-lock:prune', ['--days' => 1, '--dry-run' => true])->assertExitCode(0);

    expect(GeoLockAttempt::query()->count())->toBe(1);
});
