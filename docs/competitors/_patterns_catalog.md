# UI patterns catalog

Distilled from 22+ competitor + inspiration profiles. Each pattern has a canonical name, a description, where we saw it done well, where we saw it done badly, and whether we should adopt it.

## How to use this catalog

- Each pattern is a named, reusable UI primitive.
- The `Adopt` field is a recommendation — `yes` / `maybe` / `no`.
- For each page spec in `/home/uros/projects/nexstage/docs/pages/*.md`, reference patterns by name rather than re-describing them.
- Cross-reference source files via markdown links (e.g., `[Triple Whale](triple-whale.md)`) so every pattern is auditable back to the competitor profile.

## Layout & navigation patterns

### Pattern: Persistent left sidebar (two-zone)
- **What:** Fixed left nav with a Primary section (main pages) and a secondary Shortcuts/Saved Views section that auto-populates recents + pins.
- **Where we saw it:** [Stripe](_inspiration_stripe.md), [Linear](_inspiration_linear.md), [Lifetimely](lifetimely.md), [Polar](polar-analytics.md), [Peel](peel-insights.md), [Metorik](metorik.md).
- **Where it works:** Stripe's "Shortcuts" strip solves nav fatigue in wide apps without IA changes; Linear's collapsible sidebar with dimmer tone for focus hierarchy.
- **Where it fails:** Stripe now scrolls at 40+ products — scaling hazard. GA4's multi-level nested sidebar with "Library" pages is legendary for getting users lost.
- **Nexstage recommendation:** yes — single persistent sidebar with a Pinned Views section. Don't clone Stripe's scale.

### Pattern: Top-nav-only (context-switching)
- **What:** No persistent global sidebar; page sub-nav changes with the object (team → project → resource).
- **Where we saw it:** [Vercel](_inspiration_vercel.md).
- **Where it works:** Great for developer tools where users live inside one entity at a time.
- **Where it fails:** Breaks for dashboards where users jump Home → Campaigns → Audience → SEO frequently.
- **Nexstage recommendation:** no — we need persistent sidebar; cross-section jumps are the norm.

