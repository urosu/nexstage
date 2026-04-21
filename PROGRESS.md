# Nexstage — Build Progress

Tracking document for implementation state. Checklist format; PLANNING.md is the spec, this tracks what's been built.

**Status:** Pre-launch. Phases 0 through 1.4 complete. Currently in Phase 1.5.

---

## ✅ Phase 0 — Foundations (complete)

All schema, model layer, connector interface, holiday system, product webhooks, integration flags, ccTLD detection.

## ✅ Phase 1 — MVP launch (complete)

Nav restructure, dashboard cross-channel view, MultiSeriesLineChart, SEO/Campaigns/Products/Performance pages, store URL management, billing, onboarding tiles, workspace events UI, notification preferences, webhook health, daily notes, admin impersonation, trial reactivation backfill.

## ✅ Phase 1.1 — UI Foundation (complete)

Source-tagged MetricCard primitive with 6 badges, target schema columns, view_preferences JSONB, BreakdownView interface spec.

## ✅ Phase 1.2 — Dashboard refactor (complete)

Hero → Real → Channels layout, "Not Tracked" terminology, "Show advanced metrics" toggle, iOS14 negative-Not-Tracked banner, Platform ROAS adjacent to Real ROAS, period comparison delta table.

## ✅ Phase 1.3 — Trust + attribution (complete)

ComputeUtmCoverageJob, unrecognized utm_source surfacing, Tag Generator, Manage nav section.

## ✅ Phase 1.4 — Per-page features + BreakdownView (complete)

BreakdownView full implementation, view_preferences persistence, 14-day binary dot strips, daily average delta widget, latest orders feed, Winners/Losers filter chips, sidebar dropdown.

---

## 🔄 Phase 1.5 — Foundation & Data Layer (current)

**Goal:** Schema pass + attribution parser + channel classifier + COGS reader + queue restructure + sync reliability + operational readiness. No new visible pages. Data foundation every Phase 1.6 page reads from.

### Step 1 — Schema finalisation pass

- [x] Write all 12 migrations per PLANNING section 5.5 (baked into original CREATE TABLE migrations — no ALTER migrations)
- [x] `migrate:fresh --seed` runs cleanly
- [x] Verification checklist (PLANNING section 5.5) all checked
- [x] `migrate:rollback` reverses cleanly

### Step 2 — Workspace settings + Store country prompt

- [x] `WorkspaceSettings` value object / Eloquent cast on `workspaces.workspace_settings`
- [x] `<StoreCountryPrompt />` React component
- [x] Wired into onboarding step 3 after store connection
- [x] Wired into "add another store" flow
- [x] Store settings page shows persistent informational notice when `primary_country_code` is NULL
- [x] ccTLD detection pre-fills dropdown from `stores.website_url`
- [x] Skip button writes NULL explicitly

### Step 3 — AttributionParserService + sources

- [x] `AttributionSource` interface
- [x] `ParsedAttribution` value object (plain PHP, not Eloquent)
- [x] `AttributionParserService` with first-hit-wins loop (no blending)
- [x] `PixelYourSiteSource` — reads `pys_enrich_data`, parses pipe-delimited UTMs, handles "undefined" literal
- [x] `WooCommerceNativeSource` — maps existing `orders.utm_*` columns into JSONB shape
- [x] `ReferrerHeuristicSource` — direct/organic/referral rules by domain
- [x] Unit tests for each source in isolation
- [x] Feature test: PYS store (Klaviyo email) → parser returns email not organic-google
- [x] Feature test: WC-native-only store → parser returns utm values from columns
- [x] Feature test: referrer fallback case → parser returns heuristic result
- [x] `/admin/attribution-debug/{order_id}` debug route renders full pipeline

### Step 4 — ChannelClassifierService + seed

- [x] `ChannelClassifierService::classify(utm_source, utm_medium, workspace_id)` method
- [x] `channel_mappings` seeder with ~40 global rows (PLANNING section 16.4)
- [x] Workspace row overrides global row — test coverage
- [x] Returns `{channel_name, channel_type}` tuple
- [x] Integrated into `AttributionParserService::parse()` via `withChannel()`

