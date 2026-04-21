<?php

declare(strict_types=1);

namespace App\Services\Attribution\Sources;

use App\Contracts\AttributionSource;
use App\Models\Order;
use App\ValueObjects\ParsedAttribution;

/**
 * Fallback attribution source using WooCommerce native source_type field.
 *
 * Fires when utm_source is absent (WooCommerceNativeSource returned null) but
 * WooCommerce 8.5+ recorded a source_type value (organic_search, direct, etc.).
 * These cover non-UTM sessions where we can still classify the channel.
 *
 * WC source_type values and how they map:
 *   direct / typein  → source='direct'    (classified as Direct by ChannelClassifier)
 *   organic_search   → source='google',   medium='organic'   (approximate; no referrer domain stored)
 *   referral / link  → source='referral', medium='referral'
 *   admin            → ignored (admin-created orders don't have real attribution)
 *   utm              → handled by WooCommerceNativeSource; should not reach this source
 *
 * Produces single-touch attribution (first_touch === last_touch).
 *
 * Reads: orders.source_type.
 * Called by: AttributionParserService (priority 3, lowest).
 *
 * @see PLANNING.md section 6
 */
class ReferrerHeuristicSource implements AttributionSource
{
    public function tryParse(Order $order): ?ParsedAttribution
    {
        $sourceType = $order->source_type;

        if ($sourceType === null || $sourceType === '') {
            return null;
        }

        $touch = match ($sourceType) {
            'direct', 'typein' => ['source' => 'direct'],
            'organic_search'   => ['source' => 'google', 'medium' => 'organic'],
            'referral', 'link' => ['source' => 'referral', 'medium' => 'referral'],
            // 'admin' and 'utm' are intentionally not handled here.
            // admin → no real marketing attribution; utm → WooCommerceNativeSource handles it.
            default => null,
        };

        if ($touch === null) {
            return null;
        }

        return new ParsedAttribution(
            source_type:  'referrer',
            first_touch:  $touch,
            last_touch:   $touch,
            click_ids:    null,
            channel:      null,
            channel_type: null,
            raw_data:     null,
        );
    }
}