### Pattern: Single-page-no-nav
- **What:** Whole product fits on one scrollable page with a top chrome for date + filter only.
- **Where we saw it:** [Plausible](_inspiration_plausible.md).
- **Where it works:** Enforces ruthless simplicity; everything is deep-linkable.
- **Where it fails:** Works because Plausible has 5 reports; we have dozens.
- **Nexstage recommendation:** no — but borrow the spirit on Home page (dense single page, don't force tabs).

### Pattern: Collapsible sidebar with dimmer tone
- **What:** Two-state sidebar (expanded/icons-only), opens on hover when collapsed, slightly dimmer than main content for hierarchy.
- **Where we saw it:** [Linear](_inspiration_linear.md).
- **Nexstage recommendation:** yes — subtle color dimming is a cheap win for visual hierarchy.

### Pattern: Workspace/store switcher in top chrome (searchable)
- **What:** First-class switcher in the top bar with search, recent, pin, and keyboard shortcut.
- **Where we saw it:** [Stripe](_inspiration_stripe.md), [Vercel](_inspiration_vercel.md), [Peel](peel-insights.md).
- **Where it fails:** [GA4](_inspiration_ga4.md)'s cramped Account/Property/View dropdown — 10 years of support tickets.
- **Nexstage recommendation:** yes — deserves its own component, not a generic dropdown.

### Pattern: Aggregated-vs-per-store toggle (persistent in chrome)
- **What:** One-click toggle in top chrome switching between "All stores aggregated" and "One selected store."
- **Where we saw it:** [Glew](glew.md), [Daasity](daasity.md) (multi-store).
- **Where it works:** Glew's one-click toggle keeps multi-brand agencies out of drill-into-and-back workflows.
- **Where it fails:** Glew pairs it with per-brand contribution bars inside aggregated KPIs — good. If you don't show composition, users lose context.
- **Nexstage recommendation:** yes — this is table-stakes for multi-store workspaces. Pair with per-store contribution bars.

### Pattern: Breadcrumbs for hierarchical drill
- **What:** Clickable path (Channel → Campaign → Adset → Ad) at top of any detail page.
- **Where we saw it:** [Triple Whale](triple-whale.md), [Northbeam](northbeam.md), [ROAS Monster](roasmonster.md), [Vercel](_inspiration_vercel.md).
- **Nexstage recommendation:** yes — mandatory for breakdowns deeper than two levels.

### Pattern: Tab-based sub-nav within a page
- **What:** In-page tabs (Attribution → Pixel / Survey / Promo / MTA) share chrome and filters.
- **Where we saw it:** [Daasity](daasity.md), [Northbeam](northbeam.md), [Putler](putler.md), [MonsterInsights](monsterinsights.md).
- **Where it works:** Daasity's attribution tabs keep date range consistent while swapping lenses.
- **Nexstage recommendation:** yes for sibling views of the same data; no for fundamentally different pages (use sidebar).

### Pattern: Cmd+K command palette
- **What:** Keyboard-invoked omnibox for navigation + creation + search + actions, grouped by type.
- **Where we saw it:** [Stripe](_inspiration_stripe.md), [Linear](_inspiration_linear.md), [Vercel](_inspiration_vercel.md).
- **Where it fails:** Linear hides features behind shortcuts; discoverability bad for non-power users.
- **Nexstage recommendation:** yes — ship it, but keep visible buttons for keyboard parity.

### Pattern: Three-panel Explore canvas (Variables / Settings / Canvas)
- **What:** Left = variables library (dimensions, metrics, segments), Middle = per-view settings (rows/columns/values), Right = visualization.
- **Where we saw it:** [GA4](_inspiration_ga4.md) (Explorations).
- **Nexstage recommendation:** maybe — for a future "Custom Report" / advanced-explore surface, this IA is genuinely good. Not day-1.

### Pattern: View toolbar (filter/group/sort/display as one row)
- **What:** Horizontal toolbar above every list with filter chips, group-by, sort, and column/density toggles.
- **Where we saw it:** [Linear](_inspiration_linear.md), [Metorik](metorik.md) (partial).
- **Nexstage recommendation:** yes — canonical list-view chrome for BreakdownView.

### Pattern: Saved views as sidebar citizens
- **What:** Any filter/group/sort combo can be promoted to a named view in the sidebar.
- **Where we saw it:** [Stripe](_inspiration_stripe.md), [Linear](_inspiration_linear.md), [Metorik](metorik.md).
- **Nexstage recommendation:** yes — solves dashboard sprawl elegantly.

### Pattern: Role-scoped dashboard templates
- **What:** Pre-built starter dashboards per persona (Founder, CFO, Performance Marketer, Ops).
- **Where we saw it:** [Lifetimely](lifetimely.md), [Glew](glew.md), [Peel](peel-insights.md) (Customer/Subs/Marketing/Attribution), [ROAS Monster](roasmonster.md).
- **Where it fails:** Glew's nine-way persona split is enterprise-sales theater; overwhelming for SMB.
- **Nexstage recommendation:** yes — ≤3 personas (owner / marketer / ops); don't over-segment.

### Pattern: Department-organized dashboards (by question, not source)
- **What:** Top-level dashboards named "Acquisition" / "Retention" / "Inventory" (not "Shopify" / "Meta" / "GA4").
- **Where we saw it:** [Daasity](daasity.md), [Shopify Native](shopify-native.md) (Reports categories).
- **Nexstage recommendation:** yes — users think in questions, not sources.

### Pattern: Split pane on row click
- **What:** Clicking a list row opens a detail panel to the right instead of navigating away; closes with Esc.
- **Where we saw it:** [Linear](_inspiration_linear.md).
- **Nexstage recommendation:** yes — maintains context for order/campaign detail.

## Data display patterns (KPI cards, tables, charts)

### Pattern: KPI card with sparkline + delta
- **What:** Large number + trend arrow with % delta vs comparison + axis-less sparkline, no labels/legend.
- **Where we saw it:** [Stripe](_inspiration_stripe.md), [Triple Whale](triple-whale.md), [Shopify Native](shopify-native.md), [Metorik](metorik.md), [Polar](polar-analytics.md), [Lifetimely](lifetimely.md).
- **Where it works:** Stripe's canonical implementation — 32px number + 13px label + tiny trend line. Shopify Native's customizable card grid is the best merchant-facing implementation.
- **Nexstage recommendation:** yes — this IS `MetricCard`. Non-negotiable default.

### Pattern: Six-source badge row on metric card (Nexstage thesis)
- **What:** Under the headline number, a horizontal strip of six per-source badges (Store / Facebook / Google / GSC / Site / Real) with each showing their own count; "Real" is the gold lightbulb.
- **Where we saw it (nearest analog):** [Fairing](fairing.md) side-by-side (survey vs pixel), [Polar](polar-analytics.md) attribution compare (Meta/Google/GA4/pixel), [Triple Whale](triple-whale.md) blended + per-platform, [Putler](putler.md) source badges on transaction rows.
- **Where it fails:** [ROAS Monster](roasmonster.md) collapses platform vs store into one "Real" number — hides the disagreement that IS the information. [Metorik](metorik.md) trusts store DB absolutely; no reconciliation. [MonsterInsights](monsterinsights.md) is just GA4 in a wrapper.
- **Nexstage recommendation:** yes — this is our wedge. Polar validates that users want disagreement visible; ROAS Monster proves hiding it leaves value on the table.

### Pattern: Multi-value metric (mean/median/mode simultaneously)
- **What:** A single KPI that shows three values side-by-side because the distribution is skewed (AOV as mean/median/mode).
- **Where we saw it:** [Triple Whale](triple-whale.md).
- **Nexstage recommendation:** maybe — good pattern for any distribution-skewed metric. Don't overuse; it's dense.

### Pattern: Attribution-window suffix on metric names
- **What:** `ROAS (d)`, `CAC (d)`, `LTV` — typography itself encodes that the metric carries an attribution window.
- **Where we saw it:** [Northbeam](northbeam.md).
- **Nexstage recommendation:** yes — typography as documentation, zero UI cost.

### Pattern: Hover-to-reveal formula/definition
- **What:** Hover or click a metric name to see the formula + source references.
- **Where we saw it:** [Lifetimely](lifetimely.md), [Northbeam](northbeam.md) (NB 3.0 in-table tooltips), [Polar](polar-analytics.md).
- **Where it fails:** [Putler](putler.md) is called out for opaque number provenance — users can't explain numbers.
- **Nexstage recommendation:** yes — mandatory on every computed metric.

### Pattern: Peer-cohort shaded band on time series (P25-P75)
- **What:** Your line plus a shaded band for the P25–P75 peer distribution; "normal range" becomes visual.
- **Where we saw it:** [Varos](varos.md), [Triple Whale](triple-whale.md) (Benchmarks).
- **Nexstage recommendation:** yes — once we have peer data; till then, use account history as the band.

### Pattern: Percentile bar KPI row
- **What:** Horizontal bar with P25 / P50 / P75 gridlines and a marker for your value.
- **Where we saw it:** [Varos](varos.md).
- **Nexstage recommendation:** yes — best "you vs world" visual in the category.

### Pattern: Sample-size chip next to metric
- **What:** "Based on 12 orders — low confidence" chip next to any aggregated number.
- **Where we saw it:** [Varos](varos.md) (peer group size), absent in [MonsterInsights](monsterinsights.md) (deltas on tiny samples).
- **Nexstage recommendation:** yes — mandatory for attribution confidence. Gray out or strike through below threshold.

### Pattern: Quadrant scatter with 1–100 relative index
- **What:** Scatterplot with four colored quadrants (winners / losers / etc), axes indexed 1–100 against account history, not absolute thresholds.
- **Where we saw it:** [Northbeam](northbeam.md) (Product Analytics — CAC Index × ROAS Index).
- **Nexstage recommendation:** yes — generalise our existing `QuadrantChart`. Relative indexing is clever — avoids the absolute-benchmark problem.

### Pattern: Quick-filter quadrant buttons
- **What:** Buttons above the scatterplot to auto-filter to one quadrant (e.g., "only red").
- **Where we saw it:** [Northbeam](northbeam.md).
- **Nexstage recommendation:** yes — canned drill-downs on a viz.

### Pattern: Cohort heatmap table
- **What:** Rows = acquisition month, cols = time since acquisition, cells = retention/revenue with color-scale overlay.
- **Where we saw it:** [Peel](peel-insights.md), [Lifetimely](lifetimely.md), [Triple Whale](triple-whale.md), [Metorik](metorik.md), [Polar](polar-analytics.md), [GA4](_inspiration_ga4.md), [Shopify Native](shopify-native.md).
- **Nexstage recommendation:** yes — canonical cohort viz; every competitor has it.

### Pattern: Cohort curves (line-chart form)
- **What:** Each cohort rendered as a line on the same axes (time-since-acquisition on X, metric on Y).
- **Where we saw it:** [Peel](peel-insights.md).
- **Nexstage recommendation:** yes — visual users prefer this to heatmap.

### Pattern: Cohort pacing (actual vs predicted)
- **What:** Pacing graph showing whether the newest cohort is ahead of or behind historical averages at matching age.
- **Where we saw it:** [Peel](peel-insights.md), [Lifetimely](lifetimely.md) (3/6/9/12mo averages with YoY).
- **Nexstage recommendation:** yes — founder-legible framing ("my 90-day LTV is pacing 12% ahead of last year").

### Pattern: Three-view cohort toggle (table + curves + pacing)
- **What:** Same cohort data rendered three ways; user picks view.
- **Where we saw it:** [Peel](peel-insights.md).
- **Nexstage recommendation:** yes — serves analytical / visual / founder personas in one page.

### Pattern: Layer Cake stacked area cohort viz
- **What:** Stacked area where each layer is revenue from one acquisition cohort over time; new cohorts enter on top.
- **Where we saw it:** [Daasity](daasity.md).
- **Nexstage recommendation:** maybe — beautiful but dense; good for exec-facing cohort view.

### Pattern: P&L / income statement table
- **What:** Classic accounting rows (Revenue → COGS → Shipping → Marketing → Net Profit), columns = time periods.
- **Where we saw it:** [Lifetimely](lifetimely.md), [Shopify Native](shopify-native.md) (Finance reports), [Putler](putler.md), [Metorik](metorik.md) (Costs & Profit).
- **Where it works:** Lifetimely's deliberate non-waterfall format — CFOs recognize it instantly.
- **Nexstage recommendation:** yes — don't invent a new format; use the accounting-familiar one.

### Pattern: LTV Drivers table
- **What:** Tabular "what correlates with LTV" — product × LTV × customer count × CAC × ratio.
- **Where we saw it:** [Lifetimely](lifetimely.md).
- **Nexstage recommendation:** yes — more actionable than any chart for this question.

### Pattern: Funnel chart with drop-off percentages
- **What:** Horizontal bars for each stage, drop-off % and absolute count between steps.
- **Where we saw it:** [MonsterInsights](monsterinsights.md), [Plausible](_inspiration_plausible.md), [GA4](_inspiration_ga4.md) (Explore > Funnel).
- **Where it works:** Plausible's horizontal bar layout is more scannable than stacked bars.
- **Nexstage recommendation:** yes — horizontal preferred over stacked.

### Pattern: Side-by-side comparison view with delta column
- **What:** Two data sources (survey vs pixel, model A vs model B) as adjacent columns, with an explicit delta column in the middle.
- **Where we saw it:** [Fairing](fairing.md), [Polar](polar-analytics.md), [Daasity](daasity.md).
- **Nexstage recommendation:** yes — the purest expression of source-disagreement as information. Top pattern for our thesis.

### Pattern: Pulse + Overview dual-timeframe on one page
- **What:** Top of page = real-time current-month (Pulse); below = user-selected date range (Overview). Two timeframes coexist without a tab switch.
- **Where we saw it:** [Putler](putler.md).
- **Nexstage recommendation:** yes — excellent for Home; solves glance-vs-depth tension.

### Pattern: Today-so-far intra-day widget
- **What:** Running intra-day count + dotted projection line against yesterday/last-week's curve at same hour.
- **Where we saw it:** [Stripe](_inspiration_stripe.md) ("Today so far"), [Shopify Native](shopify-native.md) (Live View proximate).
- **Nexstage recommendation:** yes — underrated operational micro-widget.

### Pattern: 80/20 Pareto chart
- **What:** Visual separation of "top 20% that drive 80%" from the long tail; often marked with star icons on top items.
- **Where we saw it:** [Putler](putler.md) ("Top 20%" widgets).
- **Nexstage recommendation:** maybe — memorable framing for product/customer screens.

### Pattern: 7×24 sales heatmap (day × hour)
- **What:** Grid: rows = day of week, cols = hour of day, cell color = sales density.
- **Where we saw it:** [Putler](putler.md) (re-used across sales/customer/product screens).
- **Nexstage recommendation:** yes — compact, useful for ad scheduling / staffing.

### Pattern: Thumbnail-first creative grid
- **What:** Grid where the creative IS the primary axis; metrics printed below in compact stat strip.
- **Where we saw it:** [Motion](motion.md), [Atria](atria.md), [Triple Whale](triple-whale.md) (Creative Cockpit), [Northbeam](northbeam.md) (Creative Analytics).
- **Nexstage recommendation:** yes — the right default for any creative/ad view. Offer compact-table toggle for power users.

### Pattern: Stat strip under thumbnail
- **What:** 4–6 compact metrics with trend arrows below each creative tile, not a full table row.
- **Where we saw it:** [Motion](motion.md), [Atria](atria.md).
- **Nexstage recommendation:** yes — pairs with thumbnail grid.

### Pattern: Leaderboard with momentum arrows
- **What:** Ranked list with per-row up/down arrows indicating movement vs prior period (not just static rank).
- **Where we saw it:** [Motion](motion.md), [Atria](atria.md) (Radar).
- **Nexstage recommendation:** yes — identifies fatiguing winners early.

### Pattern: Three-column triage layout (Winners / Iteration / Candidates)
- **What:** Creatives pre-bucketed into three columns (green / amber / red) based on a composite score.
- **Where we saw it:** [Atria](atria.md).
- **Nexstage recommendation:** yes — reduces decision paralysis vs flat ranked list.

### Pattern: Letter-grade badge (A/B/C/D)
- **What:** Single-letter composite score with color background on each ad/creative/entity tile.
- **Where we saw it:** [Atria](atria.md).
- **Nexstage recommendation:** maybe — fast to parse but can feel reductive; consider for ad-creative surfaces only.

### Pattern: Red-to-green gradient on table cells
- **What:** Relative cell coloring (red underperforming, green top performing) inside otherwise plain tables.
- **Where we saw it:** [Northbeam](northbeam.md) (Creative Analytics), [Polar](polar-analytics.md), [Peel](peel-insights.md) (cohort heatmaps).
- **Nexstage recommendation:** yes — low-effort "table doubles as viz" pattern.

### Pattern: User journey timeline
- **What:** Per-conversion scrollable row of events (campaign → pageviews → add-to-cart → purchase) with time deltas.
- **Where we saw it:** [MonsterInsights](monsterinsights.md) (User Journey), [Northbeam](northbeam.md) (Orders page per-order journey).
- **Nexstage recommendation:** yes — joinable to order data; narrative shape is powerful.

### Pattern: Live activity feed (chronological event stream)
- **What:** Ticker-style feed of sales/refunds/disputes/failures across all sources with per-row source badge.
- **Where we saw it:** [Putler](putler.md), [Shopify Native](shopify-native.md) (Live View), [Triple Whale](triple-whale.md) (Live Orders).
- **Nexstage recommendation:** yes — visceral "the dashboard is alive" feel for BFCM / launch war rooms.

### Pattern: Geographic globe / map
- **What:** 3D globe or flat map with dots for active visitors/sales; rotatable, click to drill city.
- **Where we saw it:** [Shopify Native](shopify-native.md) (Live View).
- **Where it fails:** Shopify owns the vibe; cloning without real-time pixel access feels lame.
- **Nexstage recommendation:** no — unless anchored to something Shopify can't show (ad spend pacing, attribution disagreement in real time).

### Pattern: Venn diagram for audience overlap
- **What:** Two-or-three-circle Venn showing intersections between segments; click a region → customer list.
- **Where we saw it:** [Peel](peel-insights.md).
- **Nexstage recommendation:** maybe — cheap, high-value for "which customers overlap" question.

### Pattern: Goal progress bar / target line
- **What:** User-set monthly target; widget shows progress toward it. Can lock as chart target line.
- **Where we saw it:** [Putler](putler.md) (Pulse), [Lifetimely](lifetimely.md) (forecast-as-goal lock).
- **Nexstage recommendation:** yes — turns one-off projections into ongoing accountability.

### Pattern: Forecast range (not point estimate)
- **What:** Forecast widget shows a range (hi/lo), not a single number; honest about uncertainty.
- **Where we saw it:** [Putler](putler.md), [Lifetimely](lifetimely.md).
- **Nexstage recommendation:** yes — matches Nexstage's trust thesis.

### Pattern: Status Dot primitive
- **What:** 6–8px colored dot next to every resource — green (ready), amber pulse (building), red (error), grey (queued). Animation differentiates states.
- **Where we saw it:** [Vercel](_inspiration_vercel.md) (Geist), [Linear](_inspiration_linear.md) (workflow state icons).
- **Nexstage recommendation:** yes — use for sync/integration/import status on every row.

### Pattern: Gauge primitive
- **What:** Radial/bar gauge showing progress/usage against a limit; color shifts green → amber → red.
- **Where we saw it:** [Vercel](_inspiration_vercel.md) (Geist).
- **Nexstage recommendation:** maybe — good for plan-limit usage; avoid for revenue metrics.

### Pattern: Entity component (icon + primary + metadata + trailing action)
- **What:** One compact row/card primitive reused for projects, integrations, team members, domains.
- **Where we saw it:** [Vercel](_inspiration_vercel.md) (Geist).
- **Nexstage recommendation:** yes — one primitive, many uses (stores, integrations, campaigns).

### Pattern: Hover card preview
- **What:** Hovering an ID (order/campaign/customer) anywhere shows a rich preview card without navigation.
- **Where we saw it:** [Linear](_inspiration_linear.md).
- **Nexstage recommendation:** yes — especially for order/campaign IDs in tables.

### Pattern: Progress donut / radial
- **What:** Thin-ring donut for project/import/campaign completion; linear bar for milestones.
- **Where we saw it:** [Linear](_inspiration_linear.md).
- **Nexstage recommendation:** yes — for historical-import progress.

### Pattern: Per-brand contribution bar inside aggregated KPI
- **What:** Beneath a "$1.2M total revenue" card, a stacked horizontal bar shows brand A = 42%, B = 35%, etc.
- **Where we saw it:** [Glew](glew.md).
- **Nexstage recommendation:** yes — communicates composition without a separate chart, essential for multi-store mode.

### Pattern: Touchpoints-string column
- **What:** Compact cell string like "Facebook → Google → Direct" representing the most common journey for that row.
- **Where we saw it:** [Northbeam](northbeam.md).
- **Nexstage recommendation:** yes — compact storytelling in a table cell.

### Pattern: MiddleTruncate for long IDs
- **What:** Text utility that truncates in the middle (`abc123...xyz789`) to preserve suffix readability.
- **Where we saw it:** [Vercel](_inspiration_vercel.md).
- **Nexstage recommendation:** yes — for order IDs, UTMs, campaign IDs.

### Pattern: Inline alert strip on home
- **What:** Thin bar at top of Home ("2 unresolved disputes"), actionable and dismissable; disappears when nothing to alert.
- **Where we saw it:** [Stripe](_inspiration_stripe.md).
- **Nexstage recommendation:** yes — for broken integrations, stale data, unmatched orders.

### Pattern: Triage inbox (Linear-style)
- **What:** Focused list of "things that need a human decision" with big keyboard hotkeys.
- **Where we saw it:** [Linear](_inspiration_linear.md).
- **Nexstage recommendation:** yes — Home-page "needs attention" for unmatched orders, broken integrations, missing parsed conventions.

### Pattern: Segment list → detail pane (two-pane)
- **What:** Left = named segments, right = segment detail with charts + customer table.
- **Where we saw it:** [Glew](glew.md), [Putler](putler.md), [Peel](peel-insights.md), [Segments](segments-analytics.md).
- **Nexstage recommendation:** yes — clean IA for any segment-type surface.

### Pattern: Segments-as-tiles landing page
- **What:** Grid of named lifecycle segments, each tile showing count + LTV + AOV + growth %.
- **Where we saw it:** [Segments Analytics](segments-analytics.md).
- **Nexstage recommendation:** yes — no empty-state "build your first segment" wall.

### Pattern: Two complementary views for same data
- **What:** Same data, two lenses (by product + by date for cart abandonment).
- **Where we saw it:** [MonsterInsights](monsterinsights.md).
- **Nexstage recommendation:** yes — low-cost pattern; also applies to "revenue by channel" × "by date."

## Filter & control patterns

### Pattern: Filter-as-chip-sentence
- **What:** Applied filters render as removable pills reading like a sentence: `Country is US × | Source is Google ×`.
- **Where we saw it:** [Plausible](_inspiration_plausible.md), [Linear](_inspiration_linear.md), [Fairing](fairing.md), [Metorik](metorik.md), [Peel](peel-insights.md), [Segments](segments-analytics.md).
- **Where it fails:** [GA4](_inspiration_ga4.md) uses long flat dropdowns — everyone hates it.
- **Nexstage recommendation:** yes — canonical filter pattern; dropdowns only as fallback.

### Pattern: Filter-by-click on any row/cell
- **What:** Clicking any value in a row adds a filter for that value; whole dashboard re-computes.
- **Where we saw it:** [Plausible](_inspiration_plausible.md).
- **Nexstage recommendation:** yes — no separate filter dialog for the 90% case.

### Pattern: Right-click → create segment
- **What:** Right-click a row in any table → "Create segment from selection."
- **Where we saw it:** [GA4](_inspiration_ga4.md).
- **Nexstage recommendation:** yes — fast exploratory workflow; pair with left-click=filter.

### Pattern: Metric toggle above shared chart
- **What:** 6 KPI tiles double as buttons — click one and the hero chart re-renders with that metric as Y-axis.
- **Where we saw it:** [Plausible](_inspiration_plausible.md), [Shopify Native](shopify-native.md) (partial).
- **Nexstage recommendation:** yes — six metrics, one chart, zero chart-picker UI.

### Pattern: Filter operators inline on chip (is/is-not/contains)
- **What:** Default is "is"; affordance inside the chip flips to negation or substring.
- **Where we saw it:** [Plausible](_inspiration_plausible.md).
- **Nexstage recommendation:** yes — no query-builder modal.

### Pattern: Drag-drop dashboard builder
- **What:** Widget grid with drag handles; users add/remove/resize/reorder.
- **Where we saw it:** [Triple Whale](triple-whale.md), [Shopify Native](shopify-native.md), [Lifetimely](lifetimely.md), [Metorik](metorik.md), [Peel](peel-insights.md), [Glew](glew.md).
- **Where it fails:** [Stripe](_inspiration_stripe.md) lesson — almost nobody customises dashboards; opinionated defaults win.
- **Nexstage recommendation:** maybe — ship opinionated defaults first; add drag-drop later if demand.

### Pattern: Saved segment as cross-cutting primitive
- **What:** One segment definition applied across dashboards, reports, exports, emails.
- **Where we saw it:** [Metorik](metorik.md), [Segments](segments-analytics.md), [Peel](peel-insights.md).
- **Nexstage recommendation:** yes — strong mental model; reuse across views.

### Pattern: Segment builder with live count
- **What:** "Matches 1,247 customers" counter updates as filters are added; preview table below.
- **Where we saw it:** [Metorik](metorik.md), [Peel](peel-insights.md), [Segments](segments-analytics.md).
- **Nexstage recommendation:** yes — validates filter logic cheaply.

### Pattern: Natural-language segment builder (FilterGPT)
- **What:** Type a prompt ("first-time customers from May") → AI parses into filter chips → user can edit.
- **Where we saw it:** [Segments Analytics](segments-analytics.md) (FilterGPT), [Peel](peel-insights.md) (Magic Dash adjacent).
- **Where it fails:** Users revert to filter UI for anything non-trivial; chat-only is a trap.
- **Nexstage recommendation:** maybe — good escape hatch IF filter UI exists underneath.

### Pattern: Technique picker (named templates)
- **What:** Instead of one "build your own" chart, a handful of named templates (Funnel / Cohort / Path / Free-form) sharing one canvas.
- **Where we saw it:** [GA4](_inspiration_ga4.md) (Explorations).
- **Nexstage recommendation:** maybe — for a future advanced surface.

### Pattern: Templates library for reports
- **What:** Pre-built report templates avoid the blank-canvas problem.
- **Where we saw it:** [Polar](polar-analytics.md), [Daasity](daasity.md), [Stripe](_inspiration_stripe.md), [Shopify Native](shopify-native.md).
- **Nexstage recommendation:** yes — ship opinionated pre-builts; don't leave users blank.

### Pattern: Export with column picker + drag-to-reorder
- **What:** Toggle columns before export; dragged order matches CSV.
- **Where we saw it:** [Metorik](metorik.md), [Stripe](_inspiration_stripe.md).
- **Nexstage recommendation:** yes — small detail, universally praised.

### Pattern: Column picker = save-as-custom-report
- **What:** "Create custom report" is a button inside the report view (modify pre-built, save).
- **Where we saw it:** [Shopify Native](shopify-native.md).
- **Nexstage recommendation:** yes — start from template beats blank slate.

### Pattern: Step-by-step (not drag-drop) report builder
- **What:** Guided flow: pick metric → dimension → filter → viz.
- **Where we saw it:** [Polar](polar-analytics.md).
- **Nexstage recommendation:** yes — less intimidating than a Looker canvas.

### Pattern: Multi-select bulk actions with sticky footer
- **What:** Shift+Click / X key multi-select; action toolbar appears at bottom when rows selected.
- **Where we saw it:** [Linear](_inspiration_linear.md), [Stripe](_inspiration_stripe.md).
- **Nexstage recommendation:** yes — canonical for tables.

### Pattern: Inline editable properties
- **What:** Click a status/assignee/COGS value inline to edit without navigating.
- **Where we saw it:** [Linear](_inspiration_linear.md), [Glew](glew.md) (inline COGS on products).
- **Nexstage recommendation:** yes — for COGS per product, campaign conventions, etc.

## Date & time patterns

### Pattern: Date range picker with presets + comparison period
- **What:** Preset ranges (7d / 30d / MTD / YTD / Custom) with "Compare to previous period / year / custom" built into the same popover.
- **Where we saw it:** Universal — [Stripe](_inspiration_stripe.md), [Shopify Native](shopify-native.md), [GA4](_inspiration_ga4.md), [Triple Whale](triple-whale.md), [Northbeam](northbeam.md), [Polar](polar-analytics.md), [Lifetimely](lifetimely.md), [Peel](peel-insights.md), [Metorik](metorik.md), [Plausible](_inspiration_plausible.md).
- **Nexstage recommendation:** yes — table-stakes; compare-to inside the same popover (not a separate control).

### Pattern: "Match day of week" comparison option
- **What:** When comparing periods, option to align by weekday for retail seasonality.
- **Where we saw it:** [Plausible](_inspiration_plausible.md).
- **Nexstage recommendation:** yes — small detail; meaningful for ecommerce.

### Pattern: Per-card date range override
- **What:** Global date range is the default; individual cards can override to their own.
- **Where we saw it:** [Shopify Native](shopify-native.md), [Peel](peel-insights.md).
- **Nexstage recommendation:** maybe — powerful but risks confusion. Ship with clear visual indicator when card diverges.

### Pattern: Granularity toggle (day/week/month)
- **What:** Chart re-bins at chosen granularity; default varies (Northbeam suggests weekly).
- **Where we saw it:** [Northbeam](northbeam.md), [Polar](polar-analytics.md), [Peel](peel-insights.md).
- **Nexstage recommendation:** yes — essential for time-series charts.

### Pattern: Compare-to-previous-24h as default frame
- **What:** Default comparison is always yesterday/last-week same-time, not a toggle.
- **Where we saw it:** [ROAS Monster](roasmonster.md).
- **Nexstage recommendation:** yes — operators live in "did something break overnight" mindset.

### Pattern: Dotted line for incomplete periods
- **What:** Rightmost segment of a chart (today / this week-to-date) drawn as dotted line to signal "not complete — don't over-read the dip."
- **Where we saw it:** [Plausible](_inspiration_plausible.md).
- **Nexstage recommendation:** yes — default in every TimeSeries component.

### Pattern: Annotations as chart markers (and AI context)
- **What:** User-entered event annotations appear as markers on charts; AI also uses them as context for insights.
- **Where we saw it:** [Peel](peel-insights.md).
- **Nexstage recommendation:** yes — low effort, high value; doubles as team event log.

### Pattern: Deep-linkable URL state (filters + dates)
- **What:** Every filter/date/comparison combo is in the URL — bookmarkable, shareable without "save view."
- **Where we saw it:** [Plausible](_inspiration_plausible.md), [Linear](_inspiration_linear.md), [Northbeam](northbeam.md).
- **Nexstage recommendation:** yes — critical for agency/team sharing.

### Pattern: Realtime / "current visitors" pill
- **What:** Header-mounted live-updating pill (visitors in last 5 min); click opens 30-min rolling graph.
- **Where we saw it:** [Plausible](_inspiration_plausible.md), [Shopify Native](shopify-native.md), [MonsterInsights](monsterinsights.md), [Putler](putler.md).
- **Nexstage recommendation:** yes — for "live orders in last hour."

### Pattern: Refresh cadence as marketing + UI message
- **What:** "~15-minute refresh" stated explicitly; users know freshness baseline.
- **Where we saw it:** [Triple Whale](triple-whale.md).
- **Where it fails:** [Putler](putler.md) brands "Pulse" as real-time when data is 15-30 min behind — users feel lied to.
- **Nexstage recommendation:** yes — Data Freshness badges required; honesty over marketing.

## Comparison & drill-down patterns

### Pattern: Click KPI card → drill to filtered table
- **What:** Click "Successful payments" → land on filtered Payments table with same date range + status filter applied as chips.
- **Where we saw it:** [Stripe](_inspiration_stripe.md), [Shopify Native](shopify-native.md), [Polar](polar-analytics.md), [Lifetimely](lifetimely.md), [Metorik](metorik.md).
- **Nexstage recommendation:** yes — universal drill pattern; filters always visible as chips.

### Pattern: Click metric → switch chart metric
- **What:** Clicking a KPI tile above the chart re-renders the chart with that metric on Y.
- **Where we saw it:** [Plausible](_inspiration_plausible.md).
- **Nexstage recommendation:** yes — kills the chart-picker UI.

### Pattern: Double-click chart to drill
- **What:** Double-click any chart element to explore deeper.
- **Where we saw it:** [Peel](peel-insights.md).
- **Nexstage recommendation:** yes — fast, discoverable power-user interaction.

### Pattern: Model Comparison table
- **What:** Same rows, columns = different attribution models; surfaces quantitative disagreement.
- **Where we saw it:** [Triple Whale](triple-whale.md), [Northbeam](northbeam.md) (Model Comparison).
- **Where it fails:** Northbeam buries it in a hamburger menu; should be above the fold.
- **Nexstage recommendation:** yes — comparing models IS the differentiator; don't bury.

### Pattern: One-click "open in explorer" from any metric
- **What:** Escape hatch from curated view → explore view preserving filter/date/metric.
- **Where we saw it:** [Northbeam](northbeam.md) (Metrics Explorer).
- **Nexstage recommendation:** yes — power-user escape without cluttering defaults.

### Pattern: Click tile → promote to primary axis (correlation)
- **What:** Clicking a correlation tile promotes that metric to the primary axis; all other tiles re-compute.
- **Where we saw it:** [Northbeam](northbeam.md) (Metrics Explorer).
- **Nexstage recommendation:** maybe — for future correlation surface.

### Pattern: Hover on timing label → breakdown tooltip
- **What:** "Built in 42s" chip expands on hover into per-phase timings.
- **Where we saw it:** [Vercel](_inspiration_vercel.md).
- **Nexstage recommendation:** yes — for sync runs / historical imports.

### Pattern: Full-screen toggle for table/chart
- **What:** One-click full-screen for dense surfaces without losing state.
- **Where we saw it:** [Northbeam](northbeam.md) (NB 3.0).
- **Nexstage recommendation:** yes — for analyst-grade surfaces.

### Pattern: Shareable deep-link preserving state
- **What:** Copy-link embeds filter/sort/model; shared teammate sees the same view.
- **Where we saw it:** [Northbeam](northbeam.md), [Plausible](_inspiration_plausible.md), [Peel](peel-insights.md).
- **Nexstage recommendation:** yes — critical for team workflows.

## Attribution & source-disagreement patterns (most important for Nexstage)

### Pattern: Global attribution-model + window + accounting-mode selector
- **What:** Three global selectors in top chrome — Attribution Model / Window / Accounting Mode — every tile respects them.
- **Where we saw it:** [Northbeam](northbeam.md).
- **Nexstage recommendation:** yes — THE pattern to copy. Enforces consistency without per-tile config.

### Pattern: Attribution model dropdown at table header
- **What:** Switchable attribution model lives on the table itself, not in settings.
- **Where we saw it:** [Triple Whale](triple-whale.md).
- **Nexstage recommendation:** yes — reinforces that multiple models exist; switching models is 1-click.

### Pattern: Side-by-side survey vs pixel (delta column)
- **What:** Two columns for the same channel — what customers say vs what pixel says — with a delta in the middle.
- **Where we saw it:** [Fairing](fairing.md).
- **Nexstage recommendation:** yes — closest existing UI to our six-source MetricCard. Strongest validation of the thesis.

### Pattern: Platform-reported attribution as one dataset among many
- **What:** "What Meta says" is one column/tab, not the ground truth; explicit acknowledgment of disagreement.
- **Where we saw it:** [Polar](polar-analytics.md), [Daasity](daasity.md) (attribution tabs), [Fairing](fairing.md).
- **Nexstage recommendation:** yes — philosophical foundation of the six-source thesis.

### Pattern: First-party pixel as optional ground-truth layer
- **What:** Own-hosted pixel (server-side) provides identity graph + cross-device stitching.
- **Where we saw it:** [Triple Whale](triple-whale.md) (Triple Pixel), [Polar](polar-analytics.md), [Northbeam](northbeam.md).
- **Nexstage recommendation:** maybe — out of MVP scope; revisit post-launch.

### Pattern: Accounting mode toggle (Cash vs Accrual)
- **What:** Explicit toggle between reporting-style (Cash Snapshot) and optimization-style (Accrual Performance).
- **Where we saw it:** [Northbeam](northbeam.md).
- **Nexstage recommendation:** yes — same DNA as our source badges; acknowledges multiple defensible answers.

### Pattern: Post-purchase survey ("How did you hear about us?")
- **What:** One-click Shopify thank-you-page survey feeding attribution model; claims 40-80% response rates.
- **Where we saw it:** [Fairing](fairing.md), [Triple Whale](triple-whale.md) (Total Impact model).
- **Nexstage recommendation:** maybe — powerful zero-party-data layer; not MVP.

### Pattern: "Not Tracked" / "Other" bucket clickable
- **What:** Unattributed revenue is a surfaced, clickable bucket (can show free-text survey answers, unmatched orders, etc.).
- **Where we saw it:** [Fairing](fairing.md) ("Other" bucket).
- **Nexstage recommendation:** yes — foundational for our "Not Tracked" concept; can go negative when platforms over-report.

### Pattern: Correlation analysis for halo effects (Pearson)
- **What:** Correlation coefficient between any two metrics surfaced as a tile with caveats ("correlation ≠ causation").
- **Where we saw it:** [Northbeam](northbeam.md) (Metrics Explorer).
- **Nexstage recommendation:** maybe — statistically grounded way to surface Facebook → Google branded search halo.

### Pattern: Per-order attribution journey view
- **What:** Individual order shows every touchpoint and the credit each received under the current model.
- **Where we saw it:** [Northbeam](northbeam.md) (Orders page).
- **Nexstage recommendation:** yes — accountability surface for spot-checks ("why did this order get attributed to X?").

### Pattern: Pixel-vs-shop delta anomaly surfacing
- **What:** Automatic anomaly surface when platform-reported conversions diverge from store sales.
- **Where we saw it:** [ROAS Monster](roasmonster.md).
- **Nexstage recommendation:** yes — exact Nexstage diagnostic.

### Pattern: Ad spend attributed to products/shops (not ad IDs)
- **What:** Structural inversion — rows are products/shops, campaigns are metadata. ROAS computed using shop revenue for that product in that window.
- **Where we saw it:** [ROAS Monster](roasmonster.md).
- **Nexstage recommendation:** yes — our `RevenueAttributionService` should surface products-as-rows.

### Pattern: Four-level hierarchy (Total → Country → Shop → Product)
- **What:** Consistent navigation spine across every screen.
- **Where we saw it:** [ROAS Monster](roasmonster.md).
- **Nexstage recommendation:** yes — the correct IA for multi-market operators.

### Pattern: Product Pack (one SKU across markets/accounts/shops)
- **What:** Single SKU as the focal entity; matrix of countries × ad accounts × domains × shops with normalized currency.
- **Where we saw it:** [ROAS Monster](roasmonster.md).
- **Nexstage recommendation:** yes — genuinely unique; directly useful for multi-workspace users.

### Pattern: Winners & Losers split vs target
- **What:** "Above target" / "below target" two-column split based on user-defined ROAS/CPO thresholds.
- **Where we saw it:** [ROAS Monster](roasmonster.md).
- **Nexstage recommendation:** yes — performance as qualitative judgment beats raw numbers.

## Segmentation & cohort patterns

### Pattern: RFM with named lifecycle segments
- **What:** Auto-generated segments with human labels — "Champions," "At Risk," "Can't Lose Them," "Hibernating."
- **Where we saw it:** [Peel](peel-insights.md), [Segments Analytics](segments-analytics.md), [Putler](putler.md).
- **Where it fails:** Naming confusion when 9+ segments overlap (Segments).
- **Nexstage recommendation:** yes — emotional/cognitive shortcut beats "recency 4, frequency 5"; keep ≤5 non-overlapping.

### Pattern: RFM as clickable quadrant/grid
- **What:** Recency × Frequency grid with color-coded monetary value; each cell drills to customer list.
- **Where we saw it:** [Peel](peel-insights.md), [Putler](putler.md).
- **Nexstage recommendation:** yes — good visual entry point.

### Pattern: User/session/event segment abstraction
- **What:** Three-tier choice of segment scope — user, session, or event — drives logic.
- **Where we saw it:** [GA4](_inspiration_ga4.md).
- **Nexstage recommendation:** maybe — genuinely load-bearing abstraction for advanced explore; overkill for SMB default.

### Pattern: Segment sequence mode (step 1 before step 2 within N minutes)
- **What:** Ordered step builder with time constraint.
- **Where we saw it:** [GA4](_inspiration_ga4.md).
- **Nexstage recommendation:** maybe — for funnel/path analysis only.

### Pattern: One-click activation (push segment to Klaviyo/Meta/tag)
- **What:** "Sync to Klaviyo / Meta / Shopify tag" button on segment view; not in separate export flow.
- **Where we saw it:** [Segments](segments-analytics.md), [Peel](peel-insights.md) (Audiences), [Metorik](metorik.md) (export only — inferior).
- **Nexstage recommendation:** yes — closes analytics → action loop; strong retention story.

### Pattern: Pre-computed CLV per segment (first-class column)
- **What:** CLV shown as default column on segment tile, not hidden behind a toggle.
- **Where we saw it:** [Segments](segments-analytics.md).
- **Nexstage recommendation:** yes — if LTV is the question, put it in the first column.

### Pattern: Product journey / purchasing sequence
- **What:** Visual flow of what customers buy first → second → third; identifies gateway products.
- **Where we saw it:** [Peel](peel-insights.md) (Purchasing Journey), [Segments](segments-analytics.md) (Pro tier).
- **Nexstage recommendation:** maybe — powerful for retention brands; not MVP.

### Pattern: Market-basket analysis (frequently bought together)
- **What:** Product-pair/triplet table with co-purchase frequency, lift, confidence.
- **Where we saw it:** [Peel](peel-insights.md), [Triple Whale](triple-whale.md) (Product Journeys), [Putler](putler.md).
- **Nexstage recommendation:** maybe — bundle strategy value; not MVP.

### Pattern: Metric → Report → Dashboard hierarchy
- **What:** Three-level object model: metric is primitive, report is saved filter view of metric, dashboard composes reports.
- **Where we saw it:** [Peel](peel-insights.md).
- **Nexstage recommendation:** yes — clear mental model; allow skipping steps for ad-hoc widgets.

## Alerting & activation patterns

### Pattern: Daily/Weekly digest email
- **What:** Scheduled digest (daily, Monday morning, weekly) with key KPIs + deltas delivered to inbox.
- **Where we saw it:** [Varos](varos.md) (Monday Morning Benchmark), [Glew](glew.md) (Daily Snapshot), [Polar](polar-analytics.md), [Lifetimely](lifetimely.md), [Peel](peel-insights.md).
- **Nexstage recommendation:** yes — simplest, highest-ROI retention surface.

### Pattern: Slack digest / scheduled tables
- **What:** Scheduled tables (not charts) delivered to Slack; tables travel better in text channels.
- **Where we saw it:** [Polar](polar-analytics.md), [Varos](varos.md), [Metorik](metorik.md), [Daasity](daasity.md).
- **Nexstage recommendation:** yes — tables preferred over charts for Slack.

### Pattern: Rule-based anomaly alerts
- **What:** Alerts on metric drops/spikes (ROAS, conversion rate, "Not Tracked" delta) via Slack/email.
- **Where we saw it:** [Polar](polar-analytics.md), [Northbeam](northbeam.md) (Lighthouse), [Triple Whale](triple-whale.md), [Daasity](daasity.md).
- **Where it fails:** [GA4](_inspiration_ga4.md) "Insights" callouts that can't be dismissed or deep-linked to drill-down.
- **Nexstage recommendation:** yes — required for "Not Tracked" threshold crossings. Must deep-link to explanatory view.

### Pattern: Proactive AI insight feed
- **What:** AI-generated headline insights refreshing weekly, fed by annotations + data trends.
- **Where we saw it:** [Peel](peel-insights.md) (Magic Insights), [Atria](atria.md) (Raya proactive), [Triple Whale](triple-whale.md) (Lighthouse).
- **Nexstage recommendation:** maybe — differentiator once baseline is shipped; requires care not to generate noise.

### Pattern: Agent-initiated insight (tool → user)
- **What:** AI agent proactively posts new findings / competitor ads / weekly concepts without being prompted.
- **Where we saw it:** [Atria](atria.md) (Raya).
- **Nexstage recommendation:** maybe — UX inversion is the future; start with email + in-app surfacing.

### Pattern: Named CSM in support messaging
- **What:** "Sam from Lifetimely" as concrete contact, not anonymous ticket queue.
- **Where we saw it:** [Lifetimely](lifetimely.md), [Peel](peel-insights.md), [Daasity](daasity.md).
- **Nexstage recommendation:** yes — trust-building; cheap to execute.

### Pattern: Prescriptive "what to fix" card next to metric
- **What:** Not just "here's the number" but "here's what's driving the gap and the next action."
- **Where we saw it:** [Atria](atria.md), [Varos](varos.md) (North Star funnel decomposition).
- **Nexstage recommendation:** yes — diagnostic shape matching Nexstage ambition.

### Pattern: "Goal/target line" on charts
- **What:** Set a target; it appears as a horizontal line on dashboards.
- **Where we saw it:** [Lifetimely](lifetimely.md), [Putler](putler.md), [ROAS Monster](roasmonster.md).
- **Nexstage recommendation:** yes — accountability anchor.

### Pattern: Anonymized peer-data contribution flywheel
- **What:** Freemium brands contribute anonymized data; in exchange get benchmarks.
- **Where we saw it:** [Varos](varos.md), [Triple Whale](triple-whale.md) (Trends Benchmarks).
- **Nexstage recommendation:** yes — the schema should be designed for this from day one.

## Empty-state & loading patterns

### Pattern: Skeleton shimmer loader
- **What:** Content-shaped placeholders with shimmer pulse; chart/card/table each have specific shapes.
- **Where we saw it:** [Stripe](_inspiration_stripe.md), [Vercel](_inspiration_vercel.md), [Linear](_inspiration_linear.md) (near-invisible).
- **Nexstage recommendation:** yes — universally correct over spinners.

### Pattern: Spinner (AVOID)
- **What:** Generic rotating spinner with no content shape.
- **Where we saw it:** [GA4](_inspiration_ga4.md) (everywhere; user #1 pain point).
- **Nexstage recommendation:** no — never use as primary loading state.

### Pattern: Optimistic UI with toast rollback
- **What:** Action reflects instantly in UI; server confirmation in background; errors roll back with toast.
- **Where we saw it:** [Linear](_inspiration_linear.md), [Vercel](_inspiration_vercel.md).
- **Nexstage recommendation:** yes — for user-initiated state changes (COGS edits, annotation adds).

### Pattern: Undo toast (6-10s window)
- **What:** Every destructive action gets a toast with undo window instead of a modal confirmation.
- **Where we saw it:** [Linear](_inspiration_linear.md).
- **Nexstage recommendation:** yes — better than confirmation modals.

### Pattern: SWR revalidation (cache-first)
- **What:** Content renders from local cache instantly, then revalidates; tiny ring spinner in corner of revalidating region.
- **Where we saw it:** [Vercel](_inspiration_vercel.md), [Linear](_inspiration_linear.md).
- **Nexstage recommendation:** yes — Inertia partial reloads align with this.

### Pattern: Copy-paste-friendly empty state (CLI command)
- **What:** Empty state shows the exact CLI command in a monospace block with copy icon.
- **Where we saw it:** [Vercel](_inspiration_vercel.md).
- **Where it fails:** Our users are not devs.
- **Nexstage recommendation:** no — use UI action references, not commands.

### Pattern: Sample-data empty state
- **What:** Pre-first-sync, show realistic sample data with a visible "this is sample data" tag.
- **Where we saw it (absent):** [Segments](segments-analytics.md) ("48-hour processing" is exactly the gap this solves).
- **Nexstage recommendation:** yes — avoid the "demo momentum death" during backfill.

### Pattern: Empty state with single-sentence + single-action
- **What:** "No visitors yet. Your snippet might not be installed — [verify installation]."
- **Where we saw it:** [Plausible](_inspiration_plausible.md), [Stripe](_inspiration_stripe.md), [Linear](_inspiration_linear.md) (monochrome illustrations).
- **Nexstage recommendation:** yes — one sentence, one action; skip illustrations if not part of brand.

### Pattern: Text-only "no results match filters"
- **What:** When filters are applied and empty, text-only state: "No results — clear filters."
- **Where we saw it:** [Stripe](_inspiration_stripe.md).
- **Nexstage recommendation:** yes — filter-induced emptiness needs different state than never-populated.

### Pattern: Browser favicon + tab title as status channel
- **What:** Favicon animates + tab title gets prefix (▶ building, ✓ ready) for long-running tasks.
- **Where we saw it:** [Vercel](_inspiration_vercel.md).
- **Nexstage recommendation:** yes — for historical imports, report generation.

## Aesthetic choices (typography, color, density)

### Pattern: Neutral-dominant palette with accent for action
- **What:** Off-white backgrounds, near-black text, single brand accent used sparingly for CTAs/brand chrome only.
- **Where we saw it:** [Stripe](_inspiration_stripe.md), [Linear](_inspiration_linear.md), [Plausible](_inspiration_plausible.md), [Vercel](_inspiration_vercel.md).
- **Nexstage recommendation:** yes — neutral base + gold "Real" accent + per-source badge colors for data.

### Pattern: Tabular / monospace numbers
- **What:** Amounts in tables line up vertically; monospace for IDs/UTMs/SHAs.
- **Where we saw it:** [Stripe](_inspiration_stripe.md), [Vercel](_inspiration_vercel.md), [Linear](_inspiration_linear.md).
- **Where it fails:** [GA4](_inspiration_ga4.md) doesn't use tabular nums — visible weakness.
- **Nexstage recommendation:** yes — mandatory; consider Geist Mono for IDs/amounts.

### Pattern: LCH color space for perceptually uniform scales
- **What:** Colors at same L value look equally light; yellow and red at matching lightness are actually matched.
- **Where we saw it:** [Linear](_inspiration_linear.md).
- **Nexstage recommendation:** yes — meaningful for multi-series attribution charts.

### Pattern: Small size hierarchy (dense)
- **What:** KPI numbers ~28-32px (not 48+), body ~14px, table ~13px. Density wins over drama.
- **Where we saw it:** [Stripe](_inspiration_stripe.md), [Linear](_inspiration_linear.md), [Vercel](_inspiration_vercel.md).
- **Nexstage recommendation:** yes — information density matters; avoid marketing-sized KPIs.

### Pattern: Thin-stroke minimal data viz
- **What:** Thin lines, no gridlines, sparse axis labels, soft fill gradient, tooltips not legends.
- **Where we saw it:** [Stripe](_inspiration_stripe.md), [Plausible](_inspiration_plausible.md), [Linear](_inspiration_linear.md), [Vercel](_inspiration_vercel.md).
- **Where it fails:** Pure monochrome breaks down when showing multi-series source comparison.
- **Nexstage recommendation:** yes for chrome; maybe for series — we need per-source color differentiation.

### Pattern: Favicon as status channel
- **What:** Favicon shape/color reflects app state for one-glance tab awareness.
- **Where we saw it:** [Vercel](_inspiration_vercel.md).
- **Nexstage recommendation:** yes — for active imports / anomaly states.

### Pattern: Light mode as default
- **What:** Ship both modes but default to light; finance/marketing users skew high-luminance.
- **Where we saw it (lesson):** [Vercel](_inspiration_vercel.md) (dark-mode-first — wrong for our audience).
- **Nexstage recommendation:** yes — light default, dark available.

### Pattern: Test-mode global tint (orange chrome)
- **What:** Toggle tints entire chrome orange when in test/sandbox mode.
- **Where we saw it:** [Stripe](_inspiration_stripe.md).
- **Nexstage recommendation:** no — doesn't apply; we have no live/test duality.

### Pattern: Monochrome geometric empty-state illustrations
- **What:** Line illustrations that blend with UI rather than marketing cartoons.
- **Where we saw it:** [Linear](_inspiration_linear.md).
- **Nexstage recommendation:** maybe — cheaper than cartoons; less brand personality.

### Pattern: Isometric floating cards on marketing (lie vs product)
- **What:** Marketing hero shows isometric 3D cards; actual product doesn't look like that.
- **Where we saw it (bad):** [Metorik](metorik.md).
- **Nexstage recommendation:** no — never mismatch marketing and product visuals.

## Anti-patterns to explicitly avoid

### Anti-pattern: Paywall wall (~80% of app gated)
- **What:** Free tier crippled; most features show upgrade walls; user resentment proportional to locked-icon ratio.
- **Who does it:** [Triple Whale](triple-whale.md) (~80% paywalled), [MonsterInsights](monsterinsights.md) ("Lite is practically useless"), [Shopify Native](shopify-native.md) (custom reports gated at Advanced).
- **Why it fails:** Churn accelerant; every paywall is a signal the free product isn't real.
- **Nexstage note:** Gate features, never baseline metrics.

### Anti-pattern: "Contact sales" as the only path
- **What:** No public pricing; demo-required for trial; every tier opaque.
- **Who does it:** [Glew](glew.md), [Polar](polar-analytics.md) (partial), [Peel](peel-insights.md) (enterprise), [Motion](motion.md) (above Starter), [ROAS Monster](roasmonster.md) (everything), [Hyros](README.md).
- **Why it fails:** SMB evaluators bounce; demo-gating a trial destroys self-serve conversion.
- **Nexstage note:** Publish the full ladder. Zero demo-gate.

### Anti-pattern: DNS-change requirement during onboarding
- **What:** Onboarding blocks on a DNS record update for tracking.
- **Who does it:** [Northbeam](northbeam.md).
- **Why it fails:** Non-trivial for non-technical founders; dead-on-arrival for SMB trial.
- **Nexstage note:** Pixel-only install is hard requirement.

### Anti-pattern: Multi-phase / weeks-to-months onboarding
- **What:** 10-phase Day 1 / 30 / 90 ramp-up plans.
- **Who does it:** [Northbeam](northbeam.md) (G2 reviewer: "hadn't been onboarded properly even after a month"), [Daasity](daasity.md).
- **Why it fails:** SMB time-to-first-value must be minutes, not weeks.
- **Nexstage note:** <24h to a useful dashboard.

### Anti-pattern: Sampling without user warning
- **What:** High-cardinality reports silently sampled; users don't know which rows are estimates vs exact.
- **Who does it:** [GA4](_inspiration_ga4.md).
- **Why it fails:** Trust collapse when reconciling with other sources.
- **Nexstage note:** If we ever sample, method and scope must be visible.

### Anti-pattern: 24h data latency with no stale-data badge
- **What:** Today's metric shown as complete when it's actually 40% of today; no in-product warning.
- **Who does it:** [GA4](_inspiration_ga4.md).
- **Why it fails:** Fresh data quietly lies.
- **Nexstage note:** Data Freshness badge must be loud when data is stale.

### Anti-pattern: Dropdown-only filters
- **What:** Long flat dropdowns with type-ahead that only helps if you know the name.
- **Who does it:** [GA4](_inspiration_ga4.md).
- **Why it fails:** Inline chips / command palette beat this every time.
- **Nexstage note:** Chips primary; dropdowns only as fallback.

### Anti-pattern: Real-time branding on delayed data
- **What:** "Pulse" / "live" / "real-time" labeling when data lags 15-30+ minutes.
- **Who does it:** [Putler](putler.md).
- **Why it fails:** Users feel lied to on the first reconciliation attempt.
- **Nexstage note:** Honest refresh cadence as UI + marketing message.

### Anti-pattern: Auto-installing sister plugins / opt-out bloatware
- **What:** Cross-promo plugins activated sitewide without asking.
- **Who does it:** [MonsterInsights](monsterinsights.md).
- **Why it fails:** #1 reason for 1-star WP reviews.
- **Nexstage note:** Never ship cross-promos that auto-activate.

### Anti-pattern: Upsell nag banner inside paid tier
- **What:** Paying users still see "upgrade to X" banners.
- **Who does it:** [MonsterInsights](monsterinsights.md).
- **Why it fails:** Signals contempt for existing customers.
- **Nexstage note:** Paying users never see upsells to higher tiers / sister products.

### Anti-pattern: Permanent "50% off promotional pricing"
- **What:** Fake urgency pricing shown as always-discounted.
- **Who does it:** [MonsterInsights](monsterinsights.md).
- **Why it fails:** Transparent dishonesty; corrodes trust.
- **Nexstage note:** Price at the real number or run time-bounded sales honestly.

### Anti-pattern: Single "Real" number that hides disagreement
- **What:** Collapses platform-vs-store conflict into one reconciled number without showing the source breakdown.
- **Who does it:** [ROAS Monster](roasmonster.md).
- **Why it fails:** The disagreement IS the information; hiding it leaves value on the table.
- **Nexstage note:** Opposite of our thesis — keep source badges on every metric.

### Anti-pattern: Attribution model hidden in settings
- **What:** Model selector buried behind gear/hamburger; users don't know they're viewing one lens.
- **Who does it:** [Northbeam](northbeam.md) (Model Comparison in hamburger menu).
- **Why it fails:** Switching models should be 1-click — it's the differentiator.
- **Nexstage note:** Model selector lives on every attribution surface, above the fold.

### Anti-pattern: 25-30 day model calibration before usable
- **What:** Attribution model needs a month of warm-up to be trustworthy.
- **Who does it:** [Northbeam](northbeam.md) (Clicks+Modeled Views).
- **Why it fails:** Dead-on-arrival for trial users.
- **Nexstage note:** Attribution must work from day one; don't ship anything that needs 30d warmup.

### Anti-pattern: Pageview-based pricing
- **What:** Price scales with site traffic regardless of ad spend / value derived.
- **Who does it:** [Northbeam](northbeam.md).
- **Why it fails:** Decouples cost from value; content-heavy brands pay more than pure-paid with same revenue.
- **Nexstage note:** Price on workspaces/stores + ad spend, not pageviews.

### Anti-pattern: Paywall moves popular reports to "Custom" tier
- **What:** Once-free reports quietly migrated to higher tier, forcing upgrade.
- **Who does it:** [Shopify Native](shopify-native.md) (profit reports gated at Advanced).
- **Why it fails:** Existing-user resentment.
- **Nexstage note:** Don't downgrade existing functionality to justify pricing changes.

### Anti-pattern: Nine-way persona segmentation at launch
- **What:** Department-cut dashboards (Marketing / Ops / Finance / Retail / etc) targeting enterprise sales.
- **Who does it:** [Glew](glew.md).
- **Why it fails:** Enterprise sales theater; overwhelming for SMB with one marketer.
- **Nexstage note:** ≤3 personas at launch.

### Anti-pattern: Hero screenshots that are pure Looker / BI canvas
- **What:** Marketing pages show Looker explore canvas — terrifies non-analyst merchants.
- **Who does it:** [Glew](glew.md).
- **Why it fails:** Merchants bounce; "this isn't for me."
- **Nexstage note:** Show product screens, not BI tool screens.

### Anti-pattern: Credits-based pricing with monthly burn
- **What:** N credits/mo, don't roll over, punish spiky usage.
- **Who does it:** [Atria](atria.md).
- **Why it fails:** Creates "use it or lose it" anxiety.
- **Nexstage note:** Rollover or unlimited with soft fair-use caps.

### Anti-pattern: Auto-upgrade-during-trial billing
- **What:** Trial users auto-upgraded and charged without explicit confirmation.
- **Who does it:** [Atria](atria.md) (Trustpilot reports).
- **Why it fails:** Pre-launch trust tax.
- **Nexstage note:** No auto-upgrade from trial; explicit confirmation.

### Anti-pattern: Opaque AI credit math
- **What:** "4,000 credits" means nothing until user hits the wall.
- **Who does it:** [Atria](atria.md).
- **Why it fails:** Users can't plan consumption.
- **Nexstage note:** If any feature is metered, show consumption preview before the action.

### Anti-pattern: Pricing volatility mid-relationship
- **What:** Customer moved from $49 to $680/mo (347% hike) between renewals.
- **Who does it:** [Fairing](fairing.md).
- **Why it fails:** Tanks NPS; review footprint never recovers.
- **Nexstage note:** Publish the ladder; pricing changes grandfathered or loudly communicated.

### Anti-pattern: Data-sync caps as pricing lever
- **What:** "50k syncs on Core, 150k on Pro, unlimited on Enterprise" — penalizes growth.
- **Who does it:** [Segments](segments-analytics.md).
- **Why it fails:** Customers feel penalized for the very behavior that makes the product valuable.
- **Nexstage note:** Don't cap the value metric.

### Anti-pattern: 48-hour initial processing
- **What:** Demo day underwhelming because backfill takes 2 days.
- **Who does it:** [Segments](segments-analytics.md).
- **Why it fails:** Momentum death at exactly the conversion moment.
- **Nexstage note:** Sample/demo data during backfill; never show empty dashboard.

### Anti-pattern: Account/property/view switcher in cramped top dropdown
- **What:** Multi-tenant switching crammed into a generic top-bar dropdown.
- **Who does it:** [GA4](_inspiration_ga4.md).
- **Why it fails:** 10 years of support tickets.
- **Nexstage note:** Workspace switcher is its own deliberate component.

### Anti-pattern: Inconsistent chrome across sections
- **What:** Reports / Explore / Advertising each use different styling languages.
- **Who does it:** [GA4](_inspiration_ga4.md).
- **Why it fails:** Users feel lost; three products in one.
- **Nexstage note:** Ship one app shell.

### Anti-pattern: "Insights" ML callouts that can't be dismissed or acted on
- **What:** Auto-generated "Revenue dropped 12%" chips with no link to drill-down explanation.
- **Who does it:** [GA4](_inspiration_ga4.md).
- **Why it fails:** Anxiety without answers.
- **Nexstage note:** Every anomaly callout deep-links to explanation.

### Anti-pattern: Thresholding that silently zeroes small cohorts
- **What:** Privacy thresholding hides rows below count with no indication.
- **Who does it:** [GA4](_inspiration_ga4.md).
- **Why it fails:** Users think data is missing.
- **Nexstage note:** Disclose or don't hide.

### Anti-pattern: Keyboard-only affordances
- **What:** Features only accessible via shortcuts; discoverability zero for non-power users.
- **Who does it:** [Linear](_inspiration_linear.md) (partial).
- **Why it fails:** Excludes casual users.
- **Nexstage note:** Keyboard parity + visible buttons, not keyboard-only.

### Anti-pattern: Dark-mode-first for non-technical audience
- **What:** Default dark; light as afterthought.
- **Who does it:** [Vercel](_inspiration_vercel.md).
- **Why it fails:** Finance/marketing users skew light-mode.
- **Nexstage note:** Light default, dark available.

### Anti-pattern: Single-source black-box (no reconciliation)
- **What:** Trust one data source absolutely; no cross-check against other sources.
- **Who does it:** [Metorik](metorik.md) (trusts store DB), [MonsterInsights](monsterinsights.md) (just GA4), [Peel](peel-insights.md) (platform attribution pass-through), [Lifetimely](lifetimely.md) (attribution pass-through).
- **Why it fails:** Users think they're getting unified view; they're getting one source in a different wrapper.
- **Nexstage note:** Our wedge — never trust one source; always show disagreement.

### Anti-pattern: Drag-drop dashboard customization users don't use
- **What:** Ships drag handles on everything; almost nobody customizes.
- **Who does it:** [Stripe](_inspiration_stripe.md) (their own lesson).
- **Why it fails:** Engineering cost without product value.
- **Nexstage note:** Opinionated defaults win; skip the drag handles.

### Anti-pattern: Channel totals summing to >100%
- **What:** Meta, Google, TikTok each show "full credit" for same conversion; sums exceed actual order count.
- **Who does it:** [Triple Whale](triple-whale.md) (by design).
- **Why it fails:** Users can't explain discrepancy to their boss.
- **Nexstage note:** Our "Not Tracked" value addresses this — show the over-report as negative.

### Anti-pattern: Hover-only row actions on touch
- **What:** Row actions hidden until hover; tablet users can't find them.
- **Who does it:** [Stripe](_inspiration_stripe.md).
- **Why it fails:** Touch-device discoverability zero.
- **Nexstage note:** Always-visible kebab menu as fallback.

### Anti-pattern: Cancellation friction
- **What:** Hoops/hurdles to cancel account ("I emailed three times").
- **Who does it:** [Putler](putler.md), [Triple Whale](triple-whale.md) (reported unauthorized charges).
- **Why it fails:** Outsized reputational damage to retention preserved.
- **Nexstage note:** Self-serve cancellation in-product.

### Anti-pattern: Buggy mobile web-view wrapper app
- **What:** "Native" mobile app is slower/worse than mobile web.
- **Who does it:** [Triple Whale](triple-whale.md).
- **Why it fails:** Damages brand trust across desktop too.
- **Nexstage note:** If we ship mobile, it must be better than mobile web, not worse.

### Anti-pattern: English only for multi-country product
- **What:** Tool markets as multi-country/multi-market; app only in English.
- **Who does it:** [ROAS Monster](roasmonster.md).
- **Why it fails:** Contradiction with positioning.
- **Nexstage note:** If we pitch EU/multi-country, i18n is table-stakes long-term.

### Anti-pattern: Over-indexed AI chat replacing good surfaces
- **What:** Chat is the primary path for common questions.
- **Who does it:** [Atria](atria.md) (partial), [Segments](segments-analytics.md) (FilterGPT as only path).
- **Why it fails:** Users prefer purpose-built pages; chat is for long-tail.
- **Nexstage note:** AI chat is escape hatch, not replacement for curated surfaces.
