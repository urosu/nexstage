# Polar Analytics — UI teardown

**Scope:** screen-by-screen deep dive. Companion to [`polar-analytics.md`](polar-analytics.md). Reference from page specs in `/docs/pages/`.

Sources: polaranalytics.com marketing pages (homepage, `/features/custom-report`, `/l/dashboarding-tool`, `/solutions/automate-your-reporting`, `/solutions/analytics-for-multi-store-shopify-brands`), Polar help center at `intercom.help/polar-app` (Understanding Dashboards, Customizing your Dashboards, Understanding Custom Tables/Charts, Getting Started with Polar, Shopify connection), Shopify App Store listing at `apps.shopify.com/polar-analytics`, Swanky Agency Polar Analytics review. Everything synthesised from help-doc text, feature-page captions, and carousel captions.

## Global chrome

### Top bar
- **Logo placement:** Top-left. "Polar" wordmark with a small aurora/polar-star glyph in the brand blue.
- **Workspace/store switcher:** Top-left next to the logo. Dropdown listing all workspaces. A workspace in Polar is roughly analogous to a Shopify store but can also hold blended multi-store data. Multi-store Shopify brands can create a blended workspace with weighted FX — Polar heavily markets this.
- **Global date range:** Top-right of each page's content area, not the chrome. Date range picker with presets (Today / Yesterday / Last 7 / Last 30 / This month / Last month / This quarter / Last quarter / YTD / All / Custom). Comparison dropdown directly below: "vs previous period", "vs last year", "None".
- **Global filters:** Per-dashboard filter chips (not chrome-level). Polar does not have the global attribution/accounting toggles Northbeam has.
- **User menu, notifications, help:** Top-right. Notifications bell, help (?), AI Agent toggle ("Ask Polar"), user avatar. The "Ask Polar" AI chat sits as a persistent round button bottom-right (Intercom-style) rather than inline in the chrome.
- **Command palette / search:** Global search across dashboards, tables/charts, metrics, connectors — reachable from the sidebar header or by keyboard shortcut.

### Sidebar
- **Sections (in order, top to bottom):**
  1. **Home** — starter dashboard, shortcuts
  2. **Dashboards** — folder tree of dashboards. Folders are the organisational unit. Expanded by default for pinned folders.
     - "Acquisition" folder (default)
     - "Retention" folder
     - "Merchandising" folder
     - "Profitability" folder
     - User-created folders
  3. **Tables/Charts** — the Custom Report library (formerly "Custom Reports"). Browsable separately from dashboards.
  4. **Explorations** — ad-hoc multi-metric views
  5. **Paid Marketing** — pre-built marketing dashboards
  6. **Retention Marketing**
  7. **Merchandising / Inventory**
  8. **Incrementality Testing** (Causal)
  9. **Metric Alerts** — anomaly feed
  10. **Data Activation** — Klaviyo flows, ad platform audiences
  11. **AI Agents** — Email Agent + general agents
  12. **Data Sources** / **Connectors**
  13. **Data Model** — custom metrics, dimensions, formulas
  14. **Account Settings** / **Team** / **Workspaces** — at bottom, gear icon
- **Collapsible?:** Yes. Folders inside the Dashboards section are individually expandable.
- **Active state visual:** Brand-blue left-edge bar and subtly tinted row. Folder icons flip to open-folder state when expanded.
- **Nesting depth:** 3 levels — Section → Folder → Dashboard.

### Color / typography / density
- **Primary accent color:** Polar blue, a clean sky/azure (`#2f80ed`-ish). White chrome; light grey surface.
- **Chart palette:** A qualitative palette with around 8 distinct hues — channel consistency is less strict than Triple Whale. Sequential blues are used for single-channel time-series emphasis.
- **Font family, number formatting:** Clean sans-serif (Inter-like). Tabular numerals. Currency abbreviations at tile scale (K/M/B); full precision in tables. Locale-aware number formatting (European commas etc.). Polar is strong with multi-currency stores — a currency code chip shows next to currency-valued cells where multiple currencies are present.
- **Approximate density:** Moderate. ~8–12 widgets above the fold at 1440×900 on the default homepage. Lower density than Triple Whale, higher than Peel.

---

## Screen: Home / Getting Started Dashboard

