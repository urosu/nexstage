# Products

Route: `/products`

## Purpose

Answer "which products drive revenue, margin, LTV?" in a single sortable table — with inline COGS editing, 80/20 concentration, LTV correlation, and four opinionated lifecycle labels (Rockstar / Hot / Cold / At-Risk) that tell the merchant what to *do*, not just what the number is.

This is also the page where per-product COGS gets entered and corrected — the cost input upstream of every profit number elsewhere in the app. Get this page wrong and `/profit`, `/dashboard` ProfitMode, and LTV:CAC everywhere silently lie. The inline-edit UX is load-bearing.

## User questions this page answers

- Which SKUs are carrying the store (revenue + margin)?
- Which products need a COGS entered before profit math works?
- What's the 80/20 split — am I over-concentrated?
- Which first-product purchases correlate with the highest downstream LTV?
- Which products are declining in velocity and need attention?
- How does product performance split by country, channel, or customer segment?
- Where should I be allocating more ad spend, and where am I wasting it?
- Which SKUs are "gateway" products that create repeat customers, vs. one-and-done purchases?

## Data sources

| Source (UX §4 color) | Required? | Provenance | Freshness |
|---|---|---|---|
| Store (slate-500) | Yes | `daily_snapshot_products` aggregated from `order_items` joined to `products` / `product_variants`; COGS from `product_variants.cogs_amount` | Hourly rollup; inline COGS edits reflect within ~1min |
| Real (yellow-400) | Yes | `RevenueAttributionService` resolves per-product attributed revenue across ad sources; used when ProfitMode flips the revenue column | Same as `/attribution` cadence |
| Facebook (indigo-500) | Optional | `ad_insights` joined to product via `campaigns.parsed_convention->>'product_sku'` | Hourly |
| Google Ads (amber-500) | Optional | Same path as Facebook via `parsed_convention` | Hourly |
| Site (violet-500) | Deferred | Product-level session data from first-party pixel | v2 |
| GSC (emerald-500) | N/A | Greyed on this page — not product-scoped |

Notes on source behavior specific to this page:

- Per [RoasMonster pattern](../competitors/roasmonster.md) (see `_patterns_catalog.md` "Ad spend attributed to products/shops"), ad spend on this page is attributed to the product SKU via `parsed_convention`, not to the ad ID.
- Rows with no resolvable SKU mapping roll up into a terminal `Unmapped ad spend` row (same pattern as "Not Tracked" on Dashboard).
- Multi-workspace portfolio view uses `MetricCardPortfolio` (§5.1.1) on the KpiGrid, with per-workspace contribution segments on "Top-10 revenue concentration".
- FX conversion happens at order-line currency via `FxRateService` (DB-first cache per CLAUDE.md gotchas) — every revenue number on the page is already in workspace currency by the time it reaches the table.

## Above the fold (1440×900)

- `FilterChipSentence` (§5.4): *Showing **products** from **Last 28d** where **Country = All** and **Channel = All***.
- `KpiGrid` (4 cols) — all four cards full-source-badge row (Store + Real primary, Facebook + Google + Site for spend cards):
  - `MetricCard` "Products sold" · sources=[Store] — count of SKUs with ≥1 order in range.
  - `MetricCard` "Top-10 revenue concentration" · sources=[Store] — % of Net Sales driven by top 10 SKUs. Tooltip formula: `Σ(top-10 SKU Net Sales) ÷ Σ(all SKU Net Sales)`.
  - `MetricCard` "Median Gross Margin" · sources=[Store] — across SKUs with COGS configured. `ConfidenceChip` (§5.27) appears when <30 SKUs have COGS.
  - `MetricCardMultiValue` (§5.1.2) "Velocity" — Mean / Median / Mode units/day across active SKUs.
