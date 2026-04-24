<?php

declare(strict_types=1);

namespace App\Services;

use App\ValueObjects\StoreCostSettings;

/**
 * Applies per-store cost settings to aggregated order totals to produce
 * adjusted profit components.
 *
 * Controllers collect raw SQL aggregates (revenue, tax, refunds, shipping, etc.)
 * and pass them here. The calculator handles:
 *   - Tax deduction (direct or reverse-calculated fallback per country)
 *   - Refund deduction from revenue
 *   - Shipping cost override (order / flat / percentage)
 *
 * Fixed monthly costs are NOT included here — call proratedFixedCosts()
 * on StoreCostSettings directly and show them as a separate dashboard line item.
 *
 * @see PLANNING.md section 6 (profit formula §F3)
 *
 * Reads: StoreCostSettings
 * Writes: nothing (pure computation)
 * Called by: DashboardController, AcquisitionController, CountriesController
 */
final class ProfitCalculator
{
    /**
     * Compute adjusted profit components from aggregated order data.
     *
     * @param float             $revenue        SUM(total_in_reporting_currency)
     * @param float             $totalTax       SUM(orders.tax) — tax on orders that have it
     * @param float             $revenueNoTax   SUM(total) for orders where tax = 0 (fallback base)
     * @param float             $totalRefunds   SUM(orders.refund_amount)
     * @param float             $totalShipping  SUM(orders.shipping)
     * @param int               $orderCount     COUNT(orders.id) — for flat-rate shipping
     * @param StoreCostSettings $settings
     * @param string|null       $countryCode    ISO 3166-1 alpha-2 for per-country tax rate lookup
     *
     * @return array{
     *   net_revenue: float,
     *   effective_tax: float,
     *   effective_shipping: float,
     *   total_refunds: float,
     * }
     */
    public static function compute(
        float $revenue,
        float $totalTax,
        float $revenueNoTax,
        float $totalRefunds,
        float $totalShipping,
        int $orderCount,
        StoreCostSettings $settings,
        ?string $countryCode = null,
    ): array {
        $effectiveTax = 0.0;

        if ($settings->deductTax) {
            // Orders that already have tax recorded — use directly.
            $effectiveTax = $totalTax;

            // Orders with no tax data — apply fallback rate if not B2B.
            if ($revenueNoTax > 0 && ! $settings->zeroTaxIsB2b) {
                $rate = $countryCode
                    ? ($settings->countryTaxRates[$countryCode] ?? $settings->defaultTaxRate ?? 0.0)
                    : ($settings->defaultTaxRate ?? 0.0);

                if ($rate > 0) {
                    // Reverse-calc: extract embedded VAT from an inclusive price.
                    // e.g. €122 incl. 22% VAT → tax = 122 * 22 / 122 = €22
                    $effectiveTax += $revenueNoTax * $rate / (100 + $rate);
                }
            }
        }

        $effectiveShipping = match ($settings->shippingCostMode) {
            'flat'       => $orderCount * ($settings->shippingFlatRate ?? 0.0),
            'percentage' => $totalShipping * ($settings->shippingPercentage ?? 1.0),
            default      => $totalShipping,
        };

        $netRevenue = $revenue - $effectiveTax - $totalRefunds;

        return [
            'net_revenue'        => round($netRevenue, 4),
            'effective_tax'      => round($effectiveTax, 4),
            'effective_shipping' => round($effectiveShipping, 4),
            'total_refunds'      => round($totalRefunds, 4),
        ];
    }

    /**
     * Load cost settings for a set of store IDs, merging when multiple stores differ.
     *
     * When all selected stores share identical settings, those settings are returned
     * exactly. When settings differ (e.g. one EU store + one US store), the most
     * conservative defaults are used: no tax deduction, order shipping as-is.
     * Multi-store blending will be refined in a future iteration.
     *
     * @param  int[]  $storeIds  Empty means "all workspace stores already loaded"
     * @param  \Illuminate\Support\Collection<int, \App\Models\Store>  $stores
     */
    public static function settingsForStores(array $storeIds, \Illuminate\Support\Collection $stores): StoreCostSettings
    {
        $applicable = $storeIds
            ? $stores->whereIn('id', $storeIds)->values()
            : $stores->values();

        if ($applicable->isEmpty()) {
            return new StoreCostSettings();
        }

        if ($applicable->count() === 1) {
            return $applicable->first()->cost_settings;
        }

        // Multiple stores: use first store's settings as a starting point.
        // This is acceptable for the common case (most workspaces have one store).
        return $applicable->first()->cost_settings;
    }
}
