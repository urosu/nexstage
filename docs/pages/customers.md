# Customers

Route: `/customers`

## Purpose

One page that answers "who are my customers, how do they behave, and which groups deserve different treatment?" — RFM lifecycle, cohort retention, LTV trajectories, and audience overlaps on a single surface.

## User questions this page answers

- Who are my Champions, and how many At Risk customers should I be winning back?
- Are April acquisitions retaining better than March, and how does that compare to last year's same cohort?
- What's the 90-day / 365-day LTV of customers acquired via Facebook vs Google vs organic?
- Which customers overlap between my "bought product X" and "bought product Y" segments?
- Who has the highest predicted next-order value, and when are they likely to buy?
- How does LTV:CAC look per acquisition channel, and where is the payback period shortest?
- Which customers did a particular campaign actually acquire, and what are they worth today?

## Data sources

| Source (UX §4 color) | Required? | Provenance | Freshness |
|---|---|---|---|
| Store | yes | `customers` (tenant-scoped), `orders`, `order_line_items`; RFM scoring computed nightly into `customer_rfm_scores` | Order webhooks ~2 min; RFM/LTV rollups nightly |
| Facebook Ads | no | `ad_insights` + `attribution_*` columns on orders for acquisition-channel breakdown of cohorts | 15 min sync |
| Google Ads | no | Same as Facebook | 15 min sync |
| Real (Nexstage-computed) | yes | `RevenueAttributionService` — source-attributed LTV, Predicted LTV, Predicted Next Order, Churn Risk. Model retrained weekly minimum (§5.28 SignalTypeBadge rules apply) | Nightly retrain; on-demand recompute on window change |
| Site / GSC | no | Not used for customer lifecycle; badges greyed on MetricCards | — |

All data is filtered through `WorkspaceScope`. Predicted LTV and Churn Risk render with SignalTypeBadge "Modeled" (§5.28); users with <90 days of data see the AlertBanner "Modeled metrics are calibrating — 42 of 90 days".

## Above the fold (1440×900)

```
AlertBanner (conditional)
  - DemoBanner (UX §5.11.1) during backfill
  - info "Modeled LTV is calibrating — 42 of 90 days. Numbers become reliable on
    May 28." (§5.28) if workspace is <90 days old

SubNavTabs — sibling views of customer data (Daasity/Northbeam pattern, UX patterns §"Tab-based sub-nav")
  - Segments · Retention · LTV · Audiences
  - URL-stateful (?tab=segments default)
  - Each tab preserves the TopBar global filters (date, attribution model, window,
    accounting mode, breakdown, source toggle)

FilterChipSentence
  - "Showing customers acquired in Last 90d · Acquisition channel = all · Source = Real"

KpiGrid (4 cols)
  - MetricCard "Total Customers" · sources=[Store, Real]
      - Sub-metric "New (30d)" as second line
  - MetricCard "New Customer Revenue" · sources=[Store, Facebook, Google, Real]
      - Suffix `(1st Time)` per Northbeam naming convention
  - MetricCardMultiValue "LTV" · sources=[Real]
      - Three stacked values: LTV (30d) · LTV (90d) · LTV (365d)
      - SignalTypeBadge "Modeled" on 365d window when outside deterministic horizon
  - MetricCard "Repeat Rate" · sources=[Store]
      - TargetProgress when Repeat Rate target is set
      - ConfidenceChip (§5.27) when <30 customers in range

===== TAB: Segments (default) =====

RfmGrid — signature component for this tab
  - 5×5 clickable grid: Recency (y-axis, rows) × Frequency (x-axis, cols)
  - Cells overlaid with the Klaviyo 6-bucket naming convention:
      Champions · Loyal · Potential Loyalists · At Risk · About to Sleep · Needs Attention
    (names rendered as zone overlays spanning multiple R×F cells, not per-cell labels,
    so the 5×5 math stays intact and names remain mutually exclusive)
  - Each cell shows: customer count · total LTV ·
    color intensity scaled to monetary value
  - Click cell or named-zone chip → DataTable (below) filters to that segment;
    FilterChipSentence updates; URL persists `?segment=at_risk`
  - "Convert to Audience" button per zone (stub in v1 — see out-of-scope)
  - Pattern source: [Peel RFM Analysis](../competitors/_teardown_peel.md#screen-rfm-analysis)
    + [Klaviyo 6-bucket RFM dashboard](../competitors/klaviyo.md#rfm-dashboard-marketing-analytics-add-on)

SegmentTiles row — 6 cards (Champions, Loyal, Potential Loyalists, At Risk,
                             About to Sleep, Needs Attention)
  - Each: named segment · count · % of base · avg LTV · avg days since last order ·
    growth chip vs previous period
  - Pattern source: [Segments Analytics segments-as-tiles](../competitors/segments-analytics.md)
  - Click tile = click the matching RfmGrid zone
```

