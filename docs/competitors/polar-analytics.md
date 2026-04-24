# Polar Analytics

**URL:** https://www.polaranalytics.com
**Target customer:** Mid-market Shopify/DTC brands, typically $3M–$100M+ GMV. Also targets agencies managing multi-store portfolios and omnichannel/enterprise retailers. Not priced for true SMB — the lowest published tier starts around $3M GMV.
**Pricing:** GMV-based, opaque. No public self-serve tier.
- Core Plan (per Polar pricing calculator / Conjura analysis): starts ~$720/mo for up to $5M GMV; $1,020/mo at $5–7M; $1,660/mo at $10–15M; $2,770/mo at $20–25M; $7,970/mo at $75–100M.
- Shopify App listing shows tier names "Audiences" ($470/mo), "AI-Analytics" ($810/mo), "Polar Suite" ($1,020/mo).
- Individual modules priced separately (BI from $510/mo, Incrementality Testing ~$4,000/first test, Email Marketer AI scales with Klaviyo revenue, Advertising Signals "contact us").
- All plans include unlimited users, unlimited historical data, no per-seat fees.
- 7-day free trial via Shopify; annual discount available.
**Positioning one-liner:** "The all-in-one data stack for insights, activations, and AI agent foundations." Also frames itself as "Your Shopify Analytics. Effortless. Centralized. Smart." Internally leans hard on "one source of truth" messaging and warehouse-native architecture.

## What they do well
- **Warehouse-native architecture.** Every tenant gets (or can get) its own Snowflake database. Higher plans expose the warehouse directly, so data teams can write SQL alongside the Polar UI. This is a genuine differentiator vs Lifetimely/Peel/Triple Whale, all of which are closed black boxes.
- **Breadth of connectors.** 45+ source connectors (Shopify, Amazon, Meta, Google Ads, TikTok, Klaviyo, Recharge, Criteo, Pinterest, Snapchat, Google Analytics 4, etc.), which is why agencies like them.
- **Semantic layer + pre-built metrics.** Metrics like blended CAC, MER, ROAS, LTV are defined once at the warehouse level and reused across every dashboard — so a metric means the same thing everywhere, and custom metrics inherit the same language.
- **No-code Custom Report builder with templates.** Users pick metrics + dimensions + filters in a step-by-step builder, toggle between table and chart views, and save as a report. Explicit templates library to avoid blank-canvas problem.
- **Scheduled report delivery to Slack + email.** Custom Tables can be scheduled; reviewers cite this as the #1 time-saver ("2–3 hours/day of spreadsheet work gone").
- **Real-time anomaly alerts.** Alerting on metric drops (conversion rate, ROAS, etc.) via Slack/email — a category Peel and Lifetimely are weaker in.
- **Attribution side-by-side view.** Attribution screen shows the same revenue/conversions as reported by Meta, Google, GA4, and Polar's own first-party pixel in a single comparison view. Users can drill from the chart all the way down to an individual customer/order — exactly the "platforms disagree with the store" pattern our trust thesis is built on.
- **AI agents + Polar MCP.** Ask Polar assistant for natural-language queries; purpose-specific agents for media buying, email, inventory. MCP integration lets Claude read the semantic layer directly.

## Weaknesses / common complaints
- **Noticeable lag switching between views/reports.** Multiple G2 and Shopify reviewers cite this — large datasets, server-rendered reports, page transitions aren't instant.
- **Mobile reporting is weak.** Not designed for phone-first use.
- **Slow / time-consuming integration setup.** Custom connectors specifically require intervention from a Polar support specialist rather than self-serve.
- **Data freshness lag.** "Sometimes the data takes time to update" appears repeatedly.
- **Some ratio metrics are hard to understand** — users say definitions aren't always transparent in-product.
- **A/B testing feature rated 4.7 on G2 vs Triple Whale's 7.8** — not their focus, but a real gap for brands that want an integrated testing workflow.
- **Demographic insights are thin** compared to Triple Whale.
- **Pricing shock at ~$3M GMV.** $720/mo+ plus modular add-ons creates "substantial feels" for brands just crossing that threshold. AI agents and Incrementality are all separate line items.
- **Setup/config time for brands with unusual business logic.** Custom COGS, custom fulfillment fees etc. often need Polar support for initial modeling.
- **No integrated creative generation or campaign execution.** Reporting + signals only.
- **Missing a few CRM/email connectors** (e.g. Ometria) that competitors cover.

