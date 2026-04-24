# Peel Insights — UI teardown

**Scope:** screen-by-screen deep dive. Companion to [`peel-insights.md`](peel-insights.md). Reference from page specs in `/docs/pages/`.

Sources: help.peelinsights.com (dashboards, magic-dashboards, segmentation-options, peel-slices), peelinsights.com (Peel Quickstart Guide, Magic Dashboards marketing page, Custom Dashboards & Slices solution page, UI Update for Reporting post, New Segmentation post, Create Dashboards post), aazarshad.com Peel Insights review, smbguide.com review. All descriptions synthesised from help-doc captions, quickstart screenshots, and marketing-page descriptions.

## Global chrome

### Top bar
- **Logo placement:** Top-left. "Peel" wordmark with the Pal mascot (a small round character). Clicking returns to the Essentials tab.
- **Workspace/store switcher:** Peel is Shopify-only and typically one workspace per Shopify store, but multi-store agency accounts have a workspace switcher under the user avatar at the top-right, not top-left.
- **Global date range:** Each metric carries its own date controls — there is no app-wide date picker. Peel's time-period controls are attached to reports and metrics individually, a design inherited from its cohort-analysis origins. Common presets: 30 days, 3 months, 6 months, 1 year, All-time, custom.
- **Global filters:** Peel doesn't use global filters; instead users apply "Segment Analysis" via any metric's three-dot menu. Segments flow into the report's view for that session.
- **User menu, notifications, help:** Top-right. Notifications bell, help (?), user avatar. Help opens a searchable help center panel with onboarding videos.
- **Command palette / search:** Search bar at top of the main canvas on most pages. Typing searches metrics, reports, dashboards, and template library entries.

### Sidebar (left menu)
- **Sections (in order, top to bottom):**
  1. **Essentials** — home/default tab. Curated collection of the most important metrics (Repurchase Rate by Cohort, Market Basket Analysis, Product and Customer analytics).
  2. **Dashboards** — list of pinned dashboards (Customer, Subscriptions, Marketing, Multitouch Attribution, plus user-created).
  3. **Metrics** — browse all metrics by category
  4. **Reports** — saved filtered metric views
  5. **Slices** — multi-metric drill-down tables
  6. **Audiences** — custom customer groups buildable for Klaviyo/Meta export
  7. **Goals** — trackable KPI goals
  8. **Magic Dashboards** — AI-generated dashboards from natural-language questions
  9. **Templates** — library of pre-made dashboards, slices, and audiences
  10. **Annotations** — event markers applicable across reports
  11. **Pal's face** (bottom of sidebar) — opens a context menu to access Billing, Account Settings, Connections & Datasets, Invite Users, Integrations. This is unique; most tools have a gear icon for settings.
- **Collapsible?:** Yes — sidebar collapses to icon rail with Pal at the bottom.
- **Active state visual:** Peach/salmon left-edge accent with a light-pink row tint. Peel's brand palette has warmer tones than competitors.
- **Nesting depth:** Two levels — section → folder/report.

### Color / typography / density
- **Primary accent color:** Peachy coral / warm pink (`#ff7d5e`-ish, their brand). Secondary is a soft navy for text.
- **Chart palette:** Warm, analogue palette — corals, teals, yellows, purples. Less channel-coded than Triple Whale/Northbeam; more BI-tool-generic.
- **Font family, number formatting:** Friendly sans-serif (Circular-like). Numbers displayed with commas; decimals to 2 places for currency/ratios.
- **Approximate density:** Lower than Triple Whale; ~6–10 widgets above the fold at 1440×900. Peel favors fewer, larger visualisations with room to breathe — it reads as an analyst's BI tool, not a dashboard-per-square-inch dashboard.

---

## Screen: Essentials (home)

- **URL path:** `/essentials` or `/`.
- **Layout:** A curated single-scroll page with several sections:
  1. **Top KPI row** — Repurchase Rate by Cohort headline metric with hero chart
  2. **Market Basket Analysis** tile — a compact table of commonly-bought-together SKU pairs
  3. **Product Analytics** — top products by revenue, with mini line charts
  4. **Customer Analytics** — new vs returning customer counts, repeat rate
- **Above-the-fold elements:**
  1. "Essentials" header with quickstart link
  2. Cohort chart block (hero) — retention curve by acquisition month
  3. KPI ticker row: Total Revenue, Orders, LTV, Repeat Rate
  4. Cohort heatmap (Month 0 / Month 1 / Month 2 / Month 3 …) below the chart
