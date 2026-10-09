<?php

declare(strict_types=1);

use GeoLock\Facades\GeoLock;
use GeoLock\Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit');

/**
 * Fake the geolocation of an IP address.
 *
 * @param  array<string, array<string, mixed>|string>  $locations
 */
function fakeGeo(array $locations = []): void
{
    GeoLock::fake($locations);
}
