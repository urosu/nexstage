<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Generates one-sentence narrative headers for analytics pages.
 *
 * Each public method maps to one destination page. Input is pre-computed metrics
 * already fetched by the calling controller — no additional DB calls.
 * Returns null when there is insufficient data to produce a meaningful sentence.
 *
 * Tone rule: terse, direct, action-oriented. A senior operator pointing at the
 * screen, not a chatbot. See PROGRESS.md PageNarrative template examples (LOCKED).
 *
 * @see PROGRESS.md Phase 3.1 — NarrativeTemplateService
 * @see PROGRESS.md PageNarrative template examples
 */
class NarrativeTemplateService
{
    /**
     * Dashboard / Home narrative.
     * Template: "Revenue €{rev} ({delta}% vs last {weekday}); ROAS {roas}x — {lever}."
     *
     * @param float|null $revenue       Total revenue for the selected period
     * @param float|null $compareRevenue Revenue for the comparison period (null → omit delta)
     * @param string|null $comparisonLabel Human label for the comparison, e.g. "last Wednesday"
     * @param float|null $roas           Real ROAS (revenue / ad_spend); null when no ads
     * @param bool        $hasAds         Whether workspace has paid ads connected
     * @param bool        $hasGsc         Whether workspace has GSC connected
     */
    public function forDashboard(
        ?float $revenue,
        ?float $compareRevenue,
        ?string $comparisonLabel,
        ?float $roas,
        bool $hasAds,
        bool $hasGsc,
    ): ?string {
        if ($revenue === null) {
            return null;
        }

        $parts = [];

        // Revenue + optional delta
        $revFmt = $this->currency($revenue);
        if ($compareRevenue !== null && $compareRevenue > 0 && $comparisonLabel !== null) {
            $deltaPct = (($revenue - $compareRevenue) / $compareRevenue) * 100;
            $sign     = $deltaPct >= 0 ? '+' : '';
            $parts[]  = "Revenue {$revFmt} ({$sign}" . number_format($deltaPct, 1) . "% vs {$comparisonLabel})";
        } else {
            $parts[] = "Revenue {$revFmt}";
        }

        // ROAS — only meaningful when ads are connected
        if ($roas !== null && $hasAds) {
            $parts[] = 'ROAS ' . number_format($roas, 1) . 'x';
        }

        $lever = $this->dashboardLever($roas, $hasAds, $hasGsc);

        return implode('; ', $parts) . ($lever !== null ? " — {$lever}." : '.');
    }

    /**
     * Campaigns narrative.
     * Template: "{n} campaigns above target, {m} below. Biggest spender: '{name}' at {roas}x ({status})."
     *
     * @param int         $aboveTarget   Campaigns at or above target ROAS
     * @param int         $belowTarget   Campaigns below target ROAS
     * @param string|null $topSpenderName Name of highest-spend campaign in the period
     * @param float|null  $topSpenderRoas Real ROAS of that campaign
     * @param string|null $topSpenderStatus 'active' | 'paused' | 'ended'
     */
    public function forCampaigns(
        int $aboveTarget,
        int $belowTarget,
        ?string $topSpenderName,
        ?float $topSpenderRoas,
        ?string $topSpenderStatus,
    ): ?string {
        if ($aboveTarget === 0 && $belowTarget === 0) {
            return null;
        }

        $summary = "{$aboveTarget} " . ($aboveTarget === 1 ? 'campaign' : 'campaigns')
            . " above target, {$belowTarget} below";

        if ($topSpenderName === null) {
            return "{$summary}.";
        }

        $roasPart   = $topSpenderRoas    !== null ? ' at ' . number_format($topSpenderRoas, 1) . 'x' : '';
        $statusPart = $topSpenderStatus !== null ? " ({$topSpenderStatus})" : '';

        return "{$summary}. Biggest spender: '{$topSpenderName}'{$roasPart}{$statusPart}.";
    }

    /**
     * SEO / Organic narrative.
     * Template: "Organic clicks {delta}% WoW. {n} queries in striking distance; {m} at risk."
     *
     * @param float|null $clicksDeltaPct  Week-over-week click change (% signed)
     * @param int        $strikingDistance Queries in position 11–20 with ≥100 impressions
     * @param int        $atRisk          Queries with CTR below peer-bucket benchmark
     */
    public function forSeo(
        ?float $clicksDeltaPct,
        int $strikingDistance,
        int $atRisk,
    ): ?string {
        $parts = [];

        if ($clicksDeltaPct !== null) {
            $sign    = $clicksDeltaPct >= 0 ? '+' : '';
            $parts[] = "Organic clicks {$sign}" . number_format($clicksDeltaPct, 0) . '% WoW';
        }

        if ($strikingDistance > 0) {
            $parts[] = "{$strikingDistance} " . ($strikingDistance === 1 ? 'query' : 'queries')
                . ' in striking distance';
        }

        if ($atRisk > 0) {
            $parts[] = "{$atRisk} at risk";
        }

        if (empty($parts)) {
            return null;
        }

        return implode('; ', $parts) . '.';
    }

