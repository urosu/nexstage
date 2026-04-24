# Metabase

**URL:** https://www.metabase.com
**Target customer:** Series A–C startups, internal tool teams, ops and growth analysts at mid-market companies. Loved by engineering-adjacent founders and analytics-curious PMs. In ecommerce it's the "we have a Postgres/MySQL replica of Shopify and want dashboards" crowd — often shops running headless Shopify or WooCommerce with a direct DB connection. 50,000+ companies claimed, dominant in the self-hosted BI niche alongside Superset.
**Pricing:**
- **Open Source (self-hosted)** — $0. Unlimited queries, charts, dashboards, users. Community support. Basic embedding carries a "Powered by Metabase" badge. Deploy via Docker, JAR, or Kubernetes.
- **Starter (Cloud)** — $100/mo + $6/user/mo. First 5 users included. Managed upgrades, backups, monitoring. 3-day Slack/Teams/email support SLA.
- **Pro** — $575/mo + $12/user/mo. First 10 users included. Cloud or self-hosted. Row/column-level permissions, SSO, advanced caching, white-label embedding, environment management (dev/prod).
- **Enterprise** — starts $20k/year. Dedicated success engineer, 1-day SLA, air-gapped / single-tenant hosting options, procurement help.
- Annual pricing saves 10%. Users only count toward billing when they sign in.
**Positioning one-liner:** "The easy, open source business intelligence tool" — self-serve analytics that non-technical teams can use without writing SQL, but with SQL under the hood when they want it.

## What they do well
- **Open source is a legitimate escape hatch.** Self-hosted Metabase runs on Docker with one command, connects to your Postgres in five minutes, and is genuinely usable. No trial countdown, no upsell modals.
- **Visual query builder is the cleanest in the category.** "Ask a question" → pick a table → add filters/summaries/groupings → visualize. Non-SQL users actually build things and ship them. The builder is the default; SQL is the escape hatch, not the other way around.
- **X-ray auto-analysis.** Click a table or a chart and get an auto-generated dashboard full of distributions, segments, time series, and related-table explorations. Zero-config data exploration, which Looker Studio has nothing like. Saves as a "generated dashboard" users can iterate from.
- **Drill-through works everywhere.** Click a bar, get options: break down by category, by time, filter to that value, view the underlying records. This interaction model is consistent across every chart type.
- **Models and the Semantic Layer.** Users can define reusable "models" that wrap a SQL query or a question, giving the rest of the team a curated table with pretty column names, types, and descriptions. This turns "the analyst's scratch SQL" into "the source of truth the sales team queries".
- **Dashboard subscriptions** (formerly "Pulses") — scheduled deliveries to email or Slack with the rendered dashboard or CSV attachments. Non-users can receive them. This is how Metabase competes with Looker's scheduled reports.
- **Alerts on individual questions.** Set a threshold on any saved question — "alert me when this number crosses X" or "alert me when rows return" — and Metabase pings Slack/email. Trigger-based, not scheduled.
- **Metabot AI (2025).** Natural-language chat on top of your semantic layer. "How many orders did we get last week by region?" → generates SQL, runs it, renders a chart. Impersonates the logged-in user's permissions, so no new access layer to manage. Works with Claude, Cursor, ChatGPT via MCP server.
- **Embedding story is strong.** Static signed-URL embeds, interactive JWT-authenticated embeds, full white-label with Pro. Many SaaS products ship customer-facing dashboards on Metabase embedded.
- **UI cleanliness.** White, minimal, Stripe-adjacent aesthetic. Low-chrome. The dashboard grid is readable; default color palette is neutral and data-forward.
- **Direct SQL connection to Shopify/WooCommerce databases** when users have a Postgres/MySQL replica. No paid connector tax. This is the Metabase wedge for technical ecommerce founders.

