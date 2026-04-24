# SEO

Route: `/seo`

## Purpose

Show how the store is ranking on Google — queries, pages, countries, devices, appearance — using Google Search Console as the single source of truth, and acknowledge GSC's 48–72h data lag as first-class UI.

The page is deliberately NOT an SEO tool. We do not rewrite meta tags, audit technical SEO, suggest keywords, or score domain authority. We show what GSC shows, well, with the pre/post-algorithm-update story and an honest freshness badge. Users who want more leave to Ahrefs/SEMrush; users who want less have Shopify's weak referrer surface. We sit in the middle: read-only, honest, GSC-first.

## User questions this page answers

- Which queries are bringing impressions and clicks this month, and what changed vs last month?
- Which landing pages are winning (or losing) position?
- Where is the CTR gap between high-impression queries and actual clicks (i.e., where are we ranking but failing to earn the click)?
- How is organic performance splitting by country and device?
- Which search appearances (Featured Snippet, FAQ, Site Links) are we surfacing in?
- Is today's organic number trustworthy, or am I still inside the 2-day GSC delay?
- Did last week's site migration / schema deploy / Google core update correlate with a position change?
- Which queries are "opportunity" queries — high impressions, low CTR, positions 5-15 — where a title/meta tweak could move the needle?

## Data sources

| Source (UX §4 color) | Required? | Provenance | Freshness |
|---|---|---|---|
| GSC (emerald-500) | Yes | `search_console_daily_rollups` synced nightly via `SyncSearchConsoleJob`; historical via `GscHistoricalImportJob` | ~48h lag (Google-side) — dotted segment for last 2 days |
| Store (slate-500) | Optional | `orders` joined by landing_path to a query → order path where UTMs carry `utm_medium=organic` | Same as `/orders` (real-time-ish) |
| Facebook (indigo-500) | N/A — greyed on this page | — | — |
| Google Ads (amber-500) | N/A — greyed on this page | — | — |
| Site (violet-500) | N/A — greyed on this page | — | — |
| Real (yellow-400) | N/A — greyed on this page (pre-click surface) | — | — |

Notes on source behavior specific to this page:

- Every other SourceBadge (Facebook, Google Ads, Site, Real) is rendered greyed on every card with tooltip "Not applicable on /seo — SEO is pre-click".
- Global `AttributionModelSelector`, `WindowSelector`, `AccountingModeSelector`, and `ProfitMode` all grey out on this route per UX §7 because GSC is a pre-click impression/ranking surface with no attribution or accounting concept.
- `BreakdownSelector` on this page is restricted to: **None · Country · Device · Search Appearance · Page**. Channel / Campaign / Ad set / Customer segment are greyed with tooltip explaining GSC scope.
- `search_console_daily_rollups` uses GSC API v1 with `dataState=final` to avoid pulling provisional numbers into our cache; provisional last-48h data is rendered via a separate nightly `dataState=all` pull stored under a paired `*_api_version` column (see CLAUDE.md JSONB rules).

## Above the fold (1440×900)

- `AlertBanner` (info, dismissable) — "GSC data is ~48 hours behind Google. The last 2 days are provisional" — only when date range includes the last 72h. Copy per [`_crosscut_ux_copy.md`](../competitors/_crosscut_ux_copy.md).
- `AlertBanner` (warning, non-dismissable) — "GSC not connected — connect to see your search performance" + primary CTA to `/integrations/gsc` — only when GSC has never successfully synced for this workspace. Replaces the rest of the page body with an `EmptyState` (§5.7 pre-integration flavor).
- `FilterChipSentence` (§5.4) reading: *Showing **organic search** from **Last 28d** where **Country = All** and **Device = All***.
- `KpiGrid` (4 cols) — all four cards carry single-source GSC badge, remaining five SourceBadges outlined and dimmed with tooltip "Not applicable on /seo":
  - `MetricCard` "Impressions" · sources=[GSC]. Sparkline over the selected range with dotted last-2-days segment (§5.6 incomplete-period rule). Tooltip on value: formula `Σ impressions in range` + last-sync timestamp.
  - `MetricCard` "Clicks" · sources=[GSC]. Click body → navigates to `/seo?tab=queries&sort=clicks&dir=desc` (card-to-filtered-table per UX §6).
  - `MetricCard` "CTR" · sources=[GSC]. Tooltip formula `Σ clicks ÷ Σ impressions` (NULLIF on zero). Displayed as percent with one decimal; no tabular % symbol inside the value (percent is a unit, rendered after the number per UX §4.1).
  - `MetricCard` "Average Position" · sources=[GSC]. Delta arrow inverted — *down is better*, rendered green on negative delta with an inline note "Lower is better" in the value tooltip. Sparkline Y-axis is inverted to match (smaller number = higher on the chart).