- **URL path:** `/home` or `/`.
- **Layout:** Welcome strip at top (only for new accounts), then a standard dashboard below.
- **Above-the-fold elements:**
  1. Welcome/setup checklist — "Connect Shopify ✓ / Connect Facebook Ads / Connect Google Ads / Connect Klaviyo / Set your COGS / Invite team"
  2. Date range + comparison in top-right
  3. "Today" snapshot KPIs — Gross Sales, Net Sales, Orders, Sessions, Conversion Rate (Key Indicator section)
  4. Blended Acquisition KPIs — Blended CAC, Blended ROAS, MER, Ad Spend
  5. Revenue time-series chart (stacked by channel)
- **Below-the-fold:**
  - "Key Traffic Metrics" Key Indicator section — grouped by device / country / traffic source
  - Custom metric tiles (if set)
  - AI insights card (summary generated by the AI agent)
- **Interactions:**
  - Hover on KPI tile: shows comparison delta + sparkline
  - Click tile: opens the underlying Custom Table/Chart for that metric in a detail view
  - "+ Add block to dashboard" button in the header — primary builder entry point
- **Empty state:** Each widget shows a "Connect Shopify" or "Connect Facebook Ads" placeholder with a one-click OAuth button until the data source is wired.
- **Loading state:** Progressive skeletons; dashboard renders top-down.
- **Screenshot URLs:**
  - `https://cdn.prod.website-files.com/5d41ab9d8cacf54c04df67c0/659c11624472d20f41e5dede_composite%20screenshot%20(2).webp`
  - `https://cdn.prod.website-files.com/5d41ab9d8cacf54c04df67c0/67e6c5ccc5f4c5d539c65c24_64631aef6faef9fd604c2b07_Blended-Performance.svg`
  - `https://cdn.prod.website-files.com/5d41ab9d8cacf54c04df67c0/6463201046bbe6989b905001_Key-Traffic-Metrics.svg`

---

## Screen: Dashboards (generic structure)

- **URL path:** `/dashboards/<id>`.
- **Layout:** Dashboards are built from two block types arranged on a 12-column grid:
  - **Key Indicator Sections** — horizontal strips of KPI tiles, optionally with progress towards a target
  - **Tables/Charts** (a.k.a. Custom Reports) — custom data visualisations combining metrics, dimensions, filters, and date granularities
- **Per-block controls:**
  - Each block has its own three-dot menu: Edit / Duplicate / Move to dashboard… / Remove
  - Block header shows title + description tooltip + date range inheritance indicator (global or overridden per-block)
- **Folder/dashboard structure:**
  - Dashboards live inside folders in the sidebar
  - User can rename dashboards and folders, move blocks between dashboards within the same folder, move dashboards between folders
  - "+ New dashboard" creates a blank canvas; "+ Add block" within a dashboard opens the builder side-drawer
- **Sharing:**
  - Viewer, Editor, Admin roles
  - Block-level scheduling: each block can send to email or Slack on a recurring schedule (daily/weekly/monthly, time, timezone, recipient list)
  - Snapshot image in the email body + link back to the live block
- **Interactions:**
  - Drag block by header to reorder; resize via corner handles (snap to column grid)
  - Click on a chart data point: tooltip with value and comparison delta; right-click for "drill into table"
- **Screenshot URLs:**
  - `https://downloads.intercomcdn.com/i/o/lfrl4yis/1346410680/...` (dashboard creation interface)
  - `https://downloads.intercomcdn.com/i/o/lfrl4yis/1346419530/...` (add block button)
  - `https://cdn.prod.website-files.com/5d41ab9d8cacf54c04df67c0/684829c8316ce188c4a1b244_marketingindicators%20(advanced%20analytics).png`

---

## Screen: Custom Tables/Charts builder

- **URL path:** `/tables/new` or `/charts/new`, or opened as a modal within a dashboard.
- **Layout:** Three-panel builder.
  - **Left panel (metric + dimension picker):** Searchable list of metrics grouped by category (Revenue, Orders, Marketing, Retention, Inventory, Custom). Dimensions list below (Date, Channel, Product, Campaign, Country, Device).
  - **Centre panel (preview):** Live-rendered output — table or chart — updates as user picks metrics/dimensions. Toggle pill at top right: Table / Chart. Chart sub-types: Line / Bar / Stacked Bar / Pie / Area.
  - **Right panel (configuration):** Date range, date granularity (Daily / Weekly / Monthly), comparison (Previous Period / Year-over-Year / None), filters (multi-select per-dimension), sort, top/bottom N rows, "switch rows and columns" button, colour-scale on/off.
