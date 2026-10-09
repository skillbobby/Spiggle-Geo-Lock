<?php

declare(strict_types=1);

namespace GeoLock\Support;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * Answers whether the current user may manage the geo-lock rules, based on
 * `geo-lock.ui.authorization.manage`. The value may be null (everybody), a
 * boolean, a Gate ability name or a closure receiving the user.
 */
final class Authorizer
{
    /**
     * The authenticated user of the current Filament panel, falling back to
     * the default guard.
     */
    public static function user(): ?Authenticatable
    {
        if (class_exists(Filament::class)) {
            try {
                if ($user = Filament::auth()->user()) {
                    return $user;
                }
            } catch (Throwable) {
                // No panel is booted yet - fall back to the default guard.
            }
        }

        return auth()->user();
    }

    public static function canManage(?Authenticatable $user): bool
    {
        return self::allows(config('geo-lock.ui.authorization.manage'), $user);
    }

    public static function canBypass(?Authenticatable $user): bool
    {
        return self::allows(config('geo-lock.ui.authorization.bypass'), $user);
    }

    protected static function allows(mixed $rule, ?Authenticatable $user): bool
    {
        if ($rule === null || $rule === '') {
            return true;
        }

        if (is_bool($rule)) {
            return $rule;
        }

        if ($rule instanceof Closure) {
            return (bool) $rule($user);
        }

        if (is_string($rule)) {
            return $user !== null && Gate::forUser($user)->allows($rule);
        }

        return false;
    }
}