- `LineChart` "Organic performance over time":
  - `GranularitySelector` (§5.6) defaulting to Weekly at 28d, Daily when range ≤ 14d, Monthly when range ≥ 60d.
  - Four switchable Y-axis series via metric-toggle pattern ([Plausible](../competitors/_inspiration_plausible.md)): Impressions / Clicks / CTR / Avg Position.
  - Clicking any of the four KpiGrid cards re-renders this chart with that metric on Y (cards double as chart-metric buttons — kills the chart-picker UI).
  - Right-edge dotted segment covers the incomplete 48–72h window, not just "today".
  - `ChartAnnotationLayer` (§5.6.1) renders user-authored events (e.g., "site migration", "schema markup deployed") plus system-authored annotations for GSC property verification changes and Google core algorithm updates (seeded list refreshed monthly from a `google_algorithm_updates` table).
  - Comparison period renders as a thinner, desaturated line in the same color (not a second color — saturation difference preserves the source-color-is-sacred rule from UX §4).
- `BreakdownSelector` active state:
  - When set to Country / Device / Search Appearance, the LineChart becomes grouped/stacked by the dimension (max 5 series + "Other" overflow) and MetricCards show top-3-values stacked (§5.15).
  - When set to Page, cards and chart collapse to a search-filter affordance ("Too many pages to stack — use the Pages tab") because the Page dimension has too many values to render legibly at the above-the-fold scope.

## Below the fold

- Sub-view switcher — **tabs** (not BreakdownSelector) for the five GSC dimensions:
  - `Queries · Pages · Countries · Devices · Search Appearance`
  - Default tab = **Queries**.
  - URL-stateful (`?tab=queries`).
  - Tabs use the sibling-views pattern (`_patterns_catalog.md` Tab-based sub-nav within a page) — the global date range, comparison period, and BreakdownSelector persist across tab switches because the data shape is the same, only the dimension differs.
  - Why tabs instead of BreakdownSelector: these five are the GSC API's first-class dimensions, each with distinct row schemas (a Query row has no URL column, a Page row has no query text), so they can't be a single grouped table.