- **Below-the-fold:**
  - Market Basket Analysis matrix
  - Top products table
  - Key customer segments summary cards
- **Interactions:**
  - Hover on any metric: "Segment Analysis" menu opens via three-dot — apply a filter to the metric in-place
  - Click a cohort cell: drill into that cohort's customer list
- **Empty state:** New Shopify connection: "Building your first cohorts — this takes ~10 minutes for 12 months of historical data."
- **Loading state:** Shimmer per-widget; cohort heatmap paints row-by-row from bottom.
- **Screenshot URLs:**
  - `https://cdn.prod.website-files.com/6298f816727c4877f3fc6f6f/64efa52b4b31044ea09b1753_...Essentials.png`
  - `https://cdn.prod.website-files.com/6298f816727c4877f3fc6f6f/64efa52bf424c84d2d64a12b_...Navigation.png`

---

## Screen: Reports / Metric detail view

- **URL path:** `/metrics/<slug>` or `/reports/<id>`.
- **Layout:** Full-page visualisation of a single metric with rich controls.
- **Elements (top to bottom):**
  1. Title + description with an edit pencil (name and description are editable inline)
  2. Date range selector + granularity (daily/weekly/monthly) + comparison
  3. **"Segment Analysis" button** — opens a popup menu with "dozens of filter options". User picks values (products, locations, campaigns, customer tags) then "Apply Filters".
  4. Main chart — line/bar/cohort/number/pacing depending on metric
  5. Secondary cohort table below (if applicable)
  6. Notes field — add context, links, explanations for team
- **Chart types available per metric:**
  - Line chart (time-series)
  - Cohort table (rows = cohort, columns = periods)
  - Cohort curve (retention curves overlaid)
  - Pacing graph (target vs actual progress to goal)
  - Number tracker (single big number + comparison)
  - Bar chart
  - Stacked bar
  - Pie
- **Interactions:**
  - Hover chart: tooltip with value + comparison delta
  - Click data point: "Inspect this segment" opens a sidesheet with drill-down
  - "Save as Report" button stores current filter config as a named Report
- **Screenshot URLs:**
  - `https://cdn.prod.website-files.com/6298f816727c4877f3fc6f6f/64efa52beb3ae67c44e0dce6_...Segmentation.png`
  - `https://cdn.prod.website-files.com/6298f816727c4877f3fc6f6f/64efa52b7320569564e962ea_...RepurchaseRate.png`
  - `https://cdn.prod.website-files.com/6298f816727c4877f3fc6f6f/64efa52bdd86953f279f1fbf_...FilterMenu.png`
  - `https://cdn.prod.website-files.com/6298f816727c4877f3fc6f6f/62be01d1da48c1b43d33e5c6_...ReportUI.png`

---

## Screen: Dashboards (custom)

- **URL path:** `/dashboards/<id>`.
- **Layout:** Grid of widgets. Widgets are either **Tickers** (single-number displays) or **Legends** (chart-based visualisations).
- **Widget types:**
  - Ticker — single big number with optional comparison delta
  - Legend — chart: line, cohort table, cohort curve, pacing, bar
- **Builder flow:**
  1. Create Reports first (filtered metric views)
  2. Create Widgets from reports (pick visualization type)
  3. Build Dashboards by arranging widgets
- **Per-widget controls:**
  - Three-dot menu: Edit / Duplicate / Remove / Resize
  - Layout tab: change chart type, widget size
  - Values tab: data grouping, show/hide goals, display data point values
  - Date range override (per-widget)
  - Edit title/description or use AI-generated insights
  - Export to CSV, OneDrive, Google Sheets
- **Dashboard-level controls:**
  - "+ Widget" button top-right
  - Dashboard title + description editable inline
  - Share button — copy link, invite team members, create read-only version for external stakeholders
  - Schedule — send snapshot to email or Slack on schedule
- **Interactions:**
  - Drag widgets to rearrange
  - Resize from corners (snap to grid)
  - Hover widget title: notes tooltip appears if notes are set
- **Screenshot URLs:**
  - `https://cdn.prod.website-files.com/628e954d658d0b4a2b11c370/640652672612c654dd9e7b4f_3-min%20(7).webp` (Unlimited Dashboards)
  - `https://cdn.prod.website-files.com/6298f816727c4877f3fc6f6f/62be01cf4a1dac7de0aad9d5_...DashboardBuilder.png`
  - `https://cdn.prod.website-files.com/6298f816727c4877f3fc6f6f/62be01cff05ea80f211b6b42_...DashboardAnimation.gif`