## Weaknesses / common complaints
- **No native ecommerce connectors.** Metabase connects to databases, not to Shopify's REST API or Meta Ads. Users need Fivetran/Stitch/Airbyte/Hightouch to pipe ad data and SaaS sources into a warehouse first. This doubles the infrastructure and operational cost.
- **Performance degrades with size.** Without a warehouse (BigQuery/Snowflake/Redshift), complex dashboards on a busy OLTP Postgres either slow production queries or return slowly themselves. "Run against a replica" is load-bearing advice.
- **Drill-through depth is limited.** Click-through breakdowns work one level deep well; chained breakdowns (geo → campaign → day-of-week) become clunky.
- **Chart vocabulary is narrow for product analytics.** Line, bar, area, pie, scatter, pivot table, funnel (basic), map, row chart, progress, detail view, waterfall, sankey (2025). **No retention curve, no cohort heatmap, no flow/sankey-as-default, no path analysis.** Product analytics users feel the gap immediately.
- **Dashboard layout is a rigid grid.** Drag cards onto a 24-column grid, resize within it. No freeform canvas; no overlapping; no tabs (until 2024); no sections with their own backgrounds. Pretty, but inflexible for long-form narrative dashboards.
- **No cross-dashboard filter propagation.** Filters live per-dashboard; navigating from one dashboard to another loses context.
- **Self-hosted upgrades are manual.** Docker images, migration scripts, occasional breaking changes. Cloud is the answer but doubles the cost.
- **Pro pricing jump is steep** — $100/mo Starter → $575/mo Pro is a 5.75× jump for row-level permissions and SSO. Many SMBs bounce off the paywall for compliance features.
- **Metabot AI is early.** Handles simple aggregations; chained logic (joins + window functions + time comparisons) often produces wrong SQL or silently falls back to surfacing an existing question. A Metabase-written blog even admits it's "limited to a single level of aggregation and grouping."
- **Questions and Dashboards drift out of sync.** A popular pattern is "dashboard with 20 questions", each question lives as an independent saved object; renaming a question, or changing its underlying query, ripples into the dashboard with no diff view. Teams end up with hundreds of orphan questions.
- **Admin UX weaker than the user UX.** Permissions, groups, data sandboxing, caching — these are all powerful but the admin surface is dense and unforgiving.

## Key screens

### "Ask a Question" — visual query builder
- **Layout:** Three-step wizard at top (Data → Filter → Summarize → Visualize) + an expanding result table below.
- **Step 1 — Data:** Picker lists data sources, then schemas, then tables, then models. Type-ahead search.
- **Step 2 — Filter:** Add filter → pick column → pick operator (equals, contains, between, time range) → enter value. Compound filters with AND/OR.
- **Step 3 — Summarize:** Count, sum, average, distinct, median, percentile, min/max, standard deviation. "Group by" on any column, including derived date buckets (by hour/day/week/month/quarter/year).
- **Step 4 — Visualize:** Chart picker on the right panel. Chart type suggestions based on the data shape.
- **"View the SQL"** toggle shows the query Metabase generated. One-click convert to SQL mode (no going back; converting is destructive to the visual builder).
- **Screenshot refs:**
  - https://www.metabase.com/learn/metabase-basics/querying-and-dashboards/visualization/chart-guide
  - https://www.metabase.com/features/analytics-dashboards

### SQL Editor (native query)
- **Layout:** Single-pane SQL editor with autocomplete, variable substitution, and result table below.
- **Variables:** `{{variable_name}}` in SQL becomes a dashboard/question filter control. Supports text, number, date, field filter (typed to a column).
- **Snippets:** Saved SQL fragments reusable across queries.
- **Run results:** Tabular by default; drop into visualization picker to chart.

### Dashboard (grid layout)
- **Layout:** 24-column responsive grid. Drag "cards" (saved questions) from a sidebar; resize by dragging the corner. Cards can be chart, number, filter, text/markdown, or heading.
- **Dashboard filters:** Top bar. Each filter is typed (text / number / date / location) and maps to question variables or column filters across multiple cards.
- **Tabs (2024):** Dashboards can have multiple tabs — "Overview", "Products", "Customers" — for multi-page narratives.
- **Edit vs. view mode:** Toggle top-right. Edit mode reveals the grid, drag handles, and an "Add card" button.
- **Auto-refresh:** Dropdown (1min / 5min / 15min / hour / off). Drives TV-wall use cases.
- **Interactive drill-through:** Click any chart value → pop-up with "Break out by...", "Filter by this value", "See these X rows". Consistent across chart types.

### X-ray (auto-analysis)
- **Entry points:** Click the "X-ray" icon on any table, column, chart value, or record.
- **Output:** A generated dashboard with sections — time series of key metrics, distributions by top categorical columns, segment comparisons, related-table summaries.
- **Interactions:** "Zoom in" to a specific field, "Zoom out" to the parent table, "X-ray something related" to pivot to a linked table.
- **Save:** "Save this" promotes the generated dashboard into a real dashboard that the user can edit.
- **Use case:** New data source onboarding ("what's in this table?"), hypothesis generation, demo-day eye candy.
- **Screenshot refs:**
  - https://www.metabase.com/docs/latest/exploration-and-organization/x-rays
  - https://www.metabase.com/glossary/x-ray

### Models (Semantic Layer)
- **Definition:** A curated table backed by a SQL query or a saved question. Columns get human-readable names, types, descriptions, and semantic types (email, URL, category, etc.).
- **UI:** Model editor has a Metadata tab for column-level descriptions and a Query tab for the underlying SQL or visual query.
- **Use:** Downstream questions reference the model like a table. Metabot AI uses model descriptions as the grounding for natural-language queries.
- **Status:** The "semantic layer" pitch. Underselling — most users don't know models exist until an analyst introduces them.

