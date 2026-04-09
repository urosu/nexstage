# Nexstage — Build Progress

> **Check this file FIRST in every new session.** Update it as you complete work. Mark items done with `[x]` and date. Mark in-progress items with notes on what's left.

## Current phase: Phase 0 — Foundations (schema + data capture)

### Status: Not started

---

## Phase 0 Tasks

### Migrations — Complete rewrite
> All migrations rewritten from scratch. `php artisan migrate:fresh --seed` must pass cleanly.
> See: PLANNING.md "Database Schema — Complete Redesign"

**Existing tables to modify:**
- [ ] `workspaces` — add: has_store, has_ads, has_gsc, has_psi, country, region, timezone. Update billing_plan CHECK to ('starter','growth','scale','enterprise')
- [ ] `stores` — add: website_url (main store domain, used for TLD country detection + PSI homepage). Drop: platform_webhook_ids JSONB
- [ ] `orders` — add: payment_method, payment_method_title, shipping_country, refund_amount (DEFAULT 0), last_refunded_at, customer_id (per-store, composite INDEX with store_id), utm_term, raw_meta JSONB + raw_meta_api_version
- [ ] `order_items` — drop: workspace_id, store_id (derived via order_id)
- [ ] `products` — add: stock_status, stock_quantity, product_type
- [ ] `campaigns` — add: daily_budget, lifetime_budget, budget_type, bid_strategy, target_value
- [ ] `ads` — add: effective_status, creative_data JSONB + creative_data_api_version
- [ ] `ad_insights` — add: frequency, platform_conversions, platform_conversions_value, search_impression_share, raw_insights JSONB + raw_insights_api_version. Add CHECK constraint for level/FK integrity
- [ ] `gsc_daily_stats` — add: device (NOT NULL DEFAULT 'all'), country (NOT NULL DEFAULT 'ZZ'). Update unique constraint to include both
- [ ] `gsc_queries` — add: device, country. Update unique constraint
- [ ] `gsc_pages` — change page to TEXT + page_hash CHAR(64). Add device, country. Unique on (property_id, date, page_hash, device, country)
- [ ] `alerts` — add: source (CHECK), property_id FK, is_silent, review_status, reviewed_at, estimated_impact_low/high, gsc_conversion_rate_at_alert, store_aov_at_alert
- [ ] `daily_snapshots` — drop: top_products JSONB, revenue_by_country JSONB

**New tables:**
- [ ] `daily_snapshot_products` — normalized top products per store per day (replaces JSONB)
- [ ] `store_urls` — pages to monitor via PSI + uptime
- [ ] `lighthouse_snapshots` — PSI check results
- [ ] `uptime_checks` — partitioned by month, with probe_id. PK must include partition key (id, checked_at)
- [ ] `uptime_daily_summaries` — aggregated uptime
- [ ] `store_webhooks` — replaces stores.platform_webhook_ids JSONB
- [ ] `metric_baselines` — partial unique indexes for nullable store_id (two indexes: WHERE store_id IS NULL / IS NOT NULL)
- [ ] `holidays` — global reference table by country_code + date
- [ ] `workspace_events` — promotions/expected spikes only (NOT holidays, those are in holidays table)
- [ ] `order_coupons` — normalized coupon tracking per order
- [ ] `refunds` — individual refund events with platform_refund_id VARCHAR
- [ ] `product_categories` — category hierarchy with parent_external_id
- [ ] `product_category_product` — many-to-many pivot
- [ ] `google_ads_keywords` — Phase 3, but table created now
- [ ] `notification_preferences` — per-workspace-per-user alert config
- [ ] `coupon_exclusions` — auto-promotion detection exclusion list

**Verification:**
- [ ] `php artisan migrate:fresh --seed` passes
- [ ] All indexes, CHECK constraints, partial unique indexes, partitions correct
- [ ] Foreign keys with CASCADE in place

### StoreConnector interface
> See: PLANNING.md "StoreConnector Interface"
- [ ] Create `app/Contracts/StoreConnector.php` with interface
- [ ] Adapt existing WooCommerceClient to implement it
- [ ] Methods: testConnection, syncOrders, syncProducts, syncRefunds, registerWebhooks, removeWebhooks, getStoreInfo

### RevenueAttributionService
> See: PLANNING.md "Unattributed Revenue"
- [ ] Create `app/Services/RevenueAttributionService.php`
- [ ] UTM source matching: facebook -> ('facebook','fb','ig','instagram'); google -> ('google','cpc','google-ads','ppc')

### Holiday system
> See: PLANNING.md "holidays" table + "Population" section
- [ ] `composer require azuyalabs/yasumi`
- [ ] Create `app/Models/Holiday.php`
- [ ] Create `app/Jobs/RefreshHolidaysJob.php`
- [ ] Trigger on workspace creation if workspace.country has no holidays for current year

### Product webhooks
> See: PLANNING.md "Product webhooks"
- [ ] Update `ConnectStoreAction` to register `product.updated`
- [ ] Update `WooCommerceWebhookController` to handle product.updated (stock, price, status, categories)

### Sync job updates — new data capture
> See: PLANNING.md "Data Capture Strategy" + individual table schemas
- [ ] `SyncAdInsightsJob` — capture: frequency, platform_conversions, platform_conversions_value, search_impression_share, raw_insights JSONB with api_version
- [ ] Facebook client — request: frequency, creative data
- [ ] Google client — request: conversions, search_impression_share, budgets
- [ ] `SyncSearchConsoleJob` — add: device + country dimensions to queries
- [ ] `SyncProductsJob` — add: stock_status, stock_quantity, product_type, categories (product_categories + pivot)
- [ ] `SyncStoreOrdersJob` + `UpsertWooCommerceOrderAction` — add: payment_method, payment_method_title, shipping_country, customer_id, utm_term, raw_meta, coupon capture (order_coupons rows)
- [ ] Campaign sync (Facebook/Google jobs) — add: daily_budget, lifetime_budget, budget_type, bid_strategy, target_value
- [ ] Ads sync — add: effective_status, creative_data JSONB with api_version