---

## Screen: Magic Dashboards (AI)

- **URL path:** `/magic-dashboards`.
- **Layout:**
  - Top-left: question input field ("Ask about your business...")
  - Main canvas: AI-generated widgets responding to the question — combination of metrics and visualisations
  - Top-right: three-dot menu — Create / Share / Delete dashboard; toggle "Magic Insights" (AI-generated headlines refreshed weekly)
- **Widget output:**
  - Multiple widgets showing related metrics to the question
  - Each widget is editable/modifiable after generation
  - Users can pin a magic widget to a regular Dashboard
- **Widget customization modal (applies to magic and regular dashboards):**
  - **Layout tab:** chart types (line, bar, stacked bar, pie), widget size
  - **Values tab:** data grouping, show/hide goals, display values on points
  - Date range (per-widget)
  - Titles & descriptions (manual or AI-generated)
  - Export options — CSV, OneDrive, Google Sheets
- **Magic Insights:** Toggle at top-right enables weekly AI-generated headline summaries per dashboard — refreshed automatically.
- **Empty state:** "Ask a question to generate a dashboard. Try: 'Which products drive the highest LTV customers?'"

---

## Screen: Slices (multi-metric drill-down tables)

- **URL path:** `/slices/<id>`.
- **Layout:** Large flat table. Each row = one member of the chosen segment. Each column = one metric. This is Peel's signature analyst view.
- **Creation wizard (4 steps):**
  1. **Date range** (30 days / 3 months / 6 months / 1 year / custom)
  2. **Segment** — products, locations, variants, SKUs, discount codes, customer tags, subscriptions, product types, collections, campaigns, ad sets, Klaviyo flows
  3. **Metrics** — customizable list (Gross Sales, Net Sales, AOV, churn rate, CAC, LTV, repeat rate, etc.) — multi-select
  4. **Name** the Slice for later retrieval
- **Table features:**
  - Sortable columns
  - Sticky header and first column
  - Conditional shading per column (heatmap)
  - Export as CSV or PDF
  - Share via link
  - Saved Slices appear in the sidebar for repeat use
- **Interactions:**
  - Click a row to drill into that segment's report view
  - Resize columns
- **Screenshot URLs:**
  - `https://cdn.prod.website-files.com/6298f816727c4877f3fc6f6f/62be01ce58b9d36e9f2dbc86_60f7ec86015ee860cf5be09b_Slices_Variant_Slices_Page.jpeg`
  - `https://cdn.prod.website-files.com/6298f816727c4877f3fc6f6f/62be01ced807e518e72bd95d_60f86c31ef13a55dfebe73ec_Jul-21-2021%252014-48-42.gif`

---

## Screen: Audiences (segment builder)

- **URL path:** `/audiences`.
- **Layout:** Audience list + builder.
- **Audience list:** Table of saved audiences with name, size (customer count), last updated, destination.
- **Audience builder:**
  - Criteria rows (AND / OR)
  - Filter chips for product-specific data, customer info, locations, dimensions
  - Live count update as criteria are added
  - "Track growth over time" toggle — pins the audience size to a time-series view
  - Export destination picker: Klaviyo or Facebook, with a "Send" button
- **Screenshot URLs:**
  - `https://cdn.prod.website-files.com/6298f816727c4877f3fc6f6f/64efa52c1adf739d68574855_...AudienceBuilder.png`
  - `https://cdn.prod.website-files.com/6298f816727c4877f3fc6f6f/64efa52cb87d56e88ba05706_...AudiencesView.png`
  - `https://cdn.prod.website-files.com/6298f816727c4877f3fc6f6f/64efa52c16ef5429f7122974_...ExportDestinations.png`
  - `https://cdn.prod.website-files.com/628e954d658d0b4a2b11c370/640b92acc389394cff0829de_AudienceDashboard.webp`

---

## Screen: Audiences — Audience Traits

- **URL path:** `/audiences/<id>/traits`.
- **Layout:** Snapshot view of an audience's attributes — top products, discount codes, cities, acquisition channels, typical order patterns. Presented as a grid of small cards and lists.
- **Screenshot URLs:**
  - `https://cdn.prod.website-files.com/628e954d658d0b4a2b11c370/6406525f4767f57ef7d3f2e3_2-min%20(8).webp`

---

## Screen: Audiences Overlap (Venn)

