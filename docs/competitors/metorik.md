# Metorik

**URL:** https://metorik.com
**Target customer:** WooCommerce and Shopify SMBs (roughly 0 to 150k orders/month on the pricing slider), heavy skew toward WooCommerce merchants who outgrew native WC reports. ~8,000 stores. Equal billing for Shopify and WooCommerce but WooCommerce-specific features (Subscriptions reporting, custom fields in exports) are the differentiator.
**Pricing:** Single "all features, unlimited users" tier, priced on a sliding scale by monthly orders. Entry point is **$25/month** (quoted as "Starter"). 30-day free trial, no credit card. Historical sync capped at 120x the monthly order limit on each tier. Email credits scale with the tier; overages billed as add-ons ("Need more? Contact us").
**Positioning one-liner:** "Take eCommerce back off hard mode" — a clean, fast analytics + engagement layer that sits on top of WooCommerce/Shopify and replaces laggy native admin reports.

## What they do well
- Speed is the headline: segments and reports load "in milliseconds, not minutes." Reviewers consistently call the dashboard "fast" and "intuitive" vs. the sluggish native WC reports.
- Segment builder is the flagship. 500+ filters spanning orders, customers, products, variations, categories, subscriptions, coupons, carts — with grouped boolean logic and real-time preview as you build ("See results as you build your segments").
- Saved segments are first-class and reusable everywhere — dashboards, reports, exports, email campaigns, cohort analysis. One segment definition applies across the whole product.
- WooCommerce-specific depth: WC Subscriptions reporting (MRR, churn, cohort retention), custom fields auto-synced and usable as filters/export columns, tax/VAT reports by country.
- Costs & Profit module: sync COGS, shipping, transaction fees, and Google/Facebook/TikTok/Microsoft/Pinterest ad spend for blended ROAS and net profit metrics.
- Customizable dashboards: multiple dashboard "screens" per user, drag-and-drop cards, per-team layouts that can be saved and shared.
- Export UX is praised — drag-and-drop column picker, schedule CSVs to email/Slack, pick any WC custom field.
- Engage Suite (email): abandoned cart, transactional, broadcast — all targeted by saved segments with unlimited contacts included.

