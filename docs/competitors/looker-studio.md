# Looker Studio

**URL:** https://lookerstudio.google.com (formerly Google Data Studio, rebranded October 2022)
**Target customer:** Marketing agencies, freelance analysts, SMB operators, and any Google Ads / GA4 user who wants a "free" reporting layer on top of Google data. Heavy adoption in agency reporting workflows. Not an ecommerce tool per se — ecommerce merchants reach for it only after wiring up Supermetrics / Porter / Catchr / Windsor / Coupler connectors.
**Pricing:**
- **Looker Studio (free)** — $0. Unlimited reports and users, unlimited Google-native connectors (GA4, Google Ads, Google Sheets, BigQuery, YouTube). Pay-per-connector for most third-party sources (Supermetrics ~$39–$199/mo, Windsor.ai ~$19/mo per connector, Porter ~$15/mo).
- **Looker Studio Pro** — **$9 per user per Google Cloud project per month** (annual). Adds Google Cloud IAM, SSO, audit logs, team-owned content, scheduled email delivery, Gemini AI assistant, higher GA4 API quotas.
- True cost of a "free" ecommerce dashboard: $0 Looker Studio + $39–$200/mo per Supermetrics/etc. connector × N connectors. A three-connector Shopify + Meta + GA4 setup is typically **$60–$250/mo** in connector fees alone.
**Positioning one-liner:** "Build interactive dashboards with your Google data — for free." In ecommerce, it's the DIY reporting layer people adopt when they've outgrown Shopify's native dashboards but refuse to pay for a dedicated tool.

## What they do well
- **It's free and ubiquitous.** Any Google account gets in. Zero procurement friction. Agencies can ship "custom dashboards" to clients with no software bill.
- **GA4 and Google Ads connectors are native and first-class.** Drop a chart, pick a metric from the schema picker, it renders. This is the killer feature for the Google-heavy marketing world.
- **Canvas report builder feels like Google Slides.** Pixel-perfect drag-drop, multi-page reports that navigate like a slide deck, theme customization, branded headers/footers. Low floor for non-technical users.
- **Templates gallery is enormous.** Every agency, connector vendor, and freelancer ships templated dashboards. Supermetrics, Porter, Catchr, Windsor, Two Minute Reports, Littledata, Coupler.io — all publish free Shopify / Meta / GA4 / WooCommerce templates you can clone in one click.
- **Calculated fields + blended data** let you join two data sources on a key (e.g., GA4 sessions × Shopify orders by date) without a warehouse. Limited but workable.
- **Shareable like a Google Doc.** Public link, domain-restricted, or specific emails. Viewer vs. editor permissions. No "seat" count to manage.
- **Filter controls on the canvas** are drag-droppable widgets that can be scoped to specific charts, specific pages, or the whole report.
- **Responsive Reports (April 2025)** — new layout mode that auto-adjusts chart placement for tablet/mobile, though with layering restrictions.
- **Looker Studio Pro's Gemini AI** can generate chart explanations and turn a report into Google Slides. Still rough but it exists.

