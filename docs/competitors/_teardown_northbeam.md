# Northbeam — UI teardown

**Scope:** screen-by-screen deep dive. Companion to [`northbeam.md`](northbeam.md). Reference from page specs in `/docs/pages/`.

Sources: `docs.northbeam.io` help center (overview-page, navigating-northbeam, northbeam-30, sales, orders-tracking, creative-analytics, metrics-explorer, product-analytics, create-a-dashboard), northbeam.io marketing pages (`/features/apex`, `/features/dashboards`, `/features/platform-overview`), ATTN Agency Northbeam review, findableis.com Northbeam feature overview, and YouTube walkthrough transcripts for Sales and Breakdown Manager. All descriptions synthesised from written walkthroughs and help-doc captions.

## Global chrome

### Top bar
- **Logo placement:** Top-left. Northbeam wordmark + triangular mountain/peak glyph in the bridge/navy brand color. Clicking returns to Overview Home.
- **Workspace/store switcher:** Top-left beside the logo. A dropdown labelled with the brand/store name. For agencies it opens a searchable list; unlike Triple Whale, Northbeam does not expose a "blended" multi-store view — you switch between workspaces one at a time. Version indicator "v2.2" or "3.0" sits inline next to the brand name on certain pages.
- **Global date range:** Central or top-right of each page's control strip. Presets: Today / Yesterday / Last 7d / 14d / 30d / 90d / MTD / QTD / YTD / All-Time / Custom. A comparison selector sits immediately beside it — "Previous Period", "Prior Year Same Period", "Custom". A granularity control (Daily / Weekly / Monthly) sits alongside; weekly is the recommended default.
- **Global filters (signature feature):** Northbeam's three global controls are always visible at the top of every analytical page:
  - **Attribution Model dropdown** — Clicks-Only / First Touch / Last Touch / Last Non-Direct / Linear / Clicks+Modeled Views / Clicks+Deterministic Views. Each option has a tooltip with the one-line definition.
  - **Attribution Window dropdown** — 1d / 3d / 7d / 14d / 30d / 90d / LTV.
  - **Accounting Mode toggle** — Cash Snapshot vs. Accrual Performance. Visually rendered as a pill switch. Cash Snapshot = reporting/budgeting optimised; Accrual Performance = daily media-buying optimised.
  - These controls are **global** — set once at the top, every tile/chart/table below respects them. This is one of the strongest interaction patterns in the category.
- **User menu, notifications, help:** Top-right cluster. Notifications bell, help (?), user avatar menu (Profile, Team, Billing, Logout). "Support" opens a ticket submission modal.
- **Command palette / search:** Global search (likely `/` or `Cmd+K`) for navigating between pages, finding metrics, and jumping to orders by ID.

### Sidebar
- **Sections (in order, top to bottom):**
  1. **Overview** (home icon) — Overview Home dashboard
  2. **Sales** (bar chart icon) — the most-used page in Northbeam, the channel/campaign/adset/ad table
  3. **Attribution** (target icon) — Attribution Home, model/window comparisons
  4. **Orders** (package icon) — Customer-journey and order-level attribution
  5. **Creatives** (image icon) — Creative Analytics gallery
  6. **Metrics Explorer** (telescope icon — per review) — Pearson correlation analysis
  7. **Dashboards** — custom & shared dashboards (collapsible list)
  8. **MMM+** (chart icon) — Media Mix Modeling, enterprise only
  9. **Benchmarks** (gauge icon) — Profitability Benchmarks
  10. **Apex** (flame/rocket icon) — Meta bidding integration controls
  11. **Subscriptions** (recurring icon) — Subscription analytics for recurring stores
  12. **Product Analytics**
  13. **Integrations** / **Account Settings** (gear icon, near bottom)
- **Collapsible?:** Yes. Sidebar collapses to icon-only rail with labels on hover.
- **Active state visual:** Indigo left-edge bar + tinted row background. Active icon is solid brand indigo; inactive icons are grey outline.
- **Nesting depth:** Mostly one level. The Dashboards section expands to show individual dashboards underneath as level 2.

