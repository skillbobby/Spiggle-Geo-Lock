<?php

declare(strict_types=1);

/**
 * generate-geo-data.php
 *
 * Standalone generator for the bundled geographic reference dataset of the
 * geolock/filament-geo-lock package. No framework and no Composer dependencies
 * are required (PHP 8.2+).
 *
 * Outputs (paths are relative to the package root):
 *   resources/data/countries.json
 *   resources/data/regions.json
 *
 * ---------------------------------------------------------------------------
 * Upstream sources (retrieved over HTTPS)
 * ---------------------------------------------------------------------------
 *   [countries]     https://raw.githubusercontent.com/mledoze/countries/master/countries.json
 *                   ISO 3166-1 dataset (cca2 / cca3 / region / subregion / status).
 *                   Filtered to status === "officially-assigned" => exactly 249 entries.
 *   [subdivisions]  https://salsa.debian.org/iso-codes-team/iso-codes/-/raw/main/data/iso_3166-2.json
 *                   Debian iso-codes ISO 3166-2 dataset (authoritative, English names).
 *   [cross-check]   https://raw.githubusercontent.com/ipregistry/iso3166/main/subdivisions.csv
 *                   Derived from the same ISO 3166-2 data; used only as an optional
 *                   consistency cross-check (never fatal).
 *
 * Continent codes: AF (Africa), AN (Antarctica), AS (Asia), EU (Europe),
 * NA (North America), OC (Oceania), SA (South America). They are derived from the
 * mledoze `region`/`subregion` fields; "Antarctic" (AQ, BV, GS, TF, HM) maps to AN
 * and "Americas" is split into SA/NA using the "South America" subregion.
 *
 * ---------------------------------------------------------------------------
 * Schema
 * ---------------------------------------------------------------------------
 *   countries.json : JSON object keyed by ISO 3166-1 alpha-2 (uppercase, sorted),
 *                    value = {"name": string, "alpha3": string, "continent": string}
 *   regions.json   : JSON object keyed by ISO 3166-1 alpha-2 (only countries that
 *                    have ISO 3166-2 subdivisions), value = object of
 *                    ISO 3166-2 code => English subdivision name (sorted by code).
 *
 * Both files are written with 4-space indentation, unescaped Unicode/slashes and a
 * trailing newline:
 *   json_encode(..., JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
 *
 * ---------------------------------------------------------------------------
 * Usage
 * ---------------------------------------------------------------------------
 *   php tools/generate-geo-data.php
 *   php tools/generate-geo-data.php --source-dir=/path/to/pre-downloaded/sources
 *
 * With --source-dir the script never touches the network and expects these files
 * inside the directory (see the header above for their URLs):
 *   mledoze-countries.json
 *   iso-codes-iso_3166-2.json
 *   ipregistry-subdivisions.csv   (optional, only used for the cross-check)
 *
 * Exit status: 0 on success, 1 when a validation check fails, 2 on usage error.
 */
const COUNTRIES_URL = 'https://raw.githubusercontent.com/mledoze/countries/master/countries.json';
const SUBDIVISIONS_URL = 'https://salsa.debian.org/iso-codes-team/iso-codes/-/raw/main/data/iso_3166-2.json';
const CROSSCHECK_URL = 'https://raw.githubusercontent.com/ipregistry/iso3166/main/subdivisions.csv';

const COUNTRIES_FILE = 'mledoze-countries.json';
const SUBDIVISIONS_FILE = 'iso-codes-iso_3166-2.json';
const CROSSCHECK_FILE = 'ipregistry-subdivisions.csv';

const MAX_FILE_BYTES = 3 * 1024 * 1024;
const MAX_SUBDIVISIONS_PER_COUNTRY = 300;

/** Continent codes allowed in countries.json. */
const CONTINENTS = ['AF', 'AN', 'AS', 'EU', 'NA', 'OC', 'SA'];

/**
 * Manual name overrides applied on top of mledoze `name.common`.
 * CI: the dataset convention is the ISO/UN endonym spelling (proper UTF-8).
 */
