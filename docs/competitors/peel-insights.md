# Peel Insights

**URL:** https://peelinsights.com (also https://www.relaycommerce.io/peel-insights — Peel was acquired by Relay Commerce)
**Target customer:** Mid-market Shopify DTC brands with real retention/subscription motion. Sweet spot is **6,000+ monthly orders**; entry tier starts there. Amazon and Walmart sellers also supported. Customer examples: True Classic, Vita Coco, Jones Road, Caraway, Once Upon a Farm, Maude, Bite, Versed, Prima, Oats Overnight — a clear "established DTC brand" customer list, not startups.
**Pricing:** Order-volume-based, four published tiers. Annual discount available.
- **Core** — $179/mo annual ($199 monthly). For stores with >6,000 monthly orders. No CSM credits.
- **Essentials** — $449/mo annual ($499 monthly). >16,000 monthly orders. 1 CSM onboarding credit/month. Up to 3 multi-store connections.
- **Accelerate** — $809/mo annual ($899 monthly). >29,000 monthly orders. 2 CSM credits/month. Up to 7 stores.
- **Tailored** — custom. ≥62,000 monthly orders. 5 CSM credits/month. Unlimited stores.
- Additional support: $50 per extra credit hour across all plans.
- Free trial available; Smartrr users get a dedicated free tier.

**Positioning one-liner:** "The all-in-one analytics software Shopify brands trust to answer their hardest LTV questions." Emphasizes **retention**, **subscription**, and **LTV** as the core domain.

## What they do well
- **Retention/cohort is the product, not a feature.** Unlike Polar (breadth) and Lifetimely (profit + LTV), Peel's entire information architecture is built around cohort retention and repurchase. Everything returns to "how does this group of customers behave over time?"
- **Purpose-built reports you can't get elsewhere.** Market Basket Analysis, Audience Overlap (Venn diagrams), Repurchase Rate by City, RFM into 10 named segments, Customer Purchasing Journey. These aren't configurable from a generic builder — they're pre-modeled analyses.
- **40+ subscription analytics metrics.** MRR, churn reduction, one-time purchaser → subscriber conversion, subscription revenue mix. Deep integration with Recharge, Smartrr, Skio.
- **Metric → Report → Dashboard hierarchy.** Clean mental model: metrics are pre-computed, reports are saved filtered views of metrics, dashboards compose reports as widgets. Keeps things consistent and shareable.
- **Magic Dash AI-generated dashboards.** Type a business question into the AI input; Peel auto-generates a dashboard with line/bar/stacked/pie charts laid out. Separate from natural-language Q&A — this builds *persistent* dashboards, not ephemeral answers.
- **Magic Insights headline feed.** AI-generated insights that refresh weekly ("repurchase rate in Texas fell 8%", etc.) — delivers value without the user having to ask.
- **Audiences → Klaviyo / Meta export.** Segments built in Peel can push back out to ad platforms and email — not just a reporting tool, but an activation tool.
- **Annotations on charts.** Mark campaigns, supply disruptions, launches. AI uses annotations as context for Magic Insights. Smart — turns the tool into an event log the org shares.
- **Custom consulting / CSM credits.** Paid tiers include human-built dashboards — enterprise-style service for mid-market customers.
- **Daily Slack and email insight reports.**

## Weaknesses / common complaints
- **Expensive for small brands.** $179/mo floor is priced against 6,000+ orders/month; anything smaller gets no entry point. Multiple G2/Trust reviews flag pricing as prohibitive for SMBs.
- **Peel was acquired by Relay Commerce** — some uncertainty about product direction, investment, and brand focus. Less press/marketing velocity than Polar or Lifetimely since acquisition.
- **Review volume is low** — 34 reviews on Shopify app store, fewer than Lifetimely (481) or Polar (109). Harder to validate product at scale.
- **No native profit/P&L module.** Peel is *retention* analytics — if you need net profit, COGS tracking, integrated P&L you'll still need a Lifetimely/BeProfit/Polar alongside it.
- **No first-party attribution pixel.** Attribution uses platform-reported data + UTMs. Peel reports across 19 platforms but doesn't solve the "platforms disagree with the store" problem the way Polar does.
- **Limited ad-platform reporting depth** compared to Polar or Triple Whale — Peel isn't the tool for campaign-level ad optimization.
- **Subscription-heavy focus means non-subscription brands get less value.** The product's strongest surfaces (subscription dashboard, cohort retention, churn) resonate less for one-time-purchase brands.
- **Dashboard creation workflow is multi-step.** Metrics → Report → Widget → Dashboard requires more clicks than drag-drop from a metric library.

