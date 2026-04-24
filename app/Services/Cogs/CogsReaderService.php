<?php

declare(strict_types=1);

namespace App\Services\Cogs;

/**
 * Extracts unit cost (COGS) from a WooCommerce order line item's meta_data.
 *
 * Tries built-in meta keys in priority order, then any custom keys configured
 * per store. First non-null positive result wins.
 *
 * Built-in priority:
 *   1. _wc_cogs_total_cost  — WC 10.3+ core (Analytics → Settings → COGS); total for line, ÷ qty
 *   2. _wc_cog_cost         — SkyVerge "Cost of Goods Sold" extension; unit cost
 *   3. _alg_wc_cog_cost     — WPFactory "Cost of Goods for WooCommerce" (free); unit cost
 *   4. _wcj_purchase_price  — Booster for WooCommerce; unit cost
 *
 * Reads: raw WooCommerce line item array (from REST API `line_items[].meta_data`)
 * Writes: nothing — returns a ?float for the caller to write to order_items.unit_cost
 * Called by: UpsertWooCommerceOrderAction (per line item)
 *
 * @see PLANNING.md section 7
 */
class CogsReaderService
{
    /**
     * Built-in meta keys, in priority order.
     * 'total' → divide by quantity; 'unit' → use as-is.
     *
     * @var array<int, array{key: string, value_type: 'unit'|'total'}>
     */
    private const BUILTIN_KEYS = [
        ['key' => '_wc_cogs_total_cost', 'value_type' => 'total'],
        ['key' => '_wc_cog_cost',        'value_type' => 'unit'],
        ['key' => '_alg_wc_cog_cost',    'value_type' => 'unit'],
        ['key' => '_wcj_purchase_price', 'value_type' => 'unit'],
    ];

    /**
     * Attempt to extract unit cost from a single WooCommerce line item.
     *
     * @param  array<string, mixed>                        $lineItem       Raw WooCommerce line_items[] entry.
     * @param  array<int, array{key: string, value_type: 'unit'|'total'}> $customMetaKeys Per-store extra keys to probe after built-ins.
     * @return float|null  Unit cost in the order currency, or null if no COGS data found.
     */
    public function readFromLineItem(array $lineItem, array $customMetaKeys = []): ?float
    {
        $metaMap  = $this->buildItemMetaMap($lineItem);
        $quantity = max(1, (int) ($lineItem['quantity'] ?? 1));

        foreach ([...self::BUILTIN_KEYS, ...$customMetaKeys] as $spec) {
            $result = $this->tryMetaKey($metaMap, $spec['key'], $spec['value_type'], $quantity);

            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Source reader
    // -------------------------------------------------------------------------

    /**
     * @param  array<string, string>  $metaMap
     * @param  'unit'|'total'         $valueType
     */
    private function tryMetaKey(array $metaMap, string $key, string $valueType, int $quantity): ?float
    {
        $raw = $this->positiveFloat($metaMap[$key] ?? null);

        if ($raw === null) {
            return null;
        }

        return $valueType === 'total'
            ? round($raw / $quantity, 4)
            : round($raw, 4);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build a key→value map from a line item's meta_data array.
     *
     * @param  array<string, mixed>  $lineItem
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
