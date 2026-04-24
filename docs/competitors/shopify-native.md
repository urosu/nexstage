# Shopify Native Analytics

**URL:** https://www.shopify.com/analytics — feature marketing | https://help.shopify.com/en/manual/reports-and-analytics/shopify-reports — help docs
**Target customer:** Every Shopify merchant. This is the baseline every user already has on day 1 when they install Nexstage. Ranges from a one-person side hustle on Basic ($39/mo) to a $100M+ Shopify Plus brand ($2,300/mo+).
**Pricing:** Included in every Shopify plan; feature depth scales with subscription tier. (Not a standalone SKU.)
- Basic: $39/mo — Overview dashboard, Finance reports, limited Sales/Customer/Behavior reports
- Shopify (Standard): $105/mo — adds full Acquisition, Behavior, Marketing, Customer reports
- Advanced: $399/mo — unlocks Custom reports, Profit reports, Inventory forecasting, scheduled exports
- Plus: $2,300/mo+ — full suite, ShopifyQL everywhere, API access, benchmark cohorts, higher rate limits

**Positioning one-liner:** "Real-time and reliable data about your store, no set-up required." The baseline that costs nothing marginal and is already installed — the competitor you don't choose, you inherit.

## What they do well
- **Zero-setup.** Data is live from day one, no OAuth flows, no mapping UTMs, no attribution model picker.
- **Fast.** Overview dashboard data is fresh within ~1 minute; Live View is truly real-time.
- **Customizable Overview dashboard.** Drag-and-drop metric cards (numeric or with spark-graph), add/remove/resize — one of the best built-in dashboard-builder UX in SaaS.
- **Live View.** Geographic globe with dots for active sessions, open carts, recent orders. Genuinely fun for flash-sale / Black Friday monitoring. Nothing on the market matches this particular vibe.
- **Benchmarks.** Shopify can compare your store to anonymized peer cohorts (same vertical, similar size). Impossible for any third-party tool to replicate — they literally own the population data.
- **ShopifyQL + Sidekick AI.** Real-time custom querying via a SQL-ish language, with Sidekick AI writing the query for you from natural language prompts. Modern, flexible, and improving monthly.
- **Report breadth.** 60+ prebuilt reports across Acquisition, Behavior, Customers, Finance, Fraud, Inventory, Marketing, Orders, Profit, Retail Sales, Sales. Everything a CFO or ops person needs is somewhere.
- **Reliability.** It's Shopify's own data, so discrepancies between the store DB and the report are smallest here. (Though not zero — see weaknesses.)

## Weaknesses / common complaints
- **Last-click attribution only.** Single-touch. Cannot show multi-touch customer journeys. This is the single biggest limitation for merchants running paid media.
- **No ad-spend.** Shopify doesn't connect to Meta / Google / TikTok ad accounts for cost data. You can see sessions from a channel but never ROAS, CPA, or spend-vs-revenue without a third-party tool.
- **~60% of traffic shows as "Direct"** for many stores because of cookie consent, iOS privacy, missing UTMs, and mobile-app-to-browser handoffs. Users don't trust the channel breakdown.
- **Data delays during peak.** During BFCM / flash sales, Overview metrics can lag 1-24 hours. Widely reported on forums.
- **Refund tracking inflates revenue.** Returns/refunds aren't reflected cleanly enough — bank deposits undercut Shopify's revenue number by 15-25% for many stores.
- **Custom reports gated behind Advanced ($399/mo) or Plus.** Most SMBs on Basic/Shopify cannot build their own reports — they get prebuilt ones only. Popular reports have been quietly moved to "custom" over the last two years, forcing upgrades.
- **Profit reports gated behind Advanced.** Means COGS-aware profitability is locked out of the starter tiers entirely.
- **No financial system integration.** Can't tie to Xero / QuickBooks / NetSuite for ground-truth revenue.
- **No cross-platform / WooCommerce view.** If a merchant runs Shopify + WooCommerce (or two Shopify instances), they see two siloed dashboards.
- **UI can feel "overkill"** for sub-$50k/mo stores — they just want total revenue and top product, and instead see 10 report categories.
- **Report discovery is poor.** The Reports tab lists dozens of pre-built reports in long categorized lists; finding the right one is a chore. Search helps but is weak.

## Key screens

