# Northbeam

**URL:** https://www.northbeam.io
**Target customer:** Mid-market to enterprise DTC — $40M+ annual revenue, $250k–$500k+/mo in paid media, dedicated data/growth teams. 800+ customer brands. Sophisticated performance marketers, CFOs, growth leadership. Tolerates a learning curve in exchange for "laser-accurate first-party data."
**Pricing:** Data-volume + service-tier based. No free tier.
- **Starter** — from $1,500/mo. For <$1.5M/yr media spend. Month-to-month, usage-based on data volume. Shopify direct only.
- **Professional** — custom (publicly cited ~$2,500/mo). For $250k+/mo media spend. Annual terms, flat-rate. Any ecom platform.
- **Enterprise** — custom. For $500k+/mo media spend. Annual or semi-annual. Dedicated strategist, MMM+, incrementality testing, quarterly reviews.
- **Drivers:** pageview volume + data-processing frequency + support tier. The pageview-based model is a frequent complaint — a mid-market brand with lots of traffic but modest ad spend pays disproportionately.
**Positioning one-liner:** "The industry leader in marketing attribution" — independent, first-party, multi-touch performance measurement for profitable growth.

## What they do well
- **Sum-to-100% fractional attribution.** Every touchpoint gets a fractional credit (TikTok 0.6 / FB 0.3 / Snap 0.1 for one order); channel totals cannot exceed actual order count. This is the clean inverse of Triple Whale's permissive model and the core of Northbeam's credibility with CFOs.
- **Clicks + Deterministic Views.** Proprietary model using platform-verified view-through data (not modeled/inferred) — distinct from Meta's "Clicks + Modeled Views". Cited as their deepest technical moat.
- **Attribution window is first-class UI.** Every metric in the app carries a `(d)` or `LTV` suffix — a 3d vs. 7d vs. 30d vs. LTV ROAS is selectable inline, not hidden in settings.
- **Northbeam Apex** — bi-directional integration with Meta (and expanding). Pushes Northbeam's MTA-derived ad-level performance scores back into Meta's bidding algorithm. Average reported 34% CVR lift across a $1.5M-spend study. This is the only truly "activation" feature either tool has; Triple Whale has nothing equivalent.
- **Metrics Explorer** — correlation analysis between any two metrics using Pearson coefficient. Surfaces halo effects (FB spend ↔ Google branded search revenue). Real statistical grounding, not marketing fluff.
- **Profitability Benchmarks** — user-tailored performance targets built from their own historical baseline (minus outliers, adjusted for COGS, excluding non-incremental revenue). Not generic industry averages.
- **Media Mix Modeling (MMM+).** Budget scenario modeling with cost curves and real-time accuracy tracking; includes retail/offline revenue for DTC brands with retail arms.
- **Cross-platform store support.** Shopify + WooCommerce + BigCommerce + Magento + custom builds (vs. Triple Whale's Shopify lock-in).
- **Northbeam 3.0 UI.** ~2x speed improvement, cleaner hierarchy, tooltips embedded in tables, split/stacked line-chart visualizations, full-screen table/graph mode, one-click Metrics Explorer jumps from the Sales page.
- **Clear accounting-mode toggle** (Cash Snapshot vs. Accrual Performance) — addresses the reporting-vs-optimization split explicitly, not papered over.

## Weaknesses / common complaints
- **Steep learning curve.** "Overwhelming at first due to the amount of data and customization." Onboarding is 10+ phases; one G2 reviewer "hadn't been onboarded properly even after a month."
- **Attribution methodology doesn't mirror platform reporting.** Users can't reconcile Northbeam's ROAS with Meta Ads Manager without explanation — creates friction when defending numbers to a boss/client. The inverse of Triple Whale's problem but equally friction-y.
- **Pricing is brutal for mid-market.** Pageview-based pricing "creates a mismatch between cost and what smaller and mid-market teams actually need." Starter at $1,500/mo is 5–10x Triple Whale's Starter.
- **No free tier.** Can't trial without a sales conversation.
- **Customer support inconsistent.** TrustPilot: "appalling customer service", "dismissive toward genuine concerns", "support disappears after closing deals". G2 is kinder (4.7/5 across 14 reviews — small sample).
- **Setup requires DNS record update** (for cross-session tracking) — non-trivial for non-technical founders.
- **Less intuitive than Triple Whale.** G2 comparison: TW scores 8.7/10 ease-of-use vs. NB 8.2/10. NB scores higher on ease-of-admin (9.2/10) once configured — a rare admit that it's harder up-front.
- **Smaller integration surface** (~25 platforms vs. TW's 60+).
- **No post-purchase survey layer** — ceded that ground-truth signal to Triple Whale.
- **Modeled Views require 25–30 days to calibrate** — not usable day one.

## Key screens

### Overview Home Page
- **Layout:** Single-page dashboard with a tile grid. Tiles are add/remove/rearrange-able; layouts can be named, saved, shared across users. Left-side navigation bar with icons (telescope icon = Metrics Explorer, etc.).
- **Key metrics shown (default tiles):** Revenue (1st Time), Revenue (All), ROAS (1st Time) at 1d/7d/30d, CAC (1st Time) at 1d/7d/30d, Spend, New Customers, Returning Customers, Visitors, CPV (cost per visitor), RPV (revenue per visitor), AOV, Transactions, CVR.
- **Data density:** Medium-high. Fewer tiles than Triple Whale's default but each tile carries more configuration (attribution model + window + accounting mode baked into the tile).
- **Date range control:** Preset ranges + comparison selector (Previous Period / Prior Year Same Period / Custom). Granularity: Daily / Weekly / Monthly. Recommended default: Weekly.
- **Global dashboard controls (top bar):**
  - Attribution Model dropdown (Clicks-Only / First Touch / Last Touch / Last Non-Direct / Linear / Clicks+Modeled Views / Clicks+Deterministic Views).
  - Attribution Window (1d / 3d / 7d / 14d / 30d / 90d / LTV).
  - Accounting Mode (Cash Snapshot / Accrual Performance).
  - Granularity (Daily / Weekly / Monthly).
  - These are **global** — set once, every tile respects it. Strong pattern.
- **Interactions:** Drill into any tile; one-click jump to Metrics Explorer on any metric. Conversion Lag chart type available for "what am I spending today vs. future revenue impact".
- **Screenshot refs:**
  - https://dashboard.northbeam.io/ (login gate)
  - https://www.northbeam.io (hero)
  - https://docs.northbeam.io/docs/overview-page

### Sales page (most-used page)
- **Layout:** Top-section chart showing each channel's spend + key metrics over time; below-the-fold is a hierarchical table. Segment tabs at the top: All / New / Returning customers.
- **Table columns (customizable, default set):** Channel, Spend, Revenue (1st Time), Revenue (All), ROAS (1st Time) (d), ROAS (All) (d), New Customers, CAC (1st Time), Transactions, Visitors, CPV, RPV, AOV, CVR. Every formulaic column shows an attribution-window suffix.
- **Hierarchy:** Channel → Campaign → Adset → Ad. Expand/collapse within the table; resembles a standard ad-manager tree but cross-platform.
- **Visualizations:** Line chart + stacked/split line charts (NB 3.0 addition). Full-screen toggle for table and chart. Chart lines can be toggled per channel from the legend.
- **Customer segmentation:** All / New / Returning pill segments rerun the entire table against that cohort.
- **Model swap:** Top-level model dropdown changes the whole page; only one model visible at a time. Model Comparison is a separate modal (hamburger icon).
- **Touchpoints column:** Under Clicks-Only model, shows the most common customer journey for the selected row — e.g., "Facebook → Google → Direct".
- **One-click escape hatch to Metrics Explorer** on any column.

### Creative Analytics
- **Layout:** Grid of "creative cards." Each card has the ad creative preview on top and metrics below. Filter/search bar above; sort controls at the top.
- **Per-card metrics (default):** CTR, CPM, ECR (effective conversion rate), CAC, ROAS, Spend, plus thumbstop-style engagement metrics.
- **Color encoding:** Metrics shown on a red-to-green gradient — red for underperforming relative to the set, green for top performers. Strong at-a-glance pattern.
- **Defaults:** Accrual mode + 1-day window + Clicks+Modeled Views. Default sort is Spend (top-down).
- **Dynamic ads:** No preview image (DPAs, feed-based) — but performance data still populates. Empty-thumbnail handling is explicit.
- **Charts tool:** Select up to 6 creatives → line or bar chart comparing chosen metric over time. This is how users do head-to-head creative tests.
- **Channel coverage:** Facebook, Instagram, Snapchat, TikTok, Google, Pinterest, YouTube — unified view, not a tab per platform.
- **Hide inactive** and **search by ad name** controls are first-class filters.
- **Sharing:** Copy-link preserves the exact filter/sort state. Only works for registered Northbeam users (auth-gated).

### Metrics Explorer
- **Layout:** Left-side correlation tiles + right-side time-series line chart. Tiles show correlation coefficient vs. a primary metric. Click a tile to promote that metric to primary — all other tiles re-compute.
- **Global controls:** Accounting mode, attribution model+window, granularity, time period (60d is a common default).
- **Quick-start templates:** Pre-configured analyses (e.g., "Revenue 1st time & Spend by Platform"). One-click setup for common questions.
- **Save as new view** button at top-right.
- **Statistical method:** Pearson correlation coefficient, surfaced as a number on each tile. Caveat copy: "at least 30 days of data" for statistical significance. Explicit "correlation ≠ causation" disclaimer.
- **Use case:** Halo effect detection — "Facebook spend correlates 0.74 with Google branded search revenue over last 90d."

### Orders (order-level attribution)
- **Per-order journey view.** For each order, shows every touchpoint and how much credit each received under the current model.
- **Platform filter quickfilters** — e.g., "show only orders where Meta got >50% credit".
- **Research use case**: "why did this order get attributed to X?" — accountability surface for spot-checks.

### Product Analytics
- **Layout:** Scatterplot front-and-center. X-axis = CAC Index (1–100 relative), Y-axis = ROAS Index (1–100 relative). Bubble size = ad spend. Four colored quadrants.
  - Green (top-right): high ROAS, low CAC — winners.
  - Yellow (top-left): high ROAS, high CAC.
  - Blue (bottom-right): low ROAS, low CAC.
  - Red (bottom-left): low ROAS, high CAC — losers.
- **View toggle:** Product / Platform / Campaign / Ad — re-renders the scatterplot for each dimension.
- **Quick-analyses buttons** auto-filter the chart to a single quadrant.
- **Supporting table** below the chart; selecting rows highlights bubbles in the scatterplot.
- **Relative indexing (1–100) not absolute thresholds** — clever: compares your assets to each other, not to external benchmarks that rarely apply.

### Apex (Meta integration dashboard)
- Ad-level performance scores that get pushed to Meta's optimizer.
- Monitoring surface showing the lift vs. pre-Apex baseline.
- Primarily a backend feature — less a screen than a pipeline.

### Profitability Benchmarks
- 5-step wizard: baseline selection → COGS adjustment → outlier removal → incrementality filter → real-time comparison dashboard.
- Output is a target line overlaid on actual performance; campaigns tagged as over/under/on target.

### Model Comparison Tool
- Side-by-side table: same rows, columns = different attribution models. Surfaces disagreement between models quantitatively.
- Accessed via hamburger/overflow menu from Sales page.

## Attribution model
Seven models across two tiers. A single global model selector drives the entire app (one model visible at a time per surface — Model Comparison is the exception).

**Simple (single-touch):**
1. First Touch
2. Last Touch
3. Last Non-Direct Touch — closest match to Google Analytics' Last Click.

**Multi-touch (fractional):**
4. Linear — equal credit across all touches.
5. **Clicks-Only** — equal credit across click touchpoints, excludes lower-funnel channels. Their conservative recommended default. Hard click data only, no modeling.
6. Clicks + Modeled Views — ML-inferred view-through credit. Requires 25–30 day calibration.
7. **Clicks + Deterministic Views** — platform-verified view-through (not modeled). Their flagship.

**Design principle:** fractional credit so that summing attributed revenue across all channels never exceeds actual order count. This is the philosophical opposite of Triple Whale.

**Accounting modes:** Cash Snapshot (report-focused, freezes conversions at capture) vs. Accrual Performance (daily optimization, reassigns credit as journeys complete). Both are available; user picks intentionally per use case.

**Windows:** 1d / 3d / 7d / 14d / 30d / 90d / LTV — all selectable inline on any metric carrying a window.

## Integrations (ad platforms + stores)
- **Stores:** Shopify (direct), WooCommerce, BigCommerce, Magento, custom builds.
- **Ad platforms:** Meta, Google Ads, TikTok, Snapchat, X/Twitter, Bing, Amazon Ads, The Trade Desk, Criteo, MNTN, Pinterest, YouTube — ~25 total.
- **Email/SMS:** Klaviyo (+ more).
- **Offline channels:** TV/OOH spend via Metrics Explorer manual import + Offline Channel feature.
- **Outbound:** Apex (Meta), data exports, API, warehouse sync.

## Notable UI patterns worth stealing
- **Global attribution-model + window + accounting-mode selector at the top of every dashboard.** One setting, every tile respects it. Enforces consistency without per-tile configuration. **This is the single pattern I'd copy most directly.**
- **Metric-name suffixes like `ROAS (d)` and `CAC (d)`** that reveal windowed-ness. Typography as documentation — user sees "(d)" and knows attribution-window applies.
- **One-click "open in Metrics Explorer" from any metric** — escape hatch from the curated view into the exploratory view. Great for power users without cluttering the default.
- **Red → green gradient on creative cards.** Relative performance at a glance, no numbers needed for the first pass.
- **1–100 relative index** on the Product Analytics scatterplot — avoids the absolute-benchmark problem. Scatter + quadrants = structural storytelling, not just pretty viz.
- **Correlation tiles with click-to-promote interaction** — click a tile, it becomes the axis. Makes correlation exploration a pinball game, not a form.
- **Accounting mode toggle (Cash vs. Accrual)** as a first-class concept. Acknowledges that "what was your ROAS yesterday?" has two defensible answers. Our Store/Facebook/Google/GSC/Site/Real badges have the same DNA.
- **Touchpoints column showing "most common journey" string** — e.g., "Facebook → Google → Direct". Compact storytelling within a table cell.
- **Tooltips embedded directly in tables** (NB 3.0) for every metric — click the column header, read the definition. Removes the "WTF is NC-ROAS?" problem.
- **Shareable deep-links with filter/sort/model state preserved** — critical for agency/team workflows.
- **Quick-start correlation templates** — canned answers to common questions, one click.
- **Quick-filter quadrant buttons** on the scatterplot ("show me only red / only green") — canned drill-downs on a viz.
- **Profitability Benchmark wizard** — onboarding baseline as a structured flow rather than a settings page.

## What to avoid
- **Don't hide ease-of-use behind a 10-phase onboarding.** Ramp-up structured as Day 1 / Day 30 / Day 90 is useful for enterprise CSMs but punishing for self-serve trial users. Nexstage is SMB — time-to-first-value must be minutes, not weeks.
- **Don't require DNS changes to activate tracking.** Pixel-only install is a hard requirement for the SMB segment.
- **Don't diverge hard from platform reporting without making the delta visible.** Northbeam's fractional credit is defensible mathematically but inexplicable to founders who see Meta Ads Manager daily. Our "Store vs. Facebook vs. Real" badges should make the delta the feature, not a footnote.
- **Don't gate a free trial behind sales.** The absence of a free tier is the #1 reason SMB buyers skip Northbeam.
- **Don't price on pageviews.** It decouples cost from value (a content-heavy SEO brand pays more than a pure-paid brand with the same revenue). Tie pricing to the value metric, which for Nexstage is likely workspaces/stores + ad spend, not pageviews.
- **Don't show only one attribution model at a time by default.** Surfaces like Sales page force users to remember they're viewing a single lens. Nexstage's six-source badges should be visible side-by-side on the same card.
- **Don't bury Model Comparison behind a hamburger menu.** Comparing models IS the differentiator; it should be above the fold, not a secondary modal.
- **Don't require 25–30 days of calibration to make a model usable.** Any attribution model that needs a month of warm-up is dead-on-arrival for trial users.
- **Don't skimp on support at higher price points.** Paying $1.5k–$2.5k/mo and getting unresponsive support is the fastest way to churn. (Also true for TW, but NB's price point amplifies it.)

## Sources
- https://www.northbeam.io
- https://www.northbeam.io/pricing
- https://www.northbeam.io/attribution
- https://www.northbeam.io/apex
- https://www.northbeam.io/features/creative-analytics
- https://www.northbeam.io/features/metrics-explorer
- https://www.northbeam.io/features/sales-attribution
- https://www.northbeam.io/blog/discover-clicks-deterministic-views
- https://www.northbeam.io/blog/multi-touch-attribution-models-guide
- https://www.northbeam.io/product-news/announcing-metrics-explorer-correlation-analysis-in-northbeam
- https://www.northbeam.io/product-news/northbeams-profitability-benchmarks-tailored-performance-targets-for-your-business
- https://docs.northbeam.io/docs/navigating-northbeam
- https://docs.northbeam.io/docs/overview-page
- https://docs.northbeam.io/docs/sales
- https://docs.northbeam.io/docs/creative-analytics
- https://docs.northbeam.io/docs/product-analytics
- https://docs.northbeam.io/docs/attribution-models
- https://docs.northbeam.io/docs/attribution-windows
- https://docs.northbeam.io/docs/credit-allocation-examples
- https://docs.northbeam.io/docs/metrics-explorer-quickstart-guide
- https://docs.northbeam.io/docs/metrics-explorer-best-practices-7-tips
- https://docs.northbeam.io/docs/northbeam-30
- https://docs.northbeam.io/docs/northbeam-apex
- https://docs.northbeam.io/docs/create-a-dashboard
- https://info.northbeam.io/knowledge/northbeam-attribution-models-clicks-only
- https://www.g2.com/products/northbeam/reviews
- https://www.trustpilot.com/review/northbeam.io
- https://weberlo.com/reviews/northbeam
- https://bestecomsoftware.com/northbeam-review/
- https://www.headwestguide.com/triple-whale-vs-northbeam
- https://www.headwestguide.com/tools/northbeam
- https://www.smbguide.com/northbeam-vs-triple-whale/
- https://www.g2.com/compare/northbeam-vs-triple-whale
- https://www.youtube.com/watch?v=eKzVi8wWE3E ("Breakdown Manager Walkthrough | Northbeam")
- https://www.youtube.com/watch?v=uJMocV_YttE ("How to Use the Northbeam Sales Page")
- https://www.businesswire.com/news/home/20260407939509/en/Northbeam-Launches-Northbeam-Incrementality-Setting-a-New-Standard-for-Advertising-Measurement
