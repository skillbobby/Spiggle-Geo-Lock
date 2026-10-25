<?php

namespace Spiggle\GeoLock\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Spiggle\GeoLock\Support\Countries;
use Spiggle\GeoLock\Support\Location;
use Throwable;

class RequestGeoLocator
{
    public function locate(Request $request): Location
    {
        if (config('geo-lock.trust_headers')) {
            $header = $this->fromHeaders($request);

            if ($header !== null) {
                return $header;
            }
        }

        $ip = (string) $request->ip();

        if ($this->isLocal($ip)) {
            return $this->local();
        }

        return $this->lookup($ip);
    }

    private function fromHeaders(Request $request): ?Location
    {
        $country = strtoupper(trim((string) (
            $request->header('X-Geo-Country')
            ?: $request->header('CF-IPCountry')
        )));
        $region = trim((string) $request->header('X-Geo-Region', ''));

        if (strlen($country) !== 2 || ! Countries::exists($country) || $country === 'XX') {
            return null;
        }

        return new Location($country, $region !== '' ? $region : null, 'header');
    }

    private function local(): Location
    {
        $country = strtoupper(trim((string) config('geo-lock.local_country', '')));

        if (strlen($country) === 2 && Countries::exists($country)) {
            $region = trim((string) config('geo-lock.local_region', ''));

            return new Location($country, $region !== '' ? $region : null, 'local');
        }

        return new Location(null, null, 'local');
    }

    private function lookup(string $ip): Location
    {
        $cached = Cache::get($this->cacheKey($ip));

        if ($cached instanceof Location) {
            return $cached;
        }

        try {
            $response = Http::timeout(2)
                ->acceptJson()
                ->get('https://ipwho.is/'.$ip);

            $country = strtoupper((string) $response->json('country_code', ''));

            if ($response->ok() && $response->json('success') === true && Countries::exists($country)) {
                $region = trim((string) $response->json('region', ''));
                $location = new Location($country, $region !== '' ? $region : null, 'lookup');
                Cache::put($this->cacheKey($ip), $location, now()->addHours(12));

                return $location;
            }
        } catch (Throwable) {
            // A failed lookup is an unknown country. Allow rules then fail closed.
        }

        return new Location(null, null, 'unknown');
    }

    private function isLocal(string $ip): bool
    {
        return $ip === '' || filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) === false;
    }

    private function cacheKey(string $ip): string
    {
        return 'geo-lock:ip:'.$ip;
    }
}