## Key screens

### Dashboard / home
- **Layout:** Left sidebar (Home, Metrics, Reports, Dashboards, Magic Dash, Audiences, Annotations, Settings). Top bar: global date range + comparison + a "switch store" selector for multi-store accounts. Main pane is a user-configurable dashboard of widgets.
- **Key metrics shown (default dashboards):** Four pre-built dashboards ship out of the box — **Customer**, **Subscriptions**, **Marketing**, **Multitouch Attribution**. LTV, AOV, churn, repurchase rate, cohort trends are front and center.
- **Data density:** Medium. Dashboards are customizable but the defaults are cleaner than Polar (fewer widgets, more whitespace).
- **Date range control:** Preset ranges + custom, with comparison to previous period and YoY. Date range is dashboard-scoped — individual widgets can override.
- **Interactions:** Double-click a chart to explore (the marketing site highlights this as "interactive exploration with double-click"). Drill from widget → underlying report → raw metric. Drag-drop to rearrange widgets. Share read-only link.
- **Screenshot refs:**
  - https://www.peelinsights.com/
  - https://apps.shopify.com/peel-insights
  - https://www.peelinsights.com/post/product-update-create-dashboards

### Cohort analysis (core differentiator)
- **Layout:** Multiple visualization modes in the same page, selectable via a view toggle:
  1. **Cohort table (heatmap).** Rows = acquisition cohort (month/week/quarter — configurable), columns = time since acquisition (Month 0, 1, 2, 3, … 12+), cells = the metric (retention %, cumulative revenue per customer, repeat order count, cumulative profit per customer, etc.), with a color-scale heatmap overlay.
  2. **Cohort curves.** Line chart — one line per cohort — showing the metric over time-since-acquisition. Lets users *visually* compare whether April acquisitions are retaining better than March.
  3. **Pacing graphs.** Shows whether the most recent cohort is pacing above or below historical averages at matching cohort-age.
- **Selectable metric per view:** Retention %, cumulative revenue, LTV, repeat purchase rate, cumulative gross margin, profit per cohort.
- **Breakdown / split by:** Acquisition channel, first product, discount code, geography, campaign, customer tag. Enables side-by-side cohort tables filtered to specific segments.
- **Cohort grain:** Monthly / weekly / quarterly.
- **Time horizon:** 30/60/90/180/365/ongoing — the question spec specifically asks about this. Peel handles it by showing a configurable number of "months since acquisition" columns, capped by how much history the user has.
- **Screenshot refs:**
  - https://www.peelinsights.com/post/your-guide-to-cohort-analysis
  - https://www.peelinsights.com/ecommerce-analytics-explained/cohort-ltv-ltv-per-cohort
  - https://www.peelinsights.com/ecommerce-analytics-explained/cohort-ltv-gross-margin-per-month

### Retention curves specifically
- Distinct from cohort curves — retention curves show % of a cohort still purchasing at N days after first purchase, not revenue. Used to identify where the drop-off cliffs are (typically at 30/60/90/180 days).
- Overlaid cohorts on one chart; lift/drop against baseline highlighted.

### RFM analysis
- **Layout:** 10 named customer segments (e.g. "Champions", "Loyal Customers", "At Risk", "Can't Lose Them", "Hibernating") mapped onto a Recency × Frequency grid. Grid cells color-coded by monetary value.
- **Each segment clickable** → drills into the underlying customer list + their orders.
- **Audiences integration:** Export any RFM segment as an Audience, push to Klaviyo / Meta.

