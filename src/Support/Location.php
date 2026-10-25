<?php

namespace Spiggle\GeoLock\Support;

final class Location
{
    public function __construct(
        public ?string $country,
        public ?string $region,
        public string $source,
    ) {}

    public function label(): string
    {
        if ($this->country === null) {
            return $this->source === 'local'
                ? 'Local network. Country unknown'
                : 'Country unknown';
        }

        $country = Countries::name($this->country).' ('.$this->country.')';

        if ($this->region === null || $this->region === '') {
            return $country;
        }

        return $this->region.', '.$country;
    }
}
