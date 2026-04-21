<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Detects the user's country on first login via a priority-ordered strategy:
 *
 *   1. CF-IPCountry header — set by Cloudflare, zero extra latency, most reliable in prod.
 *   2. X-Country-Code header — fallback for other edge/proxy setups.
 *   3. ip-api.com HTTP lookup — free tier (45 req/min), used only when no header is present.
 *      Acceptable for first-login frequency; never called at query time.
 *
 * Result is stored in the session (`ip_detected_country`) and used by OnboardingController
 * as a lowest-priority pre-fill hint for `stores.primary_country_code`.
 *
 * Priority for country resolution:
 *   stored DB value > ccTLD from website URL > IP geolocation > user override
 *
 * Only the first call per session actually does work; subsequent calls return the
 * cached session value immediately.
 *
 * @see PLANNING.md section 10 (Country auto-detection)
 */
class GeoDetectionService
{
    private const SESSION_KEY = 'ip_detected_country';
    private const LOOKUP_URL  = 'http://ip-api.com/json/{ip}?fields=countryCode,status';
    private const TIMEOUT_SEC = 2;

    /**
     * Detect and return the user's country code (ISO 3166-1 alpha-2, upper-case).
     *
     * Returns null when detection fails or the IP is a private/loopback address.
     * Safe to call on every request — result is cached in the session after the first call.
     */
    public function detect(Request $request): ?string
    {
        // Already detected this session — skip the lookup
        if ($request->session()->has(self::SESSION_KEY)) {
            return $request->session()->get(self::SESSION_KEY);
        }

        $country = $this->fromHeaders($request) ?? $this->fromIpLookup($request);

        // Store in session (including null) so we don't re-query on every request.
        $request->session()->put(self::SESSION_KEY, $country);

        return $country;
    }

    /**
     * Read country from proxy/CDN headers — no network call, always preferred.
     */
    private function fromHeaders(Request $request): ?string
    {
        // Cloudflare
        $cf = $request->header('CF-IPCountry');
        if ($cf && $this->isValidCode($cf)) {
            return strtoupper($cf);
        }

        // Generic fallback header some proxies set
        $xcc = $request->header('X-Country-Code');
        if ($xcc && $this->isValidCode($xcc)) {
            return strtoupper($xcc);
        }

        return null;
    }

    /**
     * HTTP lookup against ip-api.com.
     *
     * Only runs in production (APP_ENV=production) or when explicitly enabled via
     * GEO_LOOKUP_ENABLED=true. Local dev requests come from 127.0.0.1 which ip-api
     * returns an error for anyway.
     */
    private function fromIpLookup(Request $request): ?string
    {
        if (! $this->lookupEnabled()) {
            return null;
        }

        $ip = $request->ip();

        // Skip private/loopback — ip-api returns an error for these
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return null;
        }

        try {
            $url      = str_replace('{ip}', urlencode($ip), self::LOOKUP_URL);
            $response = Http::timeout(self::TIMEOUT_SEC)->get($url);

            if (! $response->ok()) {
                return null;
            }

            $data = $response->json();

            if (($data['status'] ?? '') !== 'success') {
                return null;
            }

            $code = $data['countryCode'] ?? null;

            return ($code && $this->isValidCode($code)) ? strtoupper($code) : null;
        } catch (\Throwable $e) {
            Log::debug('GeoDetectionService: IP lookup failed', [
                'ip'    => $ip,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function isValidCode(string $code): bool
    {
        return (bool) preg_match('/^[A-Z]{2}$/', strtoupper($code));
    }

    private function lookupEnabled(): bool
    {
        return config('geo.lookup_enabled', app()->isProduction());
    }
}
