# Shopify Plus Reporting (Custom Reports + ShopifyQL Notebooks)

**URL:** https://help.shopify.com/en/manual/reports-and-analytics/shopifyql-notebooks (notebooks) | https://help.shopify.com/en/manual/reports-and-analytics/shopify-reports/report-types/custom-reports (custom reports builder) | https://shopify.dev/docs/api/shopifyql (query language reference)
**Target customer:** Shopify Plus merchants ($2,300/mo+ base subscription, typically $100k/mo+ GMV). Also partially surfaced to Advanced plan users ($399/mo) who get custom-report creation and scheduled exports but not the full ShopifyQL Notebooks app. Analyst-type personas inside mid-market brands; agencies working with Plus clients; in-house data teams who would otherwise reach for Looker or Metabase for store-only questions.
**Pricing:** Included in the plan — no separate SKU. Gating is the differentiator:
- **Basic ($39/mo)** — Overview + prebuilt reports only. Cannot save custom reports or schedule exports.
- **Shopify Standard ($105/mo)** — Same builder access; still no custom saves, no scheduling.
- **Advanced ($399/mo)** — Unlocks the **custom report builder** (freeform + cohorts modes), profit reports, inventory forecasting, and **scheduled email delivery** (daily/weekly/monthly).
- **Plus ($2,300/mo+)** — Adds **ShopifyQL Notebooks**, ShopifyQL query editor embedded in any report (Winter 2025), **Analytics API** access, benchmark peer cohorts, ~5+ year data retention, higher rate limits.
**Positioning one-liner:** "Analyst-grade querying on your commerce data — without leaving Shopify." The built-in answer to "we hired a data person and they want SQL."

## What they do well
- **ShopifyQL is a real, documented query language**, not a marketing gimmick. Syntax is reordered-SQL (`FROM {table} SHOW|VISUALIZE {cols} BY {dim} WHERE ... SINCE ... UNTIL ... COMPARE TO ...`) that reads like English and is explicitly designed for non-analysts. `SHOW` replaces `SELECT`, `BY` replaces `GROUP BY`, and `COMPARE TO` turns period-over-period analysis from a window-function rewrite into a clause.
- **Commerce-aware date semantics.** `SINCE -13m`, `DURING bfcm`, `DURING christmas` — the language ships with a retail calendar baked in. No one else in the BI world has this.
- **`VISUALIZE` is a first-class keyword.** Query returns a chart automatically (line for timeseries, bar for categorical, stacked bar for dimension+timeseries). Users don't configure the chart — the query shape implies it.
- **Notebooks are genuinely interactive.** Code blocks + text blocks (Markdown-style) + inline visualizations, like a Jupyter notebook but for commerce. Templates gallery ships with pre-written queries for common questions (top products, BFCM comparisons, cohort retention). `//` comments inside queries for documentation.
- **Embedded ShopifyQL editor in every report (Winter 2025).** Plus merchants see a "Query" toggle on every prebuilt report that drops them into the underlying ShopifyQL — edit the query, see the updated result live, save as a new custom report. Blurs the line between prebuilt and bespoke.
- **Freeform vs Cohorts exploration modes** inside the custom report builder. Freeform = pick dimensions/metrics/filters; Cohorts = customer-first pivot (first order date × nth-period retention, LTV by acquisition month). Two fundamentally different shapes, separate UIs, same underlying data model.
- **Scheduled email reports** (Advanced+) — daily/weekly/monthly CSV attached, to multiple recipients. Simple and table-stakes, but it's built-in, not a $49/mo add-on.
- **Benchmarks on Plus** — peer cohorts (same vertical, similar order volume, same primary market, same product categories, 30-day window) surfaced inline on the Overview dashboard. Triple Whale can only dream of this coverage because Shopify literally owns the population.
- **Sidekick AI writes the ShopifyQL for you.** Plain-English prompt → generated ShopifyQL → editable in the notebook cell. The AI layer is a natural-language escape hatch, not a replacement for the notebook.
- **Analytics API (Plus-only)** — lets merchants pipe ShopifyQL query results into their own warehouse or BI tool. No third-party ETL needed for commerce data if they live on Plus.