const NAME_OVERRIDES = [
    'CI' => "Côte d'Ivoire",
];

/**
 * Download a URL with the curl extension.
 *
 * @throws RuntimeException on transport error or a non-200 response.
 */
function httpGet(string $url): string
{
    $ch = curl_init($url);

    if ($ch === false) {
        throw new RuntimeException("Unable to initialise curl for {$url}");
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_FAILONERROR => true,
        CURLOPT_USERAGENT => 'geolock/filament-geo-lock generate-geo-data.php',
    ]);

    $body = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($body === false) {
        throw new RuntimeException("Failed to download {$url}: {$error}");
    }

    if ($status !== 200) {
        throw new RuntimeException("Unexpected HTTP status {$status} for {$url}");
    }

    return $body;
}

/**
 * Return the source file contents, downloading it first when needed.
 *
 * @param  string|null  $sourceDir  Directory with pre-downloaded files, or null to download.
 */
function readSource(string $filename, string $url, ?string $sourceDir): string
{
    if ($sourceDir !== null) {
        $path = $sourceDir.'/'.$filename;

        if (! is_file($path)) {
            throw new RuntimeException(
                "Missing source file {$path}. Download it from {$url} or omit --source-dir."
            );
        }

        return (string) file_get_contents($path);
    }

    fwrite(STDERR, "Downloading {$url}\n");

    return httpGet($url);
}

/** Map a mledoze region/subregion pair to a continent code. */
function continentFor(?string $region, ?string $subregion): ?string
{
    return match ($region) {
        'Africa' => 'AF',
        'Antarctic' => 'AN',
        'Asia' => 'AS',
        'Europe' => 'EU',
        'Oceania' => 'OC',
        'Americas' => $subregion === 'South America' ? 'SA' : 'NA',
        default => null,
    };
}

/**
 * Build the countries map from the mledoze dataset.
 *
 * @return array<string, array{name: string, alpha3: string, continent: string|null}>
 */
function buildCountries(string $json): array
{
    /** @var list<array<string, mixed>> $raw */
    $raw = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

    $countries = [];

    foreach ($raw as $country) {
        // ISO 3166-1 officially assigned codes only (this drops user-assigned XK).
        if (($country['status'] ?? null) !== 'officially-assigned') {
            continue;
        }

        $cc = (string) $country['cca2'];
        $name = NAME_OVERRIDES[$cc] ?? (string) $country['name']['common'];

        $countries[$cc] = [
            'name' => $name,
            'alpha3' => (string) $country['cca3'],
            'continent' => continentFor($country['region'] ?? null, $country['subregion'] ?? null),
        ];
    }

    ksort($countries);

    return $countries;
}

/**
 * Build the regions map from the Debian iso-codes ISO 3166-2 dataset.
 *
 * @return array<string, array<string, string>>
 */
function buildRegions(string $json): array
{
    /** @var array{3166-2: list<array{code: string, name: string, type?: string}>} $raw */
    $raw = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

    $regions = [];

    foreach ($raw['3166-2'] as $entry) {
        $code = (string) $entry['code'];
        $cc = explode('-', $code, 2)[0];
        $regions[$cc][$code] = (string) $entry['name'];
    }

    ksort($regions);

    foreach ($regions as &$subdivisions) {
        ksort($subdivisions);
    }
    unset($subdivisions);

    return $regions;
}

/**
 * Optional cross-check against the ipregistry subdivisions CSV (same ISO 3166-2
 * data). Returns a human readable report line, or null when the file is absent.
 */
