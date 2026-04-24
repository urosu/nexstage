# Dashboard

Route: `/dashboard` (portfolio variant: `/dashboard?view=portfolio` for 3+ workspaces, see [UX §9](../UX.md#9-multi-store--portfolio-behavior))

## Purpose
One glance at how the business is doing today, this week, this month — with source disagreement visible from the first screen.

## User questions this page answers
- Are today's numbers on track vs last week's same-day curve?
- How does Store revenue compare to what Facebook / Google / GSC are reporting for the same period?
- What's my "Not Tracked" bucket and is it growing?
- What needs my attention right now (broken integrations, attribution anomalies, disputes)?
- What just happened — who ordered what in the last few minutes?
- How is the business performing over the selected range across all six sources?

## Data sources
| Source (UX §4 color) | Required? | Provenance | Freshness |
|---|---|---|---|
| Store | yes | `daily_snapshots`, `hourly_snapshots` — derived from WooCommerce/Shopify order ingests via `UpsertWooCommerceOrderAction` / `UpsertShopifyOrderAction` | ~2–5 min (webhook + 5-min reconcile) |
| Facebook | no | `ad_insights` via `FacebookAdsClient` + `attribution_*` columns on `orders` | ~15 min |
| Google Ads | no | `ad_insights` via Google Ads client + `attribution_*` columns | ~15 min |
| GSC | no | `SearchConsoleClient` — indirect on Dashboard (used in Revenue overlay via Site channel) | 48h lag (GSC native) |
| Site | no | first-party GA4-equivalent signals on `orders.utm_*` + session data | real-time per event |
| Real | yes | `RevenueAttributionService` computed from the other five; gold lightbulb | derived on request; recomputes on filter change (Klaviyo-style retroactive recalc, [UX §6](../UX.md#6-interaction-conventions)) |

## Above the fold (1440×900)

Component tree (density target 8–15 widgets, see [UX §4](../UX.md#density)):

- `AlertBanner` · DemoBanner variant when `workspace.state = 'demo'` or any card is backfilling ([UX §5.11.1](../UX.md#5111-demobanner-alertbanner-variant))
- `TriageInbox` · compact variant, max 4 visible rows + "View all (N)" link → `/alerts` ([UX §5.22](../UX.md#522-triageinbox))
- **Pinned row** · horizontal strip of user-pinned `MetricCard`s (max 8, URL-stateful, synced cross-device). Hidden when user has zero pinned metrics. ([UX §5.1](../UX.md#51-metriccard--the-signature-primitive) pin affordance)
- `KpiGrid` (4 cols on desktop, 2 on tablet, 1 on mobile) · the six thesis-anchoring cards:
  - `MetricCard` "Revenue (28d)" · sources=[Real*, Store, Facebook, Google, GSC, Site] · clicking Real → `/orders?source=real`
  - `MetricCard` "Orders" · sources=[Store*, Facebook, Google] — source-conditional label per metric dictionary §Ads: `Orders` when Store badge active, `Purchases` when Facebook or Google active (platform-attributed vs store-recorded are always distinct, never averaged)
  - `MetricCard` "Not Tracked" · sources=[computed] · single-source card; value can be negative (platform over-report), signed chip, tooltip explains ([UX §7](../UX.md#not-tracked))
  - `MetricCard` "Ad Spend" · sources=[Facebook, Google] · stacked source totals; blended under toggle
  - `MetricCard` "MER" · sources=[Real, Store] · label expands first-time-shown as "MER — Marketing Efficiency Ratio"
  - `MetricCardMultiValue` "AOV" · three values (Mean / Median / Mode) · Triple Whale AOV tile pattern ([UX §5.1.2](../UX.md#512-metriccardmultivalue))
- `TrustBar` · full-width thesis strip beneath KpiGrid ([UX §5.14](../UX.md#514-trustbar-nexstage-specific-nexstage-owned)) · ToggleGroup `Orders / Revenue` at top of bar · clicking any source cell → `/attribution?source=<name>`
- **Toolbar row** (right-aligned above the two-column region): `ShareSnapshotButton` ([UX §5.29](../UX.md#529-sharesnapshotbutton)) · `ExportMenu` ([UX §5.30](../UX.md#530-exportmenu))
- Two-column region (8/4 split):
  - **Left (8 cols)** — `LineChart` "Revenue over time" · multi-source overlay (Real solid gold, Store slate, Facebook indigo, Google amber, GSC emerald — [UX §4 source colors](../UX.md#color-tokens)) · `GranularitySelector` defaults Weekly for ≥14d ranges · dotted incomplete-period segment always-on ([UX §5.6](../UX.md#56-chart-primitives)) · `TargetLine` when workspace has a Revenue target set ([UX §5.23](../UX.md#523-target))
  - **Right rail (4 cols)** — `TodaySoFar` · always-on intra-day widget with same-weekday-last-week ghost line + P25–P75 band ([UX §5.25](../UX.md#525-todaysofar))

**Portfolio variant** (3+ workspaces, `?view=portfolio`): all six KPI cards swap to `MetricCardPortfolio` with per-workspace contribution bar + stacked per-workspace sparklines ([UX §5.1.1](../UX.md#511-metriccardportfolio)). TrustBar aggregates across workspaces; LineChart gains one line per workspace (max 5 visible, overflow grouped as "Other").

**ProfitMode ON variant** ([UX §5.16](../UX.md#516-profitmode-toggle)): all six KPI cards flip to profit flavor (Revenue → Profit, ROAS → Profit ROAS, MER → Profit MER, AOV → AOP). The LineChart "Revenue over time" is replaced above the fold by a compact `ProfitWaterfallChart` ([UX §5.17](../UX.md#517-profitwaterfallchart)); TodaySoFar remains. If COGS is not configured, affected cards degrade with amber StatusDot + inline "Add COGS to see profit" CTA, per [UX §7 Cost inputs](../UX.md#cost-inputs).

## Below the fold

- **Channel contribution region** — two charts side by side (6/6 split):
  - `BarChart` stacked "Revenue by channel" · x-axis = channels (Paid Social, Paid Search, Organic Search, Direct, Email, Referral), y-axis = Revenue, stacked by source badge colors · clicking a bar → `/attribution?channel=<x>`
  - `BarChart` stacked "Spend by platform" · Facebook + Google bars with CPM/CPC overlay chips
- `ActivityFeed` · full-width 12-col stream of latest commerce events (orders, refunds, disputes, subscription cancels) with per-row `SourceBadge` ([UX §5.24](../UX.md#524-activityfeed)) · header reads "Auto-refresh · every 10s" · Pause toggle · entity IDs in each row (order IDs, masked customer email) wire up to `EntityHoverCard` ([UX §5.18](../UX.md#518-entityhovercard)) · click row → `DrawerSidePanel` for that order/customer (same panel used on `/orders`, see [orders.md](orders.md))
- **Recent 5 orders** summary table (compact) · 6-col width sitting beside ActivityFeed on wider viewports · links "View all orders →" to `/orders`
- **SEO glance** (when GSC connected) · 6-col width · `KpiGrid` 3-up: Impressions · Clicks · Average Position — all source=[GSC], links out to `/seo`

## Interactions specific to this page

- **Pin/unpin**: hover any `MetricCard` body → 📌 pin icon top-right; click adds to Pinned row at top (max 8, enforced via toast "Unpin one to pin another"). Pinned ordering is drag-reorderable within the Pinned row only. URL-stateful via `?pinned=revenue-28d,orders,mer,...`.
- **Portfolio ↔ single-workspace toggle**: when in portfolio view, clicking a workspace segment on a `MetricCardPortfolio` contribution bar drills into `/dashboard?workspace=<id>` with the "% of portfolio" chip persisting on every card.
- **TrustBar cell click**: deep-links to Attribution with that source pre-filtered AND the current date range carried over; preserves URL state on return (browser back returns to Dashboard with all filters intact).
- **TodaySoFar hover on projection band**: reveals P25 / P50 / P75 values with weekday labels; clicking the headline number drills to `/orders?from=<today 00:00>&to=<now>`.
- **ActivityFeed row click opens DrawerSidePanel** without leaving Dashboard — identical drawer contract as `/orders` to keep memory model one-to-one.
- **Right-click any `MetricCard` value**: ContextMenu ([UX §5.21](../UX.md#521-contextmenu)) with "Open in Explorer" greyed (v2), "Copy formula", "Pin to top row", "Add annotation here".

Shared interactions (URL state, SWR revalidation, Cmd+K, keyboard shortcuts, optimistic writes) live in [UX §6](../UX.md#6-interaction-conventions) and are not restated here.

## Competitor references

- [Triple Whale Summary Dashboard](../competitors/_teardown_triple-whale.md#screen-summary-dashboard-home) — pinned row, sectioned KPI grid, drag-to-reorder affordance. We steal the pinned row; skip the drag-and-reorder dashboard-builder (Stripe anti-lesson — "almost nobody customises").
- [Triple Whale Live Orders](../competitors/_teardown_triple-whale.md#screen-pixel--live-orders) — source for `ActivityFeed` schema (relative timestamp, masked customer, source pill, pause toggle).
- [Northbeam Overview Home](../competitors/_teardown_northbeam.md#screen-overview-home) — global AttributionModel / Window / AccountingMode selectors above the KpiGrid (implemented in TopBar, not per-page), Revenue (1st Time) framing available via `WindowSelector` New Customer filter.
- [Polar Home](../competitors/_teardown_polar.md#screen-home--getting-started-dashboard) — "Today" snapshot + blended acquisition KPIs composition; we translate to our six-source frame.
- [Putler Home (Pulse + Overview)](../competitors/putler.md) — Pulse/Overview dual-timeframe rationale. Our `TodaySoFar` + main `LineChart` (user date range) replicates the glance-vs-depth split on one page without tabs. Demo-data pattern feeds our `DemoBanner`.
- [Shopify Native Live View](../competitors/shopify-native.md) — emotional pull of real-time; we deliberately do NOT clone the globe (anti-pattern in [UX §5.6.1 pattern catalog](../competitors/_patterns_catalog.md#pattern-geographic-globe--map)). `ActivityFeed` + `TodaySoFar` answer the same "is it alive" question honestly.
- [Stripe "Today so far"](../competitors/_inspiration_stripe.md) — widget shape, honest-range projection.
- [Klaviyo / Omnisend / Mailchimp](../competitors/klaviyo.md) — Email appears as a channel inside the "Revenue by channel" BarChart, never as a standalone KPI on Dashboard v1 (channel-normalized via [ChannelMappingsSeeder](../competitors/_crosscut_metric_dictionary.md)).
- [Linear Triage](../competitors/_inspiration_linear.md) — `TriageInbox` compact variant above the fold.

## Mobile tier

**Mobile-first.** Works on 375×812 ([UX §8](../UX.md#8-responsive-stance)).

- KpiGrid collapses 4→2→1 col.
- Pinned row horizontally scrolls with snap-points.
- TrustBar wraps onto two lines (Store / FB / Google on row 1; GSC / Real / Not Tracked on row 2).
- LineChart reduces to the Real+Store lines only by default; other sources hidden behind a legend toggle.
- TodaySoFar stacks below the main chart.
- ActivityFeed is full-width single column; recent-5-orders table becomes a card-stack.
- Long-press on any MetricCard replaces hover-pin affordance.

## Out of scope v1

- Drag-drop dashboard builder / widget gallery — opinionated defaults only ([Stripe anti-lesson](../competitors/_patterns_catalog.md#anti-pattern-drag-drop-dashboard-customization-users-dont-use)).
- Custom SQL tiles — deferred, see [UX §2 out-of-scope](../UX.md#out-of-scope-for-v1-intentional).
- AI chat / Copilot / Moby-style assistant — v2.
- Geographic globe / Live View map — deliberate skip ([_patterns_catalog.md Geographic globe](../competitors/_patterns_catalog.md#pattern-geographic-globe--map)).
- Peer-cohort Benchmarks band on the LineChart — schema-ready but v2, triggered by store count ([UX §2](../UX.md#out-of-scope-for-v1-intentional)).
- Multi-correlation Metrics Explorer tile — v2.
- Scheduled email digests of the Dashboard — `ShareSnapshotButton` ([UX §5.29](../UX.md#529-sharesnapshotbutton)) covers the share case in v1; scheduled email lives on Reports v2.
- In-dashboard target editor — targets are authored in Settings → Targets per [UX §5.23](../UX.md#523-target); Dashboard only renders TargetProgress / TargetLine.
- **Agency / white-label portfolio branding** (custom logo, custom domain, client-portal theming) — v2 with Agency SKU. Portfolio view in v1 shows Nexstage chrome unmodified.
