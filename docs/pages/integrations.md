# Integrations

Route: `/integrations`

## Purpose
One page that tells the user whether every pipe feeding Nexstage is healthy, which specific events are failing, and what action will fix it.

## User questions this page answers
- Is every connection healthy — and if not, which one broke, when, and why?
- Per destination (Facebook CAPI / Google Ads / GSC / Shopify / Woo), what's my accuracy and failure rate this week?
- Why does Facebook report fewer purchases than my store? (Click the error breakdown.)
- Has the historical backfill finished, and how much longer for the ones still running?
- Which UTM tags get mapped to which channel on my dashboards, and can I override the defaults?
- When will cohort analysis / phased features unlock for this workspace?
- My Facebook token expired — where do I click to reconnect, and which pages were affected?

## Data sources

(Column header is `Category` rather than `Source (UX §4 color)` because this page's data is meta-level — the integration layer itself, not store data. UX §14 template's six-source contract applies to data pages, not meta pages.)

| Category | Required? | Provenance | Freshness |
|---|---|---|---|
| Store (Shopify / Woo) | yes | `stores`, `integration_runs`, `integration_events` — webhook health + reconcile-job outcomes from `UpsertShopifyOrderAction` / `UpsertWooCommerceOrderAction` | real-time status; rollup every 5 min |
| Facebook Ads | no | `ad_accounts`, `integration_runs`, `integration_events` — token state + per-call error codes from `FacebookAdsClient` | ~15 min |
| Google Ads | no | `ad_accounts`, `integration_runs` — token state + per-call error codes from Google Ads client | ~15 min |
| GSC | no | `search_console_properties`, `integration_runs` — delivery state from `SearchConsoleClient` | 48h GSC-native lag (surfaced as `StatusDot` amber, not an error) |
| Historical imports | yes | `historical_import_jobs` — one row per in-flight or completed backfill (`ShopifyHistoricalImportJob`, `WooCommerceHistoricalImportJob`, `AdHistoricalImportJob`, `GscHistoricalImportJob`) | real-time during run |
| Channel mappings | yes | `channel_mappings` seeded by `ChannelMappingsSeeder` + workspace overrides | on-save |
| Phased unlock state | yes | derived from `workspace.created_at` + per-feature thresholds (day 7 / 30 / 60 / 90, see [Northbeam](../competitors/northbeam.md) pattern) | on-request |

## Above the fold (1440×900)

