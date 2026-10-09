<?php

declare(strict_types=1);

namespace GeoLock\Data;

use GeoLock\Support\CountryData;
use GeoLock\Support\RegionData;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * An immutable snapshot of where a request came from.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class GeoLocation implements Arrayable, JsonSerializable
{
    public function __construct(
        public string $ip,
        public ?string $countryCode = null,
        public ?string $regionCode = null,
        public ?string $city = null,
        public ?string $postalCode = null,
        public ?float $latitude = null,
        public ?float $longitude = null,
        public string $resolver = 'unknown',
        public bool $isPrivate = false,
    ) {}

    /**
     * A location that could not be resolved to a country.
     */
    public static function unknown(string $ip, string $resolver = 'unknown', bool $isPrivate = false): self
    {
        return new self(ip: $ip, resolver: $resolver, isPrivate: $isPrivate);
    }

    /**
     * Rebuild a location from its array representation, e.g. out of a cache.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            ip: (string) ($data['ip'] ?? ''),
            countryCode: filled($data['country_code'] ?? null) ? (string) $data['country_code'] : null,
            regionCode: filled($data['region_code'] ?? null) ? (string) $data['region_code'] : null,
            city: filled($data['city'] ?? null) ? (string) $data['city'] : null,
            postalCode: filled($data['postal_code'] ?? null) ? (string) $data['postal_code'] : null,
            latitude: is_numeric($data['latitude'] ?? null) ? (float) $data['latitude'] : null,
            longitude: is_numeric($data['longitude'] ?? null) ? (float) $data['longitude'] : null,
            resolver: (string) ($data['resolver'] ?? 'unknown'),
            isPrivate: (bool) ($data['is_private'] ?? false),
        );
    }

    public function isResolved(): bool
    {
        return filled($this->countryCode);
    }

    public function isUnknown(): bool
    {
        return ! $this->isResolved();
    }

    public function countryCode(): ?string
    {
        return $this->countryCode;
    }

    public function regionCode(): ?string
    {
        return $this->regionCode;
    }

    public function countryName(): ?string
    {
        return filled($this->countryCode) ? CountryData::name($this->countryCode) : null;
    }

    public function regionName(): ?string
    {
        return filled($this->regionCode) ? RegionData::name($this->regionCode) : null;
    }

    public function countryLabel(): ?string
    {
        return filled($this->countryCode) ? CountryData::label($this->countryCode) : null;
    }

    public function regionLabel(): ?string
    {
        return filled($this->regionCode) ? RegionData::label($this->regionCode) : null;
    }

    /**
     * A human readable summary such as "Kingston, Jamaica (JM)".
     */
    public function label(): string
    {
        $parts = array_filter([
            $this->city,
            $this->regionName(),
            $this->countryName(),
        ], filled(...));

        return $parts === []
            ? __('geo-lock::geo-lock.location.unknown')
            : implode(', ', $parts);
    }

    public function equals(self $other): bool
    {
        return $this->ip === $other->ip
            && $this->countryCode === $other->countryCode
            && $this->regionCode === $other->regionCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'ip' => $this->ip,
            'country_code' => $this->countryCode,
            'country_name' => $this->countryName(),
            'region_code' => $this->regionCode,
            'region_name' => $this->regionName(),
            'city' => $this->city,
            'postal_code' => $this->postalCode,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'resolver' => $this->resolver,
            'is_private' => $this->isPrivate,
            'is_resolved' => $this->isResolved(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