function crossCheckRegions(array $regions, ?string $csv): ?string
{
    if ($csv === null || trim($csv) === '') {
        return null;
    }

    $byCountry = [];
    $lines = preg_split('/\r\n|\r|\n/', trim($csv)) ?: [];

    foreach (array_slice($lines, 1) as $line) {
        if ($line === '') {
            continue;
        }

        // Escape parameter passed explicitly (its default is deprecated in PHP 8.4).
        $cols = str_getcsv($line, ',', '"', '\\');
        if (count($cols) < 2) {
            continue;
        }

        $cc = $cols[0];
        $code = $cols[1];
        $byCountry[$cc][$code] = true;
    }

    $differences = 0;

    foreach ($byCountry as $cc => $codes) {
        $ours = array_keys($regions[$cc] ?? []);
        $differences += count(array_diff($ours, array_keys($codes)));
        $differences += count(array_diff(array_keys($codes), $ours));
    }

    return sprintf(
        'ipregistry cross-check: %d countries, %d code differences vs iso-codes',
        count($byCountry),
        $differences
    );
}

/**
 * Validate the built dataset.
 *
 * @return list<string> Human readable error messages (empty when valid).
 */
function validate(array $countries, array $regions): array
{
    $errors = [];

    if (count($countries) !== 249) {
        $errors[] = sprintf('countries.json: expected 249 entries, got %d', count($countries));
    }

    foreach ($countries as $cc => $country) {
        if (! preg_match('/^[A-Z]{2}$/', (string) $cc)) {
            $errors[] = "countries.json: invalid alpha-2 key \"{$cc}\"";
        }

        if (! is_string($country['name']) || $country['name'] === '') {
            $errors[] = "countries.json: {$cc} has an empty name";
        } elseif (preg_match('/[<>]/', $country['name']) === 1) {
            $errors[] = "countries.json: {$cc} name contains HTML";
        }

        if (! preg_match('/^[A-Z]{3}$/', $country['alpha3'])) {
            $errors[] = "countries.json: {$cc} has an invalid alpha-3 code";
        }

        if (! in_array($country['continent'], CONTINENTS, true)) {
            $errors[] = "countries.json: {$cc} has an invalid continent";
        }
    }

    if ($regions === []) {
        $errors[] = 'regions.json: no countries with subdivisions';
    }

    foreach ($regions as $cc => $subdivisions) {
        if (! isset($countries[$cc])) {
            $errors[] = "regions.json: outer key {$cc} is not present in countries.json";
        }

        if ($subdivisions === []) {
            $errors[] = "regions.json: {$cc} is an empty object";
        }

        if (count($subdivisions) > MAX_SUBDIVISIONS_PER_COUNTRY) {
            $errors[] = sprintf(
                'regions.json: %s has %d subdivisions (max %d)',
                $cc,
                count($subdivisions),
                MAX_SUBDIVISIONS_PER_COUNTRY
            );
        }

        foreach ($subdivisions as $code => $name) {
            if (! str_starts_with((string) $code, $cc.'-')) {
                $errors[] = "regions.json: {$code} does not start with {$cc}-";
            }

            if (! is_string($name) || $name === '') {
                $errors[] = "regions.json: {$code} has an empty name";
            } elseif (preg_match('/[<>]/', $name) === 1) {
                $errors[] = "regions.json: {$code} name contains HTML";
            }
        }
    }

    // Spot checks requested by the task.
    if (count($regions['US'] ?? []) < 50 || ($regions['US']['US-FL'] ?? null) !== 'Florida') {
        $errors[] = 'spot check failed: US must have >= 50 subdivisions and US-FL = "Florida"';
    }

    if (! isset($regions['JM']['JM-01'], $regions['JM']['JM-02'])) {
        $errors[] = 'spot check failed: JM must contain JM-01 and JM-02';
    }

    $expected = [
        'CA' => ['CA-ON' => 'Ontario'],
        'GB' => ['GB-ENG' => 'England'],
        'AU' => ['AU-NSW' => null],
        'DE' => ['DE-BY' => null],
        'IN' => ['IN-MH' => null],
        'BR' => ['BR-SP' => null],
        'ZA' => ['ZA-GP' => null],
    ];

    foreach ($expected as $cc => $checks) {
        foreach ($checks as $code => $name) {
            if (! isset($regions[$cc][$code])) {
                $errors[] = "spot check failed: {$code} is missing";

                continue;
            }

            if ($name !== null && $regions[$cc][$code] !== $name) {
                $errors[] = sprintf(
                    'spot check failed: %s = "%s", expected "%s"',
                    $code,
                    $regions[$cc][$code],
                    $name
                );
            }
        }
    }

    return $errors;
}

