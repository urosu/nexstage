# BigCommerce Analytics (native + Ecommerce Insights add-on)

**URL:** https://www.bigcommerce.com/product/insights/ (marketing) | https://support.bigcommerce.com/s/article/Analytics-Overview (help)
**Target customer:** Every BigCommerce merchant — the platform's ~47k active stores skew toward $1M–$25M GMV mid-market DTC and B2B, i.e., merchants who looked at Shopify and decided BigCommerce's higher staff-account limits, B2B features, and transaction-fee-free structure outweighed the smaller app ecosystem. Like Shopify Native, this is the inherited analytics surface — the tool every BigCommerce merchant already has when they evaluate Nexstage.
**Pricing:** Native analytics (Store Overview + 10 standard reports) included in all plans. **Ecommerce Insights** is a paid add-on with tier-scaled pricing:
- **Standard** — $39/mo ($29 annual) — native analytics included; Ecommerce Insights costs +$49/mo.
- **Plus** — $105/mo ($79 annual) — adds Abandoned Cart Saver + Customer Groups + stored credit cards; Ecommerce Insights costs +$49/mo.
- **Pro** — $399/mo — adds product filtering, custom SSL, faceted search; Ecommerce Insights costs +$99/mo.
- **Enterprise** — ~$1,000–$2,000+/mo custom — Ecommerce Insights costs +$249/mo.
- GMV ceilings force plan upgrades: $50k TTM for Standard, $180k for Plus, $400k for Pro (plus $150/mo per additional $200k on Pro).

**Positioning one-liner:** "Built-in analytics with a personal data analyst on top (for an extra fee)." Native reports cover the basics; Insights adds recommendations and "rockstar products" — it's Shopify Analytics with a narrower app ecosystem and a paywalled insights layer.

## What they do well
- **Native reporting suite ships with every plan** — 10 standard reports (Store Overview, Orders, Customers, Marketing, Merchandising, Abandoned Carts, Purchase Funnel, In-Store Search, Real-Time, Sales Tax) available from Standard ($39/mo) up. No "custom reports are a premium feature" paywall at the basic tier like Shopify pulls.
- **Store Overview is a solid home dashboard.** Revenue graph + purchase funnel + conversion rate + AOV + orders + visits, all in one view. Clean, skimmable, no chart junk.
- **Purchase Funnel report is the best-in-class bit.** Visitors → product views → add to cart → begin checkout → complete purchase, shown as a vertical funnel with drop-off percentages at each step. No equivalent native report on Shopify — Shopify has "Conversion over time" but not a funnel diagram.
- **Real-Time report** is a live counter of current visitors, add-to-carts, and purchases. Not a 3D globe vibe piece like Shopify's Live View, but genuinely useful for launch/promo war rooms.
- **Customer Groups** (Plus+ plan, native feature, not an Insights add-on) — define groups by purchase history, geography, or custom tag; groups flow through reports and can be filter dimensions. Closer to "segments" than what Shopify offers natively.
- **Abandoned Cart report** is a first-class report from the Standard plan, not gated behind an add-on like many competitors. Shows top abandoned products, cart count, lost revenue.
- **Ecommerce Insights "Rockstar Products" / "Products to Improve" / "Best Customers" / "Customers at Risk" surfaces** are the differentiated piece — the add-on pre-chews the analytics into recommendations rather than forcing the merchant to interpret. It's 2014-era Mint.com-for-your-store vibes, and for non-analyst founders that's genuinely useful.
- **Products Purchased Together** cross-sell analysis inside Insights — market-basket style, "customers who bought X also bought Y" for every top SKU.
- **Customer LTV By Channel** inside Insights — breaks down lifetime value by acquisition channel. Answers "is Meta bringing me one-and-done buyers or repeat customers?" which is exactly the question DTC founders want answered.
- **CSV export on every report** (Customers, Orders, Merchandising, Marketing, Carts, Sales Tax). No scheduled delivery natively, but the export button is where you expect it.
- **No transaction fees** means analytics show revenue = actual cash in, not "revenue minus Shopify's take." One fewer reconciliation step than Shopify.

