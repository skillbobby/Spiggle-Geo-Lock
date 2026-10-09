<?php

declare(strict_types=1);

namespace GeoLock\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * What to do when the geolocation service itself fails (missing database,
 * unreachable API, unexpected exception, ...).
 *
 * The default, `WhitelistOnly`, implements the fail-closed requirement of the
 * specification: visitors bound by a whitelist rule are blocked until the
 * service is restored, while blacklist-only visitors keep their access.
 */
enum ResolverFailureBehavior: string implements HasLabel
{
    case WhitelistOnly = 'whitelist_only';
    case FailClosed = 'fail_closed';
    case FailOpen = 'fail_open';

    public function getLabel(): string
    {
        return match ($this) {
            self::WhitelistOnly => __('geo-lock::geo-lock.enums.resolver_failure.whitelist_only'),
            self::FailClosed => __('geo-lock::geo-lock.enums.resolver_failure.fail_closed'),
            self::FailOpen => __('geo-lock::geo-lock.enums.resolver_failure.fail_open'),
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::WhitelistOnly => __('geo-lock::geo-lock.enums.resolver_failure.whitelist_only_description'),
            self::FailClosed => __('geo-lock::geo-lock.enums.resolver_failure.fail_closed_description'),
            self::FailOpen => __('geo-lock::geo-lock.enums.resolver_failure.fail_open_description'),
        };
    }

    public static function fromMixed(self|string|bool|null $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (is_bool($value)) {
            return $value ? self::WhitelistOnly : self::FailOpen;
        }

        return is_string($value) && ($resolved = self::tryFrom(strtolower($value))) !== null
            ? $resolved
            : self::WhitelistOnly;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::WhitelistOnly->value => self::WhitelistOnly->getLabel(),
            self::FailClosed->value => self::FailClosed->getLabel(),
            self::FailOpen->value => self::FailOpen->getLabel(),
        ];
    }
}