- `DataTable` (§5.5) — default Queries view. 50 rows per page, infinite scroll. Columns:
  - Query (sortable alphabetically; search-filtered via `/` shortcut which focuses the table's toolbar search input)
  - Impressions (tabular-nums, sortable DESC by default)
  - Clicks (tabular-nums)
  - CTR (computed, NULLIF on zero impressions → "N/A"; displayed as percent with one decimal)
  - Avg Position (one decimal; tabular-nums)
  - Position Δ vs comparison period (green arrow on improvement = position went DOWN, red on regression = position went UP; suppressed via `ConfidenceChip` §5.27 below 1000 impressions per query)
  - Clicks Δ % vs comparison period (standard green-up / red-down)
  - `SignalTypeBadge` (§5.28) = **Deterministic** on every row (GSC data is direct, never modeled)
- Table toolbar (left → right): search input · column picker · `SavedView` trigger (§5.19) · `ShareSnapshotButton` (§5.29) · `ExportMenu` (§5.30). CSV export includes raw `impressions`, `clicks`, `position`, `ctr`, the comparison-period columns, AND annotations (as footnote rows per §5.6.1).
- **Pages tab**:
  - Swaps column 1 to `Page URL` with `MiddleTruncate` (§5.18) in JetBrains Mono 12.5px.
  - Row click opens `DrawerSidePanel` (§5.10) with per-page time series, the top 20 queries ranking for that page, and a store-side join: "This page converted N orders (Store) in the same window" — the only surface on `/seo` that crosses sources.
  - Page rows are grouped by URL path (not full URL) so `/products/x?ref=a` and `/products/x?ref=b` collapse into one.
  - Path grouping is a sanity default; power users can switch to full-URL grouping via the column picker.
- **Countries tab**:
  - Rows keyed on ISO country code with a small flag glyph + country name.
  - Leading column links to `/customers?country={code}` to pivot into cohort analysis.
  - Supports `BreakdownSelector=Country` as a cross-filter that dims rows not in the selection.
- **Devices tab**:
  - Only three rows (Desktop / Mobile / Tablet).
  - Renders as compact stacked `BarChart` (one bar per device type, stacked by metric) above a 3-row summary table rather than a 3-row DataTable alone.
  - The bar chart makes the mobile-vs-desktop split immediately visible — the insight this tab exists for.
- **Search Appearance tab**:
  - Rows = appearance types returned by the GSC API (Featured Snippet, FAQ, Site Links, Video, Recipe, Review, How-to, etc.).
  - Columns same as Queries.
  - Some appearance rows can legitimately have zero clicks while having thousands of impressions — do not suppress; the "why am I in Featured Snippets without any clicks?" insight is exactly what this tab is for.
- Empty-state handling per UX §10:
  - Filter returns zero: illustration + "No queries match these filters — clear filters or widen the date range".
  - Pre-integration: as noted above, the full-page EmptyState replaces this entire below-the-fold region.
  - Brand-new connection (<48h): synced EmptyState with `Sync Progress` ticker "GSC is backfilling — first complete day appears ~48h after connect".

## Interactions specific to this page

- **Inverted delta semantics on Avg Position.** A position going from 12 → 8 renders green with a down-arrow, because lower rank is better. This is the only place in the app where arrow direction and color decouple from the raw numeric direction. Value tooltip says "Position 8 is better than 12" to make the rule self-documenting. The inverted convention applies consistently across MetricCard delta, LineChart trendline, DataTable Position Δ column, and the DrawerSidePanel sparkline.
- **Dotted-segment rule is non-dismissable** on every time-series chart on this page. Unlike other pages (where the rightmost dotted segment covers "today"), on `/seo` it covers the trailing 48–72h window. Users cannot toggle it off — the whole point of the rule is to prevent over-reading the inevitable dip at the right edge.
- **Tab switches preserve date range + comparison period + BreakdownSelector value** in the URL; they do NOT preserve per-tab sort or column customization across tab changes, which reset each tab to its default sort.
- **Click a query row → `DrawerSidePanel` (§5.10)** containing:
  - 30-day trend sparklines for all four metrics (Impressions, Clicks, CTR, Avg Position — the last inverted)
  - The top 10 pages that rank for this query
  - Country split table if the query has international impressions (≥2 countries with ≥10 clicks)
  - "Flag as opportunity" annotation button — pinned to a per-workspace `seo_opportunities` list
  - Store-side join: "This query's top-ranking page converted N orders (Store) in the same window" when the Page has matching `utm_source=google&utm_medium=organic` orders
- **Right-click any query or page cell** → `ContextMenu` (§5.21) with a page-specific escape hatch "Open in Google Search Console" that deep-links to `https://search.google.com/search-console/performance/search-analytics?...` with the current query/page and date range prefilled, alongside the standard "Filter to this / Exclude this / Copy value / Copy link" actions.
- **`FreshnessIndicator` (§5.20) popover** on this page prominently shows the GSC row with `Delayed (Google 48h delay)` as the honest status chip — never rendered as "Healthy" even when the sync itself succeeded. The freshness copy carries the explicit latency: "GSC last synced 14 min ago · Google returns data with ~48h delay".
- **Low-volume queries render with `ConfidenceChip` (§5.27)** — any query with <1000 impressions in the range shows "Based on N impressions — low confidence" and has its Position Δ and Clicks Δ suppressed (GSC position is statistically noisy at small sample). The threshold is workspace-configurable in Settings → Confidence.
- **`ProfitMode` is greyed** with tooltip "Profit requires click + order data — not applicable to pre-click search surface". Same treatment for `AttributionModelSelector`, `WindowSelector`, `AccountingModeSelector`, and the Store/Facebook/Google/Site/Real badges on each MetricCard.
- `CommandPalette` (Cmd+K) scope on this page: typing any query string jumps directly to its DrawerSidePanel; typing a URL path jumps to the Pages tab filtered to that path.
- **`ShareSnapshotButton` (§5.29) edge case**: snapshots from `/seo` with a range ending in the last 48h bake in a dashed "Contains provisional GSC data — pulled at [timestamp]" footer, so recipients don't panic at a dip that later filled in.
- **Click a chart point** → drills into the Queries tab filtered to that date's top queries (UX §6 click-hierarchy); right-click adds an annotation at that date (UX §5.6.1).
- **Keyboard**: `g s` shortcut from anywhere jumps to `/seo`; once on `/seo`, `1`–`5` number keys jump between the five tabs (Queries=1 … Search Appearance=5).
- **BreakdownSelector values that cross-filter**: Country × Pages tab creates a double-axis: pages × country. The DataTable gains a leading country column; sorting respects the combined key.

## Competitor references

- [MonsterInsights Search Console report](../competitors/monsterinsights.md) — the minimum bar in the WordPress admin ecosystem: a single table of top 50 queries with clicks / impressions / CTR / avg position. We beat it by offering five tabs (not just Queries), honest freshness chrome, and the DrawerSidePanel per-query detail. MonsterInsights has no detail drill-down — click a query and nothing happens.
- [Looker Studio GSC connector](../competitors/looker-studio.md) — the DIY incumbent, "free" only if you already have the Google stack and a report builder's time. We implicitly beat it on setup cost (no Supermetrics, no canvas building, no refresh-button-click pathology).
- [Plausible dotted-line for incomplete periods](../competitors/_inspiration_plausible.md) — borrowed for the 48–72h lag, adapted from a "today only" dotted segment to a 2-day window specifically because GSC data latency is unique.
- [Peel annotations on charts](../competitors/peel-insights.md) — `ChartAnnotationLayer` on the time series lets SEO teams mark migrations, schema deploys, algorithm updates. Seeded list of Google core algorithm updates eliminates the "did a Google update cause this?" question.
- [Shopify Native Acquisition reports](../competitors/shopify-native.md) — parallel to their "Sessions by referrer" but with GSC's actual impression/position data rather than inferred referrer. Shopify shows "sessions from Google" post-click; we show "impressions in Google SERP" pre-click — complementary, non-overlapping surfaces.
- [Triple Whale / Northbeam](../competitors/triple-whale.md) — neither ships a dedicated SEO surface (both are paid-ads-centric). Our `/seo` is a wedge they structurally don't occupy.

## Mobile tier

**Mobile-usable** (UX §8). At <768px:

- KpiGrid collapses 4→2; the four metrics fit in a 2×2 arrangement.
- Tabs become a horizontal scroll pill bar (touch-swipeable) with the active tab sticky-left.
- DataTable reduces to card-stack (Linear-mobile pattern) showing Query + Clicks + Position Δ only; tap the card to open DrawerSidePanel.
- The `AlertBanner` about GSC lag is pinned top and uses warning-amber background on mobile (easier to spot than info-sky).
- Charts remain visible but collapse to sparkline-only; the main LineChart becomes a sparkline strip under each KpiGrid card rather than a dedicated block.
- `GranularitySelector`, column picker, and `ExportMenu` collapse into a single three-dot toolbar menu.
- `ShareSnapshotButton` is prominent on mobile because "send a stakeholder a read-only view" is the mobile-native workflow.

## Out of scope v1

- **Keyword research / competitor ranking data.** No Ahrefs/SEMrush-style "what queries could you rank for" suggestions — we do not ingest third-party SERP data. Revisit v2 once we decide whether to pay for a SERP-data API or route users to their preferred keyword tool.
- **Core Web Vitals / Lighthouse on this page.** Performance metrics live on a future `/site` page; `lighthouse_snapshots` exists in the schema but isn't surfaced here in v1.
- **Rich-result validation or structured-data diagnostics.** Those belong in GSC itself; the ContextMenu "Open in Google Search Console" deep-link sends users there rather than cloning the validation UI.
- **AI-generated "SEO recommendations".** No prescriptive layer in v1 — avoiding [Atria](../competitors/atria.md)-style hallucinated prescriptions on pre-click data we can't verify.
- **Organic-to-conversion multi-touch attribution.** `utm_medium=organic` orders are shown on individual Page rows only; full attribution modeling of organic is deferred to `/attribution`. The six-source MetricCard thesis does not extend to GSC (GSC reports impressions, not customer identity).
- **Query clustering / topic grouping.** Flat query list only in v1 — no "queries about shipping" auto-group.
- **Bing / DuckDuckGo / Yandex search data.** GSC only. No Bing Webmaster Tools connector v1; architecturally the schema accommodates it (table is `search_console_daily_rollups`, not `gsc_daily_rollups`) but the connector and UI tabs ship in v2.
- **International SEO (hreflang) diagnostics.** Country breakdown is aggregated; no per-hreflang-variant analysis.
- **Historical data beyond 16 months.** GSC API caps at 16 months; we don't synthesize older data. `GscHistoricalImportJob` documents this limit in the onboarding copy.
- **Peer-cohort SEO benchmarks.** "Your CTR vs. peers in your vertical" — deferred to v2 with the rest of benchmarking (UX §2 out-of-scope table).
- **Scheduled SEO digest email.** Reuses the generic `ExportMenu` → Schedule email (§5.30); no SEO-specific digest template in v1.