## Weaknesses / common complaints
- **Reporting is "basic" compared to Shopify** is the single most common complaint on G2, Capterra, and forums. Customization is thin; the same 10 reports with the same 5 filters.
- **Ecommerce Insights is a paid add-on.** The marketing that claims "drive up to 25% more revenue with premium insights" lands sourly with merchants who feel the core platform analytics are too thin and the solution is a second subscription. Plus merchants pay $105 + $49 = $154/mo for functionality Shopify Advanced ships at $229/mo.
- **No custom report builder in the native reports.** You cannot pick your own dimensions and metrics and save a view. Even Ecommerce Insights is a fixed set of dashboards — not configurable.
- **No query language / no SQL escape hatch.** No ShopifyQL equivalent. Analysts who outgrow the native reports export CSVs and move to Excel or a BI tool.
- **Visualization options are limited.** Line chart and funnel diagram on Store Overview; everything else is a table. No heatmap, no cohort matrix, no stacked bar.
- **Antivirus triggers.** Multiple forum threads describe antivirus software flagging the Analytics page as malicious (likely because of embedded analytics scripts); merchants report having to disable AV to use reports. Trust-damaging on the enterprise side.
- **Meta CAPI integration broke post-Feedonomics migration.** Reported on r/bigcommerce mid-2024 — merchants saw CAPI coverage drop to 0% overnight after BigCommerce forced the Feedonomics migration for Meta Shops. Analytics that depend on CAPI-backfilled events were unreliable for months.
- **Abandoned Cart Saver (automation) is Plus+.** The Abandoned Cart *report* is on Standard, but the recovery workflow (emailing abandoners) requires Plus. A trap pattern.
- **Scheduled reports aren't native.** No built-in daily/weekly email of CSVs like Shopify Advanced ships. Third-party apps (Mipler, Beam, Glew) fill the gap for $20–50/mo extra.
- **No benchmarks / peer cohorts.** BigCommerce doesn't surface "your store vs. peer BigCommerce stores" the way Shopify does. They don't have the scale for it to be useful even if they shipped it.
- **Customer LTV by Channel inside Insights doesn't unify with ad spend.** It shows LTV by *acquisition channel* (direct, organic, paid, referral) but not by campaign or ad set — because BigCommerce has no ad-platform integration.
- **API access exists but isn't surfaced via a SQL-like layer.** Exports are CSV; deeper analysis requires pulling raw data and rebuilding the model in your own warehouse.
- **App ecosystem is thinner than Shopify's.** Third-party analytics apps exist (Glew, Polymer, Mipler, Daasity on the enterprise side) but the ecosystem is ~10x smaller. Merchants often have to build custom integrations.
- **Sub-400 Capterra reviews skew negative** on reporting specifically — "data is there but hard to get at" is the recurring theme.

## Key screens

### Store Overview (home)
- **Layout:** Top of admin dashboard. Revenue graph (line chart, date range selectable) spans full width; below it, a row of KPI cards (Orders, Visits, Conversion Rate, AOV). Below that, the **Purchase Funnel** — a vertical funnel diagram showing drop-off from visits → product views → cart → checkout → purchase with conversion % at each stage. Below that, top products and top traffic sources as two side-by-side tables.
- **Key metrics shown:** Revenue (for selected range), Orders count, Visits, Conversion rate, AOV, Items per order, Refunds.
- **Data density:** Medium. ~6 KPI cards + revenue chart + funnel + 2 tables above the fold. Less dense than Triple Whale, slightly denser than Shopify's default Overview.
- **Date range control:** Top-right picker — Today, Yesterday, Last 7/30/90 days, This Month, Last Month, This Year, Custom. Compare-to toggle is present but less prominent than Shopify.
- **Interactions:** Click any KPI to jump to the underlying detailed report. Funnel step clickable → filters the Orders report to that step.
- **Screenshot refs:**
  - https://www.bigcommerce.com/product/insights/
  - https://www.polymersearch.com/blog/a-guide-to-bigcommerce-analytics-what-to-consider

### Orders Report
- **Layout:** Top: line chart of daily orders. Below: filterable data table with columns (Order ID, date, customer, status, item count, total). Left-side filter panel: date range, order status, channel, customer group.
- **Interactions:** Sort any column, filter by status/channel/group, export CSV. Click order ID → order detail page in admin.
- **Limitations:** Cannot group by dimension other than date. Cannot add custom columns.

### Purchase Funnel Report
- **Layout:** Vertical funnel diagram showing each stage as a horizontal bar (width = count of sessions that reached the stage, label = conversion % from prior stage and from the top).
- **Stages:** Visits → Product Views → Adds to Cart → Begin Checkout → Complete Purchase.
- **Date range filter** only; no segmentation by traffic source or device inside this report (a commonly-requested feature on the BigCommerce community forum).
- **Export:** CSV of the raw counts per day.

### Abandoned Carts Report (Standard+)
- **Layout:** Top: summary cards — # abandoned carts, abandoned cart revenue, recovery rate. Below: table of individual abandoned carts with customer email (if logged in), product list, cart total, time of abandonment.
- **Top abandoned products** module below the table.
- **Interactions:** Click a cart → cart detail view. Filter by date, channel.
- **Recovery gating:** The report itself is on Standard; the automated recovery email workflow (Abandoned Cart Saver) is Plus+. You see the problem but can't fix it automatically until you upgrade.

