# Profit

Route: `/profit`

## Purpose

One page that answers "what's left after I pay for goods, shipping, fees, and ads?" across the six-source lens — with an accounting-grade P&L a CFO can read unchanged.

## User questions this page answers

- What's my actual net profit this month, and what ate into it?
- Where did margin leak — discounts, refunds, shipping, fees, or ad spend?
- How does this month compare to last month / same month last year as an income statement?
- Which country / product / channel / campaign is actually profitable, not just high-revenue?
- Are we on pace to hit the monthly profit target?
- Does the picture change when I switch between Cash Snapshot and Accrual Performance?

## Data sources

| Source (UX §4 color) | Required? | Provenance | Freshness |
|---|---|---|---|
| Store | yes | `orders` (revenue, discounts, refunds, shipping charged, taxes); `daily_snapshots`, `daily_snapshot_products` for aggregation | Shopify webhooks ~2 min; WooCommerce poll 15 min |
| Facebook Ads | required if ProfitMode to show paid-profit | `ad_insights` filtered to platform=facebook, level=campaign; FX-converted via `FxRateService` | 15 min sync |
| Google Ads | same as Facebook | `ad_insights` filtered to platform=google | 15 min sync |
| Store cost settings | yes | `store_cost_settings` + per-variant COGS on `order_line_items.cogs_amount`, shipping rules, transaction-fee rates, VAT table, OpEx allocation schedule | On write (inline-editable in Settings → Costs) |
| Real (Nexstage-computed) | yes | `RevenueAttributionService` net-of-costs allocation; profit attributed to a source = its attributed revenue × blended gross margin − its proportional ad spend | Recomputed with SourceToggle / WindowSelector / AccountingModeSelector changes |
| Site / GSC | no | Not relevant to profit; source badges shown as greyed/unavailable on MetricCards | — |

COGS is applied at order-capture time and is **not retroactively updated** when product costs change — see `_crosscut_metric_dictionary.md` glossary "COGS".

## Above the fold (1440×900)

```
AlertBanner (danger, conditional)
  - Rendered when StoreCostSettings.completeness < 100%: "Add COGS in Settings → Costs
    to see profit" + CTA "Configure costs". Dismissible only after all cost types present.
  - Rendered when workspace in demo state: DemoBanner (UX §5.11.1).

FilterChipSentence
  - "Showing profit for Last 30d vs previous period · Accounting mode: Cash Snapshot"
  - Chips: date range, comparison, attribution model, window, accounting mode,
    breakdown (if active), ProfitMode (forced on — chip shows "Profit view: on").

KpiGrid (4 cols × 2 rows, 7 cards + 1 targets tile)
  - MetricCard "Gross Sales" · sources=[Store, Facebook, Google, Real]
      - TargetProgress (UX §5.23): Revenue target bar beneath headline
      - Naming: accounting-grade "Gross Sales" over generic "Revenue" per `_crosscut_metric_dictionary.md` principle 3 (Shopify parity)
  - MetricCard "Net Sales" · sources=[Store, Real]
      - Label suffix "(after refunds, discounts)"
  - MetricCard "COGS" · sources=[Store]
      - ProfitMode indicator always-on (§5.1 step 6)
  - MetricCard "Ad Spend" · sources=[Facebook, Google, Real]
      - Headline = Total Ad Spend; per-source badge shows single-platform spend
  - MetricCard "Contribution Margin %" · sources=[Real]
      - Computed: (Net Revenue − COGS − Shipping − Fees) / Net Revenue
      - ConfidenceChip if n < workspace.confidence_threshold
  - MetricCard "Net Profit" · sources=[Real, Store]
      - Headline card — largest variant if grid layout allows
      - TargetProgress: Profit target
      - SignalTypeBadge "Deterministic" when all cost inputs configured; "Mixed"
        when OpEx is modelled
  - MetricCard "Net Margin %" · sources=[Real]
      - TargetProgress: Margin target (amber when behind pace)
  - (Grid cell 8) TargetProgress summary mini-card linking to Settings → Targets

ProfitWaterfallChart (UX §5.17) — primary hero component below KPI grid
  - Bars left→right: Gross Revenue → (Discounts) → (Refunds) → Net Revenue →
    (COGS) → (Shipping) → (Transaction Fees) → Contribution Margin → (Ad Spend) →
    Gross Profit → (OpEx) → Net Profit
  - Green bars positive; rose negative; subtotal bars (Net Revenue, Contribution
    Margin, Gross Profit, Net Profit) in slate outline
  - Missing cost types render as dashed bars labelled "Not configured — click to add"
  - Hover bar: formula + % of Gross Revenue + source badge
  - Click a deduction bar → DrawerSidePanel with the underlying rows
    (e.g., click "Transaction Fees" → list of orders contributing, with per-order fee)
  - When BreakdownSelector active (Country / Product / Channel / Campaign):
    split into grouped mini-waterfalls (max 6 groups rendered; "Top 5 + Other")
```