### Color / typography / density
- **Primary accent color:** Navy / indigo brand blue (`#2a3f85`-ish) with a secondary electric-cyan for data. In Northbeam 3.0, the palette is cleaner and more muted than 2.x.
- **Chart palette:** Channel-coded (Meta blue, Google red, TikTok black/magenta, Snapchat yellow) but Northbeam also uses sequential blues for time-series where a single-channel emphasis is needed. Red/green only for delta indicators — never as chart lines — so positive/negative semantics stay distinct from platform colour.
- **Font family, number formatting:** Sans-serif (Inter / SF Pro-like). Tabular numerals throughout the Sales table. Currency formatted in store-locale; abbreviates to M/K at tile scale, full precision in tables. Percentages to one decimal, ROAS to two decimals.
- **Approximate density:** In Northbeam 3.0, approximately 10–15 tiles above the fold on Overview Home, 1 full chart + ~25 rows of the Sales table at 1440×900. Density is lower than Triple Whale but information density per widget is higher (every tile carries attribution model + window + accounting mode).

---

## Screen: Overview Home

- **URL path:** `/` (landing after login), or `/overview`.
- **Layout grid:** 12-column grid of customizable tiles. Three-column default on desktop, collapses to 2 on tablet.
- **Above-the-fold elements (top-left → bottom-right):**
  1. Top-bar chrome with workspace switcher
  2. Global controls strip: Attribution Model | Attribution Window | Accounting Mode | Date Range | Comparison | Granularity
  3. Tile: Revenue (All) — hero-size, dual-line chart with "This period" vs "Previous period" overlay
  4. Tile: Revenue (1st Time) — new customer revenue, same chart shape
  5. Tile: Spend — total ad spend across channels, bar chart
  6. Tile: ROAS (1st Time) at 1d / 7d / 30d — three stacked values in one tile with window labels
  7. Tile: CAC (1st Time) at 1d / 7d / 30d — three stacked values
  8. Tile: New Customers (integer + % delta)
  9. Tile: Returning Customers
  10. Tile: Visitors
  11. Tile: CPV (cost per visitor)
  12. Tile: RPV (revenue per visitor)
- **Below-the-fold (scroll):**
  - Tile: AOV, Transactions, CVR
  - **Conversion Lag chart** — time-series showing how much of today's spend is expected to convert at 7d / 30d / 90d. Key N3.0 upgrade.
  - Channel-share donut (revenue by channel)
  - "Add tile" placeholder with + icon to customise
- **Interactions:**
  - Hover on tile: sparkline becomes interactive with a crosshair value
  - Click on tile: "Drill into Metrics Explorer" — one-click jump to the Metrics Explorer prefilled with this metric as the primary metric
  - Right-click on tile: menu — "Duplicate", "Change metric", "Remove", "Set alert"
  - Tile drag-handle (dotted corner) lets user reorder; resize by corner grip
  - "Save View" button at top-right stores the current arrangement under a name; saved views appear as tabs above the canvas and are shared across the team
- **Empty state:** For a brand-new connected store: "We're building your first 30 days of modelled data — this takes about 25 days for Modeled Views to calibrate. Until then, use Clicks-Only attribution."
- **Loading state:** Skeleton tiles with pulsing placeholder charts. Dashboard paints in waves — tiles near the top render first. N3.0 tiles load ~2× faster than v2.
- **Screenshot URLs:**
  - `https://files.readme.io/461da36cbc463bd85538dea17a694b32eae0a51634de82a05908fd69e435fa32-Screenshot_2025-03-26_at_12.57.32_PM.png`
  - `https://files.readme.io/96a76349aff79f383461fc882d14f2f41025388ae2c56f187a8decbea4a2082-Screenshot_2025-03-26_at_12.56.55_PM.png`
  - `https://files.readme.io/f82f0214269cfb0492cb20ec528dc8a02330ce837646911899d2cce99871315e-Screenshot_2025-03-26_at_12.59.29_PM.png`