Density check: 4 KPIs + RfmGrid (1 primary) + 6 SegmentTiles = 11 widgets above the fold at 1440×900.

## Below the fold

```
===== TAB: Segments (continued) =====

DataTable "Customers in <active segment>"
  - Default columns: Email (MiddleTruncate via EntityHoverCard §5.18) · Name ·
    First Order · Last Order · Orders · LTV · Predicted LTV · Predicted Next Order ·
    Churn Risk (0–100 bar) · First-Touch Source (SourceBadge) · Country
  - FilterChipSentence above — editable sentence per §5.4
  - SavedView button in toolbar (§5.19) — "Save this view" saves current
    segment + filters under sidebar as e.g. `Customers › US At Risk`
  - Row click → DrawerSidePanel (see Customer detail below)
  - (No natural-language prompt bar in v1 — AI chat / NL filter parsing deferred
    to v2 per UX §2 out-of-scope. FilterChipSentence is the sole filter input.)

===== TAB: Retention =====

CohortViewToggle — three-view toggle (Peel signature pattern)
  - Pill toggle: `Heatmap · Curves · Pacing`
  - URL-stateful (?cohort_view=heatmap default)
  - Shared controls across all three views:
      Cohort grain: Monthly / Weekly / Quarterly
      Metric: Retention % · Revenue per Customer · Cumulative LTV · Orders per Customer
      Split by: None / Acquisition Channel / First Product / Country / Campaign
      Time horizon: 30 / 60 / 90 / 180 / 365 days

  View 1: CohortHeatmap (UX §5.6)
    - Rows = acquisition month (or week / quarter per grain)
    - Columns = time-since-acquisition buckets
    - Cell = metric value; color scale; click cell → DrawerSidePanel with
      cohort customer list at that age
    - Red-to-green gradient per _patterns_catalog.md

  View 2: LineChart "Cohort Curves"
    - One line per cohort; x = months-since-acquisition; y = selected metric
    - Max 12 cohorts rendered (most recent); legend chip click to toggle
    - GranularitySelector applies

  View 3: Pacing LineChart
    - Solid line: most recent cohort at matching age
    - Dotted ghost: same-age trajectory of prior cohorts (median)
    - Shaded band: P25–P75 of prior cohorts (same component pattern as TodaySoFar §5.25)
    - Headline: "90-day LTV is pacing +12% vs last 6 cohorts' median at day 90"
    - Pattern source: [Lifetimely 3/6/9/12-month LTV vs YoY](../competitors/lifetimely.md)

  Pattern source for the whole three-view toggle:
  [Peel three-view cohort toggle](../competitors/_teardown_peel.md#screen-cohorts)
  + [Peel three-view cohort toggle (overview)](../competitors/peel-insights.md#cohort-analysis-core-differentiator)

===== TAB: LTV =====

LayerCakeChart (UX §5.6) — primary component
  - Stacked area; each layer is revenue contributed by a customer cohort acquired
    in that quarter, over time. New cohorts enter as fresh layers on top.
  - BreakdownSelector values (Country / Channel / Campaign / Product) re-color layers
  - Pattern source: [Daasity Layer Cake](../competitors/daasity.md)
  - Hover layer → that cohort's cumulative revenue at that point in time
  - Click layer → filters page to that acquisition cohort

LtvDriversTable (Lifetimely pattern)
  - DataTable; rows are dimension values, columns are
    Customer Count · CAC · LTV (90d) · LTV (365d) · LTV:CAC Ratio · CAC Payback Period
  - Dimension picker: First Product · Acquisition Channel · First Campaign ·
    Discount Code on First Order · Country · Customer Tag
  - Red-to-green cell gradient on LTV:CAC ratio column
  - Row click → filters whole `/customers` page to that dimension value
  - Pattern source: [Lifetimely LTV Drivers](../competitors/lifetimely.md)

KpiGrid (3 cols) — LTV-specific supporting metrics
  - MetricCard "LTV:CAC Ratio" · sources=[Real, Facebook, Google]
      - Target: 3:1 healthy threshold shown as TargetLine on sparkline
  - MetricCard "CAC Payback Period" · sources=[Real]
      - Value in days; amber above 180
  - MetricCard "Avg Days Between Orders" · sources=[Store]

===== TAB: Audiences =====

SegmentList (left) + SegmentDetail (right) — two-pane pattern
  - Left 320px column: list of saved segments (SavedViews, §5.19) plus system
    segments (the 6 RFM buckets). Each row: name · customer count · last updated ·
    destination badges (Klaviyo/Meta, greyed v2).
  - Right pane: segment detail
      - KpiGrid (3 cols): count · avg LTV · avg AOV
      - AudienceTraits grid — compact cards: top products, top countries,
        top acquisition channels, top discount codes, gender/age (if available),
        typical order cadence. Pattern source: [Peel Audience Traits]
        (../competitors/_teardown_peel.md#screen-audiences--audience-traits)
      - DataTable of customers in segment (same columns as Segments tab)

AudienceOverlapVenn (page-local primitive — specified here)
  - Renders at bottom of the Audiences tab
  - User picks 2 or 3 segments via multi-select (max 3); renders a Venn diagram
  - Each region shows customer count + $ LTV of that intersection
  - Click a region → DataTable filtered to the intersection customers
  - Only 2- and 3-circle variants supported v1; 4+ not meaningful visually
  - Component sizing: 480×360 fixed; not responsive below 768px (render message
    "Audience Overlap requires ≥ 768px wide" on mobile)
  - Pattern source: [Peel Audiences Overlap Venn](../competitors/_teardown_peel.md#screen-audiences-overlap-venn)

===== Cross-tab: Customer detail (DrawerSidePanel, §5.10) =====

Triggered by row click in any DataTable on this page. 480px wide.

  Header: masked email · name · country flag · StatusDot (active / churning / churned)

  Section 1: Metrics strip
    - Orders · LTV · Predicted LTV · AOV · Avg Days Between Orders ·
      Predicted Next Order (date + ConfidenceChip) · Churn Risk (0–100)

  Section 2: Order history
    - Compact table: order ID · date · $ · source badge · products
    - Click order row → navigate to /orders?order=<id> (DrawerSidePanel stays)

  Section 3: Touchpoint journey
    - Horizontal timeline: impressions → clicks → sessions → orders
    - Per touchpoint: timestamp · channel chip · campaign · device · value
    - Pattern source: [Northbeam per-order attribution journey]
      (../competitors/_teardown_northbeam.md#screen-orders-customer-journey)

  Section 4: Segments this customer belongs to
    - Chip row; click chip → filters whole page to that segment

  URL-stateful: ?customer=<id>
```