## Key screens

### Dashboard / home
- **Layout:** Left sidebar navigation (categories: Dashboards, Reports, Attribution, Integrations, Settings). Main pane is a customizable grid of dashboard widgets. Top bar carries date range + comparison control.
- **Key metrics shown:** Pre-built home dashboard includes Blended CAC, MER, ROAS, Net Profit, New vs Returning Customer Revenue, LTV, Retention Cohorts, Conversion Rate, AOV.
- **Data density:** High. Lots of metric cards + charts above the fold, very Triple-Whale-ish aesthetic.
- **Date range control:** Top-bar date picker with preset ranges (Today, Yesterday, Last 7 days, MTD, QTD, YTD, Custom) plus a comparison-to-previous-period toggle and year-over-year option.
- **Interactions:** Click a metric → drill to underlying Custom Report. Dashboards are fully customizable (hide/show, reorder). Filters apply dashboard-wide.
- **Screenshot refs:**
  - Shopify App listing screenshots: https://apps.shopify.com/polar-analytics (9 screenshots, primarily dashboard + attribution)
  - https://www.polaranalytics.com/l/centralize-your-data (landing with dashboard hero)

### Custom Report builder (Custom Tables & Charts)
- **Layout:** Step-by-step configuration pane on the left, live preview on the right. Users select: (1) primary metric, (2) dimension(s), (3) filters, (4) date range + comparison, (5) visualization mode (table vs chart).
- **Key interactions:**
  - Add up to 20 metrics to a single table.
  - Drag column reorder.
  - Swap rows/columns for different perspectives.
  - Color scales on cells to highlight top/bottom performers (heatmap overlay on otherwise plain tables).
  - Granularity toggle: day / week / month.
  - Apply multiple filters (channels, products, customer segments, geography, tags).
- **Not drag-drop canvas** — it's guided, stepwise. Polar deliberately avoids Looker-style complexity.
- **Templates library:** Pre-built templates cover common questions ("Customer retention by acquisition month", "Product performance by channel", etc.).
- **Scheduling:** Tables can be scheduled to email/Slack recipients. Charts currently can't.
- **Screenshot refs:**
  - https://www.polaranalytics.com/features/custom-report
  - https://intercom.help/polar-app/en/articles/5973031-understanding-custom-reports

### Attribution view
- **Layout:** Tabular + side-by-side comparison view showing the same metric (revenue, conversions, ROAS) as reported by Meta, Google Ads, GA4, and Polar's first-party pixel in adjacent columns.
- **Key behavior:** Explicitly designed to surface disagreement between platforms — users drill from a channel row down to campaign → ad set → ad → customer → order.
- **First-party pixel** (their own `polaranalytics.com`-hosted tracker) provides lifetime ID and cross-device stitching, positioned as more accurate than platform attribution.
- **Screenshot refs:**
  - Shopify App listing page

### Retention / LTV cohorts
- **Layout:** Standard cohort table — rows = acquisition month, columns = months since acquisition, cells = cumulative revenue per customer or retention %.
- **Breakdowns:** Product first purchased, channel, discount code used, country, customer tags.
- **Customization via Custom Report builder** — cohorts aren't a fixed view, they're a pattern you assemble.

### Product / merchandising
- **Shows products and variants selling well, inventory running low, bundling frequency** (which products are co-purchased). Tabular listings with inline sparklines.

### Klaviyo integration view
- **Single table of all Klaviyo flows** with performance vs previous month, open rate, click rate, revenue attributed.

### Ask Polar AI
- **Chat input at the top of the page.** User types a question in natural language; Polar generates a visualization (chart/table) with a brief narrative answer. Backed by the semantic layer.