## Weaknesses / common complaints
- **Paywall is the #1 user complaint.** Custom reports are Advanced+. ShopifyQL Notebooks is Plus-only. The majority of Shopify merchants (Basic + Standard, ~70%+ of accounts) can't build their own reports at all; they see pre-built ones with filter/column tweaks. Forum sentiment: "they keep moving useful reports behind the paywall."
- **Notebooks UX is a step behind best-in-class notebook tools.** No cell dependencies / reactive execution like Hex or Observable. No collaborative editing in real time. Sharing is export-a-PDF or send-the-link-to-another-staff-user. Versioning is absent.
- **ShopifyQL is Shopify-only.** Can't join external data — no ad spend, no warehouse tables, no GSC, no email platform. The second a merchant wants blended ROAS, they're stuck back in spreadsheet land.
- **Commerce data model is opinionated, not extensible.** The `sales`, `orders`, `customers`, `products`, `inventory`, `benchmarks` datasets are fixed. You cannot define your own dataset or register a UDF. Custom metrics are expression-level only (`total_sales - total_shipping AS net`).
- **Visualization options are thin.** Line / bar / stacked bar / table / single value. Heatmaps arrived in Winter 2026; no funnel, no Sankey, no cohort heatmap in the notebook (Cohorts mode in the report builder has one, inconsistently).
- **Data latency inconsistency.** Overview dashboard is real-time on newer plans; certain reports (Profit, Inventory Forecasting) run on daily rollups and lag 24h. During BFCM peaks, even Plus merchants report 1–24h lag. No explicit freshness badge on most surfaces.
- **Report discovery is still poor.** 60+ prebuilt reports in long left-nav lists; the custom-report-builder button is on the report page itself, so first-time users don't know it exists until they're already in a prebuilt report.
- **Cohorts mode is rigid.** You pick first-order-date as the cohort dimension and LTV as the metric — that's basically it. No custom cohort definitions (e.g., "customers who bought SKU X first"), no cohort-vs-cohort comparison.
- **Notebooks don't integrate with apps.** A third-party app cannot embed a notebook or write to one. Extensibility is one-way: notebooks can read Shopify data, but nothing external reaches them.
- **Sidekick-generated ShopifyQL hallucinates columns.** Users on Reddit report queries that reference non-existent fields; the error is surfaced only when you run the cell.
- **Benchmarks coverage drops off in niche verticals.** If your cohort has fewer than some threshold of peer stores, Shopify hides the benchmark — many Plus merchants in niche categories see "insufficient data" messaging.

## Key screens

### Analytics → Reports (Plus view)
- **Layout:** Left-nav category list (Acquisition, Behavior, Customers, Finance, Fraud, Inventory, Marketing, Orders, Profit, Retail Sales, Sales). Right pane: report header with title + date range + **Query toggle** + Export button + Save-as-custom-report button. Body: one or two auto-charts on top, filterable data table below with sortable columns and a right-side **Configuration panel** (Freeform mode) where users add/remove columns, change filters, toggle group-by.
- **Query toggle (Plus-only):** Click "Query" in the header → side panel slides open showing the ShopifyQL that produced the current view. Edit → re-run → the report re-renders. This is the bridge between prebuilt reports and raw notebook querying.
- **Exploration modes:** Freeform (dimensional pivot — default) vs Cohorts (first-order-date × nth-period matrix). Toggle at top of config panel. Cohorts mode renders a colored heatmap table; Freeform renders a flat table.
- **Schedule button (Advanced+):** Add recipients, pick cadence (daily/weekly/monthly), pick format (CSV attachment). Lands in email.
- **Save as custom report:** Snapshots the current filter/column/mode state as a named report. Custom reports appear at the top of the left-nav in a "Custom" group.
- **Screenshot refs:**
  - https://help.shopify.com/en/manual/reports-and-analytics/shopify-reports/report-types/custom-reports
  - https://help.shopify.com/en/manual/reports-and-analytics/shopify-reports/report-types/custom-reports/create-custom-explorations

