<?php

declare(strict_types=1);

namespace App\Services\Cogs;

/**
 * Extracts unit cost (COGS) from a WooCommerce order line item's meta_data.
 *
 * Only reads the WooCommerce native COGS field (`_wc_cogs_total_cost`), available
 * in WooCommerce 9.5+ under Analytics → Settings → Cost of Goods Sold.
 * Third-party plugin fields (WPFactory, WC.com extension) are intentionally ignored.
 *
 * Reads: raw WooCommerce line item array (from REST API `line_items[].meta_data`)
 * Writes: nothing — returns a ?float for the caller to write to order_items.unit_cost
 * Called by: UpsertWooCommerceOrderAction (Step 7, per line item)
 *
 * @see PLANNING.md section 7
 */
class CogsReaderService
{
    /**
     * Attempt to extract unit cost from a single WooCommerce line item.
     *
     * @param  array<string, mixed> $lineItem  Raw WooCommerce line_items[] entry from REST API.
     * @return float|null  Unit cost in the order currency, or null if no COGS data found.
     */
    public function readFromLineItem(array $lineItem): ?float
    {
        $metaMap  = $this->buildItemMetaMap($lineItem);
        $quantity = max(1, (int) ($lineItem['quantity'] ?? 1));

        return $this->tryWcCore($metaMap, $quantity);
    }

    // -------------------------------------------------------------------------
    // Source reader
    // -------------------------------------------------------------------------

    /**
     * WooCommerce native COGS (WC 9.5+, Analytics → Settings → Cost of Goods Sold).
     *
     * Stores the total cost for the line in `_wc_cogs_total_cost`.
     * Unit cost = total_cost / quantity.
     *
     * @param array<string, string> $metaMap
     */
    private function tryWcCore(array $metaMap, int $quantity): ?float
    {
        $totalCost = $this->positiveFloat($metaMap['_wc_cogs_total_cost'] ?? null);

        if ($totalCost === null) {
            return null;
        }

        // Guard against quantity = 0 (malformed order item); already clamped to ≥1 above.
        return round($totalCost / $quantity, 4);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build a key→value map from a line item's meta_data array.
     *
     * @param  array<string, mixed> $lineItem
     * @return array<string, string>
     */
    private function buildItemMetaMap(array $lineItem): array
    {
        $map = [];

        foreach ($lineItem['meta_data'] ?? [] as $meta) {
            $key = (string) ($meta['key'] ?? '');
            $val = $meta['value'] ?? null;

            if ($key !== '' && is_scalar($val)) {
                $map[$key] = (string) $val;
            }
        }

        return $map;
    }

    /**
     * Cast a raw meta value to a positive float, or return null.
     *
     * Zero and negative values are treated as "not configured" — WooCommerce
     * writes 0.00 rather than omitting the key when no cost is set.
     */
    private function positiveFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        $float = (float) $value;

        return $float > 0 ? $float : null;
    }
}
