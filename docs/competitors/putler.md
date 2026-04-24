# Putler

**URL:** https://putler.com
**Target customer:** SMB and mid-market online sellers, SaaS businesses tracking recurring revenue, and multi-channel sellers who collect money in more than one place (PayPal + Stripe + Shopify, PayPal + WooCommerce, etc.). Not for single-store merchants who only take one payment method — the value unlocks when data is genuinely fragmented. 7,000+ users, $5B+ transaction volume, 60M+ transactions.
**Pricing:** **Metered by monthly revenue** (auto-adjusts each month as revenue slab changes). 14-day free trial, no card required. Unlimited orders and users on all plans.

| Monthly revenue | Price |
|---|---|
| Up to $10K | $20/mo |
| $10K–$30K | $50/mo |
| $30K–$50K | $100/mo |
| $50K–$100K | $150/mo |
| $100K–$200K | $250/mo |
| $200K–$300K | $350/mo |
| $300K–$500K | $500/mo |
| $500K–$1M | $750/mo |
| $1M–$3M | $1,500/mo |
| $3M–$5M | $2,250/mo |

Tier also gates number of data sources: Starter 3 sources (2 years history), Growth 15 sources (5 years). Enterprise custom.
**Positioning one-liner:** "The self-serve BI for ecommerce" — plug in PayPal, Stripe, Shopify, WooCommerce, et al., and get one dashboard that merges, deduplicates, currency-converts, and time-zone-normalizes everything.

## What they do well
- **Unifies disparate payment sources.** The core magic is merging PayPal + Stripe + WC + Shopify into one customer record, one order stream, one revenue number — dedup by email, currency-converted at transaction time, timezone-adjusted. This is genuinely differentiated vs. Metorik/MonsterInsights/Glew which all assume one primary source.
- **Home dashboard is information-dense but two-sectioned ("Pulse" + "Overview") to avoid overwhelming the glance.** Pulse = real-time current-month activity, Overview = date-range-driven period analysis.
- **Live activity feed** aggregates events (sales, refunds, disputes, failed transactions) across all sources chronologically — a Slack-style chronological stream vs. static KPIs.
- **Sales heatmap** (7×24 grid) shows sales density by day-of-week × hour-of-day. Genuinely useful for staffing / ad scheduling.
- **RFM segmentation** is a built-in module (recency × frequency × monetary) — something Metorik notably lacks. Customer segments auto-populated: new, returning, recurring, churned, at-risk.
- **"Top 20%" widgets** applying the 80/20 Pareto framing to customers and products — a recurring Putler meme.
- **Forecasting** — daily and monthly revenue forecasts with ranges, goal tracking.
- **Copilot (AI)** — natural-language query over connected sources.
- **Subscription metrics** (MRR, ARR, churn, upgrade/downgrade) for stores that mix one-time + recurring — customer profile shows both on the same card.
- **Flat transaction cap = none.** Unlimited orders at any tier, which is unusual for a metered product.

## Weaknesses / common complaints
- **Data freshness.** "15-30 minute delay before payments are updated." Reviewers repeatedly call out that it's not real-time despite the "Pulse" branding; some say hours of lag for recent events.
- **Dashboard overwhelming.** "Can sometimes feel overwhelming with so many parameters about sales, products, and customers" — reviewers aren't always sure what a number means or how it was computed. No visible "source of number" explainers.
- **Feature bloat = data accuracy wobbles.** "Because the app has become so feature-rich and complex, users occasionally encounter small bugs or data that seem inaccurately represented." This is the downside of wide source coverage — edge cases accumulate.
- **Missing integrations that hurt.** Reviewers want Facebook Ads, Google Ads (direct, not via GA), Amazon, eBay, Etsy, Shopee, Lazada. So the "unify everything" pitch has gaps.
- **Mobile app is weak.** Users resort to website shortcuts on phones.
- **Cancellation friction.** "Software company has hoops and hurdles to cancel an account." Reputation issue.
- **Pricing opacity on the app itself.** "No clear way to see different plans and pricing from inside the product."
- **Subscription metrics are shallower than dedicated SaaS tools.** Section "could be improved with more stats" — reviewers compare unfavorably to Baremetrics/ChartMogul for SaaS depth.

