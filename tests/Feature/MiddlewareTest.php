<?php

declare(strict_types=1);

use GeoLock\Events\GeoLockAccessDenied;
use GeoLock\Events\GeoLockAccessGranted;
use GeoLock\Events\GeoLockBypassed;
use GeoLock\Models\GeoLockAttempt;
use GeoLock\Models\GeoLockRule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\View;

function blockJamaica(): void
{
    GeoLockRule::factory()->blacklist()->forCountries(['JM'])->forEveryRole()->create();
    fakeGeo(['8.8.8.8' => ['country_code' => 'JM']]);
}

beforeEach(function (): void {
    $this->actingAs($this->createUser([], ['editor']));
});

it('allows a request from an allowed location', function (): void {
    $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])
        ->get('/admin')
        ->assertRedirect();
});

it('blocks a request from a blacklisted location with 403', function (): void {
    blockJamaica();

    $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])
        ->get('/admin')
        ->assertForbidden();
});

it('returns a JSON payload for JSON requests', function (): void {
    blockJamaica();

    $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])
        ->getJson('/admin')
        ->assertStatus(403)
        ->assertJsonStructure(['message', 'outcome'])
        ->assertJsonPath('outcome', 'denied_blacklist');
});

it('denies forwarded requests when no trusted proxy is configured', function (): void {
    config()->set('geo-lock.access.require_trusted_proxy', true);

    $this->withHeader('X-Forwarded-For', '8.8.8.8')
        ->getJson('/admin')
        ->assertStatus(403)
        ->assertJsonPath('outcome', 'denied_untrusted_proxy');
});

it('uses the forwarded IP once the TrustProxies middleware trusts the proxy', function (): void {
    config()->set('geo-lock.access.require_trusted_proxy', true);
    config()->set('trustedproxy.proxies', '127.0.0.1');
    blockJamaica();

    $this->withHeader('X-Forwarded-For', '8.8.8.8')
        ->getJson('/admin')
        ->assertStatus(403)
        ->assertJsonPath('outcome', 'denied_blacklist');
});

it('accepts a trusted forwarded request', function (): void {
    config()->set('geo-lock.access.require_trusted_proxy', true);
    config()->set('trustedproxy.proxies', '127.0.0.1');

    $this->withHeader('X-Forwarded-For', '8.8.8.8')
        ->get('/admin')
        ->assertRedirect();
});

it('dispatches GeoLockAccessDenied on denial', function (): void {
    Event::fake();
    blockJamaica();

    $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])->get('/admin');

    Event::assertDispatched(GeoLockAccessDenied::class);
});

it('dispatches GeoLockAccessGranted on a grant', function (): void {
    Event::fake();

    $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])->get('/admin');

    Event::assertDispatched(GeoLockAccessGranted::class);
});

it('dispatches GeoLockBypassed when the visitor is bypassed', function (): void {
    Event::fake();
    $this->actingAs($this->createUser([], ['super_admin']));

    $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])->get('/admin');

    Event::assertDispatched(GeoLockBypassed::class);
});

it('records denials in the audit table', function (): void {
    blockJamaica();

    $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])->get('/admin');

    $this->assertDatabaseHas('geo_lock_attempts', [
        'outcome' => 'denied_blacklist',
        'ip' => '8.8.8.8',
    ]);

    expect(GeoLockAttempt::query()->count())->toBe(1);
});

it('records grants only when audit.record_grants is enabled', function (): void {
    $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])->get('/admin');

    expect(GeoLockAttempt::query()->count())->toBe(0);

    config()->set('geo-lock.audit.record_grants', true);

    $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])->get('/admin');

    expect(GeoLockAttempt::query()->count())->toBe(1);
});

it('records bypasses only when audit.record_bypasses is enabled', function (): void {
    $this->actingAs($this->createUser([], ['super_admin']));

    $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])->get('/admin');

    expect(GeoLockAttempt::query()->count())->toBe(0);

    config()->set('geo-lock.audit.record_bypasses', true);

    $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])->get('/admin');

    expect(GeoLockAttempt::query()->count())->toBe(1);
});

it('uses a custom denial status', function (): void {
    blockJamaica();
    config()->set('geo-lock.denial.status', 451);

    $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])->get('/admin')->assertStatus(451);
});

it('redirects to a custom denial URL', function (): void {
    blockJamaica();
    config()->set('geo-lock.denial.redirect', '/blocked');

    $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])->get('/admin')->assertRedirect('/blocked');
});

it('renders a custom denial view', function (): void {
    blockJamaica();
    View::addNamespace('geolock-tests', __DIR__.'/../Fixtures');
    config()->set('geo-lock.denial.view', 'geolock-tests::denied');

    $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])
        ->get('/admin')
        ->assertStatus(403)
        ->assertSee('Denied: denied_blacklist', false);
});

it('does not enforce when the middleware is disabled', function (): void {
    blockJamaica();
    config()->set('geo-lock.middleware.enabled', false);

    $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])->get('/admin')->assertRedirect();
});

it('does not enforce on excluded panels', function (): void {
    blockJamaica();
    config()->set('geo-lock.middleware.except_panels', ['admin']);

    $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])->get('/admin')->assertRedirect();
});

it('does not enforce when geo-lock is globally disabled', function (): void {
    blockJamaica();
    config()->set('geo-lock.enabled', false);

    $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])->get('/admin')->assertRedirect();
});