### ShopifyQL Notebooks (Plus-only app)
- **Layout:** Document-style canvas with a linear stack of cells. Each cell is either a **Code block** (ShopifyQL query + inline result + auto-visualization) or a **Text block** (Markdown-ish with headings, body text, static images). "+" button between cells to add a new one. Right rail: dataset reference (click to insert a `FROM` clause), templates gallery.
- **Code block UX:** Monospace query editor with syntax highlight. Run button (or Cmd+Enter). Result renders directly below as a table + chart, chart type inferred from the `VISUALIZE` clause. If no `TYPE` specified, Shopify picks a "smart default" (timeseries → line, categorical → bar).
- **Comments:** `//` single-line inside queries. Used to explain intent to teammates.
- **Templates:** Click "Templates" from a code block → modal with pre-written queries categorized (Sales, Customers, Products, Retention, BFCM). Insert as the current cell's content.
- **Sharing:** Notebooks are owned by a staff user; shareable with other staff accounts in the same shop. No public share link, no read-only guest link. Export to PDF for external stakeholders.
- **Data freshness:** Near-real-time for most datasets; benchmarks dataset refreshes daily.
- **Screenshot refs:**
  - https://help.shopify.com/en/manual/reports-and-analytics/shopifyql-notebooks/creating-notebooks
  - https://shopify.engineering/shopify-commerce-data-querying-language-shopifyql

### Custom Report Builder (Advanced+)
- **Entry point:** "Create custom report" button inside any prebuilt report view, or "New report" from the Reports landing. Start from blank or duplicate the current prebuilt report's config.
- **Freeform mode UI:** Right-side configuration panel with three sections — **Columns** (checkbox list of available dimensions and metrics for the selected dataset), **Filters** (builder with field + operator + value rows, AND/OR), **Date range** (standard picker with compare-to toggle). Left pane: live-updating table + auto-chart preview.
- **Cohorts mode UI:** Different panel layout — pick cohort dimension (always first-order date, weekly or monthly bucket), nth-period metric (LTV, orders, AOV, retention %), heatmap rendered as the canvas. Colored gradient from pale to saturated; hover a cell for exact value.
- **Save + schedule:** Save names the report; schedule is a separate action in the report menu. Scheduled reports deliver via email as CSV.
- **Limitations users hit:** No joins across datasets (sales + ad spend impossible), no custom SQL expressions inside Freeform (Plus users can fall back to ShopifyQL, but Advanced cannot), no parameterized date ranges per viewer.

### Sidekick AI (all plans, deeper on Plus)
- **Layout:** Right-side chat sidebar on the Shopify admin. Ask a question in natural language ("Show me my top 10 products by profit last quarter for UK customers") → Sidekick generates a ShopifyQL query, runs it, returns the table + chart inline in the chat. "Open in Notebook" button promotes the answer into a ShopifyQL Notebook cell (Plus-only path).
- **Context awareness:** Sidekick knows the current page you're on (e.g., if you're viewing a product, "show me the sales trend" implicitly filters to that product).
- **Known issue:** Generated queries sometimes reference columns that don't exist in the current dataset — the error surfaces only at run time.

### Shopify Plus Benchmarks Overlay
- **Layout:** Benchmark cards appear inline on the Overview dashboard and inside Sales/Customer reports. Each card shows your metric vs. the peer cohort median (same vertical, similar volume, same primary market), with a small "you vs. peers" bar and percentile ranking.
- **Peer cohort composition:** Shopify picks the cohort based on your past-30-day order volume, primary market, and product categories. No user control over cohort definition — it's algorithmic and opaque.
- **Coverage gaps:** Niche verticals show "insufficient data" when the cohort is too small to anonymize.

## The "angle" — what makes them different
Shopify Plus reporting's angle is **data model intimacy plus query-language ergonomics**. Nobody else can ship a query language that speaks `DURING bfcm` natively, because nobody else owns the commerce schema. The data model being purpose-built for commerce (fully additive metrics across all dimensions, daily granularity, benchmarks as a first-class dataset) is the moat — not the notebook UX.