- **URL path:** `/audiences/overlap`.
- **Layout:** 2- or 3-circle Venn diagram comparing audience memberships. Overlap counts shown inside regions. Below: table listing customers in the overlapping set.
- **Screenshot URLs:**
  - `https://cdn.prod.website-files.com/628e954d658d0b4a2b11c370/63f9322ed87eaf16578fee6d_image%208%20(3).webp`

---

## Screen: RFM Analysis

- **URL path:** `/rfm`.
- **Layout:** 3×3 or 5×5 grid (Recency × Frequency), with each cell showing a customer-count and monetary-value total. Cells are colour-shaded from cool (dormant) to warm (loyal).
- **Pre-built 10 customer segments:** Champions, Loyal, Potential Loyalist, At Risk, About to Churn, Can't Lose Them, Hibernating, Need Attention, Promising, New Customers.
- **One-click conversion to Audiences:** Each cell/segment has a "Convert to Audience" button that creates a pre-filled audience.
- **Screenshot URLs:**
  - `https://cdn.prod.website-files.com/6298f816727c4877f3fc6f6f/64efa52c0060fa8b12874f39_...RFM.png`
  - `https://cdn.prod.website-files.com/628e954d658d0b4a2b11c370/63fe5f2231ade6f2b819502f_solutions_RFM.webp`

---

## Screen: Channel Attribution

- **URL path:** `/attribution`.
- **Layout:** Multi-channel performance attribution breakdown. Bar chart showing channel contribution, plus a table below with columns for Spend, Orders, Revenue, ROAS, New Customer Revenue.
- **Multitouch Attribution Dashboard:** One of Peel's pre-built dashboard templates is an attribution dashboard with first-touch, last-touch, and linear models side-by-side.
- **Screenshot URLs:**
  - `https://cdn.prod.website-files.com/628e954d658d0b4a2b11c370/64502990dfeab9e8595f840a_Peel%20Attribution%20(1).webp`

---

## Screen: Goals

- **URL path:** `/goals`.
- **Layout:** List of goals with progress bars. Each goal: metric, target value, date range, current value, % progress.
- **Goal creation modal:** Pick metric, set target, pick evaluation window. Goals surface in Key Indicator widgets and pacing charts.
- **Screenshot URLs:**
  - `https://cdn.prod.website-files.com/628e954d658d0b4a2b11c370/63f9329f6d6b30119afe108f_image%2031%20(2).webp`

---

## Screen: Product Analytics

- **URL path:** `/products`.
- **Layout:** Table of SKUs with Units, Revenue, Margin, Refund Rate, Repeat Rate, Average Days Between Repeat Purchase.
- **Sub-views:**
  - Customer Purchasing Journey — flow visualization of 1st → 2nd → 3rd → 4th orders
  - Market Basket Analysis — matrix of co-purchase frequencies
- **Screenshot URLs:**
  - `https://cdn.prod.website-files.com/628e954d658d0b4a2b11c370/6406528db1b8e3b4c46563c0_5-min%20(3).webp`
  - `https://cdn.prod.website-files.com/628e954d658d0b4a2b11c370/64065368b1b8e3b4df657bb0_1-min%20(10).webp`
  - `https://cdn.prod.website-files.com/628e954d658d0b4a2b11c370/6406536152203b030963aed1_2-min%20(9).webp`

---

## Screen: Subscriber Analytics

- **URL path:** `/subscriptions`.
- **Layout:** MRR, churn, subscription growth metrics in Tickers. Subscription cohort retention heatmap. OTP-to-Subscriber conversion funnel.
- **Screenshot URLs:**
  - `https://cdn.prod.website-files.com/628e954d658d0b4a2b11c370/640653590eead378bfdba8fe_3-min%20(8).webp`
  - `https://cdn.prod.website-files.com/628e954d658d0b4a2b11c370/66f63dca7371b64d700816f9_Frame%202493%20(1).avif`

---

## Screen: Cohorts

- **URL path:** `/cohorts`.
- **Layout:** Large heatmap — rows = acquisition month/week, columns = months/weeks after acquisition (0 through 12+). Cells show retention % or LTV $ depending on selector.
- **Controls:**
  - Cohort granularity (monthly/weekly)
  - Cohort type (acquisition, first-product, first-channel)
  - Metric shown (retention %, LTV, orders)
- **Screenshot URLs:**
  - `https://cdn.prod.website-files.com/628e954d658d0b4a2b11c370/63f7afde03ba58b83234e2f1_image%2027.webp`
  - `https://cdn.prod.website-files.com/628e954d658d0b4a2b11c370/6406534d04c4e2ed8fdc5cfd_4-min%20(6).webp`