### Customers Report
- **Layout:** New vs. returning split (pie-ish or bar), visits-per-customer distribution, top customers by revenue table.
- **Customer Groups filter** (Plus+) — all Customers-report metrics can be scoped to a group (e.g., "wholesale tier 1", "VIPs").
- **Interactions:** Click a customer → customer detail view in admin.

### Marketing Report
- **Layout:** Visit origin / referrer breakdown — organic search, direct, paid, email, social, referral. Table with sessions + revenue per source.
- **Limitations:** No ad-platform cost data (BigCommerce has no Meta/Google integration for spend). Attribution is last-click, inferred from referrer — the ~60% "Direct" problem is as bad here as on Shopify.

### Ecommerce Insights — Home ($49–$249/mo add-on)
- **Layout:** Card grid of "recommendation modules" — each card is a pre-chewed insight.
- **Modules:**
  - **Rockstar Products** — most-visited, best-converting SKUs
  - **Products to Improve** — worst converters among most-visited
  - **Non-Sellers** — products not selling at all
  - **Most Discounted Products** — and their performance
  - **Hot Products** — largest revenue growth week-over-week
  - **Cold Products** — largest revenue drop week-over-week
  - **Best Customers** — recent frequent high-spenders
  - **Customers at Risk** — high-value customers who last ordered 30–365 days ago
  - **Repeat Purchase Rate** — and time between orders
  - **Customer LTV by Channel** — LTV bucketed by acquisition source
  - **Products Purchased Together** — market-basket pairs
- **Data density:** High — ~10–12 recommendation cards per dashboard.
- **Interactions:** Each card links to a full detail view with the underlying product/customer list.
- **Weakness:** These are the *only* surfaces — you can't build a custom one.
- **Screenshot refs:** https://www.bigcommerce.com/product/insights/

### Real-Time Report
- **Layout:** Live-updating counters — visitors right now, add-to-carts in the last hour, purchases in the last hour.
- **No geographic globe** vibe piece like Shopify Live View. Utilitarian.
- **Refresh:** Auto-updates every ~30 seconds.

## The "angle" — what makes them different
BigCommerce Analytics' angle is **"less UX than Shopify, but the data model is cleaner for multi-channel B2B"**. The native reports respect customer groups, price lists, and B2B channel distinctions that Shopify doesn't have first-class concepts for. If your business has a wholesale tier with different pricing, BigCommerce reports can scope to that group; Shopify merchants would need a third-party app for the same slice.

The second angle — the Ecommerce Insights add-on — is **pre-chewed recommendations rather than a query surface**. It's a fundamentally different philosophy from ShopifyQL Notebooks: Shopify gives mid-market analysts a query language; BigCommerce gives SMB operators a "personal data analyst" that surfaces "Customers at Risk" without the operator needing to know what that means. For the right persona (non-analyst founder, no time), this is valuable; for anyone who wants to ask a different question, it's a wall.

What BigCommerce Analytics **can't** do:
- **Multi-touch attribution.** Last-click-from-referrer only.
- **Ad spend.** No Meta/Google/TikTok cost integration.
- **Custom reports / custom queries.** No builder, no SQL, no notebook.
- **Cross-store.** Multi-storefront Enterprise customers see per-store dashboards only.
- **Cohort analysis at Shopify Plus depth.** Repeat purchase rate is the single cohort-adjacent metric surfaced; no cohort matrix heatmap.

Nexstage's wedge on BigCommerce merchants is the same as on Shopify merchants with extra pressure: BigCommerce merchants feel the native analytics more acutely, because the paywalled Insights tier solves only a subset of what they actually need (and costs extra on top of a plan that's already more expensive than an equivalent Shopify plan at the low tiers). Our "unified analytics for Shopify + WooCommerce" story is a natural extension to "+BigCommerce" if we ever want to expand.

## Integrations
- **Stores:** BigCommerce native data (no integration needed).
- **Ad platforms:** Not integrated natively for spend. BigCommerce has Meta Shops, Google Shopping, TikTok Shop integrations, but those are product-feed-direction, not ad-spend-reporting.
- **Apps:** BigCommerce App Marketplace has ~800 apps total, ~30 in the analytics category (Glew, Daasity, Polymer, Mipler, Beam, Putler, etc.). Smaller than Shopify's analytics category.
- **Warehouse/BI:** API-pull only; no bi-directional warehouse sync. Customers who want Snowflake/BigQuery/Redshift pipe data through Fivetran, Airbyte, or a partner app.
- **Export:** CSV from every report. No native scheduled delivery.