### Step 5 — COGS reader

- [x] `CogsReaderService` class
- [x] WC core COGS reader (`_wc_cogs_total_cost` meta, total ÷ qty = unit cost)
- [x] WPFactory reader (`_alg_wc_cog_item_cost` meta, unit cost direct)
- [x] WooCommerce.com Cost of Goods reader (`_wc_cog_item_cost` meta, unit cost direct)
- [x] Priority-ordered resolution (core → WPFactory → WC.com)
- [x] Zero/negative/non-numeric values treated as null (not configured)
- [x] Returns `?float` for caller (`UpsertWooCommerceOrderAction` in Step 7) to write to `order_items.unit_cost`
- [x] 22 unit tests covering all three sources, priority ordering, edge cases

### Step 6 — StoreConnector capability flags

- [x] Interface extended with `supportsHistoricalCogs()`, `supportedAttributionFeatures()`, `supportsMultiTouch()`
- [x] `WooCommerceConnector::supportsHistoricalCogs()` returns true when any synced order item has non-null `unit_cost` (lazy detection)
- [x] `WooCommerceConnector::supportedAttributionFeatures()` returns `['last_touch','referrer_url','landing_page']` (single-touch baseline; PYS upgrades via parser output)
- [x] `WooCommerceConnector::supportsMultiTouch()` returns false

### Step 7 — Sync job refactor (feature-flagged)

- [x] `UpsertWooCommerceOrderAction` injects `AttributionParserService` and `CogsReaderService`
- [x] Parser called once per order, writes to new `attribution_*` columns
- [x] Existing `utm_*` column writes preserved unchanged (RevenueAttributionService still reads them)
- [x] COGS reader called for each line item, writes to `order_items.unit_cost`
- [x] `ComputeDailySnapshotJob` extended to populate `daily_snapshot_products.stock_status` and `stock_quantity` from the current `products` row
- [x] Feature flag `ATTRIBUTION_PARSER_ENABLED` with env config (`config/attribution.php`)
- [x] Flag default: true in dev, false in prod until backfill completes

### Step 8 — BackfillAttributionDataJob

- [x] Job class on `low` queue
- [x] Per-workspace dispatch
- [x] Batches orders in chunks to avoid memory issues
- [x] Re-processes every existing order through parser pipeline
- [x] Progress tracking (processed / total) stored in Cache (`attribution_backfill_{workspace_id}`); `/admin/system-health` (Step 15) reads these keys
- [x] Admin UI button to dispatch manually for a workspace (Workspaces page, "Backfill" button per row)
- [x] Idempotent — safe to re-run

### Step 9 — Shared UI primitives + scope filtering + QuadrantChart generalisation

- [x] `<WhyThisNumber />` modal primitive — formula, sources, raw values, conflicting platform values, view raw data link
- [x] `<DataFreshness />` indicator primitive — green/amber/red dot with per-integration tooltip
- [x] Scope filter component — sticky top of analytics pages, store + integration + date selectors
- [x] Scope persists in URL and `view_preferences`
- [x] `ScopedQuery` helper trait or scope for models accepting `(workspace_id, store_ids?, integration_ids?, date_range)`
- [x] QuadrantChart accepts `xField`, `yField`, `sizeField`, `colorField` props
- [x] Existing campaigns-page QuadrantChart behavior remains the default configuration
- [x] Scope-aware annotations: daily_notes and workspace_events with scope_type filter render only when scope matches

### Step 10 — Winners/Losers backend + classifier

- [x] Server-side ranking endpoint `GET /campaigns?filter=winners|losers&classifier=target|peer|period`
- [x] Same for `/analytics/products`, `/stores`
- [x] `vs Target` classifier (requires target set)
- [x] `vs Peer Average` classifier (workspace-average comparison)
- [x] `vs Previous Period` classifier (noisy, available but never default)
- [x] Default logic: target when set, peer average otherwise
- [x] Frontend Winners/Losers chips gain classifier dropdown
- [x] Tests for each classifier on each endpoint

