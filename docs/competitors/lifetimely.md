# Lifetimely

**URL:** https://lifetimely.io (redirects to https://useamp.com/products/analytics). Product now marketed as "Lifetimely by AMP" or "AMP Analytics" after the AfterShip/AMP acquisition.
**Target customer:** Shopify (primary) and Amazon DTC stores, roughly solo-founder up through ~$25M+ GMV. Free tier caps at 50 orders/month — genuinely usable by small/new stores — and tiers scale to unlimited. Strong with profit-focused founders, performance marketers who want MER/CAC, and subscription/repeat brands. Less of a fit for big enterprise than Polar.
**Pricing:** Transparent monthly tiers based on **monthly order volume** (not GMV):
- **Free** — $0, 50 orders/mo, all features included.
- **M** — $149/mo, 3,000 orders/mo.
- **L** — $299/mo, 7,000 orders/mo + Silver support.
- **XL** — $499/mo, 15,000 orders/mo + Gold support (marked "most popular").
- **XXL** — $749/mo, 25,000 orders/mo + Platinum support.
- **Unlimited** — $999/mo, unlimited orders + personalized setup.
- **Amazon add-on** — +$75/mo.

All paid tiers include identical features (Daily P&L, LTV, CAC, Customer Journey, Custom Dashboards, Attribution, Sales Forecasting, Ask Amp AI). Differentiator between tiers is **order volume** and **support tier**, not feature gating.

**Positioning one-liner:** "Understand true profit across your business, from ads to repeat purchases." Historically "The Shopify analytics tool built for founders." Now under AMP framed as the "profit + LTV" layer of a broader AfterShip commerce suite.

## What they do well
- **Best-in-class cohort reporting for a Shopify app.** Reviewers specifically call it out. Breakdowns by first purchase date, first product purchased, acquisition channel, geography, discount code used, customer/order tag. Each cohort shows cumulative revenue per customer, repeat purchase rate, CAC payback period.
- **Pragmatic, founder-friendly P&L.** Real-time income statement with revenue → costs → marketing → operating expenses → net profit. Granular cost breakdown filters (orders, products, shipping, stores, custom costs).
- **LTV forecasting UI.** User picks a period, reference cohort, expected growth %, marketing spend, and CAC; Lifetimely projects revenue using historical LTV curves. Scenario-test different growth/spend assumptions. Forecasts can be locked as goals, added to dashboards, or exported CSV.
- **3/6/9/12-month LTV average benchmarks.** Lets a store see whether it's pacing above or below last year's cohorts at the same cohort-age.
- **Predictive LTV via Amp AI.** ML-driven predicted-LTV for future cohorts, not just historical rollups.
- **Transparent pricing with free tier.** Genuine free plan (50 orders/mo) and clear $149 → $999 ladder. Founders can decide without a sales call — a huge contrast with Polar and Peel.
- **Customer support reputation.** Reviewers name specific CSMs (Sam, Amar, Manny C.). Same-day email, immediate chat. 4.8 rating across 481 Shopify reviews.
- **Longevity.** Long-term users (5+ years) cite reliability across app updates and acquisitions — trust capital we don't have.
- **Drag-drop custom dashboards** with role templates (Founder, CFO, Performance Marketer, etc.), unlimited dashboards, scheduled email reports.

## Weaknesses / common complaints
- **"Add-ons cause the product to feel limiting."** Some capabilities locked behind Amazon/support add-ons, users feel nickel-and-dimed on scale.
- **Daily reports have intermittent minor glitches** — occasional stale data or missing numbers.
- **UI looks somewhat dated** vs newer-generation competitors (Peel, Triple Whale). Functional over beautiful.
- **Custom report builder less flexible than Polar's** — you can filter/slice cohorts and LTV, but there's no full-featured metric builder spanning every data source.
- **Attribution is basic.** Multi-touch exists but nowhere near Polar's first-party pixel + side-by-side platform view.
- **Data freshness lags slightly** (hourly updates during trading, not real-time sub-minute).
- **Amazon support is an add-on, not included** — small but real friction.
- **AMP acquisition concerns** — some longtime Lifetimely users worry about product direction, pricing changes, bundling into AfterShip.
- **No Snowflake or SQL access.** Closed black box compared to Polar's warehouse-native model.

## Key screens

