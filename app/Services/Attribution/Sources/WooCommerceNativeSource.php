<?php

declare(strict_types=1);

namespace App\Services\Attribution\Sources;

use App\Contracts\AttributionSource;
use App\Models\Order;
use App\ValueObjects\ParsedAttribution;

/**
 * Attribution source that reads WooCommerce native order attribution columns.
 *
 * WooCommerce 8.5+ (Jan 2024+) ships built-in Order Attribution that writes
 * _wc_order_attribution_utm_* meta at checkout time. UpsertWooCommerceOrderAction
 * promotes these into orders.utm_source / utm_medium / etc. at sync time.
 *
 * This source fires when utm_source is populated. It produces a single-touch
 * attribution (first_touch === last_touch) because WC native only captures the
 * most recent session, not the full journey.
 *
 * Note: WooCommerce can misattribute email/SMS traffic as organic when the user's
 * most recent session before purchase was organic — that is why PixelYourSiteSource
 * runs first and overrides this source for PYS-enabled stores.
 *
 * Reads: orders.utm_source, utm_medium, utm_campaign, utm_content, utm_term.
 * Called by: AttributionParserService (priority 2, after PixelYourSiteSource).
 *
 * @see PLANNING.md section 6
 */
class WooCommerceNativeSource implements AttributionSource
{
    public function tryParse(Order $order): ?ParsedAttribution
    {
        $source = $order->utm_source;

        if ($source === null || $source === '') {
            return null;
        }

        $touch = ['source' => $source];

        $this->addIfPresent($touch, 'medium',   $order->utm_medium);
        $this->addIfPresent($touch, 'campaign', $order->utm_campaign);
        $this->addIfPresent($touch, 'content',  $order->utm_content);
        $this->addIfPresent($touch, 'term',     $order->utm_term);

        return new ParsedAttribution(
            source_type:  'wc_native',
            // Single-touch: WC native records only the most recent session.
            first_touch:  $touch,
            last_touch:   $touch,
            click_ids:    null,
            channel:      null,
            channel_type: null,
            raw_data:     null,
        );
    }

    /**
     * Add $key => $value to $target only when $value is non-null and non-empty.
     *
     * @param array<string, string> $target
     */
    private function addIfPresent(array &$target, string $key, ?string $value): void
    {
        if ($value !== null && $value !== '') {
            $target[$key] = $value;
        }
    }
}