## Weaknesses / common complaints
- Not a multi-source aggregator. WooCommerce + Shopify only. No Amazon, eBay, Etsy, BigCommerce, or marketplace data. No direct PayPal/Stripe connection — payment data comes via the store platform.
- No built-in website/traffic analytics (no GA-like sessions/bounce/source breakdown from their own tracker). UTM source/medium comes from the order, not from sessions.
- No RFM segmentation out of the box (you can simulate it with segment filters but there's no dedicated RFM quadrant UI).
- Cannot issue refunds from the dashboard — read-only on orders.
- Subscription reporting covers WooCommerce Subscriptions only; does not track PayPal/Stripe subs natively.
- Scheduled reports are email/Slack only — no in-app report history or report-as-URL sharing mode in public-facing plans.
- One G2-surfaced request: push audiences/segments out to Facebook/Google for ad targeting (currently you export CSV and upload manually).
- Pricing scales by order volume; merchants with high order counts but low AOV can find it expensive per dollar of GMV.

## Key screens

### Dashboard / home
- **Layout:** Grid of "cards." Each card is a single metric or mini-chart (e.g. Blended ROAS, Ad Spend, CAC, COGS for UK Orders, Customer LTV, Best/Worst Selling Products, Sales per Day, Hourly Sales, Net Sales, New Customers, AOV). Cards are add/remove/rearrange. Users can create multiple named dashboards (e.g. "Exec View," "Marketing View") and switch between them from a top selector.
- **Key metrics shown:** Revenue (gross + net), orders, new vs returning customers, AOV, refunds, subscription MRR, blended ROAS, CAC, COGS, LTV. Each card compares against a prior period.
- **Data density:** Medium — cards are generously spaced, isometric illustrations on the marketing pages but the product itself is a clean card grid. Not a "wall of numbers."
- **Date range control:** Top-of-page date picker with presets (today, yesterday, last 7, last 30, MTD, YTD, custom) and a "compare to" toggle that re-renders every card with delta %.
- **Interactions:** Click any card → drills into the full report for that metric, date range preserved. Edit mode lets you add/remove cards and pick which segment the dashboard is scoped to (so "UK Orders Dashboard" is a saved segment + layout pair).
- **Screenshot refs:** Hero on metorik.com shows isometric floating cards over blue gradient. CommerceGurus review shows the actual product card grid.

### Reports (orders, customers, products, subscriptions, refunds, sources, devices, carts)
- **Layout:** Left sidebar lists report categories (Orders, Customers, Products, Subscriptions, Refunds, Sources, Devices, Carts, Discounts, Retention). Each category expands into sub-reports. Main area is a full-width chart + filters panel + data table.
- **Key metrics shown (per category):**
  - **Orders:** New vs returning, order-value distribution, item-count distribution, time between created & shipped, spend by day-of-week/hour, refunds by country.
  - **Customers:** New customer trends, geo map, LTV, cohort retention.
  - **Products:** Sales, trends, profit margins, inventory forecasts, by category/channel.
  - **Subscriptions:** MRR, churn by billing period, LTV, cohort retention grid.
  - **Devices:** Desktop/mobile/tablet split with AOV comparison.
  - **Carts:** Abandoned / in-progress / completed with most-added products.
- **Data density:** High. Each report page combines a summary chart, KPI strip, and a long data table with dozens of sortable columns.
- **Date range control:** Same global date picker + compare-to as dashboard.
- **Interactions:** Segment builder slides in from the side ("Filter this report by…"). Every filter shows result count live. Save segment → becomes reusable across the product. Column picker on tables. Export CSV button on every report.
- **Screenshot refs:** CommerceGurus review, wplift review (reports page with sidebar nav + chart + table pattern).

### Segment builder
- **Layout:** Slide-in or full-page modal. Filter rows stacked vertically; each row is Field → Operator → Value. Groups of rows can be combined with AND/OR in nested blocks.
- **Key metrics shown:** Live result count at the top ("Matches 1,247 customers"). Preview table below shows sample rows.
- **Data density:** Conversational — described as feeling "like a conversation than a database query."
- **Date range control:** Built into filters (e.g. "Created between…").
- **Interactions:** Real-time preview as filters are added. Save segment with a name. Apply to any report, export, dashboard card, or email campaign. Sharing: segments can be shared with team.
- **Screenshot refs:** Metorik `/features/segmenting` page; called out in G2 and Shopify App Store reviews as the single most-loved feature.

### Cohort / retention report
- **Layout:** Classic cohort table — rows are acquisition month (or week), columns are periods since acquisition, cells are retention % or retained MRR. Heatmap color gradient.
- **Key metrics shown:** Retention %, cumulative LTV by cohort, MRR retention for subscription stores.
- **Interactions:** Switch metric (retention % / revenue / MRR), switch grain (week/month), filter cohort by any saved segment.

### Costs & Profit
- **Layout:** Tab-per-cost-type UI described as "little tabs for each cost." Tabs: Product COGS, Shipping, Transaction Fees, Advertising (Google/Meta/TikTok/etc.). Each tab shows that cost category broken out by period with its own chart + table.
- **Key metrics shown:** Ad spend (by platform), COGS per SKU, blended ROAS, net profit, CAC.
- **Interactions:** Edit COGS inline per product or bulk-upload CSV; ad platforms authenticated via OAuth.

### Custom report builder
- **Layout:** Form: pick a report "total" (revenue/orders/etc.), then pick KPIs, segment stats, and an optional list view (customers/orders).
- **Interactions:** Save → schedule to email/Slack daily/weekly/monthly at a chosen time, to any set of recipients.

### Engage (email campaigns)
- **Layout:** List of campaigns + "New campaign" CTA. Campaign editor: pick type (broadcast / automation / abandoned cart / transactional), pick audience (a saved segment), design email in a block-based builder.
- **Interactions:** Preview with real customer data, consent/unsubscribe controls, dynamic coupon insertion for cart recovery.

## Integrations
Ad platforms: Google Ads, Meta (Facebook/Instagram), Microsoft Ads, TikTok, Pinterest.
Shipping: ShipStation.
Support: Zendesk, Gorgias, HelpScout, Intercom, Freshdesk, Groove.
Productivity: Slack, Google Sheets.
Data in: WooCommerce (via "Metorik Helper" plugin), Shopify (app), CSV.

## Notable UI patterns worth stealing
- **Dashboard as "saved segment + card layout" pair.** Instead of one global dashboard, users create named dashboards scoped to a segment (e.g. "UK Subscribers Dashboard"). This maps cleanly to Nexstage's multi-tenant, multi-store context — a workspace could have multiple saved views.
- **Segment builder with live result count.** Every filter change updates a "Matches N" counter before you commit. Strong trust signal — you know your segment is well-formed before saving.
- **Saved segments as cross-cutting primitives.** One segment applies to dashboards, reports, exports, emails. Nexstage could do the same for attribution/channel filters once they stabilize.
- **Per-metric card with delta chip + sparkline on dashboard.** Matches `MetricCard` conventions; Metorik's minimalism (one number, one %, optional sparkline) is a good template.
- **Export column picker with drag-to-reorder.** Small detail but universally praised in reviews — export tools are often an afterthought; Metorik treats them as a first-class surface.
- **Tabs for cost categories in the Costs & Profit module.** Rather than a wall of line items, tabbing by cost source keeps the UI legible and aligns with Nexstage's "source badges" metaphor.
- **Segment preview table under the filter builder.** Shows literal rows that match — validates your filter logic in a way a count alone can't.

## What to avoid
- **No source disagreement anywhere.** Metorik trusts the store DB absolutely. No reconciliation between Facebook's reported revenue and the store's actual revenue — no "two numbers, one drill-down." This is exactly the gap Nexstage's trust thesis fills; don't replicate Metorik's blind spot.
- **Hero illustrations with isometric floating cards.** Looks great in marketing but is not what the app looks like. Pre-launch marketing should not lie about the product.
- **Email marketing bundled in core pricing.** Increases surface area, couples two products. Nexstage should keep analytics and outbound separate.
- **WooCommerce Subscriptions-only subscription reporting.** Don't paint yourself into the same corner; if you report MRR, source it from both WC Subs and Stripe/Shopify Subs from day one.
- **Flat pricing with metered overages hidden behind "Contact us."** Metorik's pricing page forces a slider and then a contact form for email credits — friction and perceived bait-and-switch in reviews.
- **No ad spend reconciliation.** Metorik shows ad spend and blended ROAS but doesn't call out when platform-reported conversions disagree with store orders. Nexstage should make that disagreement the first-class artifact.

## Sources
- https://metorik.com
- https://metorik.com/pricing
- https://metorik.com/features
- https://metorik.com/features/reports
- https://metorik.com/features/segmenting
- https://metorik.com/analytics-reports
- https://wplift.com/metorik-review/
- https://www.commercegurus.com/metorik-review/
- https://apps.shopify.com/metorik/reviews
- https://www.g2.com/products/metorik/reviews
- https://www.putler.com/metorik-review/
- https://wordpress.org/plugins/metorik-helper/