## Interactions specific to this page

- **Tab persistence.** Switching tabs keeps date range, attribution model, window, accounting mode, and breakdown. The `?segment=` / `?cohort_view=` / `?dimension=` URL params only apply to their owning tab.
- **RfmGrid ↔ SegmentTiles bi-directional.** Clicking a cell in the RfmGrid highlights and scrolls to the matching named-segment tile; clicking a tile highlights the corresponding zone on the grid. Same selection state.
- **Breakdown available on this page.** BreakdownSelector (§5.15) exposes Country · Channel (acquisition) · Campaign (acquisition) · Product (first product bought). Breakdown tints the RfmGrid cells and the LayerCakeChart layers; the DataTable gains the dimension as a leading column.
- **Customer detail drawer shows touchpoint journey across source badges.** A customer who clicked Facebook then returned via Google shows both — uses the same attribution-model selector as the rest of the page, so First Touch vs Last Touch changes which touchpoint gets the "acquiring" highlight.
- **Saved-segment as cross-cutting primitive.** Any filter combo → SavedView → appears in sidebar under "Customers" and is selectable in AudienceOverlapVenn. One definition, reused across cohort split-by, breakdown, and overlap.
- **Inline-edit predicted LTV override.** Admin-only: click Predicted LTV cell → enter an override (e.g., to exclude a corporate order). Stored as `customer_ltv_overrides`. §5.5.1.
- **Toolbar row** on every tab (right-aligned): `SavedView` chips (§5.19) · column picker · `ShareSnapshotButton` (§5.29) · `ExportMenu` (§5.30 — CSV includes per-customer six-source LTV columns).
- **ProfitMode behavior (replace-semantic).** When global ProfitMode is ON: LTV cards flip to Profit LTV (gross margin × cumulative revenue per customer), LTV:CAC Ratio becomes Profit LTV:CAC, CAC Payback Period recomputes against contribution margin. Source badges still apply. LayerCakeChart layers render profit contribution per cohort. If COGS missing, affected metrics degrade with amber StatusDot + "Add COGS" tooltip per UX §7.