## Weaknesses / common complaints
- **Performance is the #1 complaint.** 38% of 2025 G2 reviewers flag "slow dashboards, timeouts, and visualizations that take several seconds to render." Five charts querying GA4 on a single page exhausts the concurrent-request quota for a free property on one viewer load.
- **GA4 API quota cliff.** 1,250 tokens per hour per property for standard GA4 properties. A dashboard with a dozen GA4 charts and three users refreshing simultaneously hits "quota exceeded" errors that say nothing useful to the end viewer — the chart just shows a red triangle.
- **12-hour cache by default.** "Live" dashboards can be 0–12 hours stale and Looker Studio doesn't surface which. Shortening freshness worsens the quota problem. Every ecommerce team eventually discovers "wait, yesterday's revenue isn't in here yet".
- **Third-party connectors cost money and break.** Supermetrics / Porter / Windsor / Catchr all charge per connector per month. Connectors break when ad platforms change APIs (Meta v18 → v19 etc.) and it's on the vendor to fix — outage cycles of 1–5 days are normal.
- **No row-level security.** You can share or not share; there's no per-user data filtering. Agencies serving multiple clients from one report need to clone reports or build manual workarounds.
- **Calculated fields are limited.** No window functions, no joins across more than two sources, no CTEs. Anything complex gets pushed down to BigQuery SQL — which means paying for BigQuery + learning SQL.
- **No alerting.** Looker Studio can't send "ROAS dropped 30%" to Slack. There is no alert feature. Pro's "scheduled email delivery" sends a PDF of the whole report, nothing more.
- **Version control doesn't exist.** One person edits the live report; changes are instant; there's an undo stack but no branch, no diff, no rollback to a known-good state. Agencies live in terror of a clumsy drag-drop on Friday afternoon.
- **Cross-filter interactions are manual.** Clicking a bar in one chart doesn't filter the rest of the page by default — you have to configure each chart as a "filter source" and each target to accept the filter.
- **Drill-downs are hierarchical only.** Set a drill hierarchy at chart creation (Country → Region → City). No free-form drill-through the way Metabase/Mixpanel/Amplitude handle it.
- **Maintenance nightmare at scale.** Agency reports with 30+ pages, dozens of blended sources, and custom fields become un-editable. Swydo's scaling piece cites a "breaking point" around 20 client reports where connector refreshes, quota errors, and broken calculated fields consume a full FTE.
- **No native Shopify or WooCommerce connector.** Shopify data has to come through Supermetrics, Porter, Windsor, Coupler, Catchr, Two Minute Reports, or a BigQuery export pipeline. None of these are free beyond trial.

## Key screens

### Report editor (the canvas)
- **Layout:** Google Slides-style page canvas with a top toolbar (Add a chart / Add a control / Theme / Layout / Resource) and a right-hand properties panel. Pages list on the left. Pixel-perfect grid; snap-to-grid optional.
- **Chart catalog:** Table, scorecard, time series, bar, pie, treemap, geo map, scatter, pivot table, bullet, area, line, combo, sankey (community viz), heatmap (community viz), gauge. Chart picker is a 4×N grid of icons.
- **Properties panel:** Two tabs — **Setup** (dimensions, metrics, date range, filter) and **Style** (colors, fonts, legend, axis). Each chart is configured by dragging fields from the data source schema onto dimension/metric slots.
- **Data source manager:** Bottom of the report. Each report can use multiple data sources; each chart binds to one (or uses "blended data" to join two).
- **Edit vs. view mode:** Top-right toggle. View mode removes the grid, hides the right panel, and runs the report as a viewer sees it.
- **Screenshot refs:**
  - https://lookerstudio.google.com/gallery (community templates)
  - https://lookerstudiomasterclass.com/blog/first-looker-studio-dashboard-setup-guide

### Ecommerce templates (Supermetrics Shopify overview)
- **Layout:** 4–6 pages. Page 1 is a KPI tile grid; subsequent pages cover orders, products, customers, channels, acquisition.
- **Page 1 tiles:** Revenue, Orders, AOV, Conversion Rate, Sessions, Customers, Refunds. Each with MoM / YoY delta and a sparkline trend.
- **Page 2 — Orders:** Time series of orders + revenue, a geo map of orders by country, top products table.
- **Page 3 — Channels:** Pivot table by channel × day; stacked bar of revenue by channel; channel-level CVR and AOV.
- **Filters at the top:** Date range control (always present, always top-right), channel filter, device filter, country filter.
- **Data source:** Supermetrics' Shopify connector ($39–$199/mo); template swaps the placeholder source for the user's own at clone time.
- **Screenshot refs:**
  - https://supermetrics.com/template-gallery/looker-studio-ecommerce-dashboard
  - https://supermetrics.com/template-gallery/looker-studio-shopify-overview-report
  - https://portermetrics.com/en/templates/google-looker-studio/free-shopify-template/
  - https://windsor.ai/looker-studio-shopify-dashboard-template/