## Key screens

### Home dashboard (Pulse + Overview)
- **Layout:** Two-section page.
  - **Pulse (top third):** Current-month Sales widget (daily revenue + 3-day trend + monthly forecast range + target progress + YoY %), Activity Log (live chronological feed of sales/refunds/disputes/failures across all sources), Three-Month Comparison (visitors, conversion rate, ARPU, revenue each with Δ%).
  - **Overview (bottom):** Global date picker → Net Sales widget, Customer Metrics (orders, unique customers, ARPPU, disputes, failed), Website Metrics (conversion rate, one-time vs repeat, new acquisition), Subscription Metrics (MRR, churn, active subs), Top 20% Customers widget, Top 20% Products widget.
- **Key metrics shown:** Revenue, orders, customers, AOV, MRR, churn, conversion rate, dispute rate, failed orders, forecast range, YoY %, target progress.
- **Data density:** High but bucketed. The Pulse/Overview split lets the glance be small (Pulse only) or deep (scroll into Overview).
- **Date range control:** Date picker sits in the Overview section, not at the very top — Pulse is always "current month, live." Dual timeframes coexist on one page.
- **Interactions:** Activity Log filters by event type. Each Top-20% widget is clickable to the Customers/Products dashboard. Forecast range is hoverable for prediction confidence.
- **Screenshot refs:** putler.com/ecommerce-dashboard hero; putler.com homepage "Pulse" and "Overview" side-by-side panels.

### Sales dashboard
- **Layout:** KPI strip up top (net sales, daily average, order count, avg revenue/sale). Breakdown chart (net sales + orders over time, switchable days/months/years). **Sales heatmap** 7×24 grid. Full order list at the bottom — each row expandable into a detail card (payment method, customer, products, status).
- **Key metrics shown:** Net sales, orders, AOV, refund rate, by location / product / status / amount bucket.
- **Date range control:** Top-right date picker with prior-period comparison.
- **Interactions:** Four heatmap filters (location, products, status, transaction amount) re-render the heatmap live. Click an order row → expanded detail card inline. Darker cells = more sales.
- **Screenshot refs:** putler.com/sales-heatmap.

### Customers dashboard
- **Layout:** Left: segment list (new, returning, recurring, VIP, RFM quadrants). Right: segment detail — count, avg LTV, avg orders, geo map, demographic breakdown. Customer table below with search + filters. Individual customer profile combines subscription history + one-time purchases on one card.
- **Key metrics shown:** LTV, conversion rate, RFM score, order history, geolocation, subscription + one-time blended view.
- **Interactions:** Split-second search with auto-complete. RFM quadrant chart is clickable → drills into that quadrant's customers.

### Products dashboard
- **Layout:** KPI strip. **Product Leaderboard** (top 20% star-marked). **80/20 Breakdown Chart** visually separating top 20% from the long tail. "Frequently bought together" panel. Variation/category sub-reports. Refund rates per product.
- **Interactions:** Geo performance per product. Drill into variation-level analytics.

### Transactions dashboard
- **Layout:** Chronological transaction list across all sources (PayPal, Stripe, WC, Shopify, etc.) in one table with source badge per row.
- **Key metrics shown:** Amount, fee, net, customer, source, status, date.
- **Interactions:** Filter by source / status / amount / date. Click → transaction detail card.

### Subscriptions dashboard
- **Layout:** KPI strip (MRR, ARR, churn, active subs, upgrades/downgrades). Time-series chart. Subscription list with status.
- **Interactions:** Filter by plan, status, customer.

### Audience dashboard
- **Layout:** GA-powered audience overview (demographics, interests, cross-property view).
- **Key metrics shown:** Sessions, demographics, interests, geolocation.

### Insights dashboard
- **Layout:** Aggregated narrative-style insights — "your conversion rate is down X% this week," "product Y is trending up" — over KPI summary.