Target density above the fold: 7 KPI cards + 1 waterfall = 8 primary widgets (UX §4 density target 8–15).

## Below the fold

```
IncomeStatementTable (Lifetimely pattern — classic P&L, not a waterfall)
  - DataTable variant, rows are line items, columns are time periods
  - Rows (in order, matching accounting convention):
      Gross Sales
      − Discounts
      − Refunds
      = Net Sales
      − COGS
      = Gross Profit
      · Gross Margin % (indented sub-row)
      − Shipping Costs
      − Transaction Fees
      = Contribution Margin
      · Contribution Margin % (indented)
      − Ad Spend (expandable: Facebook · Google · Other)
      = Operating Profit
      − Operating Expenses (expandable: user-defined cost rows)
      = Net Profit
      · Net Margin % (indented)
  - Columns: This month · Last month · % Δ · Same month last year · YoY Δ
    (Switchable header toggle: monthly / weekly / quarterly)
  - Totals column on the right = sum over selected date range
  - Inline-editable cells (§5.5.1) on OpEx rows — click to enter monthly fixed costs
  - Hover any amount → formula tooltip (hover-to-reveal formula, §4.1, Lifetimely pattern)
  - Click any cell → DrawerSidePanel with contributing orders / spend rows / cost
    entries for that line × period
  - ExportMenu (UX §5.30): CSV includes all six revenue source columns for Gross Sales

BarChart "Profit by <Breakdown dimension>" (rendered only when BreakdownSelector active)
  - Horizontal bars ranked by Net Profit descending
  - Secondary axis: Net Margin % (diamond marker per row)
  - Red-to-green cell gradient (per _patterns_catalog.md "Red-to-green gradient")
  - Click bar → filters entire page to that group
  - Available dimensions on /profit: Country · Product · Channel · Campaign
    (BreakdownSelector surfaces these four; no Ad Set / Device / Segment here)

LineChart "Profit over time" · multi-source overlay
  - GranularitySelector: Daily · Weekly · Monthly (default Weekly for ≥14d ranges)
  - Lines: Real (solid gold), Store (slate), Facebook-attributed profit (indigo, dotted),
    Google-attributed profit (amber, dotted) — platform-attributed profit = that source's
    attributed revenue × blended margin − that source's ad spend
  - TargetLine at monthly profit target; pacing variant shows on-pace dotted trend
  - ChartAnnotationLayer (§5.6.1): cost updates, COGS changes, Facebook token
    disconnects auto-annotate with system-authored flags
  - Rightmost bin dotted (incomplete period, §5.6)

Section: "Cost configuration health"
  - Compact row of StatusDot + label chips:
    COGS coverage · 94% of SKUs · View missing →
    Shipping rules · Flat $7.50 configured · Edit →
    Transaction fees · Shopify Payments auto-sync · Healthy
    VAT · 3 countries configured · Edit →
    OpEx · $4,200/mo · Edit →
  - Links go to Settings → Costs with the relevant subsection deep-linked.
  - Same pattern as Integrations page connection cards, sized smaller.
```

## Interactions specific to this page