### Blended data editor
- **Layout:** Modal dialog with up to five source tables on the left; join keys configured in the middle; output schema on the right.
- **Join types:** Left, right, inner, outer, cross.
- **Interaction:** Drag a field from source A and drop it on a field in source B to define a join key. Each source can be a different connector (e.g., GA4 + Shopify joined on date).
- **Gotchas:** No aggregation before join. Source tables must already return the right grain. This is where ecommerce blending often falls apart — Shopify orders are per-order, GA4 sessions are per-session, and users don't know which grain wins.

### Community Visualizations gallery
- **Layout:** Modal of third-party-contributed viz types — sankey, funnel, heatmap, word cloud, radar, network graph.
- **Install:** One-click add to the chart picker. Some are paid.
- **Quality:** Inconsistent. Many are abandoned and break silently on data-type changes.

### Looker Studio Pro admin (Google Cloud Console)
- **Layout:** Lives in GCP, not in Looker Studio itself. IAM, audit logs, subscription management.
- **Feature: team-owned content** — reports live in a GCP project, not a personal Google account. Removes the "person who built the dashboard left the company and nobody can edit it" failure mode.
- **Feature: scheduled delivery** — PDF of the whole report to a list of emails on a schedule. No conditional logic, no metric-based triggers.

## Chart vocabulary worth stealing
- **Pixel-perfect canvas with snap-to-grid** — the freeform layout model is what non-technical users expect. Most BI tools force a grid; Looker Studio's Slides metaphor is why marketers prefer it.
- **Scorecard** as a distinct chart type — single big number with optional comparison delta and sparkline, labeled. This is the lineage of every KPI tile in ecommerce analytics.
- **Date range control as a first-class component**, draggable onto the canvas, scoped per page or per chart. The norm that every tool should copy.
- **Blended data as a visible step** — users can see that a chart is joining two sources. Most BI tools hide this; Looker Studio shows it in the source list.
- **Community Visualizations** as an escape hatch for rare chart types (sankey, funnel, radar) without forcing the core product to carry them.
- **Report / Explorer split** — "Report" is the polished artifact; "Explorer" is an ephemeral scratchpad for ad-hoc queries. Ecommerce tools should consider this; most don't have the "I'm just poking around, don't save this" mode.

## Query builder / abstraction level
- **Primary abstraction:** field-drag onto dimension/metric slots. No SQL surface for native GA4/Ads data; schemas are curated by the connector.
- **SQL escape hatch:** only when the source is BigQuery (custom SQL data source). For Shopify / Meta / GA4 via Supermetrics, there is no SQL option.
- **Calculated fields:** per-source formula language that's a subset of SQL. `CASE WHEN`, arithmetic, string ops, basic date functions. No joins, no aggregations over windows.
- **Filter controls:** dropdown, list, slider, text input, date range. Drag onto canvas; each control targets specific charts or all charts on the page.

## Alerting & subscriptions
- **No alerts at all.** This is the single biggest feature gap vs. any dedicated product analytics tool.
- **Scheduled delivery (Pro only):** PDF of the full report emailed on a cadence. No metric-based conditions. No Slack.
- **Workarounds people use:** BigQuery + Cloud Functions + Slack webhooks — requires engineering.

## Integrations
- **Native Google:** GA4, Google Ads, Search Console, YouTube, Google Sheets, BigQuery, Cloud SQL, Cloud Storage, Campaign Manager 360, Display & Video 360, Search Ads 360.
- **Third-party via partner connectors (paid):** Supermetrics (150+ sources), Windsor.ai, Porter, Catchr, Coupler.io, Two Minute Reports, Funnel.io, Littledata — each charges per connector per month.
- **Databases:** MySQL, PostgreSQL, Cloud Spanner. Via community/partner connectors only.
- **Ecommerce platforms:** Shopify (via partner connectors, no native), WooCommerce (via database connection or partner), BigCommerce (partner), Magento (database).

