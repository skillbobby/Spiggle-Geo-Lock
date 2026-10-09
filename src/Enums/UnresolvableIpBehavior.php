<?php

declare(strict_types=1);

namespace GeoLock\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * What to do when an IP address cannot be resolved to a country.
 */
enum UnresolvableIpBehavior: string implements HasLabel
{
    case Allow = 'allow';
    case Deny = 'deny';

    public function getLabel(): string
    {
        return match ($this) {
            self::Allow => __('geo-lock::geo-lock.enums.unresolvable.allow'),
            self::Deny => __('geo-lock::geo-lock.enums.unresolvable.deny'),
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Allow => __('geo-lock::geo-lock.enums.unresolvable.allow_description'),
            self::Deny => __('geo-lock::geo-lock.enums.unresolvable.deny_description'),
        };
    }

    public function shouldDeny(): bool
    {
        return $this === self::Deny;
    }

    public static function fromMixed(self|string|bool|null $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (is_bool($value)) {
            return $value ? self::Deny : self::Allow;
        }

        return is_string($value) && ($resolved = self::tryFrom(strtolower($value))) !== null
            ? $resolved
            : self::Allow;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::Allow->value => self::Allow->getLabel(),
            self::Deny->value => self::Deny->getLabel(),
        ];
    }
}