- **ProfitMode is effectively always-on.** TopBar toggle remains for cross-page consistency but flipping it OFF on `/profit` shows a small chip "Profit view locked on this page" rather than hiding profit — revenue-only inspection belongs on `/dashboard` or `/orders`.
- **AccountingModeSelector (§5.26) is load-bearing.** The same period can show substantially different numbers: Cash Snapshot credits revenue on order date, Accrual Performance credits on click/impression date. Toggling triggers a Klaviyo-style retroactive recalc (§6) with `"Recomputing…"` banner.
- **Waterfall → income-statement alignment.** Clicking any waterfall bar scrolls and highlights the matching income-statement row. Reverse also works: hovering an income-statement row highlights the corresponding waterfall bar.
- **Breakdown behaviour.** When BreakdownSelector is active, the waterfall splits into grouped mini-waterfalls (one per top-N group, default N=5 + "Other"). The income statement gains a group column-header toggle; the "Profit by <dim>" BarChart renders. Clicking a group in any of these three surfaces filters the whole page to that group.
- **Target authoring.** Each headline target (Revenue / Profit / Margin) is inline-editable on the TargetProgress bar for Admin+ users via §5.5.1 pattern — no separate settings page roundtrip for the common "bump next month's target" workflow.
- **Forecast-as-goal.** "Save as goal" on the profit LineChart captures the current month's pacing trend as a locked target line on every `/profit` and `/dashboard` profit surface. Pattern source: [Lifetimely](../competitors/lifetimely.md) forecast lock.
- **DemoBanner during onboarding:** profit surfaces render sample data until COGS is configured AND at least 14 days of orders are synced — otherwise the page shows the AlertBanner CTA above and greys the waterfall.

## Competitor references

- [Lifetimely P&L](../competitors/lifetimely.md) — classic income-statement layout, drill-down on any row, daily refresh. Benchmark.
- [Lifetimely LTV Drivers + role templates](../competitors/lifetimely.md) — source for "CFO-familiar rows/columns beats a fancy waterfall" principle (we keep both: waterfall for pattern-recognition, income statement for accounting rigour).
- [Polar Profitability Dashboard](../competitors/_teardown_polar.md#screen-profitability-dashboard) — waterfall with Revenue → Refunds → COGS → Shipping → Fees → Ad Spend → Custom Expenses → Net Profit; validates our bar order.
- [Shopify Native Finance reports](../competitors/shopify-native.md) — uses same Gross/Net semantics; aligning matters because users cross-check.
- [Northbeam accounting mode toggle](../competitors/northbeam.md) — the Cash Snapshot / Accrual Performance pill we're copying verbatim (UX §5.26).
- [ROAS Monster Winners & Losers split vs target](../competitors/roasmonster.md) — the target-progress framing on profit cards.
- [Putler P&L + Pulse](../competitors/putler.md) — pacing-against-target pattern for the LineChart overlay.
- Anti-pattern: [Shopify paywalls profit reports to Advanced tier](../competitors/shopify-native.md) — reason to include this in base plan.

## Mobile tier

**Mobile-usable** (768×1024+). On `sm`/`md`:
- KpiGrid collapses to 2 columns then 1.
- ProfitWaterfallChart switches to vertical orientation; deduction bars stack; swipe-scroll horizontal if wider than viewport.
- IncomeStatementTable locks the first column (line-item name) and horizontal-scrolls periods.
- BarChart breakdown rendering remains.
- Below 768px: AlertBanner "Some widgets are clearer on desktop — view full P&L on a larger screen" (single notice, dismissible per session).

## Out of scope v1

- **Forecasting beyond target-as-line.** Lifetimely's scenario forecasting (growth %, CAC inputs → projected revenue) is v2.
- **Custom cost categories beyond the six fixed buckets** (COGS / Shipping / Fees / VAT / OpEx / Custom). V2 adds user-named cost categories with rules.
- **Multi-warehouse / multi-currency cost entry.** V1 assumes a single cost currency per workspace; FX applied at display via `FxRateService`.
- **Subscription / MRR accounting.** Subscription revenue still counts in Gross Sales but deferred-revenue schedules, contracted-MRR recognition, and churn accounting are `/customers` concerns (and mostly v2).
- **Benchmarking profit vs peers** (Varos-style). Schema-ready; not rendered in v1 until peer critical mass.
- **Cash-flow / working-capital views** (AR/AP, inventory-on-hand as a cost). Out of scope — profit, not cash.
- **Anomaly alerts on profit** (e.g., "Net Margin dropped >15% vs 7d avg"). Lives in `/alerts` (mobile-first) but not surfaced on this page v1.
- **Custom SQL P&L queries** — templated IncomeStatementTable covers v1; custom SQL deferred to v2 per [UX §2](../UX.md#out-of-scope-for-v1-intentional).