- **Save options:**
  - Name + description
  - Assign to dashboard (dropdown of dashboards)
  - Schedule delivery (Tables only — Charts not yet supported per docs)
- **Interactions:**
  - Adding a filter: chip is added in the right panel with an X to remove
  - "Lock date" button pins the date range so scheduled deliveries don't change it
  - AI suggestion chip ("Suggest metrics based on dimension") appears inline with the picker
- **Screenshot URLs:**
  - `https://downloads.intercomcdn.com/i/o/lfrl4yis/1965004972/88bbf6dfeec9db411dbe09d444bb/2024-09-20_13-15-25%2B-281-29.gif`
  - `https://downloads.intercomcdn.com/i/o/lfrl4yis/1965008320/638cbdb2ac3ed1f26e95484a157b/2024-09-20_13-11-43%2B-281-29.gif`
  - `https://downloads.intercomcdn.com/i/o/lfrl4yis/1965010315/23d82ac0d12def25f1b1dfe68f11/2025-03-14_09-03-15%2B-281-29.gif`
  - `https://cdn.prod.website-files.com/5d41ab9d8cacf54c04df67c0/664f13a2e2bf4f659c354393_Analyze%20-%20Custom%20report.webp`

---

## Screen: Paid Marketing / Acquisition Dashboard

- **URL path:** `/dashboards/acquisition` (typical slug).
- **Layout:** Multi-row dashboard.
- **Row 1 — Key Indicator section:** Blended Spend, Blended CAC, Blended ROAS, MER, New Customer Orders, New Customer Revenue.
- **Row 2 — Attribution mix:** Donut or bar showing revenue by channel (Meta / Google / TikTok / Pinterest / Email / Direct / Other).
- **Row 3 — Per-channel breakdown:** Table showing per-platform Spend / Orders / Revenue / CAC / ROAS at a weekly granularity.
- **Row 4 — Top performing ads:** Table with top 10 ads by spend, with ROAS heatmap shading.
- **Attribution options:**
  - Polar offers **10 attribution models** (per their marketing) — Last Click, First Click, Linear, Position-Based, Time-Decay, Clicks + Modelled Views, etc. Model selector is a dashboard-level filter chip.
- **Screenshot URLs:**
  - `https://cdn.prod.website-files.com/5d41ab9d8cacf54c04df67c0/659418a7790f01c689cd09ec_TrackWhatMatters.webp`

---

## Screen: Retention / LTV Dashboard

- **URL path:** `/dashboards/retention`.
- **Layout:**
  - Key Indicators: Repeat Purchase Rate, 30-day retention, 60-day retention, 90-day LTV, 365-day LTV, Average Days Between Orders
  - Cohort retention heatmap: rows = acquisition cohort (month/week), columns = time-to-order buckets (30/60/90/180/365 days), colour-shaded
  - LTV curve by channel chart — line chart with multiple channel lines
  - Customer repeat-order histogram (1st, 2nd, 3rd, 4th+ purchases)
- **Screenshot URLs:**
  - `https://cdn.prod.website-files.com/5d41ab9d8cacf54c04df67c0/659418de6eac1570ba7f9734_TrackWhatMatters-2.webp`

---

## Screen: Merchandising / Product Performance

- **URL path:** `/dashboards/merchandising`.
- **Layout:**
  - Top indicators: Units Sold, Inventory Days of Cover, Stockouts, Attach Rate
  - Product table: SKU / Name / Units / Revenue / Refund Rate / Current Inventory / Days of Cover / Velocity (units/day)
  - Inventory risk list — SKUs at risk of stockout within 14 days
- **Screenshot URLs:**
  - `https://cdn.prod.website-files.com/5d41ab9d8cacf54c04df67c0/659418de16cc4da81e293d6a_TrackWhatMatters-1.webp`

---

## Screen: Profitability Dashboard

