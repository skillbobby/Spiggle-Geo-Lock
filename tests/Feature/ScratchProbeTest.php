<?php

declare(strict_types=1);

use GeoLock\Models\GeoLockRule;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

it('probe settings url allowed', function (): void {
    $this->actingAs($this->createUser([], ['editor']));
    $this->get('/admin/geo-lock')->assertOk();
});

it('probe trusted proxies', function (): void {
    $this->actingAs($this->createUser([], ['editor']));
    config()->set('geo-lock.access.require_trusted_proxy', true);

    $this->withHeader('X-Forwarded-For', '8.8.8.8')->get('/admin/geo-lock')->assertStatus(403);

    $headers = SymfonyRequest::HEADER_X_FORWARDED_FOR | SymfonyRequest::HEADER_X_FORWARDED_HOST | SymfonyRequest::HEADER_X_FORWARDED_PROTO | SymfonyRequest::HEADER_X_FORWARDED_PORT;
    SymfonyRequest::setTrustedProxies(['127.0.0.1'], $headers);

    GeoLockRule::factory()->blacklist()->forCountries(['JM'])->forEveryRole()->create();
    fakeGeo(['8.8.8.8' => ['country_code' => 'JM']]);

    $this->withHeader('X-Forwarded-For', '8.8.8.8')->get('/admin/geo-lock')->assertStatus(403);
    $response = $this->withHeader('X-Forwarded-For', '8.8.8.8')->get('/admin/geo-lock');
    dump('trusted-status', $response->getStatusCode());

    SymfonyRequest::setTrustedProxies([], $headers);
});
