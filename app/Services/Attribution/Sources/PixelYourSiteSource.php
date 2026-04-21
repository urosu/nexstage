<?php

declare(strict_types=1);

namespace App\Services\Attribution\Sources;

use App\Contracts\AttributionSource;
use App\Models\Order;
use App\ValueObjects\ParsedAttribution;

/**
 * Attribution source that reads PixelYourSite Pro plugin data from orders.raw_meta.
 *
 * PYS is the highest-priority source because it captures the actual marketing
 * channel (e.g. Klaviyo email) even when WooCommerce native attribution records
 * the last-session referrer instead (e.g. google.com organic). See PLANNING 6.2
 * for the real-world validation case: Klaviyo email on Android misattributed by
 * WC native as organic-google.
 *
 * Data layout in raw_meta (set by UpsertWooCommerceOrderAction Step 7):
 *   raw_meta.pys_enrich_data — array with pys_utm, last_pys_utm, pys_landing, etc.
 *   raw_meta.pys_fb_cookie   — array with {fbc, fbp} for Phase 4 CAPI enabler.
 *
 * PYS UTM format: "utm_source:Klaviyo|utm_medium:email|utm_campaign:SPRING25|..."
 * The literal string "undefined" is treated as null throughout.
 *
 * Reads: orders.raw_meta (pys_enrich_data, pys_fb_cookie keys).
 * Called by: AttributionParserService (priority 1).
 *
 * @see PLANNING.md section 6, 6.2
 */