- **URL path:** `/dashboards/profitability`.
- **Layout:**
  - Key Indicators: Contribution Margin, Gross Margin, Net Profit, Marketing Efficiency Ratio, First-Order Profit, Return on Ad Spend (aggregated)
  - Waterfall chart: Revenue → Refunds → COGS → Shipping → Fees → Ad Spend → Custom Expenses → Net Profit
  - Per-channel profit ranking
- **Screenshot URLs:**
  - `https://cdn.prod.website-files.com/5d41ab9d8cacf54c04df67c0/659418de4eeb71d5dcf0b859_TrackWhatMatters-3.webp`

---

## Screen: Incrementality / Causal (Polar Causal)

- **URL path:** `/causal`.
- **Layout:** Experiment list on left, experiment detail on right.
- **Experiment detail:**
  - Test vs control group definition (geo split / user split)
  - Baseline metric and lift curve over time
  - Significance indicator (p-value, confidence interval)
  - Incrementality % reading with a verdict badge ("Incremental", "Not significant", "Negative")
- **Screenshot URLs:**
  - `https://cdn.prod.website-files.com/5d41ab9d8cacf54c04df67c0/684829d58db947f9cb9ed00b_causal%20(incrementality).png`

---

## Screen: Metric Alerts

- **URL path:** `/alerts`.
- **Layout:** List view of configured alerts with status pill (Firing / Silenced / Healthy).
- **Alert creation form:**
  - Metric picker
  - Comparison ("greater than", "less than", "% change vs previous period")
  - Threshold value
  - Evaluation window (Last hour / Last 24h / Last 7d)
  - Channels — Email / Slack / Webhook
- **Alert feed:** Each firing alert opens a card with the metric chart, threshold line, and "acknowledge / silence / resolve" actions.
- **Screenshot URLs:**
  - `https://cdn.prod.website-files.com/5d41ab9d8cacf54c04df67c0/646320206faef9fd60521d4b_alert-slack.svg`

---

## Screen: Data Activation (Ad Platform & Klaviyo flows)

- **URL path:** `/activate`.
- **Layout:** Grid of "activation cards" showing:
  - Connected destinations (Klaviyo, Meta CAPI, Google Ads enhanced conversions, TikTok)
  - Per-card status (last sync, volume pushed, conversion value sent)
  - Enable/disable toggle
- **Setup flow per card:** OAuth → choose event mapping (Polar purchase → Meta purchase event) → pick audiences / segments to push.
- **Screenshot URLs:**
  - `https://cdn.prod.website-files.com/5d41ab9d8cacf54c04df67c0/684829df80c8f14eb793b616_activate%20(data%20activations).png`
  - `https://cdn.prod.website-files.com/5d41ab9d8cacf54c04df67c0/6759a26b5cfdde8351b7d45c_activate-cards.png`

---

## Screen: AI Agents (Ask Polar + Email Agent)

- **URL path:** `/ai-agents`, plus persistent chat launcher bottom-right.
- **Ask Polar chat:**
  - Bottom-right floating circular button; opens a chat pane anchored bottom-right, roughly 400×640 px
  - Accepts natural-language questions ("What was my Meta ROAS last week vs. the week before?")
  - Responses can embed inline tables/charts identical to dashboard blocks
  - "Pin to dashboard" action at bottom of a response — converts the answer into a persistent block on a chosen dashboard
- **Email Agent:**
  - Configurable AI agent that analyzes Klaviyo email performance and suggests abandonment-flow revenue optimizations
  - Shows an opportunity list: "Add post-purchase flow for high-LTV SKUs", "Reactivate lapsed subscribers with X offer"
- **Screenshot URLs:**
  - `https://cdn.prod.website-files.com/5d41ab9d8cacf54c04df67c0/684829e9ccb113d5598165a4_bespoke.png`

---

## Screen: Connectors / Data Sources

- **URL path:** `/connectors`.
- **Layout:** Card grid with ~45+ integrations grouped by category (Ecom, Ads, Email/SMS, Analytics, Reviews, Fulfilment, Data Warehouse).
- **Connection flow:** Click card → OAuth → pick accounts → choose backfill range → confirm → shows status "Syncing (ETA 8 min)".
- **Per-connector status card:** Green/amber/red status dot, last-sync timestamp, "Reauth" button if credentials expired.
- **Screenshot URLs:**
  - `https://cdn.prod.website-files.com/5d41ab9d8cacf54c04df67c0/684829b496e1375c3c8dd9c4_connectors%20(data%20integration).png`
  - `https://cdn.prod.website-files.com/5d41ab9d8cacf54c04df67c0/664f13a2ee13dbab46349288_Track%20-%20Connectors.webp`