---

## Screen: Marketing Metrics

- **URL path:** `/marketing`.
- **Layout:** ROAS / ROI / CAC / CVR KPI cards at the top, trend charts below. Channel breakdown table.
- **Daily Slack & Email reports** — configurable from this page; a toggle sends morning summary with these KPIs.
- **Screenshot URLs:**
  - `https://cdn.prod.website-files.com/628e954d658d0b4a2b11c370/64065345ecec078014f32d7c_5-min%20(4).webp`

---

## Screen: Annotations

- **URL path:** `/annotations`.
- **Layout:** List of annotations with date, label, description, and which reports they're pinned to.
- **Inline usage:** Annotations show as vertical dashed lines on any time-series chart with a flag label. Hover the flag to read the annotation.
- **Screenshot URLs:**
  - `https://cdn.prod.website-files.com/628e954d658d0b4a2b11c370/6450518442c2921e7a4c9680_annotations%20solution.webp`

---

## Screen: Templates library

- **URL path:** `/templates`.
- **Layout:** Grid of template cards. Categories: Dashboards, Slices, Audiences.
- **Each card:** Preview image, name, short description, "Add to my account" button.
- **One-click apply:** Adds the template to the user's account with all metrics pre-configured.
- **Screenshot URLs:**
  - `https://cdn.prod.website-files.com/6298f816727c4877f3fc6f6f/64a32992c3db4817b711ed05_...TemplatesLibrary.png`

---

## Screen: Integrations / Connections & Datasets (via Pal's menu)

- **URL path:** `/settings/connections`.
- **Layout:** Card grid of datasources with status dots, last-sync times, and "Add Datasource" CTA.
- **Access path:** Click Pal face in sidebar → "Connections and Datasets".
- **Per connector:** OAuth or API-key flow; Peel is Shopify-first so Shopify is always primary.
- **Screenshot URLs:**
  - `https://cdn.prod.website-files.com/6298f816727c4877f3fc6f6f/64efa52cef666476a285858a_...ConnectionsMenu.png`
  - `https://cdn.prod.website-files.com/6298f816727c4877f3fc6f6f/64efa52dcc2861147e326483_...ConnectedDatasources.png`

---

## Screen: Billing & Account Settings (via Pal's menu)

- **URL path:** `/settings/account`.
- **Layout:** Standard settings page with Account, Billing, Team, API access sections.
- **Access path:** Click Pal's face in sidebar → "Billing and Account Settings".
- **Invite new user:** Scroll to "Invite a new user" section, enter email, send invitation.
- **Screenshot URLs:**
  - `https://cdn.prod.website-files.com/6298f816727c4877f3fc6f6f/64efa52c95069f44680ca1f0_...SettingsAccess.png`
  - `https://cdn.prod.website-files.com/6298f816727c4877f3fc6f6f/64efa52c1adf739d685748b0_...InviteInterface.png`

---

## Specific micro-patterns worth documenting