### Market Basket Analysis
- **Layout:** Tabular view of product-pairs (or triplets) with "purchased together" frequency, lift, confidence. Used for bundling strategy. Maude case study cites +236% bundle revenue from this.

### Audience Overlap
- **Visualization:** Venn diagram showing intersection between user-defined segments (e.g. "bought Product A" vs "bought Product B" vs "subscribed via Recharge"). Click a Venn region → customer list.

### Purchasing Journey
- Visual flow diagram showing what customers buy first, then second, then third. Helps identify "gateway products" and natural upsell sequences.

### Audiences builder
- **Layout:** Filter-chip interface. Add filters (tags, products, discount codes, cities, attribution channels, order frequency, total spend) stacked as rules. Live count of matching customers updates as filters change.
- **Export:** Push to Klaviyo list / Meta Custom Audience / CSV / Google Sheets.

### Subscriptions dashboard
- Dedicated dashboard with 40+ subscription metrics — MRR, churn %, subscriber LTV, OTP → subscriber conversion rate, cancellation reasons. Native Recharge / Smartrr / Skio integration.

### Custom Dashboard builder
- **Layout:** Existing widgets (tickers = single number, legends = graphs) drag-dropped onto a grid. Chart types per widget: line charts, cohort tables, cohort curves, pacing graphs, number trackers.
- **Workflow:** User first saves any metric + filter combination as a "Report", then adds that Report as a "Widget" on a "Dashboard". Three-step hierarchy is explicit.
- **Customization:** Widget titles, descriptions, annotations, goal lines. CSV/OneDrive/Google Sheets export per widget.
- **Screenshot refs:**
  - https://help.peelinsights.com/docs/dashboards

### Magic Dash (AI-generated dashboards)
- **Layout:** Text input top-left ("Ask a question about your business"), suggestion dropdown, three-dot menu for manage/share/delete. AI composes a dashboard layout with multiple widgets.
- **Widgets generated:** Line, bar, stacked bar, pie — sized automatically but user-customizable (Values tab for grouping/goal, Layout tab for chart type + widget dimensions).
- **Screenshot refs:**
  - https://help.peelinsights.com/docs/magic-dashboards

### Magic Insights
- **Layout:** Headline feed on the main dashboard. Each insight is a sentence + a small chart. Refreshes weekly. Annotations (user-entered campaign/launch/disruption notes) feed the AI as context.

## Attribution model (if they have one)
Multi-touch attribution pulled from 19 ad platforms. Reports ROAS, CAC, ROI, LTV:CAC by channel. **No first-party pixel** — Peel relies on platform reporting + UTMs and normalizes across platforms. Multitouch Attribution is one of four default dashboards, but it's not the product's headline. Peel's attribution model is less sophisticated than Polar's side-by-side comparison or Triple Whale's blended approach.

## Integrations
Shopify (primary), Amazon, Walmart, Klaviyo, Attentive, Meta, Google Ads, TikTok, Pinterest, Snapchat, Amazon Ads, Google Analytics 4, Recharge, Smartrr, Skio, Kno Commerce, Slack, Google Sheets, OneDrive. Around 19+ ad platforms covered for attribution. Heavier weighting toward subscription/retention stack (Recharge, Smartrr, Skio, Attentive) vs Polar's broader BI-first stack.