---

## Screen: Data Model (custom metrics, dimensions, formulas)

- **URL path:** `/data-model`.
- **Layout:** Two tabs — Metrics and Dimensions.
- **Metrics tab:**
  - Table of all metrics with type (base / formula / calculated)
  - "+ New metric" opens formula editor: pick base metric, apply formula ("Metric A / Metric B"), set display format, preview
  - Edit button per metric
- **Dimensions tab:**
  - Custom dimensions — e.g. "Brand Tier" mapping from product metafield
- **Custom Targets:**
  - Each metric can have weekly/monthly targets defined — these feed into Key Indicator sections with progress indicators

---

## Screen: Workspaces / Multi-store

- **URL path:** `/settings/workspaces`.
- **Layout:** Table of workspaces. Per-workspace: name, owner, number of stores, currency, timezone.
- **Multi-store merge:** A workspace can hold multiple Shopify stores merged together. The merge settings modal lets admins set currency conversion rules, dedup customer IDs by email, and map store-specific channels to unified channels.
- **Per-workspace role assignment:** Viewer, Editor, Admin.

---

## Specific micro-patterns worth documenting

- **Dashboards live in Folders:** Sidebar organisational unit is the folder — this is unusual. Users can move dashboards between folders and blocks between dashboards, making Polar feel more like a BI tool (Metabase/Looker) than a dashboard product.
- **Blocks-not-tiles:** Polar calls them "blocks" and each block has its own schedule, filter, and data. This is BI-lineage thinking.
- **Scheduled block delivery:** Individual blocks (not full dashboards) can be scheduled to email/Slack. Granular delivery is a differentiator vs. competitors that schedule entire dashboards.
- **Multi-store blended workspace:** Agencies and multi-brand operators get a genuinely blended workspace (weighted by currency-converted revenue) — cleaner than Triple Whale's "All Stores" toggle.
- **Custom Targets baked into Key Indicators:** A KPI tile can show a progress bar towards a user-defined weekly/monthly target — "ROAS 3.42 of 3.0 goal, 114%".
- **10 attribution models:** Polar exposes the widest menu in the category — used as a marketing point ("10 powerful attribution models"). Users can A/B model impact without exporting data.
- **"Ask Polar" as floating chat:** The AI agent sits as a persistent bottom-right bubble rather than a sidebar panel. Follows Intercom chat conventions.
- **Pin AI answer to dashboard:** An AI-generated chart/table can be pinned as a block on any dashboard with one click — converting a one-off question into an ongoing view.
- **Locked date on schedules:** Scheduled deliveries can "lock" a relative date range so "this week" stays this week in each delivery, never drifting.
- **Color scale for tables:** Any column in a Custom Table can be toggled to show heatmap shading inline, cell by cell. Useful for performance-vs-baseline spotting without a separate chart.
- **Row/column switch:** Custom Tables have a one-click "transpose" button — surprisingly rare in BI tools at this tier.
- **Tooltip density:** Every metric has an info icon with a short definition and a "Learn more" link to the help center. Definitions also appear inline in the metric picker.
- **Number formatting:** Locale-aware (European merchants see periods for thousands). Multi-currency cells carry a currency pill chip when the workspace contains mixed currencies.
- **Syncing indicator:** Sidebar footer shows a small green/amber "Last sync Nm ago" line; clicking opens a sync-status modal with per-connector breakdown.
- **Estimated / modeled flag:** Where Meta/Google send modeled conversions (iOS loss recovery), Polar shows a small (M) suffix next to the metric value. Click (M) opens a modal explaining. Subtler than Triple Whale's "Modeled" badge.
- **Date range picker:** Simple single-calendar popover; presets in a left rail; comparison dropdown below the calendar.
- **Empty state language:** Setup-forward, not upsell-heavy. "Connect Shopify to see Orders" — no big red "Upgrade to Pro" banners like Triple Whale.
- **No global attribution toggle:** Unlike Northbeam, Polar does not expose an app-wide attribution model/window/accounting control. Instead, attribution is per-dashboard or per-block.
- **Heavy "bespoke" positioning:** Polar markets itself as customizable-first; the UI reflects this — few opinionated defaults, many configuration surfaces.

