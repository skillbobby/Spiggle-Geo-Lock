<?php

declare(strict_types=1);

namespace GeoLock\Facades;

use GeoLock\Contracts\GeoLockRuleRepository;
use GeoLock\Contracts\GeoResolver;
use GeoLock\Contracts\RoleDirectory;
use GeoLock\Data\GeoLocation;
use GeoLock\Data\GeoLockDecision;
use GeoLock\Models\GeoLockRule;
use GeoLock\Resolvers\ArrayGeoResolver;
use GeoLock\Services\GeoLockManager;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Facade;

/**
 * @method static bool isEnabled()
 * @method static GeoLocation resolve(string $ip)
 * @method static void forgetLocation(string $ip)
 * @method static string clientIp(?Request $request = null)
 * @method static bool isUntrustedProxyRequest(?Request $request = null)
 * @method static Collection<int, GeoLockRule> rules(bool $activeOnly = true)
 * @method static GeoLockRuleRepository repository()
 * @method static RoleDirectory roleDirectory()
 * @method static GeoResolver resolver()
 * @method static GeoLockManager useResolver(GeoResolver $resolver)
 * @method static GeoLockManager resetResolver()
 * @method static ArrayGeoResolver fake(array<string, mixed> $locations = [])
 * @method static GeoLockDecision evaluate(?Authenticatable $user = null, ?string $ip = null, ?Request $request = null, ?array $roles = null, bool $applyBypass = true)
 * @method static bool allows(?Authenticatable $user = null, ?string $ip = null, ?Request $request = null, ?array $roles = null)
 * @method static bool denies(?Authenticatable $user = null, ?string $ip = null, ?Request $request = null, ?array $roles = null)
 * @method static string|null bypassReason(?Authenticatable $user, ?string $ip = null)
 * @method static array<int, string> rolesFor(?Authenticatable $user)
 * @method static void fireEvents(GeoLockDecision $decision)
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