## Competitor references

- [Peel Insights teardown — Cohorts, RFM, Audiences Overlap, Marketing Metrics, Subscriber Analytics](../competitors/_teardown_peel.md). Benchmark for the whole page.
- [Peel Insights overview — cohort + RFM + purchasing journey](../competitors/peel-insights.md).
- [Klaviyo 6-bucket RFM dashboard](../competitors/klaviyo.md#rfm-dashboard-marketing-analytics-add-on) — source of the named-segment taxonomy we're copying.
- [Klaviyo CLV dashboard](../competitors/klaviyo.md#clv-dashboard-marketing-analytics-add-on) — Predicted LTV + Predicted Next Order UX.
- [Daasity Layer Cake cohort chart](../competitors/daasity.md) — the LayerCakeChart pattern (UX §5.6).
- [Lifetimely LTV Drivers + 3/6/9/12-month LTV pacing](../competitors/lifetimely.md) — LtvDriversTable pattern and the pacing view in the Retention tab.
- [Northbeam customer segmentation with (1st Time) suffix](../competitors/_teardown_northbeam.md) — naming convention and the per-order journey rendering in DrawerSidePanel.
- [Triple Whale LTV & Cohorts](../competitors/_teardown_triple-whale.md#screen-ltv--cohorts) — cohort heatmap with LTV at Day 30/60/90/180/365 columns.
- [Segments Analytics — segments-as-tiles, FilterGPT, one-click activation](../competitors/segments-analytics.md).
- [Peel Audience Traits + Audiences Overlap Venn](../competitors/_teardown_peel.md#screen-audiences--audience-traits) — the two-pane audience detail layout.

## Mobile tier

**Mobile-usable** (768×1024+).

- KpiGrid 4→2→1 columns; MetricCardMultiValue collapses to stacked rows.
- Tabs become horizontally-scrollable pills.
- **Segments tab:** RfmGrid renders usably on tablet (768+); below 768px, RfmGrid is replaced by the SegmentTiles list only with a notice "Open on desktop for the RFM grid view."
- **Retention tab:** CohortHeatmap becomes "desktop-only" below 1280px per UX §8 (`xl` breakpoint); Curves and Pacing views work on tablet from 768px.
- **LTV tab:** LayerCakeChart simplifies to 4 layers max on mobile; LtvDriversTable keeps a sticky first column.
- **Audiences tab:** Two-pane collapses to single column with a back button; AudienceOverlapVenn hard-gated ≥ 768px with explicit message.
- DrawerSidePanel goes full-width.

## Out of scope v1

- **Segment activation / push to Klaviyo and Meta.** The "Convert to Audience" and "Send to Klaviyo" buttons are rendered but stubbed, linking to a v2 waitlist. Build-out lives in a dedicated activation service; see `_patterns_catalog.md` "One-click activation".
- **Subscription analytics (MRR, churn %, cancellation reasons, OTP→Subscriber conversion).** The schema should acknowledge a `subscription_id` dimension on orders if the store has Recharge/Shopify Subscriptions connected, but the dedicated subscription dashboard (Peel-style) is v2. Render an info chip on the Retention tab: "Subscription metrics coming in v2 — [join waitlist]".
- **Market Basket Analysis** (product co-purchase matrix). Belongs on `/products`, not `/customers`, and is v2 anyway.
- **Customer Purchasing Journey** (1st → 2nd → 3rd product sequence flow). Powerful for retention brands but v2.
- **Peer-benchmarked customer metrics** (Klaviyo-style "Excellent / Good / Fair / Poor" status badges on LTV, Repeat Rate). Schema-ready; requires peer critical mass.
- **Customer tag write-back to Shopify / WooCommerce.** Segment-as-Shopify-tag sync is v2 (and the one-way-friction trap called out in [Segments Analytics "What to avoid"](../competitors/segments-analytics.md) must be designed around).
- **4+ circle Venn diagrams.** Not meaningful visually; use overlap tables instead.
- **Deterministic cross-device identity stitching** (Triple Pixel / Northbeam Pixel). First-party pixel is explicitly out of MVP scope per UX §7.
- **Anomaly alerts on cohort trends** (e.g., "Churn Risk in Champions jumped +18%"). Lives in `/alerts` v1 via TriageInbox (§5.22); customer-specific tuning is v2.
- **Natural-language FilterGPT / AI segment builder** — deferred per UX §2 (AI chat / assistant → v2). v1 uses FilterChipSentence (§5.4) exclusively for segment construction.