- **Pal mascot as settings access:** Unusual personality UI — Pal's face at the bottom of the sidebar is the entry point to Billing, Integrations, and Account. Builds brand affinity but can confuse new users expecting a gear icon.
- **Three-tier hierarchy (Metrics → Reports → Dashboards):** Strict conceptual split. A Metric is pre-populated; a Report is a saved filtered Metric view; a Dashboard is a collection of Reports visualised as Widgets. Enforces a BI-tool mental model.
- **Tickers vs Legends nomenclature:** Two widget types — "Tickers" for single numbers, "Legends" for charts. Quirky terminology but consistent.
- **Segment Analysis on every metric:** Any metric has a three-dot menu with "Segment Analysis" that opens a universal filter modal. This is Peel's differentiator vs competitors — any cohort/LTV/repeat metric can be filtered by products, customer tags, campaigns, locations, ad sets, Klaviyo flows without leaving the view.
- **RFM pre-built:** 10 pre-computed RFM segments with one-click audience conversion. Lowest-friction customer segmentation in the category.
- **Magic Dashboards as question-first UI:** The only tool in the category where you type a natural-language question and get a multi-widget dashboard back. Positions itself like ChatGPT-for-ecommerce.
- **Slices as an analyst workflow:** Slices are big flat tables combining a segment with many metrics — this is the pattern BI analysts reach for in Looker/Metabase, exposed as a first-class product surface.
- **Widget-level configurability:** Each widget has its own date range, chart type, layout, values display. Dashboard-level settings are minimal.
- **Pacing graphs as a chart type:** A chart type specifically for "actual vs target" pacing — rare in dashboards. Used heavily with Goals.
- **Goals are universal:** Any metric can have a goal; goals surface as progress bars in widgets and pacing charts. Not gated to a "KPI" tile type.
- **Read-only external share:** Dashboards can be shared with external stakeholders as read-only, not requiring a Peel login — useful for investor or board reporting.
- **Export to Google Sheets / OneDrive (not just CSV):** Widget export targets include native Google Sheets and OneDrive connections, not just download-as-CSV.
- **Notes everywhere:** Every widget and report has a free-form notes field for context. This is the "why did this number move" surface, similar to annotations but per-widget.
- **Warmer, less dense aesthetic:** Peel deliberately looks like an analyst's tool — friendly, warm, fewer numbers per square inch — in contrast to Triple Whale's pack-everything-in approach.
- **No global attribution/accounting toggle:** Peel handles attribution and cohort logic inside the metric definitions rather than in global controls.
- **Syncing indicator:** Sync status appears in the Connections panel, not the chrome. No in-chrome "last sync" indicator — a minor gap for operators who care.
- **Estimated/modeled:** Peel doesn't heavily emphasise modeled-vs-deterministic data; most of its metrics are sourced directly from Shopify. Attribution widgets defer to last-touch in the simple case.
- **Number formatting:** Standard comma-separated; currency prefix; decimals to 2 places for money and ratios. Relatively conservative — no heavy abbreviation like Triple Whale's K/M.
- **Date picker:** Per-widget/report, not global. This is unusual and has pros (each chart can live in its natural window) and cons (it's easy to have mismatched dates across a dashboard and not notice).
- **Annotations as cross-cutting layer:** Annotations pinned globally appear on every chart they're relevant to — event context preserved across reports.

---

## Screenshot inventory

```
https://cdn.prod.website-files.com/6298f816727c4877f3fc6f6f/64efa52b4b31044ea09b1753_rnTJobP48tWL-sdGiPSfKt8tu94gQIekq3G3OruAOIH6q_laadCrxs41XlJznYhV9Zncn6P2XqhjiGtzbzYkhUGs6nS5HLxHcndSqBRCQx99yf9de26ffD-IgZl3rYhib3k8r3nnnHok3F7iUWlo0mM.png
https://cdn.prod.website-files.com/6298f816727c4877f3fc6f6f/64efa52bf424c84d2d64a12b_yejXTgY8Q9LuyorK-IFUGKP7wdQPAKRq5MqyE_mpanZgtWPifiLeMN16QmSrjyiUInIBnppxsULtxQkmrhA50rT5-zpwN5gyBKW9UZZqYFa_9j45YdZsZd30LT5R6AGRFH36znCDVJNA_WyYP4nu_34.png
https://cdn.prod.website-files.com/6298f816727c4877f3fc6f6f/64efa52beb3ae67c44e0dce6_xIGL8QMcfzcI7T07QQaXYqT6NzKmlMFVEc_htEdaIUYtiR5vT2guwnHz_dB8BiwSiYJS2OBPM2hYCOFHgic9ZLxJqNDGjWzhIgg1wpBJXkJ1ANX8E8ZFwb8ei-ldmbAo9sVyqpks8wVsrcKExe3KhJw.png
https://cdn.prod.website-files.com/6298f816727c4877f3fc6f6f/64efa52b7320569564e962ea_MCjWPJH6Yneu_ROB4LRtHJRc5zCCp1QLGZwZ5l6_mRXYYEEGfF8TelreGvtXetqOHP9ny9VsdrQZ6CeUNjTazPL38STqpZZiEAKvmiGgxC2eNI3dYWkIJm1xXgmaYWxeYXXpZbTtkXpdMvtIrk1Qdc.png
https://cdn.prod.website-files.com/6298f816727c4877f3fc6f6f/64efa52bdd86953f279f1fbf_XzCdqv6c0ffqWHp9V5AKdeze9mrDub6X7Bh5jmVQz9_hr-JKLL8LFsPnBfkudTmZklTN3PhCAHlvRGGlmqhI-Ncj8tvGPbNGIWDVCdFSUBiOnwJAiy4wk_IXjBjb_br2_O3qb0UHgvLj9g5UbUzSJ0k.png
https://cdn.prod.website-files.com/6298f816727c4877f3fc6f6f/64efa52c0060fa8b12874f39_S_2UaH1baNNeP43qffy8qS9ZbDP1ilZSbQ7LnuRo5xL_lxwVGAsVI6bxldrEog9gebZkxJyzRl9CQWIUs-uetS2nBF3cGlYCiDp4VYXLAKuux3KnAwD2BOXjlJ9zoINrnhZFkluLHBn-SALMoAqC5uw.png
https://cdn.prod.website-files.com/6298f816727c4877f3fc6f6f/64efa52c1adf739d68574855_uzgftMuGbSbhrLzjUj94RMjVd9AdcgvhUr8P-n5BZEmHnA5mkga9-a1y_mv5k6K2k939x_Z076zjclYzC1iRPovBd2-s6vH6lAGxHRzMFXNzRqrznO3rHLkIoSbIRoNSm5hW-7z9KwQpHPDHgxQRupc.png
https://cdn.prod.website-files.com/6298f816727c4877f3fc6f6f/64efa52cb87d56e88ba05706_yqLaqrAIQukq3uITM57qpvUY9W6aRoZuCwljNbddHbKyKXiXPmlRQ2xvWCXS9YtK36K6w0l-m-g65vuz99Nej1eJx0FlW1gr0siLvnqOyQ7FumfSdvPZb3HohnMWiYfPAJkJ31yn84Ykn7A42KSQFuc.png
https://cdn.prod.website-files.com/6298f816727c4877f3fc6f6f/64efa52c16ef5429f7122974_l9ZhHxVOycYeU1Tt6OC2Bxw26d_GAYZ8xIIu3nTp5IwyTurDdM2UjQx0obpXUt9XaYiZI6CnVIupGvnOSmGathw7XRj5DyRJgZETktEhcXPdeU7KEMu1nl3EP65HOjxdUCNoV_kgGoYc5fXYzSh4tVk.png
https://cdn.prod.website-files.com/6298f816727c4877f3fc6f6f/64a32992c3db4817b711ed05_iDWU1L3gTcye97CLDdSAtt0rj3ZADwzdB4nYep6O_llN2aVgbTnV8D-shG8oUXLySRf0hNw27-fZce78_SnsSqysyRxmDfc8wOz8MSGOunhgzbc1jjyWkBlf2D5gH2w3DyrbrBQKA-KuELShPz9r8d8.png
https://cdn.prod.website-files.com/6298f816727c4877f3fc6f6f/64efa52cef666476a285858a_BNr5efWuuY5dY_qfNp2sSzIfR8EI2s9qK5rvfuCroptl3DwSV7PJWQypf-vA9voFbdKMnmpNyiO28vdqiHcxc88oi_nSn3Ee9Um1kgGslXu61fut32pvt0yUDfRLeP_2KLKDYQqjIciZz_fwT6UnBXY.png
https://cdn.prod.website-files.com/6298f816727c4877f3fc6f6f/64efa52dcc2861147e326483__YkA_9ROIfebzFN3jYwwFkzXuWM2pTIpdFb1cimLiWUnV8bjtJMN2anSABpPRAln0-z4ePRWCzD8PrXtdCq05atR3U_UtuPS00UTxkwZYc4MtrO25QfgVw0DBx4qkMFAwngF4JfMF8jFYj2UtINUc8M.png
https://cdn.prod.website-files.com/6298f816727c4877f3fc6f6f/62be01d1da48c1b43d33e5c6_5fe3c29bfa68bf1831978513_Screen%2520Shot%25202020-12-07%2520at%25202.44.19%2520PM.png
https://cdn.prod.website-files.com/6298f816727c4877f3fc6f6f/62be01d1be5b54424159eebb_5fe3c2b516ee390762cdc880_Screen%2520Shot%25202020-12-07%2520at%25202.30.32%2520PM.png
https://cdn.prod.website-files.com/6298f816727c4877f3fc6f6f/62be01d1034432f572d3104d_5fe3c2a915013a2fe1842c55_Screen%2520Shot%25202020-12-07%2520at%25202.36.09%2520PM.png
https://cdn.prod.website-files.com/6298f816727c4877f3fc6f6f/62be01cf4a1dac7de0aad9d5_5fe3c5b56c40f8eb4de2f5d2_ByHgptlgH5YnhEDB2YlV6NOgMaUb6RvEozBjoUltbFwQvpZKcH6Wl-TKf8e35UaFDesoF8x5tNyUFPpHXF2etjP0luQk8AX5PKBt_rtQj3gOBfPVTIjBQLDNxzYw6Z9Eny4Ca2oR.png
https://cdn.prod.website-files.com/6298f816727c4877f3fc6f6f/62be01cff05ea80f211b6b42_5fe3c5b63d22bebf376bbf91_C5D8ddcbB3qVLyx-0A-kqaEpF_dCA0ErlKpHPvZ8xS0zH-LjBX3spgIP7FO75e9WcOiqBmxAgy6Fo2P6Pk7Fl60hKmgKc2w7AtVDBjBQVrpiSNAhCgpGTP6gBeVV57C5kLJ6ZtlR.gif
https://cdn.prod.website-files.com/6298f816727c4877f3fc6f6f/62be01cf1a98121c9f18faeb_5fe3c5be3d22bee09c6bbfa7_rihQIPLIYmcQbWQ6PhrnDd0b24hTMIh23loHhxmiLPLkROEaelHY1pV9vcRm6oyTCznZrOH-waoxoIL9c85H45zNRySgHJbN5GTwQt2xYeyFuRyoL0vTKN-j7Xw5U8h7LTmVYpxY.gif
https://cdn.prod.website-files.com/6298f816727c4877f3fc6f6f/62be01ce58b9d36e9f2dbc86_60f7ec86015ee860cf5be09b_Slices_Variant_Slices_Page.jpeg
https://cdn.prod.website-files.com/6298f816727c4877f3fc6f6f/62be01ced807e518e72bd95d_60f86c31ef13a55dfebe73ec_Jul-21-2021%252014-48-42.gif
https://cdn.prod.website-files.com/628e954d658d0b4a2b11c370/640b92acc389394cff0829de_AudienceDashboard.webp
https://cdn.prod.website-files.com/628e954d658d0b4a2b11c370/6406525f4767f57ef7d3f2e3_2-min%20(8).webp
https://cdn.prod.website-files.com/628e954d658d0b4a2b11c370/63f9322ed87eaf16578fee6d_image%208%20(3).webp
https://cdn.prod.website-files.com/628e954d658d0b4a2b11c370/63fe5f2231ade6f2b819502f_solutions_RFM.webp
https://cdn.prod.website-files.com/628e954d658d0b4a2b11c370/640652672612c654dd9e7b4f_3-min%20(7).webp
https://cdn.prod.website-files.com/628e954d658d0b4a2b11c370/640652811c3214298caa6a04_4-min%20(5).webp
https://cdn.prod.website-files.com/628e954d658d0b4a2b11c370/64504c358cdc3f6721d14ee1_export%20solution.webp
https://cdn.prod.website-files.com/628e954d658d0b4a2b11c370/649e00e108a8d1dcd2b552b1_explore%20solutions.webp
https://cdn.prod.website-files.com/628e954d658d0b4a2b11c370/63f9324b93c6e44dbf4828a4_Frame%202492%20(1).webp
https://cdn.prod.website-files.com/628e954d658d0b4a2b11c370/6406528db1b8e3b4c46563c0_5-min%20(3).webp
https://cdn.prod.website-files.com/628e954d658d0b4a2b11c370/64065368b1b8e3b4df657bb0_1-min%20(10).webp
https://cdn.prod.website-files.com/628e954d658d0b4a2b11c370/6406536152203b030963aed1_2-min%20(9).webp
https://cdn.prod.website-files.com/628e954d658d0b4a2b11c370/640653590eead378bfdba8fe_3-min%20(8).webp
https://cdn.prod.website-files.com/628e954d658d0b4a2b11c370/66f63dca7371b64d700816f9_Frame%202493%20(1).avif
https://cdn.prod.website-files.com/628e954d658d0b4a2b11c370/63f7afde03ba58b83234e2f1_image%2027.webp
https://cdn.prod.website-files.com/628e954d658d0b4a2b11c370/6406534d04c4e2ed8fdc5cfd_4-min%20(6).webp
https://cdn.prod.website-files.com/628e954d658d0b4a2b11c370/64502990dfeab9e8595f840a_Peel%20Attribution%20(1).webp
https://cdn.prod.website-files.com/628e954d658d0b4a2b11c370/64065345ecec078014f32d7c_5-min%20(4).webp
https://cdn.prod.website-files.com/628e954d658d0b4a2b11c370/6450518442c2921e7a4c9680_annotations%20solution.webp
https://cdn.prod.website-files.com/628e954d658d0b4a2b11c370/63f9329f6d6b30119afe108f_image%2031%20(2).webp
```
