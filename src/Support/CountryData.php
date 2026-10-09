<?php

declare(strict_types=1);

namespace GeoLock\Support;

use Illuminate\Support\Str;

/**
 * Access to the bundled ISO 3166-1 country list.
 *
 * The dataset lives in `resources/data/countries.json` and can be replaced at
 * runtime with {@see CountryData::useFile()} or {@see CountryData::setData()}.
 */
final class CountryData
{
    /**
     * @var array<string, array{name: string, alpha3: string|null, continent: string|null}>|null
     */
    private static ?array $records = null;

    private static ?string $path = null;

    /**
     * @return array<string, string> ISO alpha-2 code => English name
     */
    public static function all(): array
    {
        return array_map(
            static fn (array $record): string => $record['name'],
            self::records(),
        );
    }

    /**
     * @return array<string, array{name: string, alpha3: string|null, continent: string|null}>
     */
    public static function records(): array
    {
        if (self::$records !== null) {
            return self::$records;
        }

        $path = self::path();

        $decoded = is_file($path)
            ? json_decode((string) file_get_contents($path), associative: true)
            : null;

        $records = [];

        foreach (is_array($decoded) ? $decoded : [] as $code => $value) {
            $code = strtoupper((string) $code);

            $records[$code] = is_array($value)
                ? [
                    'name' => (string) ($value['name'] ?? $code),
                    'alpha3' => isset($value['alpha3']) ? (string) $value['alpha3'] : null,
                    'continent' => isset($value['continent']) ? (string) $value['continent'] : null,
                ]
                : [
                    'name' => (string) $value,
                    'alpha3' => null,
                    'continent' => null,
                ];
        }

        return self::$records = $records;
    }

    public static function name(?string $code): ?string
    {
        if (blank($code)) {
            return null;
        }

        return self::records()[strtoupper($code)]['name'] ?? null;
    }

    /**
     * A label such as "Jamaica (JM)", or the raw code when it is unknown.
     */
    public static function label(?string $code): ?string
    {
        if (blank($code)) {
            return null;
        }

        $name = self::name($code);

        return $name === null ? strtoupper($code) : "{$name} (" . strtoupper($code) . ')';
    }

    public static function alpha3(?string $code): ?string
    {
        if (blank($code)) {
            return null;
        }

        return self::records()[strtoupper($code)]['alpha3'] ?? null;
    }

    public static function continent(?string $code): ?string
    {
        if (blank($code)) {
            return null;
        }

        return self::records()[strtoupper($code)]['continent'] ?? null;
    }

    public static function continentName(?string $code): ?string
    {
        return self::continentNames()[self::continent($code) ?? ''] ?? null;
    }

    public static function exists(?string $code): bool
    {
        return filled($code) && array_key_exists(strtoupper((string) $code), self::records());
    }

    /**
     * The flag emoji of a country, built from its regional indicator symbols.
     */
    public static function flag(?string $code): ?string
    {
        if (! self::exists($code)) {
            return null;
        }

        $code = strtoupper((string) $code);

        return mb_convert_encoding(
            pack('C*', ...array_map(static fn (string $letter): int => ord($letter) + 127397, str_split($code))),
            'UTF-8',
            'UTF-32BE',
        );
    }

    /**
     * @return array<string, string> ISO alpha-2 code => "Jamaica (JM)"
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::all() as $code => $name) {
            $options[$code] = self::label($code) ?? $name;
        }

        asort($options);

        return $options;
    }

    /**
     * Options grouped by continent, which renders as optgroups in a select.
     *
     * @return array<string, array<string, string>>
     */
    public static function groupedOptions(): array
    {
        $groups = [];

        foreach (self::records() as $code => $record) {
            $group = self::continentNames()[$record['continent'] ?? ''] ?? __('geo-lock::geo-lock.countries.other');
            $groups[$group][$code] = self::label($code) ?? $record['name'];
        }

        foreach ($groups as $group => $options) {
            asort($options);
            $groups[$group] = $options;
        }

        ksort($groups);

        return $groups;
    }

    /**
     * @return array<string, string>
     */
    public static function continentNames(): array
    {
        return [
            'AF' => __('geo-lock::geo-lock.countries.continents.af'),
            'AN' => __('geo-lock::geo-lock.countries.continents.an'),
            'AS' => __('geo-lock::geo-lock.countries.continents.as'),
            'EU' => __('geo-lock::geo-lock.countries.continents.eu'),
            'NA' => __('geo-lock::geo-lock.countries.continents.na'),
            'OC' => __('geo-lock::geo-lock.countries.continents.oc'),
            'SA' => __('geo-lock::geo-lock.countries.continents.sa'),
        ];
    }

    public static function path(): string
    {
        return self::$path ??= Str::finish(dirname(__DIR__, 2), '/') . 'resources/data/countries.json';
    }

    public static function useFile(?string $path): void
    {
        self::$path = $path;
        self::$records = null;
    }

    /**
     * @param  array<string, string|array{name?: string, alpha3?: string|null, continent?: string|null}>|null  $records
     */
    public static function setData(?array $records): void
    {
        self::$records = null;

        if ($records === null) {
            return;
        }

        foreach ($records as $code => $value) {
            self::$records[strtoupper((string) $code)] = is_array($value)
                ? [
                    'name' => (string) ($value['name'] ?? $code),
                    'alpha3' => isset($value['alpha3']) ? (string) $value['alpha3'] : null,
                    'continent' => isset($value['continent']) ? (string) $value['continent'] : null,
                ]
                : [
                    'name' => (string) $value,
                    'alpha3' => null,
                    'continent' => null,
                ];
        }
    }

    public static function flush(): void
    {
        self::$records = null;
    }
}