    /**
     * Performance / Site Health narrative.
     * Template: "Lighthouse {score} ({band}). LCP median {lcp}s. Estimated revenue at risk: €{risk}."
     *
     * @param int|null   $performanceScore 0–100 Lighthouse score
     * @param int|null   $lcpMs            LCP in milliseconds (median across monitored URLs)
     * @param float|null $revenueAtRisk    §F19 estimated monthly revenue at risk from slowness
     */
    public function forPerformance(
        ?int $performanceScore,
        ?int $lcpMs,
        ?float $revenueAtRisk,
    ): ?string {
        if ($performanceScore === null) {
            return null;
        }

        // Three-band label per §M3 vocabulary (matches GSC CWV colors)
        $band = match (true) {
            $performanceScore >= 90 => 'good',
            $performanceScore >= 50 => 'amber',
            default                 => 'poor',
        };

        $parts = ["Lighthouse {$performanceScore} ({$band})"];

        if ($lcpMs !== null) {
            $parts[] = 'LCP median ' . number_format($lcpMs / 1000, 1) . 's';
        }

        if ($revenueAtRisk !== null && $revenueAtRisk > 0) {
            $parts[] = 'Estimated revenue at risk: ' . $this->currency($revenueAtRisk) . ' / month';
        }

        return implode('. ', $parts) . '.';
    }

    /**
     * Store › Products narrative.
     * Template: "{n} winners, {m} losers vs 30d median. {k} products at stockout risk."
     *
     * @param int $winners       Products with CM above 30d workspace median (§F23 Winners)
     * @param int $losers        Products below median (§F23 Losers)
     * @param int $stockoutRisk  Products with days-of-cover ≤ 7 (§F22)
     */
    public function forProducts(int $winners, int $losers, int $stockoutRisk): ?string
    {
        if ($winners === 0 && $losers === 0) {
            return null;
        }

        $summary = "{$winners} " . ($winners === 1 ? 'winner' : 'winners') . ', '
            . "{$losers} " . ($losers === 1 ? 'loser' : 'losers') . ' vs 30d median';

        if ($stockoutRisk > 0) {
            return "{$summary}. {$stockoutRisk} "
                . ($stockoutRisk === 1 ? 'product' : 'products') . ' at stockout risk.';
        }

        return "{$summary}.";
    }

    /**
     * Store › Customers narrative.
     * Template: "{n} Champions, {m} At-Risk customers. Returning order % is {pct}%."
     *
     * @param int        $champions     Customers in Champions RFM segment
     * @param int        $atRisk        Customers in At Risk / Can't Lose Them segments
     * @param float|null $returningPct  Returning-order % (§F30)
     */
    public function forCustomers(int $champions, int $atRisk, ?float $returningPct): ?string
    {
        if ($champions === 0 && $atRisk === 0) {
            return null;
        }

        $parts = [
            "{$champions} " . ($champions === 1 ? 'Champion' : 'Champions'),
            "{$atRisk} At-Risk " . ($atRisk === 1 ? 'customer' : 'customers'),
        ];

        $sentence = implode(', ', $parts);

        if ($returningPct !== null) {
            $sentence .= '. Returning order % is ' . number_format($returningPct, 1) . '%.';
        } else {
            $sentence .= '.';
        }

        return $sentence;
    }

    /**
     * Acquisition narrative.
     * Template: "{top} leads with €{rev} revenue ({roas}x ROAS); {worst} dragging at {roas}x."
     *
     * @param string|null $topChannel   Display name of highest-ROAS paid channel
     * @param float|null  $topRevenue   Revenue attributed to top channel
     * @param float|null  $topRoas      Real ROAS of top channel
     * @param string|null $worstChannel Display name of worst-ROAS paid channel (only when ≠ top)
     * @param float|null  $worstRoas    Real ROAS of worst channel
     */
    public function forAcquisition(
        ?string $topChannel,
        ?float $topRevenue,
        ?float $topRoas,
        ?string $worstChannel,
        ?float $worstRoas,
    ): ?string {
        if ($topChannel === null) {
            return null;
        }

        $revPart  = $topRevenue !== null ? ' with ' . $this->currency($topRevenue) . ' revenue' : '';
        $roasPart = $topRoas    !== null ? ' (' . number_format($topRoas, 1) . 'x ROAS)' : '';
        $sentence = "{$topChannel} leads{$revPart}{$roasPart}";

        if ($worstChannel !== null && $worstRoas !== null && $worstChannel !== $topChannel) {
            $sentence .= '; ' . $worstChannel . ' dragging at ' . number_format($worstRoas, 1) . 'x';
        }

        return "{$sentence}.";
    }

    /**
     * Inbox narrative.
     * Template: "{n} items need attention today." / "All clear — no items need attention today."
     */
    public function forInbox(int $urgentCount): string
    {
        if ($urgentCount === 0) {
            return 'All clear — no items need attention today.';
        }

        return "{$urgentCount} " . ($urgentCount === 1 ? 'item needs' : 'items need') . ' attention today.';
    }

    // ---------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------

    /**
     * Format a monetary amount compactly (€4,230, not €4230.00).
     * No decimals for amounts ≥ €10; two decimals for smaller amounts.
     */
    private function currency(float $amount): string
    {
        if (abs($amount) >= 10) {
            return '€' . number_format((int) round($amount), 0);
        }

        return '€' . number_format($amount, 2);
    }

    /** Pick a context-appropriate lever sentence for the dashboard. */
    private function dashboardLever(?float $roas, bool $hasAds, bool $hasGsc): ?string
    {
        if (! $hasAds && $hasGsc) {
            return 'organic is your primary driver today';
        }

        if (! $hasAds) {
            return null; // No paid, no GSC — no lever to name
        }

        if ($roas !== null && $roas < 1.5) {
            return 'ROAS below breakeven — check campaign budgets';
        }

        return null;
    }
}