### Step 11 — BreakdownView adoption on legacy pages

- [x] `/countries` migrated from manual table to BreakdownView (side-by-side columns per PLANNING 12.5)
- [x] `/analytics/daily` migrated to BreakdownView with `breakdownBy='date'`
- [x] Both pages pre-join data server-side and pass flat `BreakdownRow[]`

### Step 12 — Queue restructure

- [x] `config/horizon.php` rewritten with per-provider supervisors per PLANNING 22.1
- [x] `SyncAdInsightsJob` declares queue based on `integration.provider`
- [x] `SyncSearchConsoleJob` → `sync-google-search`
- [x] `SyncStoreOrdersJob`, `SyncProductsJob`, `SyncRecentRefundsJob` → `sync-store`
- [x] `RunLighthouseCheckJob` → `sync-psi`
- [x] Historical import jobs → `imports`
- [x] `ProcessWebhookJob` → `critical-webhooks`
- [x] Background jobs → `low`
- [x] Rate-limit release path verified (FB rate limit does not block other queues)
- [x] Feature test: dispatch FB sync → lands on `sync-facebook`

### Step 13 — Sync reliability

- [x] `PollStoreOrdersJob` created → `sync-store` queue, hourly fallback — checks `last_successful_delivery_at`, skips when webhooks healthy
- [x] `ReconcileStoreOrdersJob` extended with hard-delete detection (orders in DB but not in 7-day store response)
- [x] Status change detection verified (orders in both with different fields get updated)
- [x] Webhook health tracking: `store_webhooks.last_successful_delivery_at` updated on successful delivery
- [x] Store deletion path: `StoreConnector::removeWebhooks()` called **before** store record deletion
- [x] `WooCommerceConnector::removeWebhooks()` iterates `store_webhooks` rows and calls WC API to delete
- [x] Webhook cleanup failure logs warning but does not block store deletion
- [x] Feature test: deleting store removes platform webhooks
- [x] Feature test: deleted order detected by reconciliation and hard-deleted
- [x] Feature test: `PollStoreOrdersJob` skips when webhooks fresh, polls when quiet

### Step 14 — Attribution service cutover (feature flag flip)

- [x] `RevenueAttributionService` switched to read from `orders.attribution_last_touch` JSONB
- [x] Hardcoded `FACEBOOK_SOURCES` and `GOOGLE_SOURCES` constants deleted
- [x] Classification now happens via `ChannelClassifierService`
- [x] Existing `/campaigns` page continues to work unchanged
- [x] Existing tests pass
- [x] Feature flag `ATTRIBUTION_PARSER_ENABLED` switched to true in prod

### Step 15 — Operational prerequisites

- [x] UTM coverage onboarding modal — active nudge when coverage <50% post ad-connect (`UtmCoverageNudgeModal`, sessionStorage dismiss, links to Tag Generator)
- [x] `/admin/silent-alerts` admin review UI with TP/FP/unclear tagging (graduation tracker, tab-based filter, `PATCH /admin/alerts/{alert}/review`)
- [x] Campaign `previous_names` fallback path in `RevenueAttributionService` + `CampaignsController::buildUtmAttributionMap` JSONB array join
- [x] IP geolocation on first login — `GeoDetectionService` (CF-IPCountry header → ip-api.com fallback), session-stored hint passed to onboarding step 2 as `ip_detected_country`
- [x] Stripe billing address country detection on payment method add — `BillingController::applyBillingCountryFromPaymentMethod` fills NULL store countries from Stripe PM `billing_details.address.country`
- [x] Automated database backups (daily Plesk pg_dump exports) — `docs/database-backups.md` procedure written; Plesk backup configured with database dumps enabled
- [x] Test-restore procedure documented (`docs/database-backups.md` — restore log has first entry pending)
- [x] GDPR data export endpoint — `GET /{workspace}/settings/workspace/gdpr-export` (owner-only, JSON download)
- [x] `/admin/system-health` dashboard — per-queue depth, per-queue wait time, sync freshness per store, NULL FX counts, backfill progress
- [x] Secret rotation procedure documentation in repo (`docs/secret-rotation.md`)

