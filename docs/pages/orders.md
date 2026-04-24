# Orders

Route: `/orders`

## Purpose
The order-by-order ground truth — what came in, who bought, which platform claimed it, and the full touchpoint journey — cross-checked against every source.

## User questions this page answers
- Which orders came in today / this week / in my selected range?
- Why did Facebook (or Google, or Klaviyo) claim this specific order? What was the full journey?
- Which orders were claimed by zero platforms (Not Tracked)?
- How many are from new customers vs returning, and what's the refund rate?
- Where in the world is my revenue concentrated right now?
- Can I export this filtered list to CSV for a CFO / agency hand-off?

## Data sources
| Source (UX §4 color) | Required? | Provenance | Freshness |
|---|---|---|---|
| Store | yes | `orders` table (WooCommerce + Shopify), joined with `customers`, `order_items`, `products` | ~2–5 min (webhook + reconcile) |
| Facebook | no | `orders.attribution_*` columns set by `RevenueAttributionService` from click IDs + `ad_insights` | computed at order ingest |
| Google Ads | no | same as Facebook (GCLID + `ad_insights`) | computed at order ingest |
| GSC | no | indirectly, via Organic Search channel mapping on `orders.utm_*` | computed at order ingest |
| Site | no | `orders.utm_*` columns + session data (non-platform first-party signals) | computed at order ingest |
| Real | yes | `RevenueAttributionService`-computed attribution winner per order; gold lightbulb | derived on request |

