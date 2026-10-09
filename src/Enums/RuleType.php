<?php

declare(strict_types=1);

namespace GeoLock\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * The two flavours of geographic access rule.
 *
 * - Whitelist: only the listed countries/regions may access the panel, every
 *   other location is blocked.
 * - Blacklist: the listed countries/regions are blocked, every other location
 *   is allowed.
 */
enum RuleType: string implements HasColor, HasIcon, HasLabel
{
    case Whitelist = 'whitelist';
    case Blacklist = 'blacklist';

    public function getLabel(): string
    {
        return match ($this) {
            self::Whitelist => __('geo-lock::geo-lock.enums.rule_type.whitelist'),
            self::Blacklist => __('geo-lock::geo-lock.enums.rule_type.blacklist'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Whitelist => 'success',
            self::Blacklist => 'danger',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Whitelist => 'heroicon-o-check-circle',
            self::Blacklist => 'heroicon-o-no-symbol',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Whitelist => __('geo-lock::geo-lock.enums.rule_type.whitelist_description'),
            self::Blacklist => __('geo-lock::geo-lock.enums.rule_type.blacklist_description'),
        };
    }

    public function isWhitelist(): bool
    {
        return $this === self::Whitelist;
    }

    public function isBlacklist(): bool
    {
        return $this === self::Blacklist;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::Whitelist->value => self::Whitelist->getLabel(),
            self::Blacklist->value => self::Blacklist->getLabel(),
        ];
    }

    public static function fromMixed(self|string|null $value): ?self
    {
        if ($value instanceof self) {
            return $value;
        }

        return is_string($value) ? self::tryFrom(strtolower($value)) : null;
    }
}
