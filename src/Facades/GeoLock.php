<?php

declare(strict_types=1);

namespace GeoLock\Facades;

use GeoLock\Data\GeoLocation;
use GeoLock\Data\GeoLockDecision;
use GeoLock\Services\GeoLockManager;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;

/**
 * @method static bool isEnabled()
 * @method static GeoLocation resolve(string $ip)
 * @method static GeoLockDecision evaluate(?Authenticatable $user = null, ?string $ip = null, ?Request $request = null, ?array $roles = null, bool $applyBypass = true)
 * @method static bool allows(?Authenticatable $user = null, ?string $ip = null, ?Request $request = null, ?array $roles = null)
 * @method static bool denies(?Authenticatable $user = null, ?string $ip = null, ?Request $request = null, ?array $roles = null)
 * @method static string|null bypassReason(?Authenticatable $user, ?string $ip = null)
 * @method static array<int, string> rolesFor(?Authenticatable $user)
 * @method static \GeoLock\Resolvers\ArrayGeoResolver fake(array $locations = [])
 * @method static void flush()
 *
 * @see GeoLockManager
 */
class GeoLock extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return GeoLockManager::class;
    }
}