### Collections (content organization)
- **Layout:** Folder tree on the left, list view on the right. Collections can nest. Items are dashboards, questions, models, or subcollections.
- **Permissions:** Collection-level (view / edit / no access) applied to groups.
- **Pinned items:** Pin up to 6 items per collection for quick access.
- **"Trash":** Soft-deleted items live here for recovery.

### Metabot AI chat
- **Layout:** Right-hand chat drawer or full-page conversation.
- **Grounding:** Uses models and verified content; respects user permissions.
- **Output:** SQL query + auto-rendered chart. One-click "Save as question" promotes into the library.
- **Also:** Slack integration (ask Metabot in a thread), CSV upload ("here's a file, ask me about it").

### Alerts & Subscriptions
- **Alerts (per-question):**
  - Goal-based: "When results go above/below X" (for scalar questions).
  - Row-based: "When this query returns any rows" (for exception tables).
  - Progress: "When % of goal crosses threshold".
  - Delivery: Slack channel, Slack user, email. Cadence: every time the data changes, or capped once per hour/day.
- **Subscriptions (per-dashboard):**
  - Send the whole dashboard on a schedule (hourly / daily / weekly / monthly).
  - Delivery: Slack channel or email (to people without Metabase accounts too).
  - Include filter values: subscription can run the dashboard with specific filter values pre-applied.
- **Not present:** Anomaly detection, forecasted-threshold alerts, multi-metric compound alerts. These require the user to define thresholds.

## Chart vocabulary
- **Strong:** line, bar (vertical/horizontal/stacked/grouped/normalized), area, scatter, pivot table, number/scalar, progress bar, gauge, funnel (basic), map (region / pin / grid), sankey (added in Metabase 60), waterfall, trend (number + sparkline), detail view, combo.
- **Missing:** retention curve, cohort heatmap, flow/path, distribution (except via X-ray), box plot, treemap, histogram-as-first-class.
- **Ecommerce-relevant gaps:** no cohort retention heatmap, no funnel-with-drop-off-annotations, no time-to-convert distribution, no basket/co-occurrence chart. Ecommerce users reach for SQL + custom visualizations.

## Query builder / abstraction level
- **Three layers, in user-preference order:**
  1. **Visual builder** (filter → summarize → group → visualize) — the default, and the best in the category. Most questions don't need SQL.
  2. **Models** — curated "virtual tables" with typed columns and descriptions; the semantic layer.
  3. **SQL editor** — full SQL with variable substitution; one-way conversion from visual to SQL.
- **Metabot AI overlay** on top of all three. Translates NL to SQL against the semantic layer.
- **No block-based / no-code query syntax** beyond the visual builder. No "Tableau Prep"-style transformation graph.

## Alerting & subscriptions
- **Alerts:** per-question, threshold or row-based. Slack, email. Cadence throttling.
- **Subscriptions:** per-dashboard, scheduled. Slack, email to anyone (account or not).
- **Slack integration:** bot installs, channels or DMs, filter-context preserved in Slack message. Solid.
- **Missing:** anomaly detection, on-call rotation, severity levels, metric-specific delivery (always the whole dashboard or always the question — nothing in between).

## Integrations (ecommerce angle)
- **Databases (native, the strong point):** Postgres, MySQL, MariaDB, BigQuery, Snowflake, Redshift, Databricks, ClickHouse, SQL Server, Oracle, SQLite, Druid, MongoDB, Presto/Trino, SparkSQL. ~25 DB types.
- **SaaS sources:** None direct. Users need Fivetran/Stitch/Airbyte/Hightouch to pipe Shopify/Meta/GA4 into a warehouse, then Metabase queries the warehouse.
- **Output destinations:** Slack, email, CSV/XLSX download, PDF (dashboard subscriptions), JSON (API), MCP for Claude/Cursor/ChatGPT.

## Notable UI patterns worth stealing
- **Click → drill-through everywhere** — universal affordance. Every chart value is a right-click menu in spirit: break out, filter, zoom, view records.
- **X-ray as onboarding.** New data source → auto-generated exploration dashboard. Dramatically shortens the "empty state → insight" loop. Nexstage's historical-import flow could do this on first workspace creation — "here's what's in your Shopify data, generated in 30 seconds".
- **Models / Semantic Layer as a named concept.** Separates "data the analyst curated" from "data as the SaaS vendor dumped it". Any multi-tenant analytics tool needs a version of this.
- **Question as a durable, shareable primitive.** Not just a chart on a dashboard — a question has a URL, owner, history, description, related dashboards. Users can "go to a question" as a first-class action.
- **Filter variables with typed autocomplete** — `{{date_range}}` in SQL becomes a dashboard date-picker that's typed. Keeps the SQL clean and the UI typed at the same time.
- **Alert on a single question, subscribe to a whole dashboard** — the split is correct. Alerts are for one-number triggers; subscriptions are for status-of-everything delivery.
- **"Powered by Metabase" badge on free tier, removable on Pro** — classic open-source commercialization; viral acquisition without killing the free offering.
- **Pin-top items in a Collection** — prevents the "folder with 200 unlabelled charts" problem that every BI tool eventually has.
- **Slack delivery with preserved filter context** — the message says "here's the dashboard with this date range applied", not just a PDF.