---

## Screen: Sales Page (the power-user page)

- **URL path:** `/sales`.
- **Layout:** Global controls strip at top. A stacked-area or line chart in the middle showing channel-coloured spend/revenue/ROAS over time. Below: a giant flat breakdown table. This is the most-used page in Northbeam — the interface is explicitly modelled on native ad-manager tables.
- **Chart section:**
  - Chart type toggle: Line / Bar / Stacked Bar / Split (N3.0 addition)
  - Metric selector: shows whichever metrics the user has in their column set
  - Full-screen button — expands chart to fill viewport
- **Table section:**
  - Left-locked first column: breakdown entity name (channel / campaign / adset / ad)
  - Breakdown level segmented control at top: Channel / Campaign / Adset / Ad
  - Default columns: Spend, Revenue (1st Time), ROAS (1st Time), New Customers, CAC (1st Time), Visitors, CPV, RPV, CAC, CPO
  - Users can fully customize column set — "Customize Columns" opens a two-pane modal: left = all metrics grouped by category (Spend, Revenue, Efficiency, Customer, Funnel, Custom), right = currently-selected columns in display order
  - Saved column sets appear as chips above the table; "Save as new" copies the current set
  - Sort by any column (asc/desc arrow on header)
  - Sticky header + sticky first column for horizontal scroll
  - Per-row expand chevron drills down one hierarchy level inline
  - Row hover reveals a small "breakdown" icon that opens the Breakdown Manager for deeper filtering
  - Every metric respects the global attribution model / window / accounting mode
- **Breakdowns & Filters:**
  - Breakdown Manager (N3.0): platform-specific quick filters for common patterns ("only prospecting Meta campaigns", "non-brand search")
  - Quick filters at top of table as chips — clicking adds/removes
- **Interactions:**
  - Click a channel name: drill to that channel's detail page with the same table filtered
  - Right-click row: "Open in Metrics Explorer" / "Open in Orders" / "Open in Creatives" / "Copy link"
  - Highlighted tooltips (N3.0): hover over any column header shows the metric's definition inline (Touchpoints, Revenue, ROAS, CAC, Visitors all have these)
- **Empty state:** "No campaigns matched these filters. Try widening your date range or removing filters."
- **Loading state:** Shimmer rows. Filters remain interactive.
- **Screenshot URLs:**
  - `https://files.readme.io/cef51733f70c959f863689971d64c60ee9b7b56b76fab3ada0c82c55dc50b92a-Screenshot_2025-03-26_at_11.33.19_AM.png`
  - `https://files.readme.io/1a23cc225924a869c7e7a3688ba84fb75cfc3695605e2784e6005c60d463ef3f-Screenshot_2025-03-26_at_11.33.48_AM.png`
  - `https://files.readme.io/af8a325c0451b73640ede89805f113e7b10c767c46277e1df963f4a55d2df119-Screenshot_2025-04-11_at_4.00.44_PM.png`

---

## Screen: Orders (customer journey)

- **URL path:** `/orders`.
- **Layout:** Filter sidebar (left) + order list + detail drawer (right).
- **Filters sidebar:**
  - Date range (separate from global date, because this is per-order)
  - Granularity
  - E-commerce platform (Shopify / Woo / Magento / BigCommerce / custom)
  - Ad platform (Meta / Google / TikTok / Snapchat etc.) — filters to orders with that platform in journey
  - Order ID search box
  - Touchpoint count min / max
  - Customer type (new / returning / reactivated — N3.0 addition)
- **Order list (main):**
  - Each row = one order. Columns: Order # / Customer initial + masked email / Order total / Touchpoints count / First touch platform / Last touch platform / Time to convert / Attribution credit split (stacked mini-bar)
  - Click row → opens detail drawer