Layout: left-nav-free, single content column with four in-page tabs (per [UX §5 tab-based sub-nav](../competitors/_patterns_catalog.md#pattern-tab-based-sub-nav-within-a-page)). Tabs share the workspace scope and the top-level `AlertBanner` stack above them.

- **Global alerts region** (always above tabs)
  - `AlertBanner` · `danger` when any integration has `status = 'failed'` (e.g., token expired) — carries an inline "Reconnect" primary action. Same banner renders on every page that consumes that source, so the `/integrations` copy is the canonical one ([UX §5.11](../UX.md#511-alertbanner)).
  - `AlertBanner` · `warning` stacked when an integration is `stale > 2h` or rate-limited. Dismissable per-session; returns on next stale.
  - `AlertBanner` · `info` for phased-unlock nudges: `Cohort analysis unlocks at day 30 — you're on day 18` ([Northbeam](../competitors/northbeam.md) day-30/60/90 pattern).
- **Tab strip** (URL-stateful via `?tab=…`, default `connected`):
  - `Connected` · grid of connector cards (default landing)
  - `Tracking Health` · Elevar-style accuracy + error codes
  - `Historical Imports` · backfill progress + archive
  - `Channel Mapping` · UTM → channel rule editor

### Tab 1 — Connected

- Toolbar row (right-aligned):
  - Primary button `+ Add integration` → opens modal with the full connector grid (Shopify, WooCommerce, Facebook Ads, Google Ads, GSC; greyed "Coming soon" tiles for TikTok Ads, Klaviyo). Per-connector card shows the OAuth/plugin/API-key path inline so users know what they're committing to before clicking ([_crosscut_onboarding_ux.md](../competitors/_crosscut_onboarding_ux.md)).
- `KpiGrid` (4 cols) of compact summary cards (`MetricCardCompact` variant, no sparkline):
  - `Connected sources` — `5 of 5` · green `StatusDot` when all green, amber when any warning, rose when any failed.
  - `Events last 24h` — total integration events processed across all sources.
  - `Overall accuracy (7d)` — weighted mean of per-destination accuracy percentages (Elevar pattern, see [elevar.md](../competitors/elevar.md#key-screens) Channel Accuracy Report).
  - `Next sync` — relative time to the next scheduled run across all connectors.
- **Connector card grid** — 3-col grid, one `IntegrationCard` per connector. The card is an instance of the Vercel-style Entity primitive ([UX §5 / _patterns_catalog.md Entity component](../competitors/_patterns_catalog.md#pattern-entity-component-icon--primary--metadata--trailing-action)):
  - **Header**: connector logo (24px) · name · `StatusDot` + label (`Healthy` / `Warning` / `Failed` / `Not connected`) ([UX §5.9](../UX.md#59-statusdot)).
  - **Body**: `Last sync 2 min ago` · `Next sync in 13 min` · `Connected by urosu@acme.com on Mar 14`.
  - **Metadata row**: account label (e.g., `Shopify · acme-coffee.myshopify.com`, `Facebook · Ad Account #ACT_123…789` with `MiddleTruncate` — [UX §5.18](../UX.md#518-entityhovercard)).
  - **Footer actions**: `Reconnect` / `Configure` / `Disconnect` (kebab `…` hides Disconnect behind one click to avoid accidents; destructive via undo-toast, not modal, per [UX §6.2](../UX.md#62-optimistic-writes--undo-window)).
  - **Per-card alert slot** (below body): if this integration's token expired / rate-limited / missing scope, a local warning strip repeats the same banner copy scoped to the connector and the pages impacted — e.g., `Facebook token expired — affects Dashboard, Ads, Attribution`. Click the page list → deep-links to that page with source=facebook pre-filtered so the user sees what's broken.

### Tab 2 — Tracking Health (Elevar pattern)

The reference for this tab is [elevar.md § Channel Accuracy Report + Server Event Logs + Error Code Directory](../competitors/elevar.md#key-screens). We're translating that surface to our six-source frame.

- Above-the-fold layout (split 8/4):
  - **Left (8 cols)** — `KpiGrid` (3-up) of per-destination accuracy cards (`MetricCard` variant, source=single, sparkline on):
    - `Facebook CAPI accuracy (7d)` · `99.2%` · sparkline · SourceBadge=Facebook · amber `ConfidenceChip` if sample < 1000 impressions ([UX §5.27](../UX.md#527-confidencechip)).
    - `Google Ads Enhanced Conversions accuracy (7d)` · `97.8%` · sparkline · SourceBadge=Google.
    - `GSC delivery accuracy (7d)` · `100%` · sparkline · SourceBadge=GSC · tooltip explains the 48h lag is GSC-native, not a failure.
    - (A fourth card slot reserved for TikTok / Klaviyo when those connectors ship — defaults greyed with "Not connected" state.)
  - **Right (4 cols)** — `LineChart` "Events/day per destination" · multi-source overlay using canonical source colors, `GranularitySelector` defaults Daily. Click a line point → filters the error table below to that destination + date.
- **Error Code Directory** — `DataTable` of recent failures grouped by error code. Columns:
  - `Error code` (platform-native, e.g. Meta `#100`) — JetBrains Mono.
  - `Destination` — SourceBadge chip.
  - `Event` — the Nexstage event (`purchase`, `lead`, `view_content`).
  - `First seen` · `Last seen` (relative, with `EntityHoverCard` on hover).
  - `Count (7d)`.
  - `Explanation` — plain-English remediation ("Missing `user_data.em` — the customer email hash didn't reach the API. Verify that checkout extensibility is installed.") sourced from an internal error-code catalog.
  - `Action` — `Fix it` button links to the deepest possible remediation (re-auth, re-configure, or a docs link).
- Filter strip above the table via `FilterChipSentence` ([UX §5.4](../UX.md#54-filterchipsentence)): `Destination = any | Window = Last 7d | Status = any`.

### Tab 3 — Historical imports

- `KpiGrid` (3 cols) of in-flight imports as cards (`MetricCardCompact` variant):
  - Each card: connector badge · phase label (`Orders` / `Customers` / `Ad insights` / `GSC queries`) · Lifetimely-style progress copy: `Importing 4,200 of 18,400 orders (23%) — est. 8 minutes` ([_crosscut_onboarding_ux.md Lifetimely best moment](../competitors/_crosscut_onboarding_ux.md)).
  - `Sparkline` replaced by a Linear-style progress donut ([_patterns_catalog.md Progress donut](../competitors/_patterns_catalog.md#pattern-progress-donut--radial)).
  - Tab-title status channel active while any import runs: `▶ Importing (42%) · Nexstage` ([UX §5.8.1](../UX.md#581-tab-title-status-channel)).
- **Archive `DataTable`** — one row per completed or failed import. Columns:
  - `Started` (relative + absolute on hover).
  - `Duration`.
  - `Connector` (SourceBadge).
  - `Scope` (date range imported, e.g., `2024-01-01 → 2026-04-23`).
  - `Rows imported` (tabular-num).
  - `Outcome` — `StatusDot` + `Success` / `Partial` / `Failed`; row click opens `DrawerSidePanel` with the per-phase log (Vercel "Built in 42s" → hover breakdown, [_patterns_catalog.md](../competitors/_patterns_catalog.md#pattern-hover-on-timing-label--breakdown-tooltip)).
- `ExportMenu` on the archive table (CSV only v1) — per [UX §5.30](../UX.md#530-exportmenu).

### Tab 4 — Channel mapping

- Context strip at top: `FilterChipSentence` reads `Source mapping priority: Most-specific-first · Unmatched traffic → "Other"`. The sentence is editable (`priority ordering` + `fallback bucket` are the two knobs).
- `DataTable` of mapping rules · editable inline ([UX §5.5.1](../UX.md#551-inline-editable-cell-datatable-sub-primitive)):
  - `Priority` (drag-handle; first match wins, lowest number = highest priority).
  - `UTM source` (e.g., `facebook`, `fb`, `ig`).
  - `UTM medium` (e.g., `cpc`, `paid-social`, `*` wildcard).
  - `Channel` (dropdown: Paid Social / Paid Search / Organic Social / Organic Search / Email / Direct / Referral / Affiliate — channel set matches the Dashboard "Revenue by channel" chart).
  - `Source of truth` — `Seeded` (read-only, from `ChannelMappingsSeeder`) / `Workspace override` (editable/deletable).
  - Inline-editable cells; changes are optimistic with rollback toast ([UX §6.2](../UX.md#62-optimistic-writes--undo-window)).
- Toolbar: `+ Add rule` · `Reset to defaults` (destructive, undo toast) · `Preview unmatched traffic (last 30d)` — opens a drawer showing UTM combinations seen in the wild that currently hit the fallback bucket, each with a `Map this →` shortcut.
- Sync note (small text, link only): UTM values here and in the in-app TagGenerator must stay aligned; see the user memory entry on UTM source/medium sync. Both read from `channel_mappings`, so they can't diverge in practice.

## Below the fold

- **Phased unlock panel** (full width, Northbeam pattern, [northbeam.md](../competitors/northbeam.md)):
  - Horizontal milestone strip with 4 checkpoints: `Day 0 · Day 7 · Day 30 · Day 90`. The current workspace position is a marker on the strip.
  - Per checkpoint, list of features and their state: `Dashboard` (live) · `Attribution (Last-non-direct)` (live) · `Cohort analysis` (unlocks at day 30) · `LTV curves` (day 90).
  - Reframes empty-states as progress, not brokenness ([_crosscut_onboarding_ux.md Northbeam best moment](../competitors/_crosscut_onboarding_ux.md)).
- **Integration events stream** (Tracking Health tab only, below the error table): `ActivityFeed` variant scoped to integration events instead of commerce events ([UX §5.24](../UX.md#524-activityfeed)). Rows: timestamp · destination SourceBadge · event · status pill (`delivered` / `failed`) · order ID with `EntityHoverCard`. Pause toggle. Lets users watch their pipe work (Elevar's visceral "did it fire" view — [elevar.md](../competitors/elevar.md#conversion-monitoring-dashboard-the-flagship-health-surface)).
- **Advanced diagnostics** (collapsible, closed by default):
  - API version pinning (read-only) per connector (e.g., `Facebook Graph API v21.0`, `Google Ads v17`). Flagged with a small info chip when the pinned version is within 30 days of deprecation (per CLAUDE.md gotcha on API versions).
  - Webhook endpoints (Shopify / Woo) with copyable URLs and a `Test webhook` button that pushes a synthetic event and surfaces the `integration_events` row it produced. `EntityHoverCard` on the event ID.
  - Rate-limit headroom per connector (last 24h) — thin bar showing used vs. available quota.

## Interactions specific to this page

- **Reconnect flow (Facebook token expired is the canonical case).** Clicking `Reconnect` on the connector card or inside any of the propagated `AlertBanner`s (top of Integrations, on every page that consumes Facebook data, on affected `MetricCard`s) opens the OAuth redirect in a centered popup. Success writes new credentials and clears the danger banner app-wide within one SWR revalidation ([UX §6 SWR](../UX.md#6-interaction-conventions)). Failure keeps the banner and adds a Toast with the Graph API error code linked to the Error Code Directory row.
- **Affected-MetricCard propagation.** When an integration is in `status = 'failed'` or `status = 'warning'`, every `MetricCard` that uses that source renders the relevant SourceBadge greyed with a tooltip tying back to this page: `Facebook Ads not connected — fix on /integrations`. Clicking the greyed badge deep-links straight to that connector's card with the Reconnect affordance focused ([UX §5.1 Source unavailable state](../UX.md#51-metriccard--the-signature-primitive)).
- **FreshnessIndicator click-through.** The `FreshnessIndicator` popover ([UX §5.20](../UX.md#520-freshnessindicator)) in the TopBar includes a `Manage integrations →` row at the bottom that navigates here with `?tab=connected` and scrolls to the integration whose freshness the user last hovered. Sidebar sync-health `StatusDot` ([UX §3 Sidebar](../UX.md#sidebar)) click-through has the same behavior.
- **Historical import card row click** opens `DrawerSidePanel` (not a new page) with per-phase timeline, row counts per table, and the raw job log tail.
- **Error code row click** opens `DrawerSidePanel` showing the last 5 raw payloads (JSON, JetBrains Mono) sent to the platform for that error — Elevar's event-payload inspector ([elevar.md § Server Event Logs](../competitors/elevar.md#server-event-logs)) — with a `Copy payload` button. Helps agencies debug in their actual workflow.
- **Disconnect** → optimistic; card greys instantly; Toast with 10-second undo window ([UX §6.2](../UX.md#62-optimistic-writes--undo-window)). The Stripe-like "typed confirmation modal" is reserved for Settings → Workspace deletion, not per-integration disconnect.
- **Sync now** (per connector, inside `Configure`) is rate-limited to 1/min per connector; button shows remaining cooldown.

## Competitor references

- [Elevar Channel Accuracy Report + Server Event Logs + Error Code Directory](../competitors/elevar.md#key-screens) — the reference. Per-destination accuracy %, event-level delivery feed, error-code directory with plain-English remediations. Our Tracking Health tab is this, translated to the six-source frame.
- [Rockerbox De-duplicated vs. Platform-reported toggle](../competitors/rockerbox.md) — the closest two-source analog to our six-source disagreement UI. Relevant for how Tracking Health exposes the "platform says vs. we delivered" gap.
- [Shopify Native Integrations surfacing](../competitors/shopify-native.md) — the zero-price baseline. Shopify admin surfaces connected apps at the org level but doesn't expose per-destination accuracy — that's exactly the gap this page fills.
- [Triple Whale Integrations tab + Triple Pixel setup](../competitors/_teardown_triple-whale.md) — connector grid layout; we borrow the card pattern but strip the upsell chrome.
- [Klaviyo brand-asset auto-extraction on connect](../competitors/klaviyo.md) — the "+ Add integration" modal prefills workspace name / currency / primary country from Shopify OAuth metadata on first Shopify connect ([_crosscut_onboarding_ux.md](../competitors/_crosscut_onboarding_ux.md)).
- [Lifetimely sync-progress copy](../competitors/lifetimely.md) — `Importing 4,200 of 18,400 orders (23%) — est. 8 minutes`. Verbatim.
- [Northbeam day-30/60/90 phased unlock](../competitors/northbeam.md) — the phased-unlock panel framing. Reframes the empty data problem as calibration progress.
- [Linear / Vercel Entity primitive](../competitors/_inspiration_vercel.md) — the connector card is an Entity instance, not a bespoke shape.
- [_crosscut_onboarding_ux.md](../competitors/_crosscut_onboarding_ux.md) — the connect flow UX this page extends post-onboarding.

## Mobile tier

**Mobile-usable** (works from 768×1024 up, [UX §8](../UX.md#8-responsive-stance)).

- Tabs become a horizontal scroll strip.
- Connector card grid stacks 1-col.
- Tracking Health `KpiGrid` stacks 1-col; the Error Code Directory `DataTable` collapses to card-stack rows (each row = code + destination + count + action button).
- Historical imports keep progress donuts; archive table becomes card-stack.
- Channel mapping tab is editable on mobile but drag-handles are replaced by up/down arrows.
- Every `AlertBanner` remains full-width, non-dismissable where status requires.

## Out of scope v1

- **Custom connectors / webhook authoring UI.** Users can't define an arbitrary outbound destination (Elevar's full-fan-out model is a different product — see [elevar.md § What to avoid: "Don't position as an analytics product if you're plumbing"](../competitors/elevar.md#what-to-avoid)). We stay a dashboard; connectors are fixed to the five MVP set.
- **Server-side pixel installation UI** — Shopify Web Pixel + Woo plugin auto-install at onboarding ([_crosscut_onboarding_ux.md Triple Whale zero-step pixel](../competitors/_crosscut_onboarding_ux.md) pattern). Reinstall / reconfig from this page is v2.
- **Boosted Events / Session Enrichment / Identity Graph** ([elevar.md](../competitors/elevar.md) flagship features) — we don't own the pipe; those are out of scope for the foreseeable future.
- **Full event-payload inspector** with a live-editing sandbox — read-only JSON view is v1; editable replay is v2.
- **Accuracy-threshold alert rules** (Elevar pattern: `notify me if Meta CAPI < 95%`) — lands under Settings → Notifications v2, not here.
- **Data residency / region picker** — not a v1 concern; EU hosting is a separate plan conversation.
- **Slack / Teams channel routing** of integration alerts — piggy-backs on the on-demand Slack share in v1; scheduled integration alerting is v2 per [_crosscut_export_sharing_ux.md](../competitors/_crosscut_export_sharing_ux.md) stance.
- **AI-generated remediation suggestions** ("Here's how to fix error #100 for Meta") — the static error-code catalog ships first; AI remediation is v2 and requires the error-code directory as training ground truth.
