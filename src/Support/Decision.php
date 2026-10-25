<?php

namespace Spiggle\GeoLock\Support;

final class Decision
{
    public function __construct(
        public bool $allowed,
        public string $reason,
        public ?Location $location = null,
    ) {}

    public static function allow(string $reason, ?Location $location = null): self
    {
        return new self(true, $reason, $location);
    }

    public static function deny(string $reason, Location $location): self
    {
        return new self(false, $reason, $location);
    }
}
