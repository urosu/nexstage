# Ads

Route: `/ads`

## Purpose

One place to rank campaigns, adsets, ads, and creatives across Facebook and Google — with platform-reported numbers sitting next to store-reconciled numbers, so "what the platform claims" and "what actually landed in the till" are always side-by-side.

## User questions this page answers

- Which campaigns / adsets / ads / creatives are actually working — by Real ROAS, not just Purchase ROAS?
- Where do Facebook and Google over-report vs Store orders, and by how much?
- Which creatives should I scale, iterate, or kill this week?
- When (day × hour) should I schedule budget for best cost per purchase?
- Which countries / platforms / devices are burning spend I can't trace to an order?
- Is a campaign's ROAS number built on deterministic conversions or modeled views, and does the sample size hold up?

## Data sources

| Source (UX §4 color) | Required? | Provenance | Freshness |
|---|---|---|---|
| Facebook (indigo-500) | Yes | `ad_insights` + `campaigns` joined to `attribution_*` on orders | ~15 min (Insights API v21.0+; see RESEARCH) |
| Google Ads (amber-500) | Yes | `ad_insights` (Google), `gclid` on orders | ~30 min (GAQL daily + intraday) |
| Store (slate-500) | Yes | `orders` (authoritative denominator for Real ROAS) | <2 min (webhook) |
| Site (violet-500) | No | First-party session pixel (future) | n/a in v1 |
| Real (yellow-400) | Yes — computed | `RevenueAttributionService` output joined to spend | Derivative of above |
| GSC (emerald-500) | No | Not shown on this page (see `/seo`) | n/a |

Country spend uses `COALESCE(campaigns.parsed_convention->>'country', stores.primary_country_code, 'UNKNOWN')` — there is no country column on `ad_insights`.

## Above the fold (1440×900)

- AlertBanner (warning) — only if a platform token is stale or Modeled Views still calibrating. Hidden on healthy accounts.
- FilterChipSentence — pinned under TopBar: `Showing ads from Last 30d where Platform is Facebook, Google and Status is Active`
- KpiGrid (6 cols)
  - MetricCard "Total Ad Spend" · sources=[Facebook, Google, Real] — dictionary "Our pick"
  - MetricCard "Blended ROAS (7d click)" · sources=[Facebook, Google, Real] — headline gold-lightbulb on Real. Label flips to `Purchase ROAS · Facebook` when Facebook badge is active (platform-native metric name — prevents apples/pears comparison)
  - MetricCard "CAC (1st Time)" · sources=[Facebook, Google, Real]
  - MetricCard "Purchases vs Orders" · sources=[Facebook, Google, Store] — source-conditional label flip per metric dictionary §Ads "Purchases (platform-attributed) vs Orders (store-recorded) — always distinct, never averaged": when Store badge is active, label = `Orders`; when Facebook or Google badge active, label = `Purchases`
  - MetricCard "Not Tracked" · sources=[computed] — signed; negative = platforms over-report
  - MetricCard "CTR" · sources=[Facebook, Google] — `MetricCardCompact` variant, link-CTR by default
- ViewToggle (§5.32) three options: **Table** · **Creative Gallery** · **Triage**
- Toolbar row (right-aligned, above the ViewToggle content): `SavedView` chips (§5.19) · column picker · `ShareSnapshotButton` (§5.29) · `ExportMenu` (§5.30 — CSV includes six-source columns)
- Default view — **Table**:
  - BreakdownSelector bound to this page: `Platform · Campaign · Ad set · Ad · Country · Device` (Product / Customer segment hidden here)
  - HierarchyPicker (page-local segmented control): `Platform → Campaign → Ad set → Ad`. Default: Campaign.
  - DataTable (sticky header, sticky first column)
    - Columns (default column set, saveable via SavedView §5.19): `Name` · `SourceBadge` (platform) · `Status` · `Spend` · `Impressions` · `CTR` · `CPC` · `Purchases · FB/GAds` · `Orders · Store` · `Revenue · Real` · `ROAS (7d) · Real` · `Purchase ROAS · FB` / `Conv. Value / Cost · GAds` (source-specific twin column) · `CAC (1st Time)` · `Touchpoints` (Northbeam-style compact string `Facebook → Google → Direct`)
    - Red-to-green gradient on ROAS and CAC cells (Northbeam pattern)
    - Row expand chevron drills one level inline (Campaign → Ad sets inline without navigation)
    - SignalTypeBadge (§5.28) on any ROAS cell computed from Meta modeled conversions
    - ConfidenceChip (§5.27) below ROAS/CAC when a row is below workspace threshold
    - Row click → DrawerSidePanel: AdDetail (see below-the-fold)

## Below the fold