- **Order detail drawer (right):**
  - Timeline of touchpoints, vertical, newest at bottom (purchase at bottom)
  - Each touchpoint: platform icon + campaign name + ad ID + UTM + landing page + timestamp
  - Fractional attribution credit shown per touchpoint ("TikTok: 0.6 / Meta: 0.3 / Direct: 0.1")
  - Model selector at top of drawer — see how credit re-shifts under different models
- **Interactions:**
  - Change attribution model globally → credit splits in the drawer change live
  - "Export as CSV" for the filtered order set
- **Screenshot URLs:** Not published but referenced in `docs.northbeam.io/docs/orders-tracking`.

---

## Screen: Creative Analytics

- **URL path:** `/creatives`.
- **Layout:** Gallery of creative cards + chart comparison panel at top. Default date range: Last 7 Days. Default attribution: Clicks + Modeled Views, 1d click / 1d view. Accounting: Accrual Performance (Cash Snapshot unsupported here).
- **Chart panel:**
  - Comparison chart supports up to **6 ads** at once; line or bar
  - Toggle between creatives by clicking their card's "Compare" checkbox
- **Creative cards:**
  - Grid of ~280-wide cards. Each card: creative preview (image / video / dynamic placeholder) + metrics panel
  - Metrics on card: Spend, CTR, CPM, ECR (engagement-to-conversion rate), CAC, ROAS
  - Colour-coded cells on a red→green gradient — red = negative deviation from average, green = positive
  - "Inactive" grey overlay when ad is paused, with toggle at top to hide inactive ads
- **Controls:**
  - Platform filter (Facebook / Instagram / Snapchat / TikTok / Google / Pinterest / YouTube)
  - Search box for finding specific ads by name
  - Sort dropdown — default sort by spend desc (recommended)
  - Save View button — stores current configuration; list of saved views in a chip row
  - Share view as link (copy to clipboard) — only works for registered Northbeam users
- **Dynamic creative handling:** Cards for DPAs / feed-based / programmatic ads show "Dynamic" placeholder thumbnail but keep full metrics.
- **Empty state:** "No creative data in this window. Creative Analytics only supports Accrual Performance — make sure your mode is set correctly."

---

## Screen: Metrics Explorer

- **URL path:** `/metrics-explorer`.
- **Layout:** Hub-and-spoke layout. Centre: large chart for the primary metric. Around it: "spoke" tiles representing target metrics with correlation scores.
- **Controls (top):**
  - Primary metric picker (auto-highlighted purple)
  - Target metrics: multi-select — each selected metric becomes a tile
  - Attribution model / window / granularity / time period — all per-panel, independent of global
  - Accounting mode
- **Primary metric chart:**
  - Large line chart with metric over time
  - Secondary axis toggle for overlaying a target metric
- **Spoke tiles (target metrics):**
  - Each tile: target metric name + correlation score (e.g. "+0.53") + relationship tag ("Strong Positive", "No relationship", "Very strong negative")
  - Sparkline on the tile
  - Click a spoke to promote it to primary
- **Correlation math:** Pearson Correlation Coefficient (PCC). Output range -1 to +1. Tags: Very strong negative, Strong negative, Moderate negative, Weak negative, No relationship, Weak positive, Moderate positive, Strong positive, Very strong positive.
- **Access tiers:** Standard users see single correlations; Enterprise sees multi-correlation mode.
- **Save view** + **export** controls at top-right.

---

## Screen: Attribution Home (model comparison)

- **URL path:** `/attribution`.
- **Layout:** Split into three zones:
  - Top: Channel mix donuts under 3 attribution models side-by-side (e.g. First Touch vs Linear vs Last Touch)
  - Middle: Channel-breakdown table with a column per attribution model, showing how revenue allocates under each
  - Bottom: Sankey diagram of common customer paths (top-touch → top-touch → purchase)
- **Controls:** Date range, attribution window, accounting mode, new vs returning vs all.