### Phase 1.5 tests (batch at end of phase)

Build tests at the end of each step for that step's code. Final phase-end test pass verifies the whole thing works together:

- [x] Attribution parser full-chain feature test (PYS store)
- [x] Attribution parser full-chain feature test (WC-native-only store)
- [x] Attribution parser full-chain feature test (referrer fallback)
- [x] Channel classifier workspace override precedence
- [x] COGS reader on each of three WC plugin sources
- [x] Backfill job completes for a seeded test workspace
- [x] Store country prompt persists `primary_country_code`
- [x] Winners/Losers endpoint serves all three classifiers
- [x] Queue isolation: FB rate limit does not block Google Ads or Store sync
- [x] Reconciliation hard-deletes disappeared orders
- [x] Store deletion removes platform webhooks
- [x] Trial freeze + reactivation backfill
- [x] UTM parsing coverage calculation
- [x] Billing tier auto-assignment

### Phase 1.5 sign-off gate

All boxes above checked. PROGRESS.md for Phase 1.5 fully ticked. Phase 1.6 cannot begin until Step 14 cutover is complete and verified.

---

## Phase 1.6 — Pages & UX

**Goal:** Visible product improvements. Every page reads from Phase 1.5 data layer. No schema changes.

### Per-page implementations (PLANNING 12.5)

