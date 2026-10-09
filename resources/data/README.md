# Bundled geographic reference data

Static, dependency-free UTF-8 JSON powering the geo-locking country/subdivision
pickers. Pretty-printed (4-space indent) with a trailing newline.

## Files & schema

### `countries.json`
Object keyed by ISO 3166-1 alpha-2 code (uppercase, sorted):

```json
"US": { "name": "United States", "alpha3": "USA", "continent": "NA" }
```

- `name` – common English name, proper UTF-8 (e.g. `Côte d'Ivoire`)
- `alpha3` – ISO 3166-1 alpha-3 code (uppercase)
- `continent` – one of `AF`, `AN`, `AS`, `EU`, `NA`, `OC`, `SA`

### `regions.json`
Object keyed by ISO 3166-1 alpha-2 code, only for countries that have ISO 3166-2
subdivisions; each value maps an ISO 3166-2 code to its English name (sorted by
code). Every code starts with `"<COUNTRY>-"` and every outer key also exists in
`countries.json`; countries without official subdivisions are omitted.

```json
"US": { "US-AK": "Alaska", "US-FL": "Florida" }
```

## Upstream sources (retrieved 2026-10-09)

- Countries: https://raw.githubusercontent.com/mledoze/countries/master/countries.json
- Subdivisions: https://salsa.debian.org/iso-codes-team/iso-codes/-/raw/main/data/iso_3166-2.json
- Cross-check only: https://raw.githubusercontent.com/ipregistry/iso3166/main/subdivisions.csv

Countries are the mledoze records with `status == "officially-assigned"` (249;
user-assigned `XK` excluded); continents derive from mledoze `region`/`subregion`
(`Antarctic` → `AN`; `Americas` split into `SA`/`NA` via `South America`).
Subdivisions come from Debian `iso-codes` (authoritative open ISO 3166-2 data);
the ipregistry CSV mirrors it and showed 0 code differences.

## Counts (as generated)

- Countries **249**; countries with regions **200**; subdivisions **5046**
- Largest: GB 221, SI 212, UG 139, IT 126, FR 124
- Sizes: `countries.json` ≈ 24 KB, `regions.json` ≈ 159 KB (both < 3 MB)

## Regenerating

```bash
php tools/generate-geo-data.php                                  # downloads sources
php tools/generate-geo-data.php --source-dir=/path/to/sources    # offline mode
```

The script prints a validation summary and exits non-zero when validation fails.
