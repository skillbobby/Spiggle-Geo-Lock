<?php

declare(strict_types=1);

namespace GeoLock\Support;

use Illuminate\Support\Str;

/**
 * Access to the bundled ISO 3166-2 subdivision list (states, provinces,
 * parishes, regions, ...).
 *
 * The dataset lives in `resources/data/regions.json` and can be replaced at
 * runtime with {@see RegionData::useFile()} or {@see RegionData::setData()}.
 */
final class RegionData
{
    /**
     * @var array<string, array<string, string>>|null country code => [subdivision code => name]
     */
    private static ?array $data = null;

    private static ?string $path = null;

    /**
     * @return array<string, array<string, string>>
     */
    public static function all(): array
    {
        if (self::$data !== null) {
            return self::$data;
        }

        $path = self::path();

        $decoded = is_file($path)
            ? json_decode((string) file_get_contents($path), associative: true)
            : null;

        $data = [];

        foreach (is_array($decoded) ? $decoded : [] as $key => $value) {
            $key = strtoupper((string) $key);

            // Flat format: ["US-FL" => "Florida"].
            if (str_contains($key, '-') && is_string($value)) {
                $data[self::countryOf($key)][$key] = $value;

                continue;
            }

            if (! is_array($value)) {
                continue;
            }

            foreach ($value as $code => $name) {
                // List format: ["US" => [["code" => "US-FL", "name" => "Florida"]]].
                if (is_array($name)) {
                    $code = (string) ($name['code'] ?? $code);
                    $name = (string) ($name['name'] ?? '');
                }

                $code = strtoupper((string) $code);

                if (blank($name) || blank($code)) {
                    continue;
                }

                $data[$key][$code] = (string) $name;
            }
        }

        return self::$data = $data;
    }

    /**
     * @return array<string, string> subdivision code => name
     */
    public static function forCountry(?string $country): array
    {
        if (blank($country)) {
            return [];
        }

        return self::all()[strtoupper((string) $country)] ?? [];
    }

    /**
     * @param  array<int, string>|string|null  $countries
     * @return array<string, string>
     */
    public static function forCountries(array|string|null $countries): array
    {
        if (blank($countries)) {
            return [];
        }

        $regions = [];

        foreach ((array) $countries as $country) {
            if (is_string($country)) {
                $regions += self::forCountry($country);
            }
        }

        asort($regions);

        return $regions;
    }

    /**
     * The name of a subdivision, or null when it is not in the dataset.
     */
    public static function name(?string $code): ?string
    {
        if (blank($code)) {
            return null;
        }

        $code = strtoupper((string) $code);

        return self::forCountry(self::countryOf($code))[$code] ?? null;
    }

    /**
     * A label such as "Florida (US)".
     */
    public static function label(?string $code): ?string
    {
        if (blank($code)) {
            return null;
        }

        $name = self::name($code);

        if ($name === null) {
            return strtoupper((string) $code);
        }

        return "{$name} (" . self::countryOf($code) . ')';
    }

    /**
     * The ISO 3166-1 alpha-2 country a subdivision belongs to.
     */
    public static function countryOf(?string $code): ?string
    {
        if (blank($code) || ! str_contains((string) $code, '-')) {
            return null;
        }

        return strtoupper(Str::before((string) $code, '-'));
    }

    public static function exists(?string $code): bool
    {
        return filled($code) && self::name($code) !== null;
    }

    /**
     * Options grouped by country, which renders as optgroups in a select.
     *
     * @param  array<int, string>|string|null  $countries
     * @return array<string, array<string, string>>
     */
    public static function groupedOptions(array|string|null $countries = null): array
    {
        $groups = [];

        foreach ((array) $countries as $country) {
            if (! is_string($country) || blank($country)) {
                continue;
            }

            $regions = self::forCountry($country);

            if ($regions === []) {
                continue;
            }

            $groups[CountryData::label($country) ?? strtoupper($country)] = $regions;
        }

        ksort($groups);

        return $groups;
    }

    /**
     * @param  array<int, string>|string|null  $countries
     * @return array<string, string>
     */
    public static function options(array|string|null $countries = null): array
    {
        $options = [];

        foreach (self::groupedOptions($countries) as $regions) {
            foreach ($regions as $code => $name) {
                $options[$code] = $name;
            }
        }

        return $options;
    }

    /**
     * Normalize a raw subdivision code returned by a geolocation provider
     * (e.g. "FL", "fl", "USA-FL") into an ISO 3166-2 code from the dataset.
     *
     * The name is used as a fallback when the provider only knows the human
     * readable label of the subdivision.
     */
    public static function match(?string $country, ?string $code, ?string $name = null): ?string
    {
        $country = strtoupper(trim((string) $country));

        if ($country === '') {
            return null;
        }

        $code = strtoupper(trim((string) $code));

        if ($code !== '') {
            foreach (array_unique([
                $code,
                Str::contains($code, '-') ? $code : "{$country}-{$code}",
                Str::after($code, '-'),
                "{$country}-" . Str::after($code, '-'),
            ]) as $candidate) {
                if (self::exists($candidate)) {
                    return $candidate;
                }
            }
        }

        if (filled($name)) {
            $needle = self::normalizeName((string) $name);

            foreach (self::forCountry($country) as $candidate => $label) {
                if (self::normalizeName($label) === $needle) {
                    return $candidate;
                }
            }
        }

        // Unknown subdivisions are still prefixed, so that a rule created for
        // them keeps working once the dataset is updated.
        if ($code !== '' && preg_match('/^[A-Z0-9]{1,3}$/', Str::after($code, '-')) === 1) {
            return str_contains($code, '-') ? $code : "{$country}-{$code}";
        }

        return null;
    }

    public static function count(): int
    {
        return array_sum(array_map(count(...), self::all()));
    }

    public static function path(): string
    {
        return self::$path ??= Str::finish(dirname(__DIR__, 2), '/') . 'resources/data/regions.json';
    }

    public static function useFile(?string $path): void
    {
        self::$path = $path;
        self::$data = null;
    }

    /**
     * @param  array<string, array<string, string>>|null  $data
     */
    public static function setData(?array $data): void
    {
        self::$data = null;

        if ($data === null) {
            return;
        }

        foreach ($data as $country => $regions) {
            self::$data[strtoupper((string) $country)] = array_map(
                static fn (mixed $name): string => (string) $name,
                (array) $regions,
            );
        }
    }

    public static function flush(): void
    {
        self::$data = null;
    }

    private static function normalizeName(string $name): string
    {
        return Str::of($name)
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9]+/', '')
            ->value();
    }
}