### Dashboard / home (Home Overview)
- **Layout:** Left sidebar (Home, P&L, LTV, Cohorts, Attribution, Custom Dashboards, Forecasts, Settings). Top bar carries global date range + comparison. Main pane is a grid of KPI cards above a larger chart.
- **Key metrics shown:** Revenue, Gross Profit, Net Profit, Ad Spend, MER (Marketing Efficiency Ratio), ROAS, CAC, Blended CAC, New vs Returning Revenue, AOV, Order Count, Repeat Rate.
- **Data density:** Medium. More opinionated / curated than Polar; fewer elements above the fold.
- **Date range control:** Preset ranges (Today, Yesterday, WTD, MTD, QTD, YTD, Last 7/30/90 days, Custom) with YoY and previous-period comparison toggle.
- **Interactions:** Click any KPI card → drill into the corresponding dedicated page (P&L, LTV, Attribution). Hover tooltips show metric definition + formula. Ask Amp AI input sits near the top for natural-language queries.
- **Screenshot refs:**
  - https://apps.shopify.com/lifetimely-lifetime-value-and-profit-analytics (Shopify listing has 8+ screenshots)
  - https://useamp.com/products/analytics/

### P&L / Income Statement
- **Layout:** Tabular income statement — rows are line items (Revenue, Refunds, Net Revenue, COGS, Shipping Costs, Transaction Fees, Marketing Spend by channel, Operating Expenses, Custom Costs, Gross Profit, Net Profit), columns are time periods (days, weeks, months) with a totals column.
- **Not a waterfall chart** — it's a classic accounting layout, which is deliberate: CFOs/founders recognize it at a glance.
- **Drill-down:** Click a row → see contributing transactions (e.g. click "Shipping Costs" → list of orders + shipping charge per order). Click a column header → filter all rows to that period.
- **Granular cost breakdown filters:** View costs by orders / products / shipping / stores. Custom cost categories configurable.
- **Daily refresh.** Exportable to CSV and email.
- **Screenshot refs:**
  - https://useamp.com/products/analytics/profit-loss
  - https://help.useamp.com/collection/637-lifetimely

### LTV dashboard
- **Layout:** KPI strip at top (Average LTV, 3/6/9/12-month LTV, LTV:CAC ratio, CAC Payback Period), followed by the LTV cohort table, followed by the "LTV Drivers" report.
- **Cohort table:** Rows = acquisition month (cohort), columns = months since acquisition (0, 1, 2, 3, …), cells = cumulative revenue per customer. Color-scale heatmap overlay. Toggleable to show retention %, repeat rate, order count per customer.
- **LTV Drivers report:** Tabular — lists which products / product types / discount codes / countries / customer tags / order tags correlate with highest and lowest LTV. Each row shows LTV, customer count, CAC, LTV:CAC ratio.
- **Breakdowns via dropdown:** First-purchase date, first product purchased, acquisition channel, geography, discount code, order tag, customer tag.
- **LTV averages dashboard:** 3, 6, 9, 12 month averages with YoY comparison so users can see whether current cohorts are pacing above last year's.
- **Screenshot refs:**
  - https://useamp.com/products/analytics/lifetime-value

### Cohort analysis
- Separate view from LTV but shares the cohort-table component. Focus here is on **behavior** (retention %, repurchase rate, order frequency) rather than **revenue**.
- Same row/column structure (acquisition month × age in months/weeks).
- Filters for segment (customer tag, channel, product).

### Forecast / sales forecast screen
- **Layout:** Left-pane form configuration (Period, Reference data, Expected growth %, Marketing spend, CAC), right-pane live-updating forecast chart + KPI callouts (Projected Revenue, Projected Profit, Projected LTV).
- **Scenario comparison:** Save multiple forecasts side-by-side. Lock a forecast as "goal" → appears as a target line on your dashboards.
- **Actions:** Save, export CSV, add widget to dashboard, delete.

### Attribution dashboard
- Multi-touch attribution across Meta, Google Ads, TikTok, Klaviyo, Amazon, etc.
- Tabular channel-by-channel ROAS, CAC, spend, revenue, new customers. Less sophisticated than Polar's side-by-side platform comparison — no first-party pixel, no "platform vs store" disagreement surface.

### Custom dashboard builder
- **Layout:** Left-side widget library (metrics list grouped by category — Acquisition, Retention, Finance, Ecom Performance) + canvas on the right.
- **Drag-drop widgets onto a grid,** resize handles on each widget. Widget types: KPI card, line chart, bar chart, table.
- **Unlimited custom dashboards.**
- **Role templates:** Founder, CFO, Performance Marketer — pre-built starting points.
- **Email scheduling:** Daily/weekly email with dashboard snapshot.
- **Screenshot refs:**
  - https://useamp.com/products/analytics/dashboard

### Ask Amp AI
- Natural-language chat surface. Examples: "What was my CAC by channel last month?", "Which products drove the highest LTV in Q3?" Returns chart + narrative.