## What to avoid
- **Don't require users to bring their own warehouse.** Metabase's biggest onboarding friction is "get Fivetran wired up first". Nexstage should never force that step — the ingestion layer is our product.
- **Don't ship a BI tool with only scheduled delivery and threshold alerts.** Anomaly detection is table stakes in 2026; Metabase doesn't have it because their model is "you define the thresholds, we send you the Slack message." Ecommerce users don't know where to set thresholds.
- **Don't let Questions and Dashboards drift.** When the underlying query changes, the dashboard should show a diff or a warning. Metabase ships silent ripples.
- **Don't force SQL mode to be one-way.** Visual → SQL is lossy; converting back shouldn't be impossible.
- **Don't ship a dashboard grid without tabs or sections.** Metabase took years to add tabs; long-form ecommerce dashboards need structural breaks.
- **Don't paywall row-level permissions.** Metabase gates RLS behind the $575/mo Pro tier. For multi-store ecommerce users this is the basic requirement of multi-tenancy — we shouldn't gate it.
- **Don't rely on users writing SQL to unlock advanced analytics.** Metabase's retention, cohort, funnel, and flow stories are all "write SQL". That leaves 90% of our audience behind.
- **Don't ship Metabot-style AI without verified semantic grounding.** Metabase's AI is most valuable on top of well-curated Models; without them it hallucinates. Our AI surface needs to be restricted to our own attribution schema — not free-form SQL.

## Why ecommerce brands pick it
- Open-source and self-hosted — technical founders love the control. No vendor lock-in, no per-MTU pricing.
- Direct Postgres/MySQL connection to their Shopify/WooCommerce database; no Fivetran needed if they already have a replica.
- SQL is there when the visual builder runs out — ceiling is tall for analytically curious operators.
- X-ray and drill-through do the "what's in my data" job better than Looker Studio's static templates.
- Slack alerts on revenue thresholds, low stock, refund spikes.

## Why they leave it
- No native ad-platform or store-platform connectors. Ecommerce data pipelining (Fivetran for Meta + Shopify + Klaviyo) is $300–$1,000/mo and engineering time.
- No retention curves, cohort heatmaps, or funnel analysis out of the box. "Write SQL" answer doesn't cut it for growth teams.
- Dashboards become unreadable past 12–15 cards. Grid layout is rigid.
- No blended attribution (Meta vs. Shopify reconciliation) — that's something a user has to build in SQL, and nobody gets it right.
- Pro price jump ($100 → $575) for row-level permissions feels hostile for a 10-store agency.
- Metabot AI isn't yet reliable enough to replace the "I need an analyst" moment.

## Sources
- https://www.metabase.com
- https://www.metabase.com/pricing
- https://www.metabase.com/features/analytics-dashboards
- https://www.metabase.com/features/metabot-ai
- https://www.metabase.com/docs/latest/questions/alerts
- https://www.metabase.com/docs/latest/dashboards/subscriptions
- https://www.metabase.com/docs/latest/exploration-and-organization/x-rays
- https://www.metabase.com/docs/latest/configuring-metabase/slack
- https://www.metabase.com/docs/latest/ai/metabot
- https://www.metabase.com/glossary/x-ray
- https://www.metabase.com/community-posts/metabase-for-shopify-merchants
- https://discourse.metabase.com/t/how-efficient-is-metabase-with-woocommerce-as-datasource/1109
- https://discourse.metabase.com/t/shopify-to-metabase/14913
- https://discourse.metabase.com/t/using-metabase-with-woocommerce/229509
- https://www.metabase.com/lp/metabase-vs-looker-studio
- https://www.metabase.com/learn/metabase-basics/querying-and-dashboards/visualization/chart-guide
- https://www.metabase.com/learn/metabase-basics/overview/tour-of-metabase
- https://www.metabase.com/learn/metabase-basics/overview/next-steps
- https://dev.to/metabase/metabase-60-we-made-ai-open-source-official-mcp-server-metabot-in-slack-split-panel-charts-and-3eb0
- https://letdataspeak.com/metabase-ai-features/
- https://www.graphed.com/blog/looker-studio-vs-metabase
- https://seresa.io/blog/reporting-dashboards/looker-studio-vs-metabase-which-free-bi-tool-for-woocommerce-bigquery-data
- https://www.trustradius.com/compare-products/looker-studio-vs-metabase