### New sync jobs
- [ ] Create `app/Jobs/SyncRecentRefundsJob.php` — last 7 days, nightly
- [ ] Populate refunds table + update orders.refund_amount and last_refunded_at

### ComputeDailySnapshotJob update
> See: PLANNING.md "daily_snapshot_products"
- [ ] Write to daily_snapshot_products (top 50 per store per day by revenue)
- [ ] Remove writes to dropped JSONB columns (top_products, revenue_by_country)

### Controller updates
> Update controllers that read old JSONB columns
- [ ] Products page: read from daily_snapshot_products instead of daily_snapshots.top_products
- [ ] Countries page: query orders directly (indexed on workspace_id, shipping_country)
- [ ] Any other controller reading revenue_by_country or top_products JSONB

### order_items query audit (SECURITY)
> After dropping workspace_id/store_id from order_items, all direct OrderItem::where() queries bypass tenant isolation via WorkspaceScope. Every OrderItem query MUST go through the Order relationship.
- [ ] Grep codebase for `OrderItem::` and `order_items` direct queries
- [ ] Verify all go through `$order->items()` or join through orders table
- [ ] Add comment to OrderItem model explaining why no WorkspaceScope

### Workspace integration flags + country detection
> See: PLANNING.md "Onboarding Flow" country auto-detection
- [ ] Set has_store/has_ads/has_gsc/has_psi when integrations connect/disconnect
- [ ] Country auto-detection: ccTLD (for country-code domains only) -> IP geolocation -> Stripe billing address
- [ ] Override-able in workspace settings

### New Eloquent models
> All tenant models need WorkspaceScope. Add `// Related:` comments to each model pointing to the job/controller that reads/writes it.
> Exception: Holiday is a global reference table (no workspace_id, no WorkspaceScope).
- [ ] StoreUrl, LighthouseSnapshot, UptimeCheck, UptimeDailySummary
- [ ] DailySnapshotProduct, MetricBaseline, Holiday (global, no WorkspaceScope), WorkspaceEvent
- [ ] OrderCoupon, Refund, ProductCategory, GoogleAdsKeyword
- [ ] NotificationPreference, CouponExclusion, StoreWebhook

---

## Phase 0 Verification Checklist
> ALL must pass before starting Phase 1

- [ ] `php artisan migrate:fresh --seed` — clean, no errors
- [ ] Product webhook: trigger product.updated in Woo, confirm stock_status/categories updated
- [ ] Holidays: dispatch RefreshHolidaysJob for country=DE, confirm rows in holidays table
- [ ] Coupons: create order with coupon via Woo, confirm order_coupons row
- [ ] Refunds: issue partial refund, dispatch SyncRecentRefundsJob, confirm refunds row + orders.refund_amount
- [ ] GSC: dispatch sync, confirm device + country populated on gsc_daily_stats/gsc_queries/gsc_pages
- [ ] Ad insights: dispatch sync, confirm frequency, conversions, search_impression_share, raw_insights populated
- [ ] order_items: no direct queries bypass tenant isolation

---

## Phase 1: MVP Launch (reporting + data visibility)
> Do not start until Phase 0 verification passes. See PLANNING.md items 18-34.

- [ ] New nav structure (AppLayout.tsx, conditional sections by integration flags)
- [ ] Dashboard cross-channel view (priority-tier layout + day-1 empty-state with baseline progress indicator)
- [ ] MultiSeriesLineChart + event overlays (holidays table + workspace_events + daily_notes)
- [ ] SEO page: Unattributed Revenue cards
- [ ] Campaigns page: revenue context + spend velocity + ROAS tooltips + data accuracy help link
- [ ] Products page: trending deltas + stock status badges
- [ ] Performance page + PerformanceController + RunLighthouseCheckJob (PSI only, no uptime yet)
- [ ] Store settings: URL management UI
- [ ] Billing restructure: 3-tier (Starter/Growth/Scale) + ad-spend billing + trial logic + freeze
- [ ] Onboarding update: single-screen connection tiles + country auto-detect
- [ ] Workspace events: manual promotion UI + chart overlay markers
- [ ] Notification preferences UI
- [ ] Webhook health: ReconcileStoreOrdersJob + sync status display
- [ ] Daily notes: prominent input + chart annotations
- [ ] Admin workspace impersonation
- [ ] Data accuracy FAQ + contextual "why don't numbers match?" help links
- [ ] Trial reactivation backfill sync

---

## Phase 1.5: Reports
- [ ] GenerateMonthlyReportJob + Blade template + dompdf
- [ ] On-demand trigger from Insights page

---

## Phase 2: Intelligence
- [ ] ComputeMetricBaselinesJob (historical backfill on first run)
- [ ] DetectAnomaliesJob (silent mode) + all signal detectors
- [ ] correlateSignals() + composite alerts with narratives
- [ ] AI structured anomaly output + alert deduplication
- [ ] Coupon auto-promotion detection
- [ ] CTR Opportunities (SEO page)
- [ ] HTTP checkout health check
- [ ] Payment gateway failure detection
- [ ] Refund anomaly detection
- [ ] Uptime system (external probes + API endpoints + EvaluateUptimeJob)
- [ ] Flip is_silent default (after SILENT_MODE_GRADUATION criteria met)

---

## Completed Items

| Task | Date | Notes |
|------|------|-------|
| _(nothing yet)_ | | |