The second angle is the **Plus-tier paywall as a signal.** By putting ShopifyQL Notebooks behind $2,300/mo, Shopify positions the feature as "analyst-grade." That's aspirational marketing — most Plus stores that install the app use two templates and never write a raw query — but it works because the merchants who actually need it (mid-market data hires) are specifically on Plus.

What Plus reporting **doesn't** do, and structurally can't:
- **Ad spend.** ShopifyQL can't query Meta/Google/TikTok cost.
- **Cross-store.** Multi-shop Plus accounts see per-shop notebooks, not a unified view. (Winter 2026 adds "multi-store analytics" but only at dashboard level, not notebook level.)
- **Platform-vs-store disagreement.** Shopify is the store; showing "Meta claims X, store shows Y" is politically impossible for them.
- **Custom datasets.** You cannot register your own table or load external CSVs into the notebook environment.

Nexstage's opening: everything ShopifyQL Notebooks does well — commerce-aware date semantics, `COMPARE TO` as a first-class clause, visualization-inferred-from-query — should be aspirational table stakes for our future query layer, *scoped across Shopify + WooCommerce + ad platforms simultaneously*. A Nexstage notebook that can write `FROM orders JOIN ad_spend ... WHERE store_platform IN (shopify, woocommerce) DURING bfcm` is the thing Shopify structurally cannot ship.

## Integrations
- **Stores:** Shopify only. No way to query a non-Shopify data source.
- **Ad platforms:** Not integrated. ShopifyQL has no concept of Meta/Google/TikTok spend.
- **Apps:** Shopify App Store has ~20 reporting apps (Mipler, Report Pundit, Better Reports) that fill gaps — ad-spend joins, custom datasets, fancier visualizations. Most install as an embedded page in the admin.
- **Warehouse/BI:** Analytics API on Plus lets you pull ShopifyQL results into Snowflake, BigQuery, Redshift, Looker, etc. No native bi-directional sync — it's a pull-only API.
- **Export:** CSV from any report; scheduled CSV via email on Advanced+.
- **Embedding:** No public embed for notebooks or custom reports.

## Notable UI patterns worth stealing
- **`COMPARE TO` as a first-class query keyword.** Period-over-period is the single most-asked ecommerce question; making it a one-word clause instead of a window function or join is a huge UX win. Nexstage's query/metric layer should expose compare-to as a toggle that's as cheap as typing a word.
- **`DURING bfcm`, `DURING christmas` — named retail calendars built in.** Nexstage should have a "retail calendar" table with named date ranges users can pick (`DURING blackfriday_2024` etc.) — zero merchant ever wants to remember the exact date range for BFCM.
- **Visualization inferred from query shape.** No separate "chart config" step — if the query groups by a date dimension, it's a line; if by a category, a bar; if by date × category, stacked bar. Reduces clicks-to-chart from five to zero. Nexstage's metric surfaces should follow this — let the shape of the underlying query pick the chart.
- **Code + text + visualization cells in one document (notebook model).** This is the natural form for "an analyst handing a finding to the founder" — prose explaining what the number means, followed by the number. Nexstage should consider a lightweight notebook surface for canned investigations (e.g., "why did revenue drop last week?" → notebook with narrative + query results).
- **Query toggle in every prebuilt report.** Blurs the line between "use the prebuilt thing" and "write your own." Great pattern — our prebuilt dashboards should let a curious user peek at the underlying query and clone it as a custom metric.
- **Templates gallery inside the code editor.** Pre-written queries categorized by question ("top products by profit", "retention by cohort"). Reduces blank-page paralysis. Nexstage should ship a library of pre-written "investigations" users can one-click into.
- **Freeform vs Cohorts as two separate builder modes.** Acknowledges that cohort analysis is structurally different from dimensional pivots — not just another filter. Our customer/LTV surfaces should have a dedicated Cohorts UI, not shoehorn it into the generic "reports" builder.
- **Peer-cohort benchmarks inline on every relevant metric card.** Not a separate benchmarks page — shown alongside your number. Nexstage can't match Shopify's peer data coverage, but we can borrow the pattern (put benchmarks next to metrics, not in a siloed view).
- **Scheduled email reports as a built-in feature, not an add-on.** Advanced+ merchants get daily/weekly/monthly CSV for free. No "that's $49/mo extra" moment.

