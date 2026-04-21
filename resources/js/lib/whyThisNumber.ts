/**
 * Builder functions for WhyThisNumberData objects.
 *
 * Each function returns a ready-to-use WhyThisNumberData that can be passed
 * directly to the `whyThisNumber` prop on MetricCard. Callers may extend the
 * returned object with rawValues or conflicts specific to their page.
 *
 * Conventions:
 *   - Formula strings are human-readable, not code.
 *   - Sources list every data source that feeds the formula.
 *   - Skip pure counts (Orders, Clicks, Impressions) — no formula to explain.
 *
 * @see PLANNING.md section 14.1
 */

import type { WhyThisNumberData } from '@/Components/shared/WhyThisNumber';

// ─── Store metrics ──────────────────────────────────────────────────────────

export function whyRevenue(): WhyThisNumberData {
    return {
        title: 'How Revenue is computed',
        formula: 'Revenue = Sum of order totals (completed + processing) converted to reporting currency via daily FX rates',
        sources: ['store'],
    };
}

export function whyAov(): WhyThisNumberData {
    return {
        title: 'How AOV is computed',
        formula: 'AOV = Total Revenue ÷ Number of Orders',
        sources: ['store'],
    };
}

export function whyNewCustomers(): WhyThisNumberData {
    return {
        title: 'How New Customers is computed',
        formula: 'New Customers = Orders where the customer email (SHA-256 hashed) has no prior purchase history in the workspace',
        sources: ['store'],
    };
}

export function whyItemsPerOrder(): WhyThisNumberData {
    return {
        title: 'How Items / Order is computed',
        formula: 'Items / Order = Total line-item quantity ÷ Number of Orders',
        sources: ['store'],
    };
}

// ─── Real (blended) metrics ─────────────────────────────────────────────────

export function whyRealRoas(): WhyThisNumberData {
    return {
        title: 'How Real ROAS is computed',
        formula: 'Real ROAS = Store Revenue ÷ Total Ad Spend\n\nUses verified store orders, not platform pixel attribution. Blended across all connected ad platforms.',
        sources: ['store', 'facebook', 'google'],
    };
}

export function whyMarketingPct(): WhyThisNumberData {
    return {
        title: 'How Marketing % is computed',
        formula: 'Marketing % = Total Ad Spend ÷ Revenue × 100\n\nLower is better — a high marketing % means a larger share of revenue is returned to ad platforms.',
        sources: ['store', 'facebook', 'google'],
    };
}

export function whyRealCpo(): WhyThisNumberData {
    return {
        title: 'How Real CPO is computed',
        formula: 'Real CPO = Total Ad Spend ÷ Total Store Orders\n\nUses the actual order count from your store, not platform-reported conversions.',
        sources: ['store', 'facebook', 'google'],
    };
}

export function whyNotTracked(isNegative: boolean): WhyThisNumberData {
    return {
        title: isNegative ? 'Why Not Tracked is negative' : 'How Not Tracked is computed',
        formula: isNegative
            ? 'Not Tracked (negative) = Store Revenue − Total Platform-Reported Conversion Value\n\nNegative means ad platforms claimed more conversion value than your store actually received. Usually caused by iOS14+ modeled conversions.'
            : 'Not Tracked = Total Revenue − Revenue matched to a paid channel via UTM attribution\n\nIncludes organic search, direct, email, affiliates, and any untagged traffic.',
        sources: ['store', 'facebook', 'google'],
    };
}

export function whyContributionMargin(): WhyThisNumberData {
    return {
        title: 'How Contribution Margin is computed',
        formula: 'Contribution Margin = Revenue − Cost of Goods Sold\n\nCOGS comes from order-level unit costs (WooCommerce Cost of Goods plugin meta fields).',
        sources: ['store', 'real'],
    };
}

export function whyMarginPct(): WhyThisNumberData {
    return {
        title: 'How Avg Margin % is computed',
        formula: 'Avg Margin % = Total Contribution Margin ÷ Total Revenue × 100',
        sources: ['store', 'real'],
    };
}

export function whyRealProfit(): WhyThisNumberData {
    return {
        title: 'How Total Real Profit is computed',
        formula: 'Real Profit = Revenue − COGS − Total Ad Spend',
        sources: ['store', 'facebook', 'google', 'real'],
    };
}

export function whyOrganicRevenue(): WhyThisNumberData {
    return {
        title: 'How Organic Revenue is computed',
        formula: 'Organic Revenue = Sum of order totals where attribution channel_type = "organic_search"\n\nBased on UTM attribution parsed by AttributionParserService.',
        sources: ['store', 'gsc', 'real'],
    };
}

// ─── Paid ads metrics ───────────────────────────────────────────────────────

export function whyAdSpend(): WhyThisNumberData {
    return {
        title: 'How Total Ad Spend is computed',
        formula: 'Ad Spend = Sum of spend reported by all connected ad platforms (Meta + Google Ads) converted to reporting currency',
        sources: ['facebook', 'google'],
    };
}