## Notable UI patterns worth stealing
- **Purchase Funnel as a standalone first-class report.** Vertical funnel diagram, drop-off percentages at each step, clickable stages. Shopify Native doesn't have this; Triple Whale doesn't have this; it's a meaningful differentiator. Nexstage's behavior surface should have a funnel chart as a primary visualization, not buried in a custom builder.
- **"Rockstar / Hot / Cold / At Risk" — product and customer buckets with one-word labels.** These are better UX than "top 10 products by conversion rate" — the label tells the merchant what to *do*, not what the number is. Nexstage insight surfaces should borrow this linguistic convention (e.g., "Ads to Kill", "Products to Restock", "Customers to Win Back") rather than raw metric names.
- **Week-over-week hot/cold comparison as a default module.** Always-on "what changed this week" surface rather than a ranked leaderboard. This is the single biggest "should have seen that coming" pattern for ecom operators.
- **Products Purchased Together — market-basket analysis as a card, not a page.** Nexstage should have a "bought together" module on every product-detail surface, computed from `order_items`.
- **Customer Groups as a filter dimension across every report.** If Nexstage ever adds customer segmentation, the segment should be a first-class filter on every page, not a separate "Segments" tab.
- **Revenue-graph-plus-funnel home layout.** The Store Overview pairing of a big revenue line chart + a funnel diagram is the most actionable SMB home screen in ecommerce analytics. More useful than a 20-widget grid for non-analysts.
- **Per-plan add-on pricing transparency (even if painful).** BigCommerce publishes Ecommerce Insights pricing as $49/$99/$249 tiered against your plan. Users hate the paywall, but they at least know the cost. Our pricing page should be equally explicit.

## What to avoid
- **Don't paywall recommendations behind a second subscription.** The single loudest BigCommerce complaint is "I already pay $105/mo and the useful insights are a $49/mo add-on on top." Recommendations (hot products, at-risk customers) are the thing an SMB operator wants by default — put them in the base tier.
- **Don't ship fixed-module dashboards with no customization escape hatch.** Ecommerce Insights is 10 cards and you can't change them. The moment a merchant's business doesn't fit those exact cards, the product has nothing to offer. Nexstage should have opinionated defaults *and* a custom-metric escape hatch.
- **Don't forget scheduled email delivery.** BigCommerce doesn't have it natively; Nexstage should.
- **Don't ship a Real-Time report that's just counters.** BigCommerce's Real-Time report is functional but forgettable. If we ship a "now" surface, make it visceral (Shopify Live View globe vibe) or tie it to something actionable (live attribution, ad pacing vs. real-time revenue).
- **Don't build an Abandoned Cart *report* and a separate Cart Recovery *product* on different pricing tiers.** BigCommerce's split (report on Standard, recovery workflow on Plus) is a textbook frustrating paywall.
- **Don't over-index on "recommendations" if the underlying data is thin.** "Rockstar Products" is compelling only if the ranking accounts for recency, sample size, and margin. Thin rankings (last week's highest-CVR product with 3 sales) erode trust faster than showing raw numbers.
- **Don't let the platform's app ecosystem be the safety valve for analytics gaps.** BigCommerce's strategy of "our analytics are basic, install Glew" is an admission that the core product is under-invested. Nexstage's positioning is we *are* the analytics answer, not a gap-filler.
- **Don't let antivirus / browser-extension issues break the analytics page silently.** If a script blocks render, surface a visible diagnostic, don't just show a blank page. BigCommerce's forum is full of "why is Analytics blank" posts where the answer is "check your AV."

## Sources
- https://www.bigcommerce.com/product/insights/
- https://support.bigcommerce.com/s/article/Carts-Report
- https://support.bigcommerce.com/s/article/Ecommerce-Insights
- https://support.bigcommerce.com/s/article/Analytics-Overview
- https://www.bigcommerce.com/essentials/pricing/
- https://www.bigcommerce.com/product/abandoned-cart/
- https://www.bigcommerce.com/articles/ecommerce/abandoned-carts/
- https://www.polymersearch.com/blog/a-guide-to-bigcommerce-analytics-what-to-consider
- https://www.glew.io/integrations/bigcommerce-analytics-reporting
- https://agencyanalytics.com/blog/bigcommerce-analytics
- https://databox.com/metric-library/data-source/bigcommerce
- https://cmscritic.com/bigcommerce-bolsters-built-in-analytics-with-insights
- https://wizcommerce.com/blog/bigcommerce-pricing/
- https://www.stylefactoryproductions.com/blog/bigcommerce-pricing
- https://www.shift4shop.com/comparison/bigcommerce/bigcommerce-pricing.html
- https://www.capterra.com/p/131883/Bigcommerce/reviews/
- https://www.trustpilot.com/review/bigcommerce.com
- https://www.gartner.com/reviews/product/bigcommerce
- https://www.softwareadvice.com/ecommerce/bigcommerce-profile/reviews/
- https://www.crazyegg.com/blog/bigcommerce-review/
- https://feedonomics.com/blog/meta-removing-native-checkout/