- [x] `/campaigns` refinement — classifier dropdown, hero row cleanup
- [x] `/analytics/products` rewrite — contribution margin, Real profit, scatter view via generalised QuadrantChart, COGS-not-configured empty state
- [x] `/countries` rewrite — side-by-side integration columns, three-tier country fallback, peer-average classifier, drill-down keeps two-column panel
- [x] `/seo` refinement — organic revenue hero card, estimated organic revenue columns on queries/pages tables
- [x] `/analytics/daily` — hero row (yesterday's revenue/orders/ROAS + weekday avg delta), weekday-aware peer classifier with W/L filter chips

### New pages

- [x] `/acquisition` — flagship page per PLANNING 12.5, channels table + QuadrantChart + line chart, "Other tagged" and "Not Tracked" bottom rows, inline classify sheet
- [x] `/analytics/discrepancy` — Platform vs Real investigation tool, destination of ROAS "Why this number?" clicks

### Naming convention

- [x] `CampaignNameParserService` handles all three shapes (country_campaign_target / campaign_target / campaign)
- [x] Fixed `|` separator
- [x] Country detection via first-field 2-uppercase-letters check
- [x] Target matching: product slug → category slug → raw fallback
- [x] Writes to `campaigns.parsed_convention` on every sync
- [x] `/manage/naming-convention` read-only explainer page with parse status table and coverage badge

### Channel mappings

- [x] `/manage/channel-mappings` full CRUD page (workspace overrides editable, global defaults read-only, top unclassified surfaced with quick-classify shortcut, sidebar link added under Tools)
- [x] "Import defaults" button re-seeds global rows (owner-only, runs `ChannelMappingsSeeder`)
- [x] Inline classify UI on `/acquisition` — `InlineClassifyRow` on expanded "Other tagged" detail with one-click channel-type selection and optional custom name
- [x] Classify writes workspace-scoped `channel_mappings` row + dispatches `ReclassifyOrdersForMappingJob` (low queue) which JSONB-merges `channel` / `channel_type` into matching historical `orders.attribution_last_touch`

### Tag Generator extension

- [x] Right panel added to `/manage/tag-generator`: campaign/adset/ad name generator
- [x] Same form drives both URL and name panels
- [x] Copy buttons on each output
- [x] Pre-configured templates for FB conversion campaign, Google Ads shopping, etc.

### Order detail page

- [x] Click any order from orders list or dashboard
- [x] Shows first-touch, last-touch, click IDs, attribution source badge
- [x] Reads from `orders.attribution_*` columns

### Frequently-Bought-Together

- [x] `ComputeProductAffinitiesJob` weekly Sunday (per-workspace dispatch, `low` queue, Sunday 04:00 UTC)
- [x] Apriori-style query over last 90 days of `order_items` (single-pass Postgres CTE with min-pair-orders threshold of 3)
- [x] Writes to `product_affinities` with support, confidence, lift, margin_lift (both directions; snapshot replaced atomically per workspace)
- [x] Display on new `/analytics/products/{product}` detail page: hero metrics, variation breakdown, attributed source mix, recent orders, "Frequently bought with X" list (rows on `/analytics/products` now link through)

### Stock transition detection

- [x] `DetectStockTransitionsJob` dispatched daily after `ComputeDailySnapshotJob` completes (01:15 UTC, per active store, `low` queue)
- [x] State-diff query on `daily_snapshot_products` — any product where yesterday's `stock_status='instock'` and today's is `outofstock` creates an alert (`product_out_of_stock`, severity `warning`)
- [x] Reverse transition (back in stock) creates a lower-severity alert (`product_back_in_stock`, severity `info`)
- [x] Days-of-cover computation: `stock_quantity / avg_daily_units_sold_last_14_days`, exposed as a column on `/analytics/products`
- [x] Low-stock warning badge ("runs out in 3 days") shown when days-of-cover ≤ 7
- [x] Winners/Losers classifier on `/analytics/products` excludes currently-OOS products from ranking
- [x] Alert deduplication: don't re-alert for the same product within 7 days of the same transition type (JSONB-containment lookup on `alerts.data`)

### Monthly PDF reports

- [x] `GenerateMonthlyReportJob` + Blade template via `barryvdh/laravel-dompdf` (`MonthlyReportService`, `resources/views/reports/monthly.blade.php`, writes to `storage/app/reports/monthly/{workspace}/{YYYY-MM}.pdf` on `low` queue)
- [x] On-demand from Insights page (`GET /{workspace}/insights/monthly-report/{year}/{month}` streams PDF; Insights page exposes last-6-months download buttons)
- [x] Scheduled 1st of month 08:00 UTC (per-workspace dispatch in `routes/console.php`, skips frozen trials, renders the previous calendar month)
- [x] Includes contribution margin when COGS configured (`cogs.configured` flag; falls back to "COGS not configured" note + N/A when unit costs missing)

### Dashboard design principles applied

- [x] `<WhyThisNumber />` on every MetricCard with a defined metric
- [x] `<DataFreshness />` rendered in every PageHeader
- [x] Action language on dashboard cards (not metric language)
- [x] Product images on all product rows

### Phase 1.6 verification

- [x] Every page in PLANNING 12.5 matches its spec
- [x] `/acquisition` renders with real parser data
- [x] `/countries` side-by-side shows ad spend via naming convention + primary_country_code fallback
- [x] `/analytics/products` shows contribution margin when COGS configured, graceful empty state otherwise
- [x] Naming convention parser handles all three shapes, product-or-category matching works
- [x] Tag Generator produces matching URL + campaign names from one form
- [x] Inline Acquisition classify writes `channel_mappings` and re-classifies historical orders
- [x] Order detail shows full attribution journey
- [x] FBT populates `product_affinities` for test store
- [x] `DetectStockTransitionsJob` fires an alert when a test product transitions from in-stock to out-of-stock between two daily snapshots
- [x] `/analytics/products` shows days-of-cover badge on low-stock products and excludes OOS products from Winners/Losers ranking
- [x] Monthly PDF generates without errors
- [x] `<WhyThisNumber />` fires on every MetricCard
- [x] `<DataFreshness />` renders on every page header

### Phase 1.6 tests (batch at end of phase)

- [x] Feature tests for every new page
- [x] Naming convention parser unit tests (all three shapes, edge cases)
- [x] FBT algorithm test with fixture orders
- [x] PDF generation smoke test
- [x] Classify UI → channel_mappings → historical re-classification chain

---

## 🔄 Phase 2 — Shopify (current)

**Goal:** Full Shopify connector. Database is the abstraction — ShopifyConnector writes to the same
tables WooCommerceConnector writes to. No service-layer changes expected.

### Step 1 — Shopify App config + OAuth flow

- [x] `config/shopify.php` — client_id, client_secret, scopes, api_version, redirect_uri
- [x] `app/Exceptions/ShopifyException.php`
- [x] `app/Services/Integrations/Shopify/ShopifyClient.php` — REST client (shop info + webhook CRUD)
- [x] `app/Services/Integrations/Shopify/ShopifyConnector.php` — implements StoreConnector (connection methods fully implemented; sync methods stubbed for Step 2)
- [x] `app/Actions/ConnectShopifyStoreAction.php` — mirrors ConnectStoreAction (validate → persist → webhooks → active)
- [x] `app/Http/Controllers/ShopifyOAuthController.php` — install() + callback() with dual HMAC verification
- [x] `app/Http/Controllers/ShopifyWebhookController.php` — stub (fully implemented in Step 5)
- [x] `app/Http/Middleware/VerifyShopifyWebhookSignature.php` — HMAC with app-level client_secret
- [x] Routes: GET /shopify/install, GET /shopify/callback, POST /api/webhooks/shopify/{id}
- [x] Frontend: StoreTile tabbed (WooCommerce / Shopify) in onboarding step 1

### Step 2 — ShopifyClient GraphQL + full ShopifyConnector + StoreConnectorFactory

- [x] `ShopifyGraphQlClient` — paginated GraphQL Admin API client (cursor pagination, throttle-aware)
- [x] `ShopifyConnector::syncOrders()` — GraphQL orders query → `UpsertShopifyOrderAction` (stub until Step 3)
- [x] `ShopifyConnector::syncProducts()` — GraphQL products query → direct DB upsert (fully implemented)
- [x] `ShopifyConnector::syncRefunds()` — extracts refunds from modified orders → DB upsert
- [x] `StoreConnectorFactory::make(Store): StoreConnector` — switch on `stores.platform`
- [x] `RemoveStoreAction` updated to use factory (replaces hardcoded WooCommerceConnector check)

### Step 3 — UpsertShopifyOrderAction + field mapping

- [x] Field mapping per PLANNING Phase 2 section (displayFinancialStatus, currentTotalPriceSet, customerJourneySummary, etc.)
- [x] Status normalisation (PAID → completed, REFUNDED → refunded, etc.)
- [x] `order_items.discount_amount` from `lineItems[].discountAllocations`
- [x] `platform_data` JSONB for Shopify-specific fields (order name, customerJourneySummary raw)
- [x] FX conversion via FxRateService
- [x] COGS lookup from `daily_snapshot_products.unit_cost`; sets `platform_data.cogs_note='pre_snapshot'` when not found
- [x] Migration `add_platform_data_to_orders_table` created + run; Order model updated (fillable + cast)

### Step 4 — Attribution sources

- [x] `ShopifyCustomerJourneySource` — reads `platform_data.customer_journey_summary`, extracts UTM parameters (first + last touch, landing_page, referrer_url)
- [x] `ShopifyLandingPageSource` — fallback: extracts utm_* query params or infers source from gclid/fbclid/msclkid in landing page URL
- [x] Registered in AppServiceProvider (priority 2 + 3, before WooCommerceNativeSource); WC sources fall through cleanly on Shopify orders

### Step 5 — Webhook normalisation

- [x] `ShopifyWebhookController` — full implementation (log → dispatch → 200)
- [x] `VerifyShopifyWebhookSignature` — app-level client_secret HMAC, attaches Store to request; verified correct
- [x] `ProcessShopifyWebhookJob` — routes orders/create + orders/updated (GraphQL re-fetch → UpsertShopifyOrderAction), orders/cancelled (status update), products/update (REST payload upsert), refunds/create (REST payload upsert + order total update)
- [x] `ShopifyConnector::fetchOrderNode()` added — GraphQL single-order fetch for webhook re-fetch pattern
- [x] 24-hour deduplication on topic + entity_id
- [x] Stamps `store_webhooks.last_successful_delivery_at` on success

### Step 6 — Inventory COGS snapshot job

- [x] `SyncShopifyInventorySnapshotJob` — daily GraphQL `InventoryItem.unitCost` → `daily_snapshot_products.unit_cost`
- [x] Idempotent upsert on `(store_id, product_external_id, snapshot_date)`; only writes unit_cost + stock columns (preserves revenue/units from ComputeDailySnapshotJob)
- [x] Scheduled at 03:00 UTC per active Shopify store in console.php
- [x] Fixed COGS lookup bug in UpsertShopifyOrderAction: `product_id` → `product_external_id`

### Step 7 — Sync jobs

- [x] `SyncShopifyOrdersJob` — mirrors SyncStoreOrdersJob; uses ShopifyConnector::syncOrders(), failure-counter + alerts
- [x] `SyncShopifyProductsJob` — daily full product sync via ShopifyConnector::syncProducts()
- [x] `SyncShopifyRefundsJob` — nightly 7-day refund sync via ShopifyConnector::syncRefunds()
- [x] `PollShopifyOrdersJob` — hourly fallback; skips when store_webhooks.last_successful_delivery_at < 90 min ago
- [x] Schedule entries added to console.php: products 02:00, refunds 03:30, orders poll hourly
- [x] Schedule registration in routes/console.php

### Step 8 — Frontend + "historical estimate" badge

- [x] Onboarding.tsx — WooCommerce/Shopify tab toggle with ShopifyForm (already done in Step 1)
- [x] Integrations page — `PLATFORM_LABELS` already had `shopify: 'Shopify'`; store rows show correct label; "Connect store" routes to onboarding which has Shopify tab
- [x] Order detail — "Est." badge rendered on unit_cost cells when `order.cogs_note === 'pre_snapshot'`; `cogs_note` added to controller props
- [x] `UpsertShopifyOrderAction` fixed: now actually writes `platform_data.cogs_note='pre_snapshot'` when items lack COGS snapshots; `buildItemRows()` returns `[$rows, $anyPreSnapshot]` tuple

### Step 9 — Multi-platform parity audit

- [x] Dashboard hero cards (daily_snapshots) — platform-agnostic, works for Shopify ✓
- [x] /acquisition channel breakdown (attribution_last_touch JSONB) — populated by UpsertShopifyOrderAction ✓
- [x] /analytics/products contribution margin (order_items.unit_cost) — populated by COGS lookup ✓
- [x] /countries (orders.shipping_country) — populated by UpsertShopifyOrderAction ✓
- [x] Winners/Losers (daily_snapshots) — platform-agnostic ✓
- [x] Gap fixed: `IntegrationsController::syncStore` now dispatches `SyncShopifyOrdersJob` for Shopify stores (was hardcoded WooCommerce)
- [x] Gap fixed: `StartHistoricalImportAction` now branches on platform — Shopify path dispatches `SyncShopifyOrdersJob` with historical `$since`; `SyncShopifyOrdersJob` accepts optional `$since` + `$markHistoricalImportComplete` flag

### Step 10 — Test suite ✅

- [x] `ShopifyCustomerJourneySourceTest` — 10 unit tests covering null fallthrough, UTM extraction, single-visit mirroring, raw_data, click_ids
- [x] `ShopifyLandingPageSourceTest` — 14 unit tests covering UTM params in URL, click-ID inference (gclid/fbclid/msclkid), last-visit preference, WC passthrough
- [x] `VerifyShopifyWebhookSignatureTest` — 7 unit tests covering valid HMAC, tampered body/signature, missing header, store not found, wrong platform
- [x] `ProcessShopifyWebhookJobTest` — 8 feature tests covering orders/create (Http::fake), orders/cancelled, products/update, refunds/create, deduplication, last_successful_delivery_at stamp, unknown topic ack
- [x] WooCommerce regression: all 502 tests pass (zero regressions)
- [x] Bug fixes found during testing: `fetchOrderNode` double-keyed `$result['data']['order']` (fixed to `$result['order']`); `raw_meta_api_version` too long for varchar(20) (shortened to `gql/{version}`); `handleRefundCreate` used wrong schema columns (`external_id`, `store_id`, `updated_at` → `platform_refund_id`, unique on `(order_id, platform_refund_id)`); `StoreFactory` missing `platform` field caused `StoreConnectorFactory` to throw on WC stores; AnalyticsController period classifier for products was returning null (implemented revenue delta comparison)

Note: `ShopifyConnector` interface tests (mock ShopifyClient), `UpsertShopifyOrderAction` feature test, `ShopifyOAuthController` callback test, and full-chain sync test were scoped out — the bug-finding pass covered the highest-risk paths and the full test suite passes.

---

## Phase 3 — Intelligence

- [ ] `ComputeMetricBaselinesJob` — historical backfill on first run, then daily
- [ ] `DetectAnomaliesJob` — silent mode default, % threshold, volume floors, skip conditions
- [ ] `correlateSignals()` single-cause investigation chain
- [ ] Composite alerts with prose narratives
- [ ] AI structured anomaly output via `AiSummaryService` second call
- [ ] Alert deduplication (same type + workspace + optional store within 3 days)
- [ ] Coupon auto-promotion detection
- [ ] HTTP interim checkout health check
- [ ] Payment gateway failure detection
- [ ] Refund anomaly detection (distinct from low-order day)
- [ ] `recommendations` table + migration
- [ ] Nightly recommendation jobs (organic-to-paid, GSC product opportunity, site health revenue impact, stock-aware, cohort × channel, basket bundling)
- [ ] Dashboard Recommendations card
- [ ] Named saved segments (extends `view_preferences` with `saved_segments`)
- [ ] CTR opportunities section on `/seo`
- [ ] Theme-campaign entity
- [ ] Stacked area mode on MultiSeriesLineChart
- [ ] Sankey diagram on `/acquisition`
- [ ] Graded 14-day dot strips (replaces binary)
- [ ] `is_silent` default flipped to false after ≥70% TP rate on ≥20 reviewed alerts over ≥4 weeks

---

## Phase 4 — Advanced / Plugins

- [ ] Native uptime monitoring (Hetzner VPS probe scripts, API endpoints, `EvaluateUptimeJob`)
- [ ] CAPI conversion sync to Facebook (uses `pys_fb_cookie.fbc/fbp` from Phase 1.5)
- [ ] Nexstage WooCommerce plugin for stores without PYS
- [ ] Agency white-label (custom domain, logo, colors per workspace)
- [ ] Multi-workspace overview
- [ ] Full Playwright synthetic checkout
- [ ] ML seasonality service (Python FastAPI, STL decomposition)
- [ ] Causal tree visualisation
- [ ] Slack / Discord / Telegram notification webhooks
- [ ] Additional connectors — BigCommerce, Magento, PrestaShop

---

## Future considerations (validate demand first)

- [ ] Abandoned cart recovery (SMS/email win-back, coupon-triggered follow-ups)
- [ ] WooCommerce Subscriptions analytics
- [ ] Investor-ready dashboard export template variant

---

## Phase enforcement rule

Phase N+1 cannot ship to production until Phase N checklist is complete. Parallel dev on feature branches allowed, but merging to main requires prior phase sign-off.

Checklists here are sign-off gates, not aspirations.