export function whyAttributedRevenue(): WhyThisNumberData {
    return {
        title: 'How Attributed Revenue is computed',
        formula: 'Attributed Revenue = Revenue from orders where utm_source matches a paid ad platform (facebook, google)\n\nBased on UTM parameters, not pixel attribution.',
        sources: ['store'],
    };
}

export function whyPlatformRoas(): WhyThisNumberData {
    return {
        title: 'How Platform ROAS is computed',
        formula: 'Platform ROAS = Platform-reported conversion value ÷ Ad Spend\n\nThis is what Meta / Google report. May include modeled iOS14+ conversions that inflate the number vs Real ROAS.',
        sources: ['facebook', 'google'],
    };
}

// ─── Organic Search (GSC) metrics ───────────────────────────────────────────

export function whyAvgCtr(): WhyThisNumberData {
    return {
        title: 'How Avg CTR is computed',
        formula: 'Avg CTR = Total Clicks ÷ Total Impressions × 100\n\nWeighted across all queries and pages for the selected property and date range.',
        sources: ['gsc'],
    };
}

export function whyAvgPosition(): WhyThisNumberData {
    return {
        title: 'How Avg Position is computed',
        formula: 'Avg Position = Impression-weighted average ranking position across all queries\n\nLower is better. Position 1 = top of Google Search results. Provided by Google Search Console API.',
        sources: ['gsc'],
    };
}

// ─── Site Performance metrics ───────────────────────────────────────────────

export function whyPerformanceScore(): WhyThisNumberData {
    return {
        title: 'How Performance Score is computed',
        formula: 'Performance Score = Lighthouse v10 lab score (0–100) for your homepage using the mobile strategy\n\nPowered by PageSpeed Insights API. Score ≥ 90 = good, 50–89 = needs improvement, < 50 = poor.',
        sources: ['site'],
    };
}

export function whyLcp(): WhyThisNumberData {
    return {
        title: 'How LCP is measured',
        formula: 'LCP = Largest Contentful Paint — time until the largest visible element finishes loading\n\nTarget: ≤ 2.5 s (good), ≤ 4.0 s (needs improvement), > 4.0 s (poor). Measured by Lighthouse.',
        sources: ['site'],
    };
}

export function whyCls(): WhyThisNumberData {
    return {
        title: 'How CLS is measured',
        formula: 'CLS = Cumulative Layout Shift — total unexpected layout movement during page load\n\nTarget: ≤ 0.1 (good), ≤ 0.25 (needs improvement), > 0.25 (poor). Lower = more stable.',
        sources: ['site'],
    };
}

// ─── Countries metrics ──────────────────────────────────────────────────────

export function whyTopCountryShare(): WhyThisNumberData {
    return {
        title: 'How Top Country Share is computed',
        formula: 'Top Country Share = Revenue of the largest country ÷ Total Revenue × 100',
        sources: ['store'],
    };
}

export function whyAboveAvgMargin(): WhyThisNumberData {
    return {
        title: 'How Above Avg Margin is computed',
        formula: 'Above Avg Margin = Count of countries whose contribution margin per order exceeds the workspace-wide average\n\nRequires COGS data.',
        sources: ['store', 'real'],
    };
}

export function whyProfitableRoasCountries(): WhyThisNumberData {
    return {
        title: 'How Profitable ROAS Countries is computed',
        formula: 'Profitable ROAS Countries = Count of countries where Real ROAS ≥ 1.0\n\nReal ROAS ≥ 1.0 means ad spend is at least covered by store revenue for that country.',
        sources: ['store', 'facebook', 'google', 'real'],
    };
}

// ─── Daily metrics ──────────────────────────────────────────────────────────

export function whyYesterdayRoas(): WhyThisNumberData {
    return {
        title: "How Yesterday's Real ROAS is computed",
        formula: "Real ROAS = Store Revenue for yesterday ÷ Total Ad Spend for yesterday",
        sources: ['store', 'facebook', 'google', 'real'],
    };
}

export function whyWeekdayDelta(): WhyThisNumberData {
    return {
        title: 'How the Weekday Delta is computed',
        formula: "Weekday Delta = (Yesterday's Revenue − Average revenue on the same weekday in the last 4 weeks) ÷ Average × 100\n\nCompares against same-weekday peers to filter out day-of-week seasonality.",
        sources: ['store', 'real'],
    };
}

// ─── Discrepancy metrics ─────────────────────────────────────────────────────

export function whyRevenueGap(): WhyThisNumberData {
    return {
        title: 'How Revenue Gap is computed',
        formula: 'Revenue Gap = Platform-reported conversion value − Store-attributed revenue\n\nPositive = platforms over-report. Negative = platforms under-report (rare). Driven by iOS14+ modeled conversions.',
        sources: ['store', 'facebook', 'google', 'real'],
    };
}