### Copilot (AI chat)
- **Layout:** Chat panel. Natural-language input → answer sourced from connected data.

## Integrations
17+ sources:
- **Payment:** PayPal, Stripe, Braintree, Authorize.net.
- **Ecommerce:** Shopify, WooCommerce, BigCommerce, eBay, Etsy, Easy Digital Downloads.
- **Analytics:** Google Analytics.
- **Email:** Mailchimp.
- **Data in:** CSV, API.

## Notable UI patterns worth stealing
- **Pulse + Overview dual timeframe on one page.** The "what's happening right now" frame coexists with "what happened over this date range" without forcing a tab switch. Solves the tension between glance-ability and depth on the home dashboard. Highly applicable to Nexstage.
- **Live activity feed as a dashboard citizen.** Chronological event stream across all data sources, filterable by event type. Great for ops/support teams; gives the dashboard a "pulse" feeling vs. static cards.
- **Source badges on transactions.** Every row shows which source it came from (PayPal / Stripe / Shopify). This is exactly the source-badge pattern Nexstage's trust thesis uses on `MetricCard` — Putler proves the pattern works at the row level too.
- **Dedup + currency-convert + timezone-normalize at ingest.** Treated as infrastructure, not a feature. Nexstage's `fx_rates` cache and WorkspaceSettings are aligned with this approach — worth investing in early.
- **80/20 Pareto chart framing.** Visually separating "the top 20% that drive 80%" from the long tail. Simple, memorable, leads to action.
- **Sales heatmap (7×24).** Re-used across the app with different filter sets. Good compact visualization for temporal patterns.
- **Blended subscription + one-time on customer profile.** A customer who bought a subscription and a one-time add-on shows both on the same card, not split across two systems.
- **Forecast range vs. point estimate.** Sales widget shows a forecast *range* (not a single number) — more honest about uncertainty.
- **Goal progress bar on Pulse.** User sets a monthly target; the widget shows progress toward it. Simple, motivating.

## What to avoid
- **Opaque number provenance.** Reviewers repeatedly say "I'm not sure what this number means or how it was calculated." Nexstage should have explain-this-metric affordances (hover, click-to-drill) on every displayed number.
- **Branding "Pulse" as real-time when data is 15-30 minutes behind.** Either make it real-time (cache-bust + streaming) or rebrand honestly. "Data freshness" badges are required.
- **Feature-bloat-driven data inaccuracy.** Putler's "small bugs or data that seem inaccurately represented" is the symptom of expanding source coverage without matching investment in test coverage. Growth-kill feature gates are warranted.
- **Mobile app neglect.** Users resort to mobile browser shortcuts — the "mobile app" shouldn't be strictly worse than mobile web.
- **Metered-by-revenue pricing jumps that penalize growth.** $20 → $50 → $100 → $150 as revenue passes $10K / $30K / $50K can feel punishing when a merchant has a single big month.
- **Cancellation friction.** Reputation damage outsized to the retention it preserves.
- **RFM quadrant without explainers.** Putler shows RFM segments but doesn't explain *why* a customer fell into a quadrant in most reviewers' recollections. Every segment badge should be hover-explainable.

## Sources
- https://putler.com
- https://putler.com/pricing/
- https://www.putler.com/ecommerce-dashboard
- https://www.putler.com/sales-heatmap
- https://www.putler.com/features/
- https://www.putler.com/putler-features
- https://www.putler.com/docs/category/putler-dashboards/
- https://www.putler.com/docs/category/putler-dashboards/sales/
- https://www.putler.com/docs/category/putler-dashboards/products/
- https://www.putler.com/saas-metrics-dashboard
- https://www.putler.com/metorik-review/
- https://www.capterra.com/p/179100/Putler/reviews/
- https://www.g2.com/products/putler/reviews
- https://reviews.financesonline.com/p/putler/
- https://www.kasareviews.com/putler-review-advanced-reports-metrics-insights-woocommerce/
- https://www.trustpilot.com/review/www.putler.com