### Shopify Admin Home (the real homepage for most merchants)
- **Layout:** Top: "daily tasks" inbox (abandoned carts, low stock, unfulfilled orders). Middle: **Home metrics** — up to 8 metric tabs the user picks from a menu of 30+ options (Total sales, Sessions, Orders, Conversion rate, AOV, Returning customer rate, Total sales by channel, Sales over time, Top products by units, etc). Bottom: recent activity feed, channel-specific cards, onboarding suggestions for new stores.
- **Key metrics shown:** Last-30-days Total Sales is the default anchor. Each metric tab shows current value + sparkline + % change vs prior period.
- **Data density:** Low to medium — home is intentionally skimmable. This is what 90% of Shopify merchants look at daily; most never go to the Analytics tab.
- **Date range control:** Per-metric-tab date range (you can set different ranges per card). Defaults to last 30 days.
- **Interactions:** Swap any metric tab via the gear icon → select from menu. Click a metric to jump to the full report. Channel dropdown filters all cards.
- **Screenshot refs:** https://help.shopify.com/en/manual/shopify-admin/shopify-home
- **Important:** This is what our users compare Nexstage against on day 1. If a merchant's first impression of Nexstage is slower, emptier, or less actionable than Shopify Home, we lose them.

### Analytics → Overview Dashboard
- **Layout:** Grid of metric cards (user-customizable), drag-and-drop reorderable. Top of page: global date range with compare-to-period toggle. Cards are numeric or include a trend graph.
- **Default cards:** Total sales, Online store sessions, Online store conversion rate, Average order value, Returning customer rate, Total orders, Sales by channel, Sessions by traffic source, Top products by units sold, Top landing pages, Top referrers, Sessions by location, Sessions by device type, Online store sessions by social source.
- **Data density:** High — 12-20 cards by default.
- **Date range control:** Global picker (Today, Yesterday, Last 7d, 30d, 90d, Year, Custom) with "compared to" toggle (previous period / year ago).
- **Interactions:** Click any card to open the underlying full report with pivotable columns. Drag to reorder. Remove / add cards from a gear menu.
- **Screenshot refs:** https://help.shopify.com/en/manual/reports-and-analytics/shopify-reports/overview-dashboard

### Analytics → Reports
- **Layout:** Left-nav list of categories (Acquisition, Behavior, Customers, Finance, Fraud, Inventory, Marketing, Orders, Profit, Retail Sales, Sales). Right pane shows the report: header with title + date range + export + save-as-custom. Body: one or two charts on top, filterable data table below with sortable columns.
- **Report depth varies by plan.** Basic users see a subset; Advanced/Plus see everything plus "Create custom report" button.
- **Interactions:** Filter by column values (e.g., product, channel, country), sort any column, group by dimensions, export CSV, save customized views (Advanced+), schedule email delivery (Advanced+).
- **Known reports include:**
  - **Acquisition:** Sessions by traffic source, Sessions by referrer, Sessions by location, Sessions by device, Sessions by landing page, New vs returning customers
  - **Behavior:** Top online store searches, Top products by views, Online store conversion over time, Sessions by device, Cart analysis
  - **Marketing:** Sales attributed to marketing, Sessions by UTM campaign, Conversion by marketing (attribution)
  - **Customers:** First-time vs returning sales, Customers over time, Customer cohort analysis (Plus / Advanced emphasis), Returning customers, At-risk customers, Loyal customers
  - **Finance:** Finance summary, Sales, Gross sales, Net sales, Taxes, Payments, Liabilities, Tips
  - **Profit (Advanced+):** Gross profit, Profit by product, Profit by channel, Margin
  - **Inventory:** ABC analysis, Sell-through rate, Days of inventory remaining, Product sell-through
  - **Sales:** Sales over time, Sales by product, Sales by channel, Sales by traffic referrer (attribution), Sales by discount

### Live View
- **Layout:** Full-screen 3D globe with glowing dots for active visitors. Left rail: counters (Visitors right now, Total sessions today, Total sales today, Customer behavior counters — viewed a product, added to cart, reached checkout, purchased). Right rail: live feed of orders and top locations.
- **Data density:** Low, visceral, designed to sit on a boardroom TV during Black Friday.
- **Interactions:** Zoom/rotate globe; click a dot to see the city/region. No real drill-down — it's a "vibe" dashboard.

### ShopifyQL + Sidekick AI (Advanced / Plus)
- **Layout:** SQL-like query editor on top, result table and auto-chart below. Sidekick AI sidebar where users type natural-language prompts that generate ShopifyQL.
- **Interactions:** Write a query or ask Sidekick "show me top products by profit last quarter for customers in the UK". Execute → get table + auto-chart → save as custom report → add to dashboard.
- **Critical note for Nexstage:** This is rapidly closing the gap with third-party tools for analyst-grade merchants. The "Shopify Analytics is too basic" argument weakens every release.

## The "angle" — what makes them different
Shopify Analytics is **"free, fast, and already there."** Its angle isn't a differentiated product — it's inertia. Every Shopify merchant is already logged in, already has the data populated, and paying nothing marginal for the feature. That's a brutal anchor to compete against, especially for SMB merchants who don't realize what they're missing in attribution depth.