## Attribution model (if they have one)
Polar runs its own **first-party pixel** (with cross-device lifetime ID stitching), plus pulls platform-reported attribution from Meta/Google/GA4/TikTok, plus ingests order data from Shopify. The attribution screen exposes all of these side-by-side so users can pick the model they trust. Offers server-side event forwarding (CAPI enhancement) as an "Advertising Signals" add-on for Meta and Google Ads. Multi-touch attribution is available but not the primary model — the product leans on *disagreement visibility* rather than a single "true" attribution number.

## Integrations
Shopify, Amazon, Klaviyo, Meta, Google Ads, TikTok, Pinterest, Snapchat, Criteo, Recharge, Google Analytics 4, Snowflake (as a destination *and* data source), QuickBooks, n8n, Claude/MCP, Slack. 45+ connectors total. Custom connectors supported but require vendor assistance.

## Notable UI patterns worth stealing
- **Side-by-side attribution comparison.** This is directly what our six-source-badge MetricCard is after. Polar validates the pattern: users *want* to see platform disagreement, not a single averaged number. Our "Real" gold lightbulb is a stronger version of this idea — Polar shows the disagreement, we also show our own computed truth.
- **Semantic layer + custom-metric reuse.** A user-defined metric created once shows up everywhere. Prevents the "net profit definition drift" problem across dashboards.
- **Step-by-step Custom Report builder over drag-drop canvas.** Guided flow (metric → dimension → filter → visualization) is less intimidating than Looker/Explore-style blank canvas. Worth borrowing for our BreakdownView.
- **Templates library for custom reports.** Avoids the blank-state problem for new users.
- **Scheduled tables (not charts) to Slack/email.** Smart differentiation — tabular data travels better in text channels than charts.
- **Alerts on metric anomalies.** Baked-in observability for store metrics. We should have this for our "Not Tracked" delta crossing thresholds.
- **Color-scale heatmap overlay on tables.** Lets a table double as a visualization without adding a chart. Good for our BreakdownRow tables.
- **Unlimited users, unlimited history.** No per-seat friction — removes a whole class of purchase decisions.

## What to avoid
- **Opaque pricing.** Every review mentions the "contact sales for pricing" friction. We already lean toward transparency; keep it.
- **Performance lag on page transitions.** Their reports server-render a lot; reviewers feel it. Our daily/hourly snapshot tables and Inertia partial reloads should keep us faster.
- **Module-and-add-on sprawl.** Audiences, AI-Analytics, Polar Suite, Incrementality, Signals, Email Marketer AI — users have to assemble their package. Confusing and expensive-feeling.
- **Weak mobile experience.** Plan for at least a read-only mobile view of the dashboard.
- **Integration setup requiring vendor hand-holding.** Custom connectors shouldn't need sales.
- **Black-box ratio metrics.** Always show the formula on hover / tooltip (we already plan to).
- **Starting price that excludes true SMB.** $720/mo floor is where we explicitly want to undercut.

## Sources
- https://www.polaranalytics.com
- https://www.polaranalytics.com/pricing
- https://pricing.polaranalytics.ai/
- https://www.polaranalytics.com/features/custom-report
- https://www.polaranalytics.com/l/centralize-your-data
- https://www.polaranalytics.com/connectors
- https://intercom.help/polar-app/en/articles/5973031-understanding-custom-reports
- https://apps.shopify.com/polar-analytics
- https://apps.shopify.com/polar-analytics/reviews
- https://www.g2.com/products/polar-analytics/reviews
- https://www.g2.com/compare/polar-analytics-vs-triple-whale
- https://www.conjura.com/blog/polar-analytics-pricing-in-2025-costs-features-and-best-alternatives
- https://www.aisystemscommerce.com/post/polar-analytics-review-2026-warehouse-native-ecommerce-intelligence-omnichannel-brands
- https://swankyagency.com/polar-analytics-shopify-data-analysis/
- https://bloggle.app/app-reviews/polar-analytics-review
- https://useamp.com/products/analytics/lifetimely-vs-polar-analytics (competitor's comparison page)