- **80/20 Pareto chart** — `BarChart` (§5.6, horizontal variant) — Putler pattern. X-axis: product rank (1..N, truncated at 50). Left Y-axis: revenue bar (descending, Store-badged). Right Y-axis: cumulative % line (Real-badged). Dashed crosshair at 80% cumulative marks the "vital few" boundary; bars left of it get a gold star glyph. Click any bar → row-filter the DataTable below to that SKU.
- **Products `DataTable` (§5.5)** — the core of the page. Sticky header, default sort Revenue DESC, default 50 rows, infinite scroll. Columns (column picker §5.5, `SavedView` §5.19):
  - Product (thumbnail 32×32 + title + SKU in `JetBrains Mono` 12.5px via `MiddleTruncate` §5.18)
  - **Label** — page-local lifecycle chip; rules below.
  - Revenue (tabular-nums; flips to Profit under `ProfitMode` §5.16)
  - Orders
  - Units
  - AOV
  - **COGS** — `Inline-editable cell` (§5.5.1). Empty cells render as amber chip "Add COGS" per UX §7 cost-inputs rule.
  - Margin % (computed `(Revenue - COGS × Units) ÷ Revenue`; "N/A" when COGS missing; `NULLIF` in SQL)
  - Velocity (units/day over range; 7-day rolling sparkline column, §5.6 Sparkline)
  - Source mini-row — compact six-dot `SourceBadge` strip per product showing which sources attributed revenue to this SKU this period. Click a dot → filter the row view to that source.
- **Product Labels** (page-local component `ProductLifecycleChip`, four values — BigCommerce naming, [bigcommerce-analytics.md](../competitors/bigcommerce-analytics.md) adopted):
  - `Rockstar` (emerald) — top decile by **both** revenue *and* margin % in the current range, min 30 orders (sample-size gate).
  - `Hot` (amber-warm) — revenue velocity up ≥25% vs comparison period, any margin.
  - `Cold` (zinc) — revenue velocity down ≥25% vs comparison period, any margin.
  - `At Risk` (rose) — declining velocity (down ≥15%) AND margin % in bottom quartile AND ≥ 10 orders.
  - Unlabeled rows get no chip (most SKUs — avoids chip spam). Hover a chip: tooltip states the rule, the SKU's current-period values, and the comparison-period values. Rules live in `app/Services/ProductLifecycleService.php`; workspace-overridable thresholds in Settings → Product Rules (v2).

## Below the fold

