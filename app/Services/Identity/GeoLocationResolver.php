<?php

declare(strict_types=1);

namespace App\Services\Identity;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * A best-effort location estimate for first-login onboarding.
 *
 * This is a suggestion, never proof. The default driver reads country/city
 * headers set by a trusted edge (Cloudflare, Vercel, a reverse proxy) and makes
 * no outbound call; any other deployment simply returns `null`, which the
 * detector reports as "not detected" rather than inventing a location. The
 * opt-in `ipapi` driver fails closed and is cached per address, so an outage or
 * a rate limit degrades to no estimate instead of a wrong one.
 *
 * No caller may store these values as verified. The confidence attached to an
 * IP-derived value is deliberately below the confidence of a value the user has
 * confirmed.
 */
final class GeoLocationResolver
{
    public function __construct(private readonly CountryCatalog $countries) {}

    /**
     * @return array{country_code: string, city: string|null, confidence: int}|null
     */
    public function resolve(Request $request): ?array
    {
        $ip = (string) $request->ip();

        if ($ip === '' || ! $this->isPublicIp($ip)) {
            return null;
        }

        return match ((string) config('dukavo.identity.geo.driver', 'headers')) {
            'ipapi' => $this->fromIpApi($ip),
            default => $this->fromHeaders($request),
        };
    }

    /**
     * @return array{country_code: string, city: string|null, confidence: int}|null
     */
    private function fromHeaders(Request $request): ?array
    {
        $country = null;

        foreach ((array) config('dukavo.identity.geo.country_headers', []) as $header) {
            $candidate = strtoupper(trim((string) $request->header($header, '')));

            if ($this->countries->exists($candidate)) {
                $country = $candidate;
                break;
            }
        }

        if ($country === null) {
            return null;
        }

        $city = null;

        foreach ((array) config('dukavo.identity.geo.city_headers', []) as $header) {
            $candidate = trim((string) $request->header($header, ''));

            if ($candidate !== '') {
                $city = mb_substr($candidate, 0, 120);
                break;
            }
        }

        return [
            'country_code' => $country,
            'city' => $city,
            'confidence' => $city === null ? 55 : 45,
        ];
    }

    /**
     * @return array{country_code: string, city: string|null, confidence: int}|null
     */
    private function fromIpApi(string $ip): ?array
    {
        $cacheKey = 'identity:geo:'.$ip;
        $minutes = max(1, (int) config('dukavo.identity.geo.ipapi.cache_minutes', 1440));
        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return $cached === [] ? null : $cached;
        }

        try {
            $response = Http::timeout(max(1, (int) config('dukavo.identity.geo.ipapi.timeout_seconds', 2)))
                ->acceptJson()
                ->get(rtrim((string) config('dukavo.identity.geo.ipapi.base_url', 'https://ipapi.co'), '/').'/'.$ip.'/json/');

            if (! $response->successful()) {
                Cache::put($cacheKey, [], now()->addMinutes($minutes));

                return null;
            }

            $payload = (array) $response->json();
            $country = strtoupper(trim((string) ($payload['country_code'] ?? '')));

            if (! $this->countries->exists($country)) {
                Cache::put($cacheKey, [], now()->addMinutes($minutes));

                return null;
            }

            $city = trim((string) ($payload['city'] ?? ''));
            $result = [
                'country_code' => $country,
                'city' => $city === '' ? null : mb_substr($city, 0, 120),
                'confidence' => $city === '' ? 55 : 45,
            ];

            Cache::put($cacheKey, $result, now()->addMinutes($minutes));

            return $result;
        } catch (Throwable) {
            // An unreachable provider is not a reason to guess.
            Cache::put($cacheKey, [], now()->addMinutes($minutes));

            return null;
        }
    }

    /**
     * Private, loopback, link-local and reserved addresses carry no geographic
     * meaning. Asking a provider about them would only produce a fabricated hit.
     */
    private function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }
}