---

## Screen: Product Analytics

- **URL path:** `/products`.
- **Layout:** Product-first breakdown table. Each row = SKU. Columns: Units Sold, Revenue, AOV, Refund Rate, New Customer %, LTV-to-date.
- **Drill:** Clicking a product shows: Campaign attribution for this SKU, channel share, bundling (which other SKUs it pairs with), seasonality curve.

---

## Screen: Subscription Analytics

- **URL path:** `/subscriptions`.
- **Layout:** Grid of subscription KPIs: MRR, churn rate, LTV, subscriber cohorts. Cohort retention heatmap (Week 0 → Week 52).

---

## Screen: MMM+ (Media Mix Modeling, Enterprise)

- **URL path:** `/mmm`.
- **Layout:** Budget scenario builder on the left; response curves on the right; forecast table below.
- **Scenario builder:** Sliders per channel for "proposed weekly spend"; live recalculation of forecasted revenue and CAC.
- **Response curves:** S-shaped curve per channel showing diminishing returns. Line of current spend and "optimal spend".
- **Accuracy tracking:** A backtest panel shows model's recent accuracy vs actuals.
- **Enterprise-only feature.**

---

## Screen: Apex (Meta bidding integration)

- **URL path:** `/apex`.
- **Layout:** Connection status header (green checkmark or amber config needed). Below: table of campaigns being optimised with a "performance score" Northbeam is pushing back to Meta.
- **Settings:** Which campaigns to include, sensitivity toggle (conservative / balanced / aggressive).
- **Results panel:** Baseline CVR vs post-Apex CVR over time; cited 34% average improvement across studied advertisers.

---

## Screen: Profitability Benchmarks

- **URL path:** `/benchmarks`.
- **Layout:** Each metric card shows: your store's current value, your store's historical baseline (with outliers excluded), a range band, and a "within/outside target" indicator.
- **Crucially not peer-based** — benchmarks are user-tailored against their own history, not industry averages.

---

## Screen: Dashboards (custom)

- **URL path:** `/dashboards/<id>`.
- **Builder:** Similar to Overview but with tile-level overrides of attribution model / window / mode. Users save and share layouts; team-accessible.

---

## Screen: Integrations / Account Settings

- **URL path:** `/settings/integrations`.
- **Layout:** Card grid of connected platforms. Each card: logo, status pill (Connected / Error), last sync timestamp, "Configure" button.
- **Account Settings sub-pages:** Team (user invitations with role selector), Domains, COGS setup, Custom Metrics Editor.

---

## Specific micro-patterns worth documenting