- **`ProductGatewayTable`** (page-local variant of the Lifetimely pattern — [lifetimely.md](../competitors/lifetimely.md) "LTV Drivers"; distinct from `/customers` `LtvDriversTable` because rows are always first-product SKUs rather than a switchable dimension):
  - Separate `DataTable` titled "First-purchased product → downstream LTV".
  - Answers the question "which SKU makes for the best gateway customer?" tabularly rather than via Peel's Purchasing Journey flow viz.
  - Columns:
    - First Product (thumbnail + title + SKU)
    - First-purchase Customers (N)
    - Avg LTV (365d)
    - Avg CAC (when a customer's first order was ad-attributable; source badge per row showing which platform acquired the customer)
    - LTV:CAC Ratio (computed, "N/A" when CAC unknown or zero; NULLIF in SQL)
    - Repeat Rate (% of first-buyers with ≥2 orders — metric dictionary "Repeat Rate")
    - Avg Days Between Orders
  - `ConfidenceChip` (§5.27) — rows with <30 first-purchase customers render greyed with suppressed LTV:CAC delta.
  - Sortable by any numeric column; default sort LTV:CAC DESC.
  - Row click opens the same per-product `DrawerSidePanel` as the main table (not a separate detail), scrolled to the "Downstream cohort" section which shows the 12-month cohort curve of first-buyers of this SKU.
  - Empty-state: when no cost data is configured workspace-wide, the CAC / LTV:CAC columns render as `Add costs to compute` with a CTA to Settings → Costs.
- **`QuadrantChart` (§5.6) — "Revenue vs Margin"** — Northbeam-style 1–100 relative index ([northbeam.md](../competitors/northbeam.md)). X-axis: Revenue Index (vs account history 90d). Y-axis: Margin % Index (vs account history). Four quadrants pre-colored: winners (top-right, emerald), margin-heavy niche (top-left, sky), volume-heavy thin-margin (bottom-right, amber), losers (bottom-left, rose). Quick-filter quadrant buttons above the chart auto-filter the DataTable to that quadrant. Hover a dot → EntityHoverCard (§5.18) with the product thumbnail + 5 key metrics.
- **`BarChart` — "Revenue by product category"** — stacked bar: one bar per product type / collection, stacked by Source. When `BreakdownSelector` = Country, becomes grouped by country × category.
- **7×24 product sales heatmap** (Putler pattern, [putler.md](../competitors/putler.md)) — below the category chart, scoped to the top-10 SKUs. Rows = day of week, columns = hour of day. Useful for creative/ad scheduling. Collapsible.

## Interactions specific to this page

- **Row click → `DrawerSidePanel` (§5.10)** — per-product detail:
  - Header: thumbnail + title + SKU + `ProductLifecycleChip` + Shopify/Woo deep-link button
  - Per-source attribution: six-source stack showing revenue attributed to this SKU by each source + the computed "Not Tracked" bucket (§5.14 logic applied at product scope)
  - 90-day trend: overlaid `LineChart` with Revenue / Orders / Units, `GranularitySelector` local
  - Inventory snapshot (when store connector exposes it — Shopify yes, WooCommerce depends on plugin):
    - `StatusDot` (§5.9) for stock health (emerald > 14 days cover, amber 3-14, rose < 3, zinc unknown)
    - Units on hand
    - Days-of-cover estimated from current-period velocity
    - "Inventory not available from this connector" when the source doesn't expose it (v1 graceful degrade, no v2 forecasting)
  - Margin breakdown:
    - Mini `ProfitWaterfallChart` (§5.17 compact form) scoped to this SKU
    - Stages: Gross revenue → (discounts) → Net → (COGS) → (shipping per order) → (fees) → Contribution per order
    - Missing costs render as dashed bars with "Not configured — click to add" per §5.17
  - Top 5 co-purchased products:
    - Computed cheaply from `order_items` as co-occurrence count only
    - No lift / confidence / support statistics — just a count
    - Labeled with a note: "Full Market Basket analysis in v2"
  - Annotation list for this SKU — the `ChartAnnotationLayer` (§5.6.1) filtered to the SKU, editable inline
  - "Open in Ads" button — deep-link escape hatch matching the ContextMenu action
- **Inline COGS edits are optimistic** (§6.2). Cell transforms into input on click; Enter saves; rollback Toast on server error. A saved edit does NOT retroactively restate historical `orders.cogs_amount` (explicit behavior in [`_crosscut_metric_dictionary.md`](../competitors/_crosscut_metric_dictionary.md) COGS glossary); a Toast on save says "New COGS applies to orders from today forward. [Backfill historical]" — the backfill link opens a confirm modal that dispatches `RecalculateProductCogsJob`.
- **Bulk COGS update** via multi-select (§ patterns catalog "Multi-select bulk actions"): Shift+Click or `x` to multi-select rows, sticky footer appears with "Set COGS…", "Apply margin %…", "Export selected". Bulk COGS update opens a modal with CSV upload or flat-value input.
- **`ProfitMode` toggle flips the entire table**: Revenue column becomes Profit, AOV becomes AOP (Avg Order Profit), the Pareto chart bars recompute with profit values, LTV Drivers surfaces Profit LTV. Source badges still apply (§5.16). When ProfitMode is on and a SKU has no COGS, its row is greyed 20% with the amber "Add COGS" chip in the Profit cell.
- **`BreakdownSelector` (§5.15) dimensions available on this page**: `None · Country · Channel · Campaign · Customer segment`. When active, the DataTable gains a leading dimension column and the Pareto chart groups bars by the dimension. `Ad set` and `Device` are disabled on this page — tooltip: "Not product-scoped; use `/ads` for ad-set breakdowns".
- **Right-click any product cell** → `ContextMenu` (§5.21) with a page-specific escape hatch "Open in Ads" (deep-links to `/ads?filter=product_sku:{sku}` to inspect which campaigns drive this product) plus standard Filter/Exclude/Copy actions.
- **URL-stateful**: `?sort=revenue&dir=desc&tab=main&drawer=sku_abc123&mode=profit&breakdown=country`. Shareable with one click via `ShareSnapshotButton` (§5.29).
- **`CommandPalette` (Cmd+K)** on this page: typing any product title or SKU jumps directly to its DrawerSidePanel.
- **Click a Pareto bar** → filters the DataTable to that SKU (left-click drills, per UX §6.1 chart-point rule).
- **Quick-filter quadrant buttons** above the QuadrantChart: one-click to filter the DataTable to just that quadrant's SKUs — the [Northbeam](../competitors/northbeam.md) pattern for canned drill-downs on a viz.
- **`SavedView` examples** expected on this page: `US Rockstars`, `At-Risk EU`, `Missing COGS` (pre-canned filter: COGS is null), `Top 20% by Profit`. Seeded on first workspace load.
- **URL-stateful side-drawer**: `?drawer=sku_abc123` opens the panel on page load; links shared via Cmd+K's "Copy link" action on a row reopen identically.

## Competitor references

- [Shopify Native — Sales by product / Profit by product](../competitors/shopify-native.md) — baseline every user has. We beat it by: cross-platform ad-spend-per-product, source badges, editable COGS (Shopify Advanced+ only).
- [BigCommerce Ecommerce Insights — Rockstar / Hot / Cold / At-Risk labels](../competitors/bigcommerce-analytics.md) — linguistic convention adopted directly for `ProductLifecycleChip`.
- [Lifetimely LTV Drivers table](../competitors/lifetimely.md) — the below-the-fold tabular correlation view, copied and extended with source-badged CAC.
- [Peel Product Analytics screen + Market Basket + Purchasing Journey](../competitors/peel-insights.md) — the inspiration for the DrawerSidePanel's co-purchase strip; full Market Basket is v2.
- [Putler 80/20 Pareto framing + 7×24 heatmap + Top 20% star glyph](../competitors/putler.md) — adopted directly.
- [Metorik Products report + inline COGS + bulk CSV upload](../competitors/metorik.md) — WooCommerce-native benchmark for COGS editing UX.
- [Glew per-brand contribution bar + inline COGS](../competitors/glew.md) — multi-store contribution is covered by `MetricCardPortfolio` (§5.1.1) when a portfolio view is active.
- [Northbeam Product Analytics QuadrantChart with 1–100 relative index](../competitors/northbeam.md) — directly transplanted as the Revenue × Margin quadrant.
- [RoasMonster products-as-rows](../competitors/roasmonster.md) — the ad-spend attribution structural inversion; we out-execute by keeping source badges visible rather than collapsing to one "Real" number.

## Mobile tier

**Mobile-usable** (UX §8). At <768px: KpiGrid collapses 4→2, Pareto chart renders top 15 only, DataTable becomes card-stack showing Thumbnail + Title + Label chip + Revenue + Margin %. LTV Drivers table and QuadrantChart hide behind a "View on desktop for full analysis" banner. Inline COGS editing remains functional via tap-to-edit.

## Out of scope v1

- **Market Basket analysis with lift / confidence / support statistics.** The DrawerSidePanel shows co-occurrence count only. Full market-basket table, including cross-product bundle suggestions, is **deferred to v2** (Peel / Putler parity). Explicitly called out so users don't expect it.
- **Inventory forecasting** (days-of-cover projection, reorder-point suggestions). `StatusDot` is a live snapshot only. Shopify Native and Metorik both ship forecasting; we don't in v1.
- **Product-level first-party pixel / sessions / Add-to-Cart Rate.** Site source greyed until pixel ships (v2).
- **Category / collection hierarchy drill-down.** v1 renders category as a flat BarChart dimension, not an expandable tree.
- **Product variant matrix view** (size × color × material analytics per SKU). Variants roll up into parent SKU row; v2 surfaces variant-level drilldown inside DrawerSidePanel.
- **Auto-generated "products to kill / scale" prescriptions.** Lifecycle chips are rules-based and transparent; no AI prescriptive layer (anti-[Atria](../competitors/atria.md)).
- **Bundle / kit product unbundling into component SKUs.** v2 — requires a `product_bundles` table and is explicitly a bigger modeling exercise.
- **Creative performance per product** (top-performing ad creatives for this SKU). Lives on `/ads` v1; the DrawerSidePanel links out via the "Open in Ads" ContextMenu action.
- **Automatic bundle suggestions** ("customers who buy X and Y should see Z as a bundle"). v2 with Market Basket.
- **Pareto label customisation** (letting the user set their own "top 20%" threshold). Rule is fixed at 80/20 in v1 — the convention is the memorable part.
- **Per-channel product detail in the drawer** (which Meta campaign drove this SKU's Facebook-attributed revenue). v1 shows source totals only; drill to `/ads?filter=product_sku:{sku}` for the campaign-level view.
- **Pricing / discount A/B test surfaces.** Price-change impact analysis is a v2 surface pending an `experiments` table.