COGS shown per row is read-only on this page (sourced from product variant meta). Inline edits live on `/products` per [CLAUDE.md Gotchas](../../CLAUDE.md) and [UX §5.5.1](../UX.md#551-inline-editable-cell-datatable-sub-primitive).

## Above the fold (1440×900)

- `FilterChipSentence` · reads e.g. "Showing **orders** from **Last 30d** where **Source = any** and **Status = any** in **all countries**" — every bold span a removable chip, trailing `+` adds a new filter ([UX §5.4](../UX.md#54-filterchipsentence))
- `KpiGrid` (4 cols) · strip above table:
  - `MetricCard` "Orders" · sources=[Store*, Facebook, Google, Real] · shows count in range + delta vs previous period
  - `MetricCard` "AOV" · sources=[Store] · mean primary, median in hover tooltip
  - `MetricCard` "New Customer %" · sources=[Store] · `ConfidenceChip` shows when n<30 ([UX §5.27](../UX.md#527-confidencechip))
  - `MetricCard` "Refund Rate" · sources=[Store]
- **Toolbar row** (right-aligned above table):
  - `SavedView` chips row on the left (e.g. "US Paid Social · Last 7d", "High-AOV returning customers") ([UX §5.19](../UX.md#519-savedview))
  - Column picker icon · opens the standard DataTable column-set modal
  - `ShareSnapshotButton` ([UX §5.29](../UX.md#529-sharesnapshotbutton))
  - `ExportMenu` ([UX §5.30](../UX.md#530-exportmenu)) — CSV includes the six-source columns per spec
- `DataTable` "Orders" · sticky header + sticky first column · default sort: order date DESC · default page size 50, infinite scroll · row click → `OrderDetailDrawer` (`DrawerSidePanel` with page-local content, see below) ([UX §5.5](../UX.md#55-datatable))

**Default columns** (all sortable; column picker can add/remove; saved per user per table):

| Column | Type | Notes |
|---|---|---|
| Order ID | `EntityHoverCard` · JetBrains Mono + `MiddleTruncate` (`#ord_abc...xyz789`) | Hover opens preview card ([UX §5.18](../UX.md#518-entityhovercard)) |
| Date | relative short (`3h ago`) with absolute on hover | |
| Customer | masked email (`b***@gmail.com`) + `EntityHoverCard` on hover | |
| Total | tabular currency, workspace-converted; native chip on hover shows FX ([UX §9](../UX.md#9-multi-store--portfolio-behavior)) | |
| Status | pill (Completed / Refunded / Disputed / Cancelled) | |
| Source | `SourceBadge` for the `Real`-winning source + a `SignalTypeBadge` ([UX §5.28](../UX.md#528-signaltypebadge)) when attribution is Modeled | |
| Touchpoints | compact string `Facebook → Google → Direct` ([Northbeam pattern](../competitors/_patterns_catalog.md#pattern-touchpoints-string-column)); overflow renders `Facebook → Google → +3` with full journey in drawer | |
| Country | ISO flag + 2-letter code | |
| COGS | read-only tabular currency; `—` if not configured, with inline tooltip "Configure on /products" | |

ProfitMode ON variant ([UX §5.16](../UX.md#516-profitmode-toggle)): Total column gains a paired `Profit` column and `Margin %` column; the KPI strip's Revenue-flavor cards flip to Profit equivalents. Orders count card unchanged.

Breakdown ON variant ([UX §5.15](../UX.md#515-breakdownselector)): when `?breakdown=country`, a leading "Country" group header row is injected and KPI cards show top-3 values stacked (e.g., `US $42k · DE $18k · UK $12k`).

## Below the fold

- Table continues via infinite scroll (50 rows per page).
- **Right rail widget during BFCM / high-traffic periods** — `TodaySoFar` ([UX §5.25](../UX.md#525-todaysofar)) docked as a fixed sidebar above 1280px viewports; toggle in toolbar to hide. Off by default outside BFCM window.
- **Empty variants:**
  - Filter returns zero → DataTable EmptyState: illustration + "No orders match these filters between [date range]" + secondary action "Clear filters" + primary "Expand to Last 30d".
  - Pre-sync → `EmptyState` Syncing: "Importing 4,200 of 18,400 orders (23%) — est. 8 min" with `DemoBanner` above.

### OrderDetailDrawer (page-local component, extends `DrawerSidePanel`)

Opens via row click. 480px wide desktop, full-width mobile. URL-stateful (`/orders?order=ord_abc123`), Esc closes, shareable.

Sections, top → bottom inside the drawer:

1. **Header strip** — Order ID (full, Copy button) · placed-at absolute time · status pill · close X.
2. **Summary row** — 4 inline stats: Total · Items · Customer type (New / Returning) · Fulfillment status.
3. **Source attribution card** — six-source row mirroring `MetricCard` badge strip for this specific order. Shows which source(s) claimed this order and which won under Real. Tooltip per badge: "Facebook claimed this order (GCLID + 7d click window)". Anomaly chip if sources disagree by > threshold.
4. **Customer Journey Timeline** (the narrative centerpiece) — vertical timeline, newest at bottom (order at bottom), one row per touchpoint:
   - Per row: platform icon (canonical source color) · campaign / adset / ad name · UTM values (monospace) · landing page · relative timestamp + absolute on hover · fractional credit under current attribution model (`TikTok 0.6 · Meta 0.3 · Direct 0.1`).
   - Attribution model selector at the top of the timeline section (overrides global for this drawer only, subdued chip indicator); changing the model live-updates credit splits. Northbeam Orders pattern.
   - "Not Tracked" timeline state: single row "No touchpoints matched — this order is unattributed" with a `?` opening explainer.
5. **Gap Reason chip** — when the Not Tracked delta is non-zero, a chip explains why the order wasn't attributed. Logic:
   - `No click ID` — neither GCLID nor FBCLID is present on the order.
   - `Outside window` — click timestamp is older than the global attribution window (default 7-day click, 1-day view).
   - `Over-reported elsewhere` — the order is counted in Not Tracked (Store total < sum of platform attributions).
   - `Consent denied` — (Shopify / WC native data) order is flagged as consent-challenged in the store's records (v2 — placeholder for now).
   - `Unmatched UTM` — utm_source set but doesn't map to any seeded channel in `channel_mappings`.
6. **Line items table** — compact: SKU · product name (hover card) · qty · unit price · COGS (read-only) · line total.
7. **Raw payload section** (collapsed by default) — JSON viewer of relevant `raw_meta` fields (PYS data, `fee_lines`, `customer_note`), per [CLAUDE.md Gotchas](../../CLAUDE.md) — excludes sensitive payment data.
8. **Quick actions footer** — "Open in Shopify admin" (or WooCommerce) · "Copy link to order" · "Add annotation at this date".

## Interactions specific to this page

- **Click any table cell value**: adds that value as a filter chip on the `FilterChipSentence` and re-queries (Plausible-style filter-on-click, [UX §6.1](../UX.md#61-click-hierarchy-on-data-primitives)). Example: click a country code → filter by country.
- **Row click** opens `OrderDetailDrawer`; double-click on an entity (customer/campaign/product) inside the drawer navigates to that entity's page.
- **Touchpoints column hover**: tooltip shows full journey as a one-line expanded string (`Facebook · Carousel UGC → Google · Brand Search → Direct`) before user commits to opening the drawer.
- **Source column click**: switches Real to that source for this row only, rendering an inline "(per-row override)" chip — useful for spot-checking "what would Facebook say this order is worth?".
- **Attribution model change inside drawer**: credit splits in the journey timeline recompute live; does NOT change the global model for the table behind it (drawer-scoped override, rendered as subdued chip per [UX §5.1 Custom date state](../UX.md#51-metriccard--the-signature-primitive)).
- **Export CSV** from `ExportMenu` always includes the six-source revenue columns per [UX §5.30](../UX.md#530-exportmenu); never collapsed to a single Revenue column.
- **Bulk select** (Shift+Click / X key) surfaces a sticky footer toolbar with "Export selected", "Add tag", "Create segment from selection (v2)" — canonical multi-select pattern from Linear/Stripe.
- **Saved View**: "Save view" in toolbar promotes current filter + sort + columns + date range to a named `SavedView` pinned in the sidebar under Orders (e.g., `Orders › Not Tracked (30d)`).

Shared interactions (URL state, sort cycle, right-click ContextMenu, SWR revalidation, Cmd+K) live in [UX §6](../UX.md#6-interaction-conventions).

## Competitor references

- [Northbeam Orders (per-order journey)](../competitors/_teardown_northbeam.md#screen-orders-customer-journey) — direct blueprint for `OrderDetailDrawer` timeline + live attribution-model credit recompute. Fractional credit rendering is lifted verbatim.
- [Triple Whale Customer Journeys](../competitors/_teardown_triple-whale.md#screen-pixel--customer-journeys) — masked email convention, touchpoint icon-row, per-order card anatomy. We prefer a drawer (Linear split-pane) over TW's full-page card list to keep the table in context.
- [Triple Whale Attribution All table](../competitors/_teardown_triple-whale.md#screen-pixel--attribution-all) — sticky header, sticky first column, column settings modal pattern.
- [Putler Transactions](../competitors/putler.md) — source badge per row across mixed providers (our equivalent is the Real+badge column).
- [Metorik](../competitors/metorik.md) — column picker + drag-to-reorder export (pattern: [Export with column picker + drag-to-reorder](../competitors/_patterns_catalog.md#pattern-export-with-column-picker--drag-to-reorder)).
- [Plausible filter-on-click](../competitors/_patterns_catalog.md#pattern-filter-by-click-on-any-rowcell) — click any cell to add that value as a filter chip.
- [Linear split-pane on row click](../competitors/_patterns_catalog.md#pattern-split-pane-on-row-click) — OrderDetailDrawer contract.
- [Shopify Native Orders](../competitors/shopify-native.md) — day-1 comparison: users arrive here expecting the Shopify orders list and must immediately see the per-source badges + touchpoints string as the upgrade over native.
- [Rockerbox dedup view](../competitors/rockerbox.md) — per-order source-disagreement philosophy, implemented here as the drawer's "Source attribution card".

## Mobile tier

**Mobile-first.** Works on 375×812 ([UX §8](../UX.md#8-responsive-stance), [_crosscut_mobile_ux.md](../competitors/_crosscut_mobile_ux.md)).

- KpiGrid collapses 4→2→1.
- `DataTable` reduces to a **card-stack**: each card shows Order ID · customer · total · source badge · date relative · tiny touchpoints string. Columns Country, Status, COGS hidden; accessible by expanding the card.
- `FilterChipSentence` wraps vertically; the `+` opens a bottom-sheet filter picker.
- `OrderDetailDrawer` becomes a full-screen route (`/orders/<id>` visually, same URL shape) with back arrow; timeline orientation unchanged.
- Long-press on a row replaces hover for tooltips and per-row source override.
- ExportMenu CSV download uses mobile share sheet when available.

## Out of scope v1

- Inline COGS editing — lives on `/products` per [CLAUDE.md](../../CLAUDE.md); this page is read-only for COGS.
- Bulk status changes (refund, cancel, fulfill) — we're analytics, not order management.
- "Create segment from selection" actual segment builder — button surfaced, opens a "Coming in v2" toast.
- Market-basket / "frequently bought together" analysis on the drawer — v2.
- Scheduled email of filtered order lists — `ExportMenu` → Schedule email is v1 via [UX §5.30](../UX.md#530-exportmenu), but custom views as triggers are v2.
- Triple Whale-style Live Orders auto-refresh stream on this page — we have `ActivityFeed` on `/dashboard` instead; Orders stays date-range-scoped and manually refreshable.
- Post-purchase survey answers ("How did you hear about us?") — no survey product in v1 ([UX §2 out-of-scope](../UX.md#out-of-scope-for-v1-intentional)).
- Per-row "Send to creative cockpit" / cross-surface shortcuts — deferred until `/ads` creative view ships.