What it *doesn't* have, and can't easily get:
- **Ad-spend data.** Shopify is not going to connect your Meta ad account anytime soon — that's the third-party wedge.
- **Cross-platform.** Shopify will never show WooCommerce alongside Shopify.
- **Honest multi-source comparison.** Shopify cannot tell you "Meta says 40% of revenue but only 20% actually shows matching store orders" — they're the store, not the ad platform, and the political incentive to surface that disagreement doesn't exist.

Nexstage's trust thesis (six source badges, platforms-vs-store disagreement as the headline) is specifically the thing Shopify structurally cannot build. Every marketing screenshot should contrast a Nexstage view against a Shopify view that's missing half the context.

## Integrations
- Shopify data is native (no integration needed).
- Shopify App Store has ~100 analytics apps that plug into the Admin Overview dashboard as custom cards — a real extensibility surface.
- Shopify Inbox, Shopify Email, Shop App, POS all pipe data in.
- **Ad platforms:** Not integrated for spend data. You see sessions-from-Facebook but not dollars-spent-on-Facebook.
- **Warehouse/BI:** API access for Plus customers; otherwise export-only via CSV.

## Notable UI patterns worth stealing
- **Customizable metric-card grid on the admin Home.** Drag-drop, per-card date range, user-picked from a menu of 30+ options. This is one of the best dashboard-builder UX patterns in SaaS — worth directly modeling Nexstage's home against.
- **"Compared to previous period" as a first-class toggle**, not buried in settings.
- **Spark graphs inside each KPI card.** Tiny, readable, dense — don't skip them.
- **Click-through from metric card to full report with the same date range preserved.** Critical UX continuity.
- **Live View as a vibe piece.** SMBs love the spinning globe on a TV during BFCM. Not actionable, but creates emotional attachment to the product. Nexstage should have an equivalent.
- **Report categories in the left nav** (Acquisition / Behavior / Marketing / etc). The categorization is the taxonomy — use the same words the user already learned from Shopify.
- **"Create custom report" as a button inside the report view.** Saves a user from a blank-slate builder — start from a template, modify, save.
- **Sidekick AI natural-language → ShopifyQL query.** Nexstage doesn't need ShopifyQL but should have an equivalent "ask the AI" escape hatch.

## What to avoid
- **Don't duplicate Shopify's tabs without adding value.** If the user sees the same "Sales over time" chart in Nexstage that's one click away in Shopify, they'll stop opening us. Every screen needs to do something Shopify can't (cross-source reconciliation, ad-spend overlay, multi-platform join).
- **Don't hide everything behind plan tiers.** The biggest complaint about Shopify is the "oh that report is Advanced-only" paywall. Our SMB promise is "the analytics Shopify keeps taking away from you."
- **Don't assume users will click into Analytics → Reports.** Most merchants live on the admin Home; anything that requires them to leave feels expensive. Nexstage home page needs to deliver top insights without deeper clicks.
- **Don't let the Reports list get unmanageably long.** Shopify has ~60 reports. Users can't find what they want. Ship 10-15 opinionated views, not 60 permutations.
- **Don't build a Live View clone.** Shopify owns the vibe here, and replicating it without the real-time-visitor data stream (which we don't have direct access to without a pixel) will feel lame. If we do one, anchor it to something Shopify can't show — ad-spend pacing, attribution disagreement in real time, etc.
- **Don't pretend Sidekick isn't coming.** Assume Shopify ships a good-enough AI analyst in the next 12 months. Nexstage's AI layer has to be differentiated by *data scope* (cross-platform, cross-source), not by chat interface quality.

## Sources
- https://www.shopify.com/analytics
- https://help.shopify.com/en/manual/reports-and-analytics/shopify-reports
- https://help.shopify.com/en/manual/reports-and-analytics/shopify-reports/overview-dashboard
- https://help.shopify.com/en/manual/reports-and-analytics/shopify-reports/report-types
- https://help.shopify.com/en/manual/shopify-admin/shopify-home
- https://plausible.io/blog/shopify-analytics
- https://analyzify.com/shopify-analytics
- https://ask-luca.com/blogs/shopify-analytics-guide
- https://ask-luca.com/blogs/shopify-analytics-dashboard-explained
- https://reportgenix.com/shopify-analytics-issues-2025/
- https://www.sarasanalytics.com/blog/shopify-reports
- https://www.sarasanalytics.com/blog/shopify-analytics-dashboard
- https://trueprofit.io/blog/shopify-vs-shopify-plus
- https://www.richpanel.com/blog/shopify-vs-shopify-plus
- https://improvado.io/blog/shopify-dashboard
