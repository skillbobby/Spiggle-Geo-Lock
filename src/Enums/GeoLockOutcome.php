<?php

declare(strict_types=1);

namespace GeoLock\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * The verdict of a geo-lock evaluation.
 */
enum GeoLockOutcome: string implements HasColor, HasIcon, HasLabel
{
    case Allowed = 'allowed';
    case Bypassed = 'bypassed';
    case NoRules = 'no_rules';
    case DeniedBlacklist = 'denied_blacklist';
    case DeniedWhitelist = 'denied_whitelist';
    case DeniedUnresolvable = 'denied_unresolvable';
    case DeniedResolverFailure = 'denied_resolver_failure';
    case DeniedUntrustedProxy = 'denied_untrusted_proxy';
    case Disabled = 'disabled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Allowed => __('geo-lock::geo-lock.enums.outcome.allowed'),
            self::Bypassed => __('geo-lock::geo-lock.enums.outcome.bypassed'),
            self::NoRules => __('geo-lock::geo-lock.enums.outcome.no_rules'),
            self::DeniedBlacklist => __('geo-lock::geo-lock.enums.outcome.denied_blacklist'),
            self::DeniedWhitelist => __('geo-lock::geo-lock.enums.outcome.denied_whitelist'),
            self::DeniedUnresolvable => __('geo-lock::geo-lock.enums.outcome.denied_unresolvable'),
            self::DeniedResolverFailure => __('geo-lock::geo-lock.enums.outcome.denied_resolver_failure'),
            self::DeniedUntrustedProxy => __('geo-lock::geo-lock.enums.outcome.denied_untrusted_proxy'),
            self::Disabled => __('geo-lock::geo-lock.enums.outcome.disabled'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Allowed, self::Bypassed, self::NoRules => 'success',
            self::Disabled => 'gray',
            default => 'danger',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Allowed => 'heroicon-o-check-badge',
            self::Bypassed => 'heroicon-o-key',
            self::NoRules => 'heroicon-o-minus-circle',
            self::DeniedBlacklist => 'heroicon-o-no-symbol',
            self::DeniedWhitelist => 'heroicon-o-shield-exclamation',
            self::DeniedUnresolvable => 'heroicon-o-question-mark-circle',
            self::DeniedResolverFailure => 'heroicon-o-server-stack',
            self::DeniedUntrustedProxy => 'heroicon-o-arrows-right-left',
            self::Disabled => 'heroicon-o-power',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Allowed => __('geo-lock::geo-lock.enums.outcome.allowed_description'),
            self::Bypassed => __('geo-lock::geo-lock.enums.outcome.bypassed_description'),
            self::NoRules => __('geo-lock::geo-lock.enums.outcome.no_rules_description'),
            self::DeniedBlacklist => __('geo-lock::geo-lock.enums.outcome.denied_blacklist_description'),
            self::DeniedWhitelist => __('geo-lock::geo-lock.enums.outcome.denied_whitelist_description'),
            self::DeniedUnresolvable => __('geo-lock::geo-lock.enums.outcome.denied_unresolvable_description'),
            self::DeniedResolverFailure => __('geo-lock::geo-lock.enums.outcome.denied_resolver_failure_description'),
            self::DeniedUntrustedProxy => __('geo-lock::geo-lock.enums.outcome.denied_untrusted_proxy_description'),
            self::Disabled => __('geo-lock::geo-lock.enums.outcome.disabled_description'),
        };
    }

    public function isAllowed(): bool
    {
        return in_array($this, [self::Allowed, self::Bypassed, self::NoRules, self::Disabled], strict: true);
    }

    public function isDenied(): bool
    {
        return ! $this->isAllowed();
    }

    public function isBypassed(): bool
    {
        return $this === self::Bypassed;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case): array => [$case->value => $case->getLabel()])
            ->all();
    }
}