## Attribution model (if they have one)
Standard multi-touch pulled from ad platforms + UTM tagging. No first-party pixel of their own (contrast with Polar's and Triple Whale's). Attribution dashboard is functional but not a headline feature — Lifetimely leans on cohort + LTV as their differentiator, letting ad-platform-reported attribution pass through largely unmodified. MER (Marketing Efficiency Ratio = total revenue / total ad spend) is featured prominently as a blended sanity check that sidesteps attribution debates.

## Integrations
Shopify (primary), Amazon (add-on), Klaviyo, Sendlane, Google Ads, Meta, TikTok, Pinterest, Snapchat, Criteo, ShipStation, ShipBob, Recharge, QuickBooks Online, Google Sheets. Custom data imports supported. More retention/subscription-focused integrations than Polar (Sendlane, Recharge, ShipBob), fewer pure BI connectors.

## Notable UI patterns worth stealing
- **Classic income-statement P&L layout.** Accounting-familiar rows and columns beat a fancy waterfall chart for the founder/CFO persona. Our P&L page should follow this format, not invent a new one.
- **LTV Drivers table.** A plain tabular "what correlates with LTV" view (product × LTV × customer count × CAC × ratio) is more actionable than any chart. We should build one.
- **3/6/9/12-month LTV pacing vs last year.** Cohort-age benchmarks are more useful than cohort-to-cohort comparisons — easy for a founder to understand "my 90-day LTV is pacing 12% ahead of last year's".
- **Drag-drop custom dashboard with role-based starter templates** (Founder, CFO, Performance Marketer). Directly transplantable to our AppLayout.
- **Forecast-as-goal pattern.** Saving a forecast, locking it, then having the lock line appear on dashboards as a target. Turns a one-off projection into ongoing accountability.
- **Named CSMs in support messaging.** Trust-building — "Sam from Lifetimely" is a concrete contact, not an anonymous ticket queue. Worth replicating.
- **Genuinely free tier (50 orders/mo).** Lets founders evaluate without sales friction. Strong counter-positioning to Polar's "contact us" wall.
- **Order-volume-based pricing, not GMV.** For many SMBs (especially low-AOV stores), orders is a more predictable cost axis than GMV. Worth considering as an alternative pricing dimension to GMV.
- **Hover tooltip with metric formula.** Users don't have to trust the number — they can see how it was computed.
- **Amp AI inline in dashboard header.** Natural-language query box anchored right in the normal workflow, not a separate page.

## What to avoid
- **Add-ons that feel like nickel-and-diming.** Amazon as a $75 add-on has been a consistent complaint. Bundle it or make the boundary obvious.
- **Dated visual design.** Lifetimely's aesthetic is "functional 2019". Feature parity isn't enough if the UI feels old.
- **Minor daily-report glitches.** Data freshness and completeness has to be 99.9% — any stale-data complaint corrodes trust, and in an "analytics tool you trust" product, trust is the entire moat.
- **Attribution that's a pass-through of ad-platform numbers.** Worse than no attribution — users think they're getting a unified view but they're just getting Meta's number in a different wrapper. Our first-party attribution + six-source comparison is the stronger answer.
- **Closed black box.** No SQL, no warehouse, no data export beyond CSV. For mid-market this is a deal-breaker; they'll leave for Polar or Triple Whale.
- **"All features included on every paid tier" sounds great but reduces upsell.** Lifetimely's only upsell lever is order volume + support tier, which may be why they bolt on Amazon and AI as separate SKUs.

## Sources
- https://lifetimely.io (redirects to useamp.com)
- https://useamp.com/products/analytics/
- https://useamp.com/products/analytics/profit-loss
- https://useamp.com/products/analytics/lifetime-value
- https://useamp.com/products/analytics/dashboard
- https://useamp.com/pricing
- https://useamp.com/products/analytics/lifetimely-vs-polar-analytics
- https://apps.shopify.com/lifetimely-lifetime-value-and-profit-analytics
- https://apps.shopify.com/lifetimely-lifetime-value-and-profit-analytics/reviews
- https://help.useamp.com/collection/637-lifetimely
- https://www.attnagency.com/blog/lifetimely-shopify-review
- https://letsmetrix.com/app/lifetimely-lifetime-value-and-profit-analytics
- https://acquireconvert.com/app/lifetimely-ltv-profit-by-amp/
- https://www.hulkapps.com/blogs/compare/shopify-profit-calculator-apps-lifetimely-ltv-profit-by-amp-vs-beprofit-profit-analytics
- https://ecomvivid.com/alternatives/lifetimely
- https://www.omniconvert.com/blog/customer-lifetime-value-apps-shopify/