---

## Screenshot inventory

```
https://cdn.prod.website-files.com/5d41ab9d8cacf54c04df67c0/659c11624472d20f41e5dede_composite%20screenshot%20(2).webp
https://cdn.prod.website-files.com/5d41ab9d8cacf54c04df67c0/67e6c5ccc5f4c5d539c65c24_64631aef6faef9fd604c2b07_Blended-Performance.svg
https://cdn.prod.website-files.com/5d41ab9d8cacf54c04df67c0/6463201046bbe6989b905001_Key-Traffic-Metrics.svg
https://cdn.prod.website-files.com/5d41ab9d8cacf54c04df67c0/67e6df532c21ba12df537aa8_64631c256afc95b84ac3d7cc_Custom-Metrics.svg
https://cdn.prod.website-files.com/5d41ab9d8cacf54c04df67c0/6759a26b5cfdde8351b7d45c_activate-cards.png
https://cdn.prod.website-files.com/5d41ab9d8cacf54c04df67c0/646320206faef9fd60521d4b_alert-slack.svg
https://cdn.prod.website-files.com/5d41ab9d8cacf54c04df67c0/664f13a2ee13dbab46349288_Track%20-%20Connectors.webp
https://cdn.prod.website-files.com/5d41ab9d8cacf54c04df67c0/664f13a2e2bf4f659c354393_Analyze%20-%20Custom%20report.webp
https://cdn.prod.website-files.com/5d41ab9d8cacf54c04df67c0/664f13a2f07959f4354cadf0_Activate.webp
https://cdn.prod.website-files.com/5d41ab9d8cacf54c04df67c0/659418a7790f01c689cd09ec_TrackWhatMatters.webp
https://cdn.prod.website-files.com/5d41ab9d8cacf54c04df67c0/659418de16cc4da81e293d6a_TrackWhatMatters-1.webp
https://cdn.prod.website-files.com/5d41ab9d8cacf54c04df67c0/659418de6eac1570ba7f9734_TrackWhatMatters-2.webp
https://cdn.prod.website-files.com/5d41ab9d8cacf54c04df67c0/659418de4eeb71d5dcf0b859_TrackWhatMatters-3.webp
https://cdn.prod.website-files.com/5d41ab9d8cacf54c04df67c0/659418dec66fcb4c3efba867_TrackWhatMatters-4.webp
https://cdn.prod.website-files.com/5d41ab9d8cacf54c04df67c0/684829b496e1375c3c8dd9c4_connectors%20(data%20integration).png
https://cdn.prod.website-files.com/5d41ab9d8cacf54c04df67c0/684829c8316ce188c4a1b244_marketingindicators%20(advanced%20analytics).png
https://cdn.prod.website-files.com/5d41ab9d8cacf54c04df67c0/684829d58db947f9cb9ed00b_causal%20(incrementality).png
https://cdn.prod.website-files.com/5d41ab9d8cacf54c04df67c0/684829df80c8f14eb793b616_activate%20(data%20activations).png
https://cdn.prod.website-files.com/5d41ab9d8cacf54c04df67c0/684829e9ccb113d5598165a4_bespoke.png
https://downloads.intercomcdn.com/i/o/lfrl4yis/1346410680/
https://downloads.intercomcdn.com/i/o/lfrl4yis/1346419530/
https://downloads.intercomcdn.com/i/o/lfrl4yis/1965004972/88bbf6dfeec9db411dbe09d444bb/2024-09-20_13-15-25%2B-281-29.gif
https://downloads.intercomcdn.com/i/o/lfrl4yis/1965008320/638cbdb2ac3ed1f26e95484a157b/2024-09-20_13-11-43%2B-281-29.gif
https://downloads.intercomcdn.com/i/o/lfrl4yis/1965010315/23d82ac0d12def25f1b1dfe68f11/2025-03-14_09-03-15%2B-281-29.gif
```
