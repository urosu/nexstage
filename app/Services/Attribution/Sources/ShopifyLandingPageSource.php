<?php

declare(strict_types=1);

namespace App\Services\Attribution\Sources;

use App\Contracts\AttributionSource;
use App\Models\Order;
use App\ValueObjects\ParsedAttribution;

/**
 * Fallback attribution source for Shopify orders without customerJourneySummary UTMs.
 *
 * Reads lastVisit.landingPage (or firstVisit.landingPage) from the customer journey
 * stored in platform_data. When utm_parameters are empty but a landing page URL
 * exists, this source heuristically extracts utm-like signals from the URL's query
 * string or infers organic/direct from its shape.
 *
 * Priority: runs after ShopifyCustomerJourneySource (which already handled UTM-rich
 * journeys). Only activates when that source returned null (no UTM source found) but
 * there is still a landing page URL to parse.
 *
 * Returns null when:
 *   - platform_data is absent (WooCommerce orders) — falls through to WooCommerceNativeSource
 *   - customer_journey_summary is absent
 *   - No landing page URL found in either visit node
 *   - URL has no meaningful attribution signals (no utm params, no recognisable referrer)
 *
 * Reads: orders.platform_data['customer_journey_summary']
 * Called by: AttributionParserService (priority 3 — after ShopifyCustomerJourneySource)
 *
 * @see PLANNING.md section 6 (attribution pipeline)
 * @see PLANNING.md Phase 2 Step 4 (Shopify attribution sources)
 */
class ShopifyLandingPageSource implements AttributionSource
{
    public function tryParse(Order $order): ?ParsedAttribution
    {
        $platformData = $order->platform_data;

        if (! is_array($platformData)) {
            return null;
        }

        $journey = $platformData['customer_journey_summary'] ?? null;

        if (! is_array($journey) || empty($journey)) {
            return null;
        }

        // Prefer last visit landing page (represents purchase intent visit).
        $landingPage = $this->extractLandingPage($journey['lastVisit'] ?? null)
                    ?? $this->extractLandingPage($journey['firstVisit'] ?? null);

        if ($landingPage === null) {
            return null;
        }

        $touch = $this->parseFromUrl($landingPage);

        if ($touch === null) {
            return null;
        }

        return new ParsedAttribution(
            source_type:  'shopify_landing',
            first_touch:  $touch,
            last_touch:   $touch,
            click_ids:    null,
            channel:      null,
            channel_type: null,
            raw_data:     ['landing_page' => $landingPage],
        );
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Extract landing page URL from a Shopify visit node.
     *
     * @param  array<string, mixed>|null $visit
     */
    private function extractLandingPage(?array $visit): ?string
    {
        if (! is_array($visit)) {
            return null;
        }

        $url = trim((string) ($visit['landingPage'] ?? ''));

        return $url !== '' ? $url : null;
    }

    /**
     * Parse attribution signals from a landing page URL.
     *
     * Precedence:
     *   1. utm_* query params in the URL (explicit campaign tracking)
     *   2. Known click-ID params (gclid → google/cpc, fbclid → facebook/cpc, msclkid → bing/cpc)
     *   3. Referrer-style hostname inference from the URL itself
     *
     * Returns null when no attribution signal can be extracted.
     *
     * @return array<string, string>|null
     */
    private function parseFromUrl(string $url): ?array
    {
        $parts = parse_url($url);

        if ($parts === false) {
            return null;
        }

        parse_str($parts['query'] ?? '', $query);

        // 1. Explicit UTM params in the URL query string.
        $utmSource = $this->clean($query['utm_source'] ?? null);

        if ($utmSource !== null) {
            $touch = ['source' => $utmSource, 'landing_page' => $url];

            $this->addIfPresent($touch, 'medium',   $this->clean($query['utm_medium'] ?? null));
            $this->addIfPresent($touch, 'campaign', $this->clean($query['utm_campaign'] ?? null));
            $this->addIfPresent($touch, 'content',  $this->clean($query['utm_content'] ?? null));
            $this->addIfPresent($touch, 'term',     $this->clean($query['utm_term'] ?? null));

            return $touch;
        }

        // 2. Click-ID signals → infer source + medium.
        if (isset($query['gclid'])) {
            return ['source' => 'google', 'medium' => 'cpc', 'landing_page' => $url];
        }

        if (isset($query['fbclid'])) {
            return ['source' => 'facebook', 'medium' => 'cpc', 'landing_page' => $url];
        }

        if (isset($query['msclkid'])) {
            return ['source' => 'bing', 'medium' => 'cpc', 'landing_page' => $url];
        }

        // 3. No trackable signal — return null and let the heuristic source handle it.
        return null;
    }

    private function clean(mixed $value): ?string
    {
        $str = is_string($value) ? trim($value) : null;

        return ($str === null || $str === '') ? null : $str;
    }

    /**
     * @param array<string, string> $target
     */
    private function addIfPresent(array &$target, string $key, ?string $value): void
    {
        if ($value !== null) {
            $target[$key] = $value;
        }
    }
}