## Notable UI patterns worth stealing
- **Properties panel split into Setup and Style tabs.** Data first, cosmetics second. Clean separation that keeps the data configuration visible at all times.
- **Data source binds at report level, chart-level dimensions/metrics override.** Lets you swap a data source globally without editing every chart — the "change connector" button many agencies rely on.
- **Page-level vs. report-level filters.** Filters can be scoped to a single page (the ecommerce deep-dive) or cascade across all pages (the date picker). Good precedent for multi-page Nexstage dashboards.
- **Inline "edit data source" from a chart** — click the source icon on any chart to jump to schema editor. Keeps flow inside the canvas.
- **Theme editor with saved branded themes (2025 addition).** Agency-centric feature; Nexstage has a lesser version with workspace color accents.
- **Community Viz as a plugin system.** Chart types shouldn't all live in the core codebase.

## What to avoid
- **Don't ship a BI tool without alerts.** Looker Studio's biggest complaint is "I want to know when something breaks and nobody does." Silence is lethal.
- **Don't cache data for 12 hours silently.** If a number is stale, the UI must say so. Our `DataFreshness` component is the correct answer.
- **Don't require paid third-party connectors for the ecommerce data that's your product's whole point.** If Shopify is a core source, it's a native connector or it doesn't exist.
- **Don't let dashboards break with "quota exceeded" errors visible to end viewers.** Either cache aggressively or surface the problem in a way that teaches users what's happening.
- **Don't expose blended joins without warning about grain mismatches.** Users merge two sources and get nonsense aggregates; the UI should flag it.
- **Don't let "agency mode" (many client reports) be a maintenance time bomb.** Multi-workspace users need bulk operations, templating, and connector health monitoring at the root level.
- **Don't conflate "free" with "cheap in total cost."** Our users know Looker Studio is free but costs them $100–$250/mo in connectors. The savings argument has to include setup time.

## Why ecommerce brands pick it
- Free. Already have a Google account. Agency already uses it.
- One dashboard for GA4 + Google Ads is trivial to build.
- Client deliverable: "we'll send you a custom dashboard" is a sales motion for marketing agencies.

## Why they leave it
- Performance gets unbearable past ~10 charts per page or 3 concurrent viewers on GA4 free.
- Connector bills creep up (Supermetrics at $99–$299/mo undermines the "free" claim).
- Shopify data requires paid connectors and the grain problems are real.
- Attribution is GA4's attribution — last-click-ish, 14-day window, and not reconciled against ad platforms.
- No alerts. No real-time. No way to trust the dashboard without a manual daily check.
- "My calculated field broke again" — schema changes at GA4 or Meta cascade into broken reports that no one notices until a client does.

## Sources
- https://lookerstudio.google.com
- https://cloud.google.com/data-studio
- https://cloud.google.com/looker/pricing
- https://docs.cloud.google.com/looker/docs/studio/looker-studio-pro-subscription-overview
- https://docs.cloud.google.com/looker/docs/studio/about-looker-studio-pro
- https://support.google.com/looker-studio/answer/7020039?hl=en (data freshness)
- https://supermetrics.com/template-gallery/looker-studio-ecommerce-dashboard
- https://supermetrics.com/template-gallery/looker-studio-shopify-overview-report
- https://portermetrics.com/en/templates/google-looker-studio/free-shopify-template/
- https://windsor.ai/looker-studio-shopify-dashboard-template/
- https://blog.coupler.io/shopify-looker-studio-templates/
- https://www.swydo.com/blog/looker-studio-limitations/
- https://www.swydo.com/blog/how-to-solve-looker-studio-quota-errors/
- https://www.swydo.com/blog/scaling-looker-studio/
- https://coefficient.io/looker-studio-limitations-and-workarounds
- https://seresa.io/blog/looker-studio-dashboards/looker-studio-is-slow-because-ga4-cant-answer-dashboard-questions
- https://lookerstudiomasterclass.com/blog/fix-slow-looker-studio-dashboards-data-retrieval-issue
- https://lookerstudiomasterclass.com/blog/looker-studio-pro-vs-free
- https://www.databloo.com/blog/responsive-reports-looker-studio/
- https://beastmetrics.io/blog/looker-studio-pro-pricing/
- https://whatagraph.com/reviews/looker-studio
- https://whatagraph.com/blog/articles/google-data-studio-pricing
- https://www.metabase.com/lp/metabase-vs-looker-studio (competitor-framed, take with salt)