## Notable UI patterns worth stealing
- **Cohort table + cohort curves + pacing graphs as three views of the same data.** Critical for our cohort page: tabular heatmap for the analytical user, line-chart curves for the visual user, pacing graph for the "am I doing better than last year" founder. Don't pick one; offer all three with a view toggle.
- **Named RFM segments ("Champions", "At Risk", etc.)** over raw scores. Emotional/cognitive shortcut — much more actionable than "recency 4, frequency 5, monetary 3".
- **Audience Overlap Venn diagram.** Cheap to build, surprisingly powerful for the "which of my customers overlap" question. Worth a small investment.
- **Metric → Report → Dashboard hierarchy.** Explicit three-level model that keeps things consistent. Parallels our MetricCard/BreakdownView structure.
- **Annotations as chart + AI context.** Doubles as a team event log and improves AI-generated insights. Low effort, high value.
- **Magic Dash (AI-built persistent dashboard)** as a distinct thing from AI chat Q&A. Chat is ephemeral; Magic Dash is a kept, named object. Different user intent, different UI.
- **Magic Insights feed** as a dashboard surface that pushes findings at you. Avoids the "I don't know what to ask" problem — a major SMB use case.
- **Ticker vs Legend widget taxonomy.** Single-number widgets vs graph widgets as first-class distinctions, not just chart-type variations. Cleaner than Polar's flatter widget model.
- **Double-click to drill down on any chart.** Fast, discoverable power-user interaction. We should adopt it.
- **Audiences export to Klaviyo + Meta Custom Audience.** Closes the loop from "what did we learn" to "what did we do about it" — strong retention story. Gives analytics tool reach beyond just reporting.
- **Pre-built, purpose-built reports** (Market Basket, Repurchase Rate by City, Customer Purchasing Journey) — proves there's demand for *opinionated* analyses, not just a builder. Our page-level IA should include some of these as first-class pages, not "configure it yourself".
- **Four default dashboards (Customer / Subscriptions / Marketing / Multitouch Attribution)** as starter IA. Clean persona split.

## What to avoid
- **$179/mo floor priced to 6,000+ orders/month.** Shuts out the SMB/new-store segment entirely. Our positioning is explicitly the smaller end — don't repeat this exclusion.
- **Retention-only focus means users still need a separate profit tool.** Peel is excellent at one thing and forces a multi-tool stack. We want one dashboard; include P&L, COGS, ad spend alongside cohorts.
- **Three-step hierarchy (Metric → Report → Dashboard) can feel heavyweight.** Users have to name and save at each step before they can compose. We can keep the mental model but let users skip steps (e.g. ad-hoc widgets that aren't saved Reports).
- **Closed ecosystem (no Snowflake, no SQL).** Same weakness as Lifetimely — mid-market will hit the wall.
- **Low review volume / post-acquisition uncertainty.** We don't control narrative, but we can invest in review velocity earlier than they did and build trust capital on G2 and Shopify from launch.
- **Attribution that isn't a differentiator.** Our six-source MetricCard + "Real" gold lightbulb is the honest answer to the attribution problem; we shouldn't settle for Peel's platform-normalized attribution.
- **Overuse of consulting/CSM credits as the support model.** It suggests self-serve isn't enough — worth watching whether we need that crutch.

## Sources
- https://peelinsights.com
- https://www.peelinsights.com/pricing
- https://www.peelinsights.com/solutions
- https://www.peelinsights.com/data-insights-demo
- https://www.peelinsights.com/ecommerce-analytics-explained/cohort-ltv-ltv-per-cohort
- https://www.peelinsights.com/ecommerce-analytics-explained/cohort-ltv-gross-margin-per-month
- https://www.peelinsights.com/ecommerce-analytics-explained/lifetime-value
- https://www.peelinsights.com/post/cohort-analysis-101-an-introduction
- https://www.peelinsights.com/post/your-guide-to-cohort-analysis
- https://www.peelinsights.com/post/product-update-create-dashboards
- https://help.peelinsights.com/docs
- https://help.peelinsights.com/docs/dashboards
- https://help.peelinsights.com/docs/magic-dashboards
- https://apps.shopify.com/peel-insights
- https://www.g2.com/products/peel-analytics/reviews
- https://www.trustradius.com/products/peel-insights/pricing
- https://www.trustradius.com/products/peel-insights/reviews
- https://www.smbguide.com/review/peel-insights/
- https://aazarshad.com/resources/peel-insights-review/
- https://www.relaycommerce.io/peel-insights
- https://ecommercetech.io/apps/peel-insights