- LineChart "Spend vs Real Revenue over time" · multi-source overlay (Facebook-indigo / Google-amber / Real-yellow) · GranularitySelector (default Daily for ≤14d ranges)
  - TargetLine (§5.23) dashed at workspace ROAS target, labeled at right edge
  - ChartAnnotationLayer (§5.6.1) shows token-expiry events, budget changes, workspace annotations
  - Dotted tail on the rightmost incomplete period ("Partial — range closes in 6h")
- QuadrantChart (§5.6) "Ad Efficiency Map" — X: CAC Index (1–100, account history relative) · Y: ROAS Index · color: Platform · size: Spend
  - Quick-filter quadrant buttons above chart (Northbeam pattern): `Only Winners · Only Candidates · Fatiguing`
  - Right-click a dot → ContextMenu "Open in Attribution" deep-links to `/attribution?campaign=…`
- `DaypartHeatmap` (§5.6) "Cost per Purchase by Day × Hour" — rows = day of week, cols = hour-of-day, cell shade = CPA. Hover shows Spend / Purchases / Revenue for that cell. Click a cell → DataTable filters to that day×hour and re-ranks.
- BreakdownView — Country × Platform contribution grid (only when BreakdownSelector = Country). Flat BreakdownRow[] pre-joined server-side; `cardData` display hint surfaces top-3 country stacks inside KpiGrid cards above.

### Creative Gallery view (ViewToggle = Creative Gallery)

Replaces the DataTable above-the-fold when active. Mobile-usable: grid collapses from 4-col to 2-col at `md`.

- Toolbar: Platform filter (Facebook / Google / Both) · Ad status (Active / Paused / All) · Sort (Spend desc default · ROAS desc · Thumb-Stop · Hold Rate) · "Hide inactive" toggle
- Grid of creative cards (~280×320, Motion pattern):
  - Thumbnail (hover autoplays video muted; click opens full-screen in DrawerSidePanel with ad detail)
  - `LetterGradeBadge` (UX §5.33) `A/B/C/D` top-right — composite percentile of Real ROAS within workspace over active window. Greyed if `ConfidenceChip` threshold unmet.
  - `StatStripe` (UX §5.34) under thumbnail: `Spend · CTR · Thumb-Stop · Hold Rate · ROAS (Real) · CAC`
  - SourceBadge strip at card foot — Facebook/Google filled based on origin
  - "Compare" checkbox (max 6) → opens side-by-side compare drawer with overlaid LineCharts (Northbeam creative compare)

### Triage view (ViewToggle = Triage)

Three-column layout (Atria pattern). Each column is a ranked creative list with the same card primitive as Creative Gallery, minus the compare checkbox.