class PixelYourSiteSource implements AttributionSource
{
    public function tryParse(Order $order): ?ParsedAttribution
    {
        $rawMeta = $order->raw_meta;

        if (! is_array($rawMeta) || ! isset($rawMeta['pys_enrich_data'])) {
            return null;
        }

        $pys = $rawMeta['pys_enrich_data'];

        if (! is_array($pys)) {
            return null;
        }

        $firstTouch = $this->parseTouchPoint(
            utmString:    $pys['pys_utm'] ?? null,
            utmIdString:  $pys['pys_utm_id'] ?? null,
            source:       $pys['pys_source'] ?? null,
            landingPage:  $pys['pys_landing'] ?? null,
        );

        $lastTouch = $this->parseTouchPoint(
            utmString:    $pys['last_pys_utm'] ?? null,
            utmIdString:  $pys['last_pys_utm_id'] ?? null,
            source:       $pys['last_pys_source'] ?? null,
            landingPage:  $pys['last_pys_landing'] ?? null,
        );

        // If both touches are empty, try to infer channel from click IDs before
        // giving up — covers stores that had no UTMs configured but PYS still
        // recorded fbc (Facebook ad click) or gadid/gclid (Google Ads click).
        if ($firstTouch === null && $lastTouch === null) {
            $inferred = $this->inferTouchFromClickIds($rawMeta, $pys);
            if ($inferred === null) {
                return null;
            }
            $firstTouch = $inferred;
            $lastTouch  = $inferred;
        }

        // Single-touch fallback: mirror whichever touch is available.
        $firstTouch ??= $lastTouch;
        $lastTouch  ??= $firstTouch;

        return new ParsedAttribution(
            source_type:  'pys',
            first_touch:  $firstTouch,
            last_touch:   $lastTouch,
            click_ids:    $this->extractClickIds($rawMeta),
            channel:      null,
            channel_type: null,
            raw_data:     ['pys_enrich_data' => $pys],
        );
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Parse a single PYS touch point from the UTM string and supplementary fields.
     *
     * Returns null when no usable source can be derived.
     *
     * @return array<string, string>|null
     */
    private function parseTouchPoint(
        ?string $utmString,
        ?string $utmIdString,
        ?string $source,
        ?string $landingPage,
    ): ?array {
        $utm    = $this->parsePipeDelimited($utmString);
        $utmIds = $this->parsePipeDelimited($utmIdString);

        // utm_source from the pipe-delimited string takes precedence; fall back
        // to the bare pys_source / last_pys_source field.
        $finalSource = $utm['utm_source'] ?? $this->normalise($source);

        if ($finalSource === null) {
            return null;
        }

        $touch = ['source' => $finalSource];

        $this->addIfPresent($touch, 'medium',       $utm['utm_medium'] ?? null);
        $this->addIfPresent($touch, 'campaign',     $utm['utm_campaign'] ?? null);
        $this->addIfPresent($touch, 'content',      $utm['utm_content'] ?? null);
        $this->addIfPresent($touch, 'term',         $utm['utm_term'] ?? null);
        $this->addIfPresent($touch, 'landing_page', $this->normalise($landingPage));

        // gclid from PYS utm_id fields (gadid key)
        $this->addIfPresent($touch, 'gclid', $utmIds['gadid'] ?? null);

        return $touch;
    }

    /**
     * Parse PYS pipe-delimited "key:value|key:value" strings.
     *
     * Treats "undefined" literal values as absent.
     *
     * @return array<string, string>
     */
    private function parsePipeDelimited(?string $str): array
    {
        if ($str === null || $str === '') {
            return [];
        }

        $result = [];

        foreach (explode('|', $str) as $pair) {
            $colonPos = strpos($pair, ':');

            if ($colonPos === false) {
                continue;
            }

            $key   = trim(substr($pair, 0, $colonPos));
            $value = $this->normalise(substr($pair, $colonPos + 1));

            if ($key !== '' && $value !== null) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Extract Facebook click IDs from pys_fb_cookie for Phase 4 CAPI enabler.
     *
     * @param  array<string, mixed> $rawMeta
     * @return array<string, string>|null
     */
    private function extractClickIds(array $rawMeta): ?array
    {
        $ids = [];

        $fbCookie = $rawMeta['pys_fb_cookie'] ?? null;

        if (is_array($fbCookie)) {
            $fbc = $this->normalise($fbCookie['fbc'] ?? null);
            $fbp = $this->normalise($fbCookie['fbp'] ?? null);

            if ($fbc !== null) {
                $ids['fbc'] = $fbc;
            }

            if ($fbp !== null) {
                $ids['fbp'] = $fbp;
            }
        }

        // Also extract Google Ads click ID stored by PYS in pys_utm_id / last_pys_utm_id.
        $pysData = $rawMeta['pys_enrich_data'] ?? [];
        if (is_array($pysData)) {
            $firstIds = $this->parsePipeDelimited($pysData['pys_utm_id'] ?? null);
            $lastIds  = $this->parsePipeDelimited($pysData['last_pys_utm_id'] ?? null);
            $gclid    = $this->normalise($firstIds['gadid'] ?? $lastIds['gadid'] ?? null);
            if ($gclid !== null) {
                $ids['gclid'] = $gclid;
            }
        }

        return ! empty($ids) ? $ids : null;
    }

    /**
     * Infer a minimal touch point from click IDs when UTMs and pys_source are absent.
     *
     * Priority: gclid (session-specific, higher confidence) > fbc (90-day cookie).
     * fbc is only set on ad clicks, never on organic Facebook visits, so its presence
     * is near-definitive evidence of a paid Facebook session.
     *
     * @param  array<string, mixed> $rawMeta
     * @param  array<string, mixed> $pys
     * @return array<string, string>|null
     */
    private function inferTouchFromClickIds(array $rawMeta, array $pys): ?array
    {
        // gclid from pys_utm_id / last_pys_utm_id (session-specific Google Ads click)
        $firstIds = $this->parsePipeDelimited($pys['pys_utm_id'] ?? null);
        $lastIds  = $this->parsePipeDelimited($pys['last_pys_utm_id'] ?? null);

        if (! empty($firstIds['gadid']) || ! empty($lastIds['gadid'])) {
            return ['source' => 'google', 'medium' => 'cpc'];
        }

        // fbc from pys_fb_cookie (Facebook ad click cookie)
        $fbc = $this->normalise(($rawMeta['pys_fb_cookie'] ?? [])['fbc'] ?? null);
        if ($fbc !== null) {
            return ['source' => 'facebook', 'medium' => 'paid_social'];
        }

        return null;
    }

    /**
     * Treat "undefined" literal and empty strings as null.
     */
    private function normalise(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === 'undefined') {
            return null;
        }

        return (string) $value;
    }

    /**
     * Add $key => $value to $target only when $value is non-null.
     *
     * @param array<string, string> $target
     */
    private function addIfPresent(array &$target, string $key, ?string $value): void
    {
        if ($value !== null) {
            $target[$key] = $value;
        }
    }
}
