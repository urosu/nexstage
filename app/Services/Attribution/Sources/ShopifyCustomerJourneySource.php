<?php

declare(strict_types=1);

namespace App\Services\Attribution\Sources;

use App\Contracts\AttributionSource;
use App\Models\Order;
use App\ValueObjects\ParsedAttribution;

/**
 * Attribution source that reads Shopify's customerJourneySummary from platform_data.
 *
 * Shopify exposes a rich customer journey graph (first visit + last visit + all touchpoints)
 * via the GraphQL `customerJourneySummary` field on the Order type. UpsertShopifyOrderAction
 * stores the raw node verbatim in platform_data['customer_journey_summary'].
 *
 * This source is the highest-priority Shopify-specific source. It extracts:
 *   - first_touch: from firstVisit.utmParameters
 *   - last_touch:  from lastVisit.utmParameters
 *   - landing_page: from the respective visit's landingPage URL
 *
 * Returns null when:
 *   - platform_data is absent (WooCommerce orders) — falls through to WooCommerceNativeSource
 *   - customer_journey_summary is absent or empty
 *   - Neither first nor last visit has a non-empty utm source
 *
 * Reads: orders.platform_data['customer_journey_summary']
 * Called by: AttributionParserService (priority 2 — after PixelYourSiteSource)
 *
 * @see PLANNING.md section 6 (attribution pipeline)
 * @see PLANNING.md Phase 2 Step 4 (Shopify attribution sources)
 */
class ShopifyCustomerJourneySource implements AttributionSource
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

        $firstTouch = $this->parseTouchPoint($journey['firstVisit'] ?? null);
        $lastTouch  = $this->parseTouchPoint($journey['lastVisit'] ?? null);

        if ($firstTouch === null && $lastTouch === null) {
            return null;
        }

        // Mirror whichever touch is available when only one exists.
        $firstTouch ??= $lastTouch;
        $lastTouch  ??= $firstTouch;

        return new ParsedAttribution(
            source_type:  'shopify_journey',
            first_touch:  $firstTouch,
            last_touch:   $lastTouch,
            click_ids:    null,
            channel:      null,
            channel_type: null,
            raw_data:     ['customer_journey_summary' => $journey],
        );
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Parse a single Shopify visit node into a touch-point array.
     *
     * Visit node shape (from Shopify GraphQL customerJourneySummary):
     * {
     *   utmParameters: { source, medium, campaign, content, term },
     *   landingPage:   "https://my-store.myshopify.com/..."
     *   referrerUrl:   "https://..."
     * }
     *
     * Returns null when the visit node is absent or has no utm source.
     *
     * @param  array<string, mixed>|null $visit
     * @return array<string, string>|null
     */
    private function parseTouchPoint(?array $visit): ?array
    {
        if (! is_array($visit)) {
            return null;
        }

        $utm = $visit['utmParameters'] ?? null;

        $source = $this->clean($utm['source'] ?? null);

        if ($source === null) {
            return null;
        }

        $touch = ['source' => $source];

        $this->addIfPresent($touch, 'medium',       $this->clean($utm['medium'] ?? null));
        $this->addIfPresent($touch, 'campaign',     $this->clean($utm['campaign'] ?? null));
        $this->addIfPresent($touch, 'content',      $this->clean($utm['content'] ?? null));
        $this->addIfPresent($touch, 'term',         $this->clean($utm['term'] ?? null));
        $this->addIfPresent($touch, 'landing_page', $this->clean($visit['landingPage'] ?? null));
        $this->addIfPresent($touch, 'referrer_url', $this->clean($visit['referrerUrl'] ?? null));

        return $touch;
    }

    /**
     * Normalise a value — treat empty strings as null.
     */
    private function clean(mixed $value): ?string
    {
        $str = is_string($value) ? trim($value) : null;

        return ($str === null || $str === '') ? null : $str;
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