/** Encode a dataset with the canonical formatting used by both output files. */
function encodeJson(array $data): string
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($json === false) {
        throw new RuntimeException('json_encode() failed: '.json_last_error_msg());
    }

    return $json."\n";
}

/**
 * @param  list<string>  $argv
 */
function main(array $argv): int
{
    $sourceDir = null;

    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '--source-dir=')) {
            $sourceDir = rtrim(substr($arg, strlen('--source-dir=')), '/');
        } elseif ($arg === '-h' || $arg === '--help') {
            fwrite(STDOUT, "Usage: php tools/generate-geo-data.php [--source-dir=DIR]\n");

            return 0;
        } else {
            fwrite(STDERR, "Unknown option: {$arg}\n");

            return 2;
        }
    }

    $root = dirname(__DIR__);
    $outputDir = $root.'/resources/data';

    $countries = buildCountries(readSource(COUNTRIES_FILE, COUNTRIES_URL, $sourceDir));
    $regions = buildRegions(readSource(SUBDIVISIONS_FILE, SUBDIVISIONS_URL, $sourceDir));

    $crossCheck = null;
    $crossCheckPath = $sourceDir !== null ? $sourceDir.'/'.CROSSCHECK_FILE : null;
    if ($crossCheckPath !== null && is_file($crossCheckPath)) {
        $crossCheck = crossCheckRegions($regions, (string) file_get_contents($crossCheckPath));
    }

    $countriesPath = $outputDir.'/countries.json';
    $regionsPath = $outputDir.'/regions.json';
    $countriesJson = encodeJson($countries);
    $regionsJson = encodeJson($regions);

    $errors = validate($countries, $regions);

    if (strlen($countriesJson) > MAX_FILE_BYTES) {
        $errors[] = 'countries.json exceeds the 3 MB limit';
    }
    if (strlen($regionsJson) > MAX_FILE_BYTES) {
        $errors[] = 'regions.json exceeds the 3 MB limit';
    }

    $total = array_sum(array_map('count', $regions));
    $largest = $regions;
    uasort($largest, static fn (array $a, array $b): int => count($b) <=> count($a));
    $largest = array_slice($largest, 0, 5, true);

    fwrite(STDOUT, "\n=== Validation summary ===\n");
    fwrite(STDOUT, sprintf("countries: %d\n", count($countries)));
    fwrite(STDOUT, sprintf("countries with regions: %d\n", count($regions)));
    fwrite(STDOUT, sprintf("total subdivisions: %d\n", $total));
    fwrite(STDOUT, "largest 5 countries by subdivision count:\n");

    foreach ($largest as $cc => $subdivisions) {
        fwrite(STDOUT, sprintf("  %s: %d\n", $cc, count($subdivisions)));
    }

    fwrite(STDOUT, "file sizes:\n");
    fwrite(STDOUT, sprintf("  %s: %d bytes\n", $countriesPath, strlen($countriesJson)));
    fwrite(STDOUT, sprintf("  %s: %d bytes\n", $regionsPath, strlen($regionsJson)));

    if ($crossCheck !== null) {
        fwrite(STDOUT, $crossCheck."\n");
    }

    if ($errors !== []) {
        fwrite(STDERR, "\nValidation FAILED:\n");
        foreach ($errors as $error) {
            fwrite(STDERR, "  - {$error}\n");
        }

        return 1;
    }

    if (! is_dir($outputDir) && ! mkdir($outputDir, 0755, true) && ! is_dir($outputDir)) {
        throw new RuntimeException("Unable to create output directory {$outputDir}");
    }

    file_put_contents($countriesPath, $countriesJson);
    file_put_contents($regionsPath, $regionsJson);

    fwrite(STDOUT, "OK\n");

    return 0;
}

try {
    exit(main($argv));
} catch (Throwable $exception) {
    fwrite(STDERR, 'Error: '.$exception->getMessage()."\n");

    exit(1);
}
