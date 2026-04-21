<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\Order;
use App\ValueObjects\ParsedAttribution;

/**
 * Contract for a single attribution data source.
 *
 * Implementations are registered in AttributionParserService in priority order.
 * The first source that returns a non-null ParsedAttribution wins — the loop exits
 * immediately with no blending or cross-source field filling.
 *
 * Implementations:
 *   - PixelYourSiteSource     — PYS meta (highest priority; corrects WC native for email/SMS)
 *   - WooCommerceNativeSource — orders.utm_* columns from WC 8.5+ native attribution
 *   - ReferrerHeuristicSource — orders.source_type heuristics (direct/organic/referral)
 *
 * @see PLANNING.md section 6
 */
interface AttributionSource
{
    /**
     * Attempt to parse attribution data from the given order.
     *
     * Returns null when this source has no data for the order — the parser
     * will move to the next registered source.
     *
     * The returned ParsedAttribution does NOT yet have channel/channel_type set;
     * AttributionParserService calls withChannel() after the source returns.
     */
    public function tryParse(Order $order): ?ParsedAttribution;
}