- **Winners** (green header): grade = A, ROAS (Real) above target, CAC stable or dropping
- **Iteration Potential** (amber): grade = B and ROAS trend down >15% WoW OR high CTR + low CVR (fatigue pattern)
- **Candidates** (rose): grade = C/D, below-target ROAS, burned >$250 with <3 purchases. Prescriptive one-liner per card: *"Spend $420 · 2 purchases · CAC 2.1× target — consider pausing"* (Atria pattern; Wicked's Scale / Chill / Kill framing).

Each column header shows count + aggregate Spend in that bucket.

### AdDetail DrawerSidePanel (row click)

- Entity header: MiddleTruncate ad ID · thumbnail · platform SourceBadge · status chip · EntityHoverCard on parent campaign/adset
- KpiGrid (2 cols, `MetricCardCompact`): Spend · Purchases · Revenue · ROAS (Real) · CAC · Thumb-Stop
- LineChart "Performance over time" · source overlay
- Touchpoints-string summary: top 3 customer journeys that closed through this ad
- **Source disagreement row** (thin, two-line): `Facebook reports 42 purchases · Store shows 38 orders · Δ = +4 (+10.5% over-report)` with a "See in Attribution" link deep-linking to `/attribution?ad=…`
- Quick actions: `Copy link · Open in Attribution · Add annotation · Pin to dashboard`

### Naming-convention parser strip (above the Table view, collapsed by default)

When a workspace has `campaigns.parsed_convention` populated (via `CampaignConventionParser`), a thin inline strip offers pivot chips by convention token: `Country · Audience · Offer · Angle · Creative Iteration`. Clicking a chip becomes the active BreakdownSelector value and rows re-aggregate under that token. Campaigns with unparsed names render an AlertBanner (info) with a "Add convention" CTA that jumps to Settings → Campaigns. Pattern source: [Motion](../competitors/motion.md) naming-convention decoding and [Atria](../competitors/atria.md) tag-filter pipeline — tags become the primary slice axis, not metadata.

## Interactions specific to this page

- **ViewToggle (Table / Creative Gallery / Triage)** is URL-stateful: `?view=triage`. Different default column sets saved per view.
- **HierarchyPicker** persists independently in URL (`?level=adset`). Affects table + QuadrantChart + heatmap simultaneously.
- **AccountingModeSelector is load-bearing here.** Cash Snapshot pins revenue to order date (matches Shopify P&L); Accrual Performance attributes revenue back to the click date. Switching modes recomputes ROAS on every row + card with a 200ms transition and a brief `Recomputing…` banner (§7.0.1).
- **ProfitMode flip:** Revenue → Profit, ROAS → Profit ROAS, CAC → Profit-CAC across every card, chart, and table column. Missing COGS degrades card to amber StatusDot + "Add COGS to see profit" CTA — we do not estimate (§7 cost inputs).
- **Clicking a Facebook source badge on a MetricCard** swaps that card to platform-reported view, header auto-relabels `ROAS · Facebook` → `Purchase ROAS · Facebook` (Meta-specific naming from metric dictionary) so users aren't comparing apples and pears.
- **Heatmap cell click** adds a day-of-week + hour filter chip to FilterChipSentence; table and QuadrantChart re-rank within that window.
- **Creative "Compare"** (Gallery view) supports up to 6 ads simultaneously; button stays disabled past the cap with a tooltip.
- **Right-click any campaign/adset/ad row** → ContextMenu includes "Open in Attribution" (deep-links with filters) and "Create segment from this" (v2 stub).
- **Saved views** (§5.19): canonical presets seeded per workspace — `Prospecting · Retargeting · Brand Search · Non-Brand Search · Broken Campaigns (no parsed_convention)`.

## Competitor references

- [Northbeam — Sales Page](../competitors/_teardown_northbeam.md#screen-sales-page-the-power-user-page) — global Attribution Model / Window / Accounting-Mode selector propagating to every row; customizable column sets with save-as-chip; per-row expand inline; red-to-green gradient on ROAS cells. The power-user table pattern we are directly cloning.
- [Northbeam — Creative Analytics](../competitors/_teardown_northbeam.md#screen-creative-analytics) — creative grid with red/green metric cells, 6-ad compare, modeled-views calibration banner.
- [Triple Whale — Creative Cockpit](../competitors/_teardown_triple-whale.md#screen-creative-cockpit--creative-analysis) — Creative Highlights trophy strip, Thumb-Stop Ratio, hover-to-play thumbnails, group-by Naming Convention.
- [Motion](../competitors/motion.md) — thumbnail-first grid (the creative IS the primary axis), StatStripe, leaderboards with momentum arrows. Mobile-usable density target (~12–24 cards per viewport).
- [Atria](../competitors/atria.md) — three-column Winners / Iteration Potential / Candidates triage; A/B/C/D letter-grade badges; prescriptive "here's what to fix" card.
- [RoasMonster](../competitors/roasmonster.md) — attributing ad spend to products and shops (not ad IDs); Winners & Losers vs target; anomaly surfacing via pixel-vs-shop delta. We go further by showing the disagreement instead of collapsing it.
- [Putler](../competitors/putler.md) — 7×24 sales heatmap (day × hour) re-used here for ad-schedule insight.
- [Wicked Reports](../competitors/wicked-reports.md) — Scale / Chill / Kill three-state recommendations feeding Triage column taxonomy.
- [Elevar](../competitors/elevar.md) — per-destination accuracy chips inform the SignalTypeBadge behaviour on modeled conversions.
- [RockerBox — Cross-Channel Attribution Report](../competitors/rockerbox.md#cross-channel-attribution-report-mta) — de-duplicated vs platform-reported toggle; the philosophical basis for our twin `Purchases · Platform` / `Orders · Store` table columns.

## Mobile tier

**Mobile-usable** (md and up, ≥768px). At `<md`: KpiGrid collapses 6→3, DataTable reduces to card-stack (card per campaign with top 4 metrics), Creative Gallery collapses to 2-col, Triage stacks columns vertically with accordion collapse. QuadrantChart and 7×24 HeatmapChart render a "View on desktop" banner.

## Out of scope v1

- **TikTok, Pinterest, Snap, Microsoft/Bing connectors** — schema-ready, not wired. Platform dropdown shows greyed with "Coming in v2".
- **Frame-by-frame video retention overlay** (Motion signature) — reserved for v2.
- **AI auto-tagging of creative attributes** — deferred; parsed `campaigns.parsed_convention` covers naming-convention pivots.
- **Budget editing / campaign launch back to Meta or Google** — Nexstage is read-only per thesis; Apex-style closed-loop bidding deferred.
- **Customer-level journey inside an ad's detail drawer** — link out to `/attribution` Customer Journey timeline instead of duplicating.
- **MMM / incrementality testing** — RockerBox's wedge, not ours at v1.
- **Post-purchase survey data layered onto ads** — Fairing-style survey source deferred (see RESEARCH).
- **Benchmarking creative ROAS against peer cohort** — Varos-style peer bands arrive with benchmarks in v2.