- **Attribution suffix on every metric:** Every number in the UI carries a `(1d)` / `(7d)` / `(30d)` / `(LTV)` suffix — e.g. "ROAS (1st Time, 7d)". Users never have to guess which attribution window a metric is on. Global controls at top of page change all suffixes at once. This is the signature Northbeam UX gesture and the most differentiated pattern in the category.
- **Accounting mode as a first-class toggle:** Cash Snapshot vs Accrual Performance is a global switch always visible. Cash Snapshot uses when-the-money-hits attribution; Accrual Performance counts conversions when the click happened. N3.0 reinforces the distinction in tooltips.
- **"1st Time" suffix on customer metrics:** New-customer-only revenue is called "Revenue (1st Time)" with "All" as the counterpart. This naming is stricter than Triple Whale's "NC-ROAS" and appears everywhere.
- **Tooltips embedded in tables (N3.0):** Column headers are hoverable and show the definition inline — no need to navigate to docs. Covers Touchpoints, Revenue, ROAS, CAC, Visitors.
- **Customer segmentation everywhere (N3.0):** Where customer splits apply, tables now show All / New / Returning / Reactivated (the "Reactivated" status is new in 3.0).
- **Column set saving:** Sales page lets users save multiple column sets and switch between them with one click — a power-user pattern missing from Triple Whale.
- **Full-screen chart/table mode:** N3.0 adds a full-viewport mode for both charts and tables. Useful on the Sales page for large breakdowns.
- **Metric naming updates (N3.0):** CAC → CPO (Cost Per Order), eCPNV → CPV (Cost Per Visitor). The renames are explicitly called out in release notes with a legacy-name badge for the first few weeks.
- **Version toggle:** One-click switch between Northbeam 2.x and 3.0 during the transition period — visible in the user menu.
- **Date range picker:** Simpler than Triple Whale's — single calendar with preset rail. Comparison selector is a separate dropdown beside it rather than embedded in the calendar modal.
- **Conversion Lag charts:** Available on any metric via "+ Add Titles" on dashboard tile. Answers "what will today's spend convert to over the next 7/30/90 days" — a standout pattern for subscription/considered-purchase stores.
- **Branded conversion metrics:** N3.0 introduces branded-conversion metrics from Axon by Applovin and Vibe — these are separate metric families, not mixed into the main ROAS.
- **Syncing indicator:** "Synced Nh ago" timestamp per-data-source, visible from the workspace switcher's expanded state.
- **Estimated / modelled indicator:** Modeled Views require 25–30 days to calibrate; during that window, an amber banner appears on any page showing Modeled Views attribution. Numbers become trustworthy after calibration.
- **Sharing model:** Saved views and dashboard layouts are team-shared by default (not per-user). This drives a "team dashboard culture" and matches the enterprise customer profile.
- **No post-purchase surveys:** Deliberate gap — Northbeam has ceded that ground-truth signal to Triple Whale / Fairing. Worth noting because Nexstage will need to make the same decision.
- **No blended multi-store view:** Agencies switch workspaces one at a time — not an "All Stores" blended mode like Triple Whale.

---

## Screenshot inventory

```
https://files.readme.io/461da36cbc463bd85538dea17a694b32eae0a51634de82a05908fd69e435fa32-Screenshot_2025-03-26_at_12.57.32_PM.png
https://files.readme.io/96a76349aff79f383461fc882d14f2f41025388ae2c56f187a8decbea4a2082-Screenshot_2025-03-26_at_12.56.55_PM.png
https://files.readme.io/f82f0214269cfb0492cb20ec528dc8a02330ce837646911899d2cce99871315e-Screenshot_2025-03-26_at_12.59.29_PM.png
https://files.readme.io/cef51733f70c959f863689971d64c60ee9b7b56b76fab3ada0c82c55dc50b92a-Screenshot_2025-03-26_at_11.33.19_AM.png
https://files.readme.io/1a23cc225924a869c7e7a3688ba84fb75cfc3695605e2784e6005c60d463ef3f-Screenshot_2025-03-26_at_11.33.48_AM.png
https://files.readme.io/af8a325c0451b73640ede89805f113e7b10c767c46277e1df963f4a55d2df119-Screenshot_2025-04-11_at_4.00.44_PM.png
https://files.readme.io/1ae56dd97ad8f5139d16396b55883856418788e96514e2854035b9807bbd1daf-image.png
https://northbeam.findableis.com/features/dashboards.html (hero: 63d7dae453f4b817ad9dda70_Overview Page - Bottom of Page (1)-min.webp)
https://northbeam.findableis.com/features/dashboards.html (Full-Funnel: 63d8fb82290dd44da13ead7d_Group 3906-min.webp)
https://northbeam.findableis.com/features/dashboards.html (Omnichannel: 63d8fb8349fdb3e072afac2e_Group 3830-min.webp)
https://northbeam.findableis.com/features/dashboards.html (Cohort: 63e52012295a65ff85a23f2c_Group 3832-min.webp)
https://northbeam.findableis.com/features/dashboards.html (Accounting: 63e520135f5d674319456fce_Group 4052-min.webp)
https://northbeam.findableis.com/features/dashboards.html (Attribution: 63e5201313093e30574c106a_Group 3833-min.webp)
```