## What to avoid
- **Don't paywall the query editor.** Shopify's decision to lock Notebooks behind the $2,300/mo tier is a significant reason why analysts at $50k/mo stores go to Metabase or Lifetimely instead. Our power-user surface should be available to every paying workspace; throttle query complexity/volume, not access.
- **Don't ship a notebook without collaboration.** Read-only shareable links, teammate comments, version history, named-query permalinks — these are table stakes in 2026 notebook UX. Shopify's missing all of them and it shows.
- **Don't invent a query language unless you own a data model nobody else has.** ShopifyQL is defensible because Shopify owns the schema. Nexstage should prefer SQL (potentially with commerce-aware macros / view layer) over a bespoke dialect — our users already know SQL, and we don't have Shopify's brand leverage to force a new language.
- **Don't let commerce-aware macros be the only escape hatch.** `DURING bfcm` is great, but the moment a merchant wants `DURING my_custom_promo`, ShopifyQL has no answer. A seeded calendar table that users can extend with their own date ranges is the right pattern.
- **Don't hide the custom-report-builder entry point.** Shopify's "click into a prebuilt report, then look for the 'create custom' button" flow is bad discovery. Put the builder on the Reports landing page as a primary action.
- **Don't let the notebook visualization set lag behind the table builder.** If the notebook can only do line/bar/stacked bar, but the custom-report builder has heatmaps, the inconsistency itself is a bug. Pick one visual grammar and ship it everywhere.
- **Don't generate buggy queries via AI and only error at run time.** Sidekick's hallucinated-column problem is a trust-killer; our AI query generation needs schema-grounded validation at generation time, not execution time.
- **Don't make cohort analysis so rigid that users can't define their own cohort.** "First order date" is one axis; "first SKU bought", "first acquisition channel", "first LTV tier" are equally valid. Our cohort builder should let users pick the cohort-defining event.

## Sources
- https://help.shopify.com/en/manual/reports-and-analytics/shopifyql-notebooks
- https://help.shopify.com/en/manual/reports-and-analytics/shopifyql-notebooks/creating-notebooks
- https://help.shopify.com/en/manual/reports-and-analytics/shopify-reports/report-types/custom-reports
- https://help.shopify.com/en/manual/reports-and-analytics/shopify-reports/report-types/custom-reports/create-custom-explorations
- https://help.shopify.com/en/manual/reports-and-analytics/shopify-reports/report-types/custom-reports/benchmarks
- https://shopify.dev/docs/api/shopifyql
- https://shopify.engineering/shopify-commerce-data-querying-language-shopifyql
- https://shopify.engineering/building-commerce-data-models-with-shopifyql
- https://changelog.shopify.com/posts/live-view-benchmarks-and-customer-cohort-analysis-are-now-supported-in-the-new-analytics-experience
- https://www.shopify.com/editions/winter2026
- https://www.shopify.com/blog/benchmarks
- https://swankyagency.com/how-to-use-shopifyql-notebooks-bfcm/
- https://mipler.com/blog/shopify-reports/
- https://www.sarasanalytics.com/blog/shopify-reports
- https://ask-luca.com/blogs/shopify-analytics-guide
- https://www.charleagency.com/articles/shopify-plus-vs-advanced/
- https://www.reportpundit.com/post/a-z-of-advanced-shopify-reports
- https://blog.boostcommerce.net/posts/shopify-ql-new-data-querying-language-for-shopify-merchants
- https://ecommercefastlane.com/write-your-own-data-story-with-shopifyql-notebooks/
- https://www.getmesa.com/blog/shopify-sidekick/
- https://instant.so/blog/shopify-winter-editions-everything-you-need-to-know
