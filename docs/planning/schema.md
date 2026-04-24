# Target schema — v1 MVP

Designed against [UX.md](../UX.md) + the ten page specs in [`/docs/pages/`](../pages/) + [`_crosscut_metric_dictionary.md`](../competitors/_crosscut_metric_dictionary.md). The current schema (per [`_codebase_audit_schema.md`](../_codebase_audit_schema.md)) is treated as advisory — each current table is annotated **KEEP / MODIFY / NEW / DROP**. Execution belongs in `MIGRATION.md`; this file is the target.

Connectors in v1 MVP: **WooCommerce · Shopify · Facebook Ads · Google Ads · Google Search Console**. No GA4. No Site pixel. Pricing: €39/mo base + 0.4% revenue share.

---

## 0. Guiding principles

Every table obeys all ten rules. Deviations are flagged per-table under **Why** or **Notes**.

1. **Tenancy by scope attribute.** Every tenant-scoped table has `workspace_id bigint NOT NULL` and its model applies `#[ScopedBy(WorkspaceScope::class)]`. `WorkspaceScope` throws if `WorkspaceContext` is not set — jobs must set context or filter manually (CLAUDE.md gotcha).
2. **Cascade from `workspaces`.** All tenant rows die when the workspace dies. FK: `ON DELETE CASCADE`.
3. **JSONB + paired `*_api_version`.** Every JSONB column holding external API data has a paired `*_api_version varchar(16)` column. Applies to `orders.raw_meta`, `refunds.raw_meta`, `ads.creative_data`, `ad_insights.raw_insights`, `lighthouse_snapshots.raw_response`, `webhook_logs.payload`, `integration_events.payload`. Does **not** apply to Nexstage-owned shapes (`attribution_*`, `parsed_convention`, `workspace_settings`, `store_cost_settings`).
4. **Aggregate through snapshots.** Page controllers read `daily_snapshots` / `hourly_snapshots` / `daily_snapshot_products` — never `SUM` raw `orders` on the request path. Enforced via lint rule on controllers (repo convention, not DB constraint).
5. **Never `SUM` across `ad_insights` levels.** All `ad_insights` queries filter `level IN ('campaign' | 'adset' | 'ad')` to a single value. CHECK constraint enforces FK integrity per level; query enforcement is application-layer.
6. **Divide-by-zero discipline.** Ratios never stored. `CPM`, `CPC`, `CPA`, `ROAS`, `CTR`, `CVR`, `AOV`, `MER`, `LTV:CAC` are computed on the fly with `NULLIF` in SQL, null-check in PHP, "N/A" in UI.
7. **FX at display, not ingest.** Currency columns store native value + a converted-at-ingest cache (`*_in_reporting_currency`). Cross-currency rollups happen via `FxRateService` (DB-first cache on `fx_rates`). Never call Frankfurter at query time.
8. **"Not Tracked" is computed, not stored.** `Total Revenue − Σ(Attributed Revenue after de-dup)`. Can go negative. Derived at query time from `orders.attribution_*` + `ad_insights`. No column stores this value.
9. **Attribution columns on `orders` are canonical.** `attribution_first_touch`, `attribution_last_touch`, `attribution_last_non_direct`, `attribution_click_ids`, `attribution_source_survey` (schema-ready only), `attribution_parsed_at`. Each touch JSONB shape: `{channel_type, source, medium, campaign, content, term, landing_page, credit_weight, platform, sample_type, timestamp}`.
10. **CHECK constraints over Postgres ENUM.** Enum-style string columns use `DB::statement` CHECK constraints (current pattern) — cheaper to alter than a native enum type. Every CHECK-bound column has its allowed values enumerated in this doc.
11. **Indexes serve page specs, not scale.** MVP: every `workspace_id` column is indexed (composite-leading is fine). Every hot page query path gets a composite index. Partial indexes are used for sparse states (`customer_id IS NOT NULL`, `is_first_for_customer = true`, `status IN (...)`). No pre-optimisation for scale we haven't hit.
12. **Seeded reference tables beat PHP constants.** `channel_mappings`, `fx_rates`, `holidays`, `google_algorithm_updates`, `tax_rules` (per-country defaults), `transaction_fee_defaults`, `creative_tag_categories`/`creative_tags`, `gsc_ctr_benchmarks` all seed at migration time.
13. **Raw commerce data is untouchable after ingest.** Historical restatement of orders/line items is never implicit. COGS changes don't retroactively restate `order_items.unit_cost` — a backfill job must be explicitly triggered (see `products.md` inline-edit flow).

---

## 1. Per-table reference

Tables grouped by bounded context. Every row under each section header lists **status · purpose · columns · indexes · FKs · JSONB · tenancy · why · migration delta**.

### 1.1 Core tenancy (users, workspaces, team)

#### `users`

- **Status:** KEEP.
- **Purpose:** Laravel auth account, global (no workspace).
- **Columns:**
  | Column | Type | Nullable | Default | Notes |
  |---|---|---|---|---|
  | `id` | bigserial PK | – | – | |
  | `name` | varchar(255) | no | – | |
  | `email` | varchar(255) unique | no | – | |
  | `email_verified_at` | timestamp | yes | null | |
  | `password` | varchar(255) | no | – | bcrypt |
  | `is_super_admin` | bool | no | false | |
  | `remember_token` | varchar(100) | yes | null | |
  | `last_login_at` | timestamp | yes | null | |
  | `view_preferences` | jsonb | no | `'{}'` | Nexstage-owned UI state per-user (sidebar collapsed, pinned metric IDs, saved view last-used, tab-last-active). No api_version. |
  | timestamps | – | – | – | standard |
- **Indexes:** unique `email`.
- **Foreign keys:** none.
- **Tenancy:** none. Global.
- **JSONB shapes:** `view_preferences` — `{sidebar_collapsed: bool, pinned_metrics: {[workspace_id]: string[]}, saved_view_last_used: {[page]: id}, ...}`.
- **Why:** UX §3 sidebar preference, §5.1 pinned metric row cross-device sync.
- **Migration from current:** no change.

#### `workspaces`

- **Status:** MODIFY.
- **Purpose:** Tenant root. Billable (Cashier). Also the agency-billing parent via `billing_workspace_id` self-FK.
- **Columns:**
  | Column | Type | Nullable | Default | Notes |
  |---|---|---|---|---|
  | `id` | bigserial PK | – | – | |
  | `name` | varchar(60) | no | – | UX §Settings identity (60-char limit) |
  | `slug` | varchar(255) unique | no | – | URL slug |
  | `owner_id` | bigint FK users | yes | null | nullOnDelete |
  | `billing_workspace_id` | bigint self-FK | yes | null | Agency consolidated billing (multistore doc §Agency-paid) |
  | `avatar_emoji` | varchar(8) | yes | null | **NEW** — workspace identity in WorkspaceSwitcher + portfolio contribution bar (UX §Settings) |
  | `state` | varchar(16) | no | `'active'` | **NEW** — CHECK in (`onboarding`, `demo`, `active`, `suspended`, `archived`). Powers DemoBanner (UX §5.11.1) |
  | `reporting_currency` | char(3) | no | `'EUR'` | |
  | `reporting_timezone` | varchar(64) | no | `'UTC'` | IANA |
  | `primary_country_code` | char(2) | yes | null | ISO-2 |
  | `week_start` | varchar(8) | no | `'sunday'` | CHECK in (`sunday`, `monday`). Shopify parity default (UX §5.3). |
  | `trial_ends_at` | timestamp | yes | null | |
  | `billing_plan` | varchar(16) | no | `'standard'` | CHECK in (`standard`, `enterprise`) |
  | `stripe_id` | varchar(255) | yes | null | Cashier |
  | `pm_type` | varchar(16) | yes | null | |
  | `pm_last_four` | char(4) | yes | null | |
  | `billing_name` | varchar(255) | yes | null | |
  | `billing_email` | varchar(255) | yes | null | |
  | `billing_address` | jsonb | no | `'{}'` | Nexstage-owned |
  | `vat_number` | varchar(64) | yes | null | VIES-validated (UX §Billing) |
  | `workspace_settings` | jsonb | no | `'{}'` | **MODIFY** — `WorkspaceSettings` VO: see shape below |
  | `deleted_at` | timestamp | yes | null | SoftDeletes |
  | timestamps | – | – | – | |
  | `is_orphaned` | bool | no | false | keep |
- **Columns DROPPED:** `has_store`, `has_ads`, `has_gsc`, `has_psi` (derived via `stores`/`ad_accounts`/`search_console_properties` existence); `country` (replaced by `primary_country_code`); `region`, `timezone` (replaced by `reporting_timezone`); `target_roas`, `target_cpo`, `target_marketing_pct` (move to `workspace_targets`); `utm_coverage_pct`, `utm_coverage_status`, `utm_coverage_checked_at`, `utm_unrecognized_sources` (derived — UX surfaces it in `/integrations` Channel Mapping tab from `channel_mappings` + `orders`).
- **Indexes:** unique `slug`; index `billing_workspace_id` (agency dashboard lookup).
- **Foreign keys:** `owner_id`→users nullOnDelete, `billing_workspace_id`→workspaces nullOnDelete.
- **JSONB shapes:**
  - `workspace_settings` — VO with keys: `default_attribution_model` (`first_touch`|`last_touch`|`last_non_direct`|`linear`|`data_driven`), `default_window` (`1d`|`7d`|`28d`|`ltv`), `default_accounting_mode` (`cash`|`accrual`), `default_profit_mode` bool, `default_breakdown` (`none`|`country`|`channel`|…), `confidence_threshold_orders` int (default 30), `confidence_threshold_sessions` int (default 100), `confidence_threshold_impressions` int (default 1000), `anomaly_threshold_platform_overreport_pct` (default 20), `anomaly_threshold_real_vs_store_pct` (default 15), `anomaly_threshold_spend_dod_pct` (default 40), `anomaly_threshold_integration_down_hours` (default 6). UX §Settings workspace + notifications.
  - `billing_address` — Stripe customer address shape.
- **Tenancy:** this IS the tenant root. No `WorkspaceScope` on model.
- **Why:** tenant root; `billing_workspace_id` powers agency billing per multistore doc; `state` powers DemoBanner; `workspace_settings` holds all user-tunable defaults from the Settings → Workspace page.
- **Migration delta:** add `avatar_emoji`, `state`, `primary_country_code` (rename), `week_start`; drop `has_*` integration booleans + `country`/`region`/`timezone` + `target_*` + `utm_coverage_*`; extend `workspace_settings` VO shape.

#### `workspace_users`

- **Status:** KEEP.
- **Purpose:** Membership pivot with role.
- **Columns:** `workspace_id`, `user_id`, `role` CHECK in (`owner`, `admin`, `member`), timestamps.
- **Indexes:** unique (`workspace_id`, `user_id`); index `user_id`.
- **FKs:** both cascade.
- **Tenancy:** `workspace_id`. Model scoped.
- **Why:** UX §Settings team, multistore doc §v1 role model. Exactly one Owner per workspace enforced at the service layer (inline hint, not a DB trigger).
- **Migration delta:** no change.

#### `workspace_invitations`

- **Status:** KEEP.
- **Purpose:** Pending email invites with token.
- **Columns:** `workspace_id`, `email`, `role` CHECK in (`admin`, `member`), `token` unique, `expires_at`, `accepted_at`, timestamps.
- **Indexes:** unique `token`; index `workspace_id`.
- **FKs:** `workspace_id` cascade.
- **Tenancy:** `workspace_id`. Model scoped.
- **Why:** UX §Settings invite modal.
- **Migration delta:** no change.

### 1.2 Connected stores (Shopify / WooCommerce)

#### `stores`

- **Status:** MODIFY.
- **Purpose:** Ecommerce store integration.
- **Columns:**
  | Column | Type | Nullable | Default | Notes |
  |---|---|---|---|---|
  | `id` | bigserial PK | – | – | |
  | `workspace_id` | bigint FK | no | – | |
  | `name` | varchar(255) | no | – | |
  | `slug` | varchar(255) | no | – | |
  | `platform` | varchar(24) | no | – | CHECK in (`woocommerce`, `shopify`). Reduced from 6 values — others are v2. |
  | `primary_country_code` | char(2) | yes | null | ISO-2. Used for ad-spend country COALESCE (CLAUDE.md) |
  | `domain` | varchar(255) | no | – | |
  | `website_url` | varchar(512) | yes | null | |
  | `currency` | char(3) | no | – | native |
  | `timezone` | varchar(64) | no | `'UTC'` | |
  | `platform_store_id` | varchar(255) | yes | null | |
  | `status` | varchar(16) | no | `'connecting'` | CHECK in (`connecting`, `active`, `error`, `disconnected`) |
  | `last_synced_at` | timestamp | yes | null | |
  | `consecutive_sync_failures` | int | no | 0 | |
  | timestamps | – | – | – | |
- **Columns DROPPED:** encrypted token fields → moved to `integration_credentials`; `type` (redundant with `platform`); `target_roas`, `target_cpo`, `target_marketing_pct` → `workspace_targets`; `cost_settings` jsonb → dedicated `store_cost_settings` table; `historical_import_*` state → `historical_import_jobs` table.
- **Indexes:** unique (`workspace_id`, `domain`), unique (`workspace_id`, `slug`); index `workspace_id`; (`workspace_id`, `platform_store_id`).
- **FKs:** `workspace_id` cascade.
- **Tenancy:** `workspace_id`. Model scoped.
- **Why:** Every page; `primary_country_code` needed for ad-spend country attribution per CLAUDE.md.
- **Migration delta:** remove encrypted token cols, cost settings jsonb, historical-import state, and `target_*` columns; keep the slim integration-identity shape.

### 1.3 Ad platforms

#### `ad_accounts`

- **Status:** MODIFY.
- **Purpose:** Ad account (Facebook / Google).
- **Columns:** `id`, `workspace_id`, `platform` CHECK in (`facebook`, `google`), `external_id`, `name`, `currency`, `status` CHECK in (`active`, `error`, `token_expired`, `disconnected`, `disabled`), `consecutive_sync_failures`, `last_synced_at`, `last_structure_synced_at`, timestamps.
- **Columns DROPPED:** encrypted token fields → `integration_credentials`; `historical_import_*` → `historical_import_jobs`.
- **Indexes:** unique (`workspace_id`, `platform`, `external_id`); `workspace_id`.
- **FKs:** `workspace_id` cascade.
- **Tenancy:** `workspace_id`. Model scoped.
- **Why:** `/ads` + `/attribution` data sources.
- **Migration delta:** strip credentials + import-state columns.

#### `campaigns`

- **Status:** KEEP (with small add).
- **Purpose:** Ad campaigns.
- **Columns:** `workspace_id`, `ad_account_id`, `external_id`, `name`, `previous_names` jsonb default `[]`, `parsed_convention` jsonb, `status`, `objective`, `daily_budget`, `lifetime_budget`, `budget_type`, `bid_strategy`, `target_value`, **NEW** `convention_version` smallint default 1 (bumped when the parser output shape changes, enables re-parse jobs), timestamps.
- **Columns DROPPED:** `target_roas`, `target_cpo` (→ `workspace_targets`).
- **Indexes:** unique (`ad_account_id`, `external_id`); (`workspace_id`, `ad_account_id`); partial functional on `parsed_convention->>'country'` for ad-spend country attribution.
- **FKs:** `workspace_id`, `ad_account_id` cascade.
- **Tenancy:** `workspace_id`. Model scoped.
- **JSONB shapes:**
  - `parsed_convention` — `{country, audience, offer, angle, creative_iteration, product_sku, placement, funnel_stage}` (all optional strings). `/ads` naming-convention parser strip reads this. No api_version — Nexstage-owned.
  - `previous_names` — array of `{name, observed_at}`.
- **Why:** `/ads` hierarchy, breakdown by convention, country COALESCE.
- **Migration delta:** drop `target_*`; add `convention_version` smallint.

#### `adsets`

- **Status:** KEEP.
- **Purpose:** Ad sets.
- **Columns:** `workspace_id`, `campaign_id`, `external_id`, `name`, `status`, timestamps.
- **Indexes:** unique (`campaign_id`, `external_id`); `workspace_id`.
- **FKs:** `workspace_id`, `campaign_id` cascade.
- **Tenancy:** `workspace_id`. Scoped.
- **Why:** `/ads` table hierarchy.
- **Migration delta:** none.

#### `ads`

- **Status:** KEEP.
- **Purpose:** Ads + creative snapshot.
- **Columns:** `workspace_id`, `adset_id`, `external_id`, `name`, `status`, `effective_status`, `destination_url`, `creative_data` jsonb, `creative_data_api_version` varchar(16), timestamps.
- **Indexes:** unique (`adset_id`, `external_id`); `workspace_id`.
- **FKs:** `workspace_id`, `adset_id` cascade.
- **Tenancy:** `workspace_id`. Scoped.
- **JSONB shapes:** `creative_data` — platform-owned (Meta/Google ad snapshot). Paired with `creative_data_api_version`.
- **Why:** `/ads` Creative Gallery thumbnails, ad detail drawer.
- **Migration delta:** none.

#### `ad_insights`

- **Status:** KEEP (no country column on purpose — CLAUDE.md).
- **Purpose:** Daily/hourly ad performance per campaign/adset/ad.
- **Columns:** `workspace_id`, `ad_account_id` (nullable), `level` CHECK in (`campaign`, `adset`, `ad`), `campaign_id`, `adset_id`, `ad_id` (nullable per level, CHECK enforces level-FK), `date`, `hour` (nullable), `spend`, `spend_in_reporting_currency`, `impressions`, `clicks`, `reach`, `frequency`, `platform_conversions`, `platform_conversions_value`, `platform_conversions_value_in_reporting_currency` (**NEW** — enables FX-safe Purchase-ROAS aggregation), `search_impression_share`, `platform_roas`, `currency`, `raw_insights` jsonb, `raw_insights_api_version`, timestamps.
- **Indexes:** (`workspace_id`, `ad_account_id`, `date`); (`workspace_id`, `campaign_id`, `date`); (`workspace_id`, `adset_id`, `date`); (`workspace_id`, `ad_id`, `date`); (`workspace_id`, `date`); partial unique per (level + time-granularity) — `ai_campaign_daily_unique`, `ai_campaign_hourly_unique`, `ai_adset_daily_unique`, `ai_ad_daily_unique`, `ai_ad_hourly_unique`; partial `idx_ad_insights_ws_campaign_daily` WHERE `level='campaign' AND hour IS NULL`.
- **FKs:** `workspace_id`, `ad_account_id`, `campaign_id`, `adset_id`, `ad_id` (nullOnDelete on platform FKs).
- **Tenancy:** `workspace_id`. Scoped.
- **JSONB shapes:** `raw_insights` — platform-owned. Paired.
- **Why:** `/ads` table + QuadrantChart + DaypartHeatmap + LineChart; `/dashboard` Ad Spend; `/attribution` Purchase ROAS twin column.
- **Migration delta:** add `platform_conversions_value_in_reporting_currency`.

#### `creative_tag_categories` / `creative_tags` / `ad_creative_tags`

- **Status:** KEEP trio.
- **Purpose:** Fixed taxonomy (category → allowed tag slugs), applied to ads via pivot for Creative Gallery breakdown.
- **Tenancy:** categories + tags global; pivot tenancy via `ad_id`.
- **Why:** `/ads` Creative Gallery filter-by-tag (future), Motion naming-convention analog.
- **Migration delta:** none.

#### `google_ads_keywords` — **DROP** (v1)

- **Status:** DROP.
- **Rationale:** Keyword cannibalization is not on any v1 page spec. Resurrect when `/ads` ships a Keywords tab v2.

### 1.4 Orders, customers, products

#### `orders`

- **Status:** MODIFY (additions for multi-touch attribution + explicit COGS snapshot).
- **Purpose:** Per-order record, joined with `order_items`, `refunds`, `customers`.
- **Columns:**
  | Column | Type | Nullable | Default | Notes |
  |---|---|---|---|---|
  | `id` | bigserial PK | – | – | |
  | `workspace_id` | bigint FK | no | – | |
  | `store_id` | bigint FK | no | – | |
  | `customer_id` | bigint FK customers | yes | null | **NEW** — points at `customers.id`. Current `customer_id` is a per-store scalar — see migration note. |
  | `external_id` | varchar(255) | no | – | |
  | `external_number` | varchar(64) | yes | null | |
  | `status` | varchar(16) | no | – | CHECK in (`pending`, `processing`, `completed`, `cancelled`, `refunded`) |
  | `currency` | char(3) | no | – | native |
  | `total` | numeric(14,2) | no | 0 | native |
  | `total_in_reporting_currency` | numeric(14,2) | yes | null | FX-cached at ingest |
  | `subtotal` | numeric(14,2) | no | 0 | |
  | `tax` | numeric(14,2) | no | 0 | |
  | `shipping` | numeric(14,2) | no | 0 | shipping charged to customer |
  | `shipping_cost_snapshot` | numeric(14,2) | yes | null | **NEW** — shipping cost owed by store, resolved via `shipping_rules` at ingest. `NULL` when not configured; waterfall renders a dashed bar. |
  | `payment_fee` | numeric(14,2) | no | 0 | kept |
  | `discount` | numeric(14,2) | no | 0 | |
  | `refund_amount` | numeric(14,2) | no | 0 | denormalised from `refunds` |
  | `last_refunded_at` | timestamp | yes | null | |
  | `is_first_for_customer` | bool | no | false | NC-ROAS, New Customer Revenue |
  | `customer_email_hash` | char(64) | yes | null | SHA-256, for privacy-preserving stitching + masked-email display |
  | `customer_country` | char(2) | yes | null | |
  | `shipping_country` | char(2) | yes | null | |
  | `payment_method` | varchar(64) | yes | null | used by `transaction_fee_rules` `applies_to` |
  | `payment_method_title` | varchar(255) | yes | null | |
  | `utm_source` | varchar(128) | yes | null | promoted from `raw_meta` on ingest |
  | `utm_medium` | varchar(128) | yes | null | |
  | `utm_campaign` | varchar(255) | yes | null | |
  | `utm_content` | varchar(255) | yes | null | |
  | `utm_term` | varchar(255) | yes | null | |
  | `source_type` | varchar(64) | yes | null | WC native attribution type |
  | `attribution_source` | varchar(32) | yes | null | Real-winning source (`facebook`/`google`/`organic`/`direct`/…) — the computed resolution from `RevenueAttributionService` |
  | `attribution_first_touch` | jsonb | yes | null | shape below |
  | `attribution_last_touch` | jsonb | yes | null | shape below |
  | `attribution_last_non_direct` | jsonb | yes | null | **NEW** — required because Last-Non-Direct is default model |
  | `attribution_click_ids` | jsonb | yes | null | `{fbc, fbp, gclid, msclkid}` |
  | `attribution_source_survey` | jsonb | yes | null | **NEW — schema-ready, not wired**; reserved for Fairing-style post-purchase survey (out of scope v1) |
  | `attribution_parsed_at` | timestamp | yes | null | |
  | `attribution_confidence` | smallint | yes | null | **NEW** — 0-100, populated by `RevenueAttributionService`. Powers `SignalTypeBadge` (§5.28) Mixed/Modeled state on `/orders`. |
  | `raw_meta` | jsonb | no | `'{}'` | platform-owned — `fee_lines`, `customer_note`, `pys_enrich_data`, `pys_fb_cookie`. Paired. |
  | `raw_meta_api_version` | varchar(16) | yes | null | |
  | `platform_data` | jsonb | yes | null | Nexstage-owned Shopify-specific wrapper |
  | `occurred_at` | timestamp | no | – | order placed |
  | `synced_at` | timestamp | yes | null | |
  | timestamps | – | – | – | |
- **Indexes (kept from current):** unique (`store_id`, `external_id`); (`workspace_id`, `occurred_at`); (`workspace_id`, `store_id`, `occurred_at`); (`workspace_id`, `status`, `occurred_at`); (`workspace_id`, `customer_country`, `occurred_at`); (`workspace_id`, `shipping_country`); (`store_id`, `synced_at`); functional `idx_orders_attr_lt_source` on `LOWER(attribution_last_touch->>'source')`; functional `idx_orders_attr_lt_campaign` on `(attribution_last_touch->>'campaign')`; partial `idx_orders_ws_occurred_real` WHERE `status IN ('completed','processing')`; partial `idx_orders_attribution_occurred_real` WHERE status IN (..) AND total_in_reporting_currency IS NOT NULL; partial `idx_orders_first_customer` WHERE `is_first_for_customer = true`; partial `idx_orders_customer_hash_occurred` WHERE `customer_email_hash IS NOT NULL`.
- **New indexes:** (`workspace_id`, `customer_id`, `occurred_at`) — `/customers` tab queries and Customer Journey Timeline; functional on `attribution_click_ids->>'fbclid'` and `attribution_click_ids->>'gclid'` for Attribution Time Machine drill.
- **FKs:** `workspace_id`, `store_id` cascade; `customer_id` nullOnDelete.
- **Tenancy:** `workspace_id`. Scoped.
- **JSONB shapes:**
  - `attribution_first_touch` / `attribution_last_touch` / `attribution_last_non_direct` — `{channel_type, source, medium, campaign, content, term, landing_page, credit_weight?, platform, sample_type, timestamp}`.
  - `attribution_click_ids` — `{fbc, fbp, fbclid, gclid, msclkid}`.
  - `attribution_source_survey` — reserved: `{question, answer, responded_at, survey_id}`.
  - `raw_meta` — platform payload. Paired.
- **Why:** `/orders` DataTable + OrderDetailDrawer + Customer Journey Timeline; `/attribution` drills; `/dashboard` Revenue by channel.
- **Migration delta:** add `customer_id` FK (new shape — see `customers` below; current per-store scalar `customer_id` is retained temporarily as `external_customer_id` for the ingest upsert path); add `attribution_last_non_direct`, `attribution_source_survey`, `attribution_confidence`, `shipping_cost_snapshot`.

#### `order_items`

- **Status:** MODIFY (add `product_id` + `variant_id` FKs).
- **Purpose:** Line items per order.
- **Columns:** `id`, `order_id`, **NEW** `product_id` FK products nullable, **NEW** `product_variant_id` FK product_variants nullable, `product_external_id`, `product_name`, `variant_name`, `sku`, `quantity`, `unit_price`, `unit_cost` (COGS snapshot at order time — immutable), `discount_amount`, `line_total`, timestamps.
- **Indexes:** `order_id`; functional unique `order_items_upsert_key` on `(order_id, product_external_id, COALESCE(variant_name,''))`; `idx_order_items_product_external_id`; `idx_order_items_order_product`; **NEW** `idx_order_items_product_id`; **NEW** `idx_order_items_variant_id`.
- **FKs:** `order_id` cascade; `product_id`, `product_variant_id` nullOnDelete.
- **Tenancy:** none on table; parent-scoped via `$order->items()`. Current practice kept.
- **Why:** `/products` Pareto + DataTable + Gateway table; `/orders` drawer line items.
- **Migration delta:** add `product_id`, `product_variant_id` (backfilled on next upsert run from `product_external_id` + variant match).

#### `customers`

- **Status:** NEW.
- **Purpose:** Unified customer record per workspace. Current schema collapses customer identity to a per-store `customer_id` scalar on `orders` — this doesn't support `/customers` RFM, LTV, or cross-store stitching.
- **Columns:**
  | Column | Type | Nullable | Default | Notes |
  |---|---|---|---|---|
  | `id` | bigserial PK | – | – | |
  | `workspace_id` | bigint FK | no | – | |
  | `store_id` | bigint FK | no | – | primary store of first order |
  | `email_hash` | char(64) | no | – | SHA-256 canonical lower-trimmed email |
  | `platform_customer_id` | varchar(128) | yes | null | original per-store id |
  | `display_email_masked` | varchar(255) | yes | null | `b***@gmail.com` computed at ingest, safe to render |
  | `name` | varchar(255) | yes | null | |
  | `first_order_at` | timestamp | yes | null | |
  | `last_order_at` | timestamp | yes | null | |
  | `orders_count` | int | no | 0 | denormalised |
  | `lifetime_value_native` | numeric(14,2) | no | 0 | native store currency |
  | `lifetime_value_reporting` | numeric(14,2) | no | 0 | FX-cached |
  | `country` | char(2) | yes | null | |
  | `acquisition_source` | varchar(32) | yes | null | first-order `attribution_source` |
  | `acquisition_campaign_id` | bigint | yes | null | nullOnDelete → campaigns |
  | `acquisition_product_id` | bigint | yes | null | first-purchased product (Gateway table) |
  | `is_subscriber` | bool | no | false | reserved for v2 Recharge |
  | `tags` | jsonb | no | `'[]'` | user-entered tags (v2 write-back) |
  | timestamps | – | – | – | |
- **Indexes:** unique (`workspace_id`, `email_hash`); (`workspace_id`, `first_order_at`); (`workspace_id`, `last_order_at`); (`workspace_id`, `acquisition_source`, `first_order_at`); (`workspace_id`, `store_id`).
- **FKs:** `workspace_id`, `store_id` cascade; `acquisition_campaign_id`, `acquisition_product_id` nullOnDelete.
- **Tenancy:** `workspace_id`. Scoped.
- **Why:** `/customers` every tab (Segments / Retention / LTV / Audiences); `/orders` customer cell; `CommandPalette` customer search.
- **Migration delta:** new table. Backfill from `orders.customer_email_hash` + per-store `customer_id`.

#### `customer_rfm_scores`

- **Status:** NEW.
- **Purpose:** Nightly RFM + segment assignment per customer.
- **Columns:** `id`, `workspace_id`, `customer_id`, `computed_for` date, `recency_score` smallint (1-5), `frequency_score` smallint (1-5), `monetary_score` smallint (1-5), `segment` varchar(32) CHECK in (`champions`, `loyal`, `potential_loyalists`, `at_risk`, `about_to_sleep`, `needs_attention`, `hibernating`), `churn_risk` smallint (0-100, nullable), `predicted_next_order_at` timestamp nullable, `predicted_ltv_reporting` numeric(14,2) nullable, `predicted_ltv_confidence` smallint nullable (0-100), `model_version` varchar(16) (track retrains), `created_at`.
- **Indexes:** unique (`workspace_id`, `customer_id`, `computed_for`); (`workspace_id`, `segment`, `computed_for`); partial (`workspace_id`, `computed_for`) WHERE `segment = 'at_risk'` for fast "who needs attention" queries.
- **FKs:** `workspace_id`, `customer_id` cascade.
- **Tenancy:** `workspace_id`. Scoped.
- **Why:** `/customers` RfmGrid, SegmentTiles, Customer drawer, Churn Risk column.
- **Migration delta:** new table.

#### `customer_ltv_overrides`

- **Status:** NEW.
- **Purpose:** Admin-authored overrides on Predicted LTV (UX §Customers inline-edit).
- **Columns:** `id`, `workspace_id`, `customer_id`, `overridden_ltv_reporting` numeric(14,2), `reason` varchar(255), `overridden_by` bigint FK users, `overridden_at` timestamp, timestamps.
- **Indexes:** unique (`workspace_id`, `customer_id`).
- **FKs:** cascade.
- **Tenancy:** `workspace_id`. Scoped.
- **Why:** `/customers` "exclude a corporate order" inline edit flow.
- **Migration delta:** new table.

#### `products`

- **Status:** MODIFY (variants promoted out).
- **Purpose:** Parent product catalog record. Variants live in `product_variants`.
- **Columns:** `id`, `workspace_id`, `store_id`, `external_id`, `name`, `slug`, `sku`, `price`, `status`, `product_type`, `image_url`, `product_url`, `platform_updated_at`, timestamps.
- **Columns DROPPED (moved to variants):** `stock_status`, `stock_quantity`.
- **Indexes:** unique (`store_id`, `external_id`); (`workspace_id`, `store_id`); (`workspace_id`, `store_id`, `status`).
- **FKs:** `workspace_id`, `store_id` cascade.
- **Tenancy:** `workspace_id`. Scoped.
- **Why:** `/products` DataTable + Gateway + QuadrantChart; `/orders` line items; `/profit` per-product breakdown.
- **Migration delta:** move stock cols to `product_variants`.

#### `product_variants`

- **Status:** NEW.
- **Purpose:** SKU-level variant record. Carries canonical COGS (used by `order_items.unit_cost` ingest default).
- **Columns:** `id`, `workspace_id`, `store_id`, `product_id`, `external_id`, `sku`, `variant_name`, `price`, `cogs_amount` numeric(14,2) nullable, `cogs_source` varchar(24) CHECK in (`manual`, `shopify_cost_per_item`, `woo_meta`, `csv_upload`), `cogs_currency` char(3), `stock_status`, `stock_quantity`, `platform_updated_at`, timestamps.
- **Indexes:** unique (`store_id`, `external_id`); (`workspace_id`, `product_id`); partial (`workspace_id`) WHERE `cogs_amount IS NULL` for "Missing COGS" SavedView.
- **FKs:** `workspace_id`, `store_id`, `product_id` cascade.
- **Tenancy:** `workspace_id`. Scoped.
- **Why:** UX §Settings Costs COGS table primary data source; `/products` inline-edit target; `/profit` COGS line.
- **Migration delta:** new table; backfill from Shopify variant API + existing `product_costs`.

#### `product_categories` / `product_category_product`

- **Status:** KEEP.
- **Purpose:** Category hierarchy + pivot.
- **Why:** `/products` BarChart by category.
- **Migration delta:** none.

#### `order_coupons`

- **Status:** KEEP.
- **Purpose:** Coupon usage per order.
- **Why:** `/profit` Discounts bar; `/orders` drawer; `/customers` LTV Drivers (discount code on first order).
- **Migration delta:** none.

#### `refunds`

- **Status:** KEEP.
- **Purpose:** Individual refund events.
- **Columns:** `workspace_id`, `order_id`, `platform_refund_id`, `amount`, `reason`, `refunded_by_id`, `refunded_at`, `raw_meta` jsonb + `raw_meta_api_version`, created_at only.
- **Indexes:** unique (`order_id`, `platform_refund_id`); (`workspace_id`, `refunded_at`).
- **Why:** `/profit` Refunds bar; `/dashboard` ActivityFeed.
- **Migration delta:** none.

#### `product_affinities` — **DROP** (v1)

- **Status:** DROP.
- **Rationale:** `/products` drawer spec says "co-occurrence count only, full Market Basket v2". Simpler to compute on the fly from `order_items` than to run Apriori nightly. Re-introduce when Market Basket ships.

### 1.5 Aggregation snapshots

#### `daily_snapshots`

- **Status:** MODIFY (add per-source revenue columns).
- **Purpose:** Per-store per-day aggregate. The aggregation truth for page queries.
- **Columns:** `workspace_id`, `store_id`, `date`, `orders_count`, `revenue_native`, `revenue` (reporting currency — existing name kept), `aov`, `items_sold`, `items_per_order`, `new_customers`, `returning_customers`, **NEW** `revenue_facebook_attributed`, **NEW** `revenue_google_attributed`, **NEW** `revenue_gsc_attributed`, **NEW** `revenue_direct_attributed`, **NEW** `revenue_organic_attributed`, **NEW** `revenue_email_attributed`, **NEW** `revenue_real_attributed`, **NEW** `discounts_total`, **NEW** `refunds_total`, **NEW** `cogs_total` (sum of `order_items.unit_cost * quantity`, null if any row missing COGS), **NEW** `shipping_cost_total`, **NEW** `transaction_fees_total`, **NEW** `sessions` (from Shopify analytics where available, nullable), timestamps.
- **Indexes:** unique (`store_id`, `date`); (`workspace_id`, `date`); (`workspace_id`, `store_id`, `date`).
- **FKs:** cascade.
- **Tenancy:** `workspace_id`. Scoped.
- **Why:** powers every page's time-series queries. Adding per-source revenue columns makes TrustBar, Source Disagreement Matrix, and `/attribution` Time Machine scrubber servable from one table without per-request recomputation. Profit columns make ProfitWaterfallChart + IncomeStatementTable servable without touching raw orders.
- **Migration delta:** add 13 new columns (7 per-source revenue + 6 profit components + 1 sessions). Refresh job (`ComputeDailySnapshotJob`) extended.

#### `hourly_snapshots`

- **Status:** MODIFY (same per-source revenue add).
- **Purpose:** Per-store per-date-per-hour aggregate.
- **Columns:** `workspace_id`, `store_id`, `date`, `hour`, `orders_count`, `revenue`, **NEW** `revenue_facebook_attributed`, **NEW** `revenue_google_attributed`, **NEW** `revenue_real_attributed`, timestamps.
- **Indexes:** unique (`store_id`, `date`, `hour`); (`workspace_id`, `store_id`, `date`).
- **Why:** `/dashboard` TodaySoFar intra-day widget; `/ads` DaypartHeatmap (joined with `ad_insights.hour`).
- **Migration delta:** add 3 per-source revenue columns (not all 7 — hourly is hotter; MVP only needs FB/Google/Real).

#### `daily_snapshot_products`

- **Status:** KEEP.
- **Purpose:** Top 50 products per store per day.
- **Why:** `/products` Pareto + DataTable + gateway table.
- **Migration delta:** consider raising cap from 50 to 100 once Pareto renders top 50 — observe, don't pre-optimise.

### 1.6 Costs (Settings → Costs is the authoring surface)

#### `store_cost_settings`

- **Status:** NEW (promotes the jsonb VO from `stores.cost_settings` into a real table).
- **Purpose:** Workspace-level shipping / fees / VAT / OpEx configuration surface. One row per workspace (or per store — see `scope` below).
- **Columns:** `id`, `workspace_id`, `store_id` nullable (NULL = workspace-wide default), `shipping_mode` CHECK in (`flat_rate`, `per_order`, `weight_tiered`, `none`), `shipping_flat_rate_native` numeric(14,2) nullable, `shipping_per_order_native` numeric(14,2) nullable, `default_currency` char(3), `completeness_score` smallint (0-100, computed — drives the amber StatusDot left-nav in Settings), `last_recalculated_at` timestamp, timestamps.
- **Indexes:** unique (`workspace_id`, COALESCE(`store_id`, 0)).
- **FKs:** cascade.
- **Tenancy:** `workspace_id`. Scoped.
- **Why:** Settings → Costs page; `/profit` completeness check; ProfitMode degrade-gracefully logic.
- **Migration delta:** new table. Hydrate from `stores.cost_settings` jsonb.

#### `shipping_rules`

- **Status:** NEW.
- **Purpose:** Weight-tier or zone rows when `store_cost_settings.shipping_mode = 'weight_tiered'`.
- **Columns:** `id`, `workspace_id`, `store_id` nullable, `min_weight_grams` int, `max_weight_grams` int, `destination_country` char(2) nullable (NULL = any), `cost_native` numeric(14,2), `currency` char(3), timestamps.
- **Indexes:** (`workspace_id`, `store_id`, `min_weight_grams`).
- **FKs:** cascade.
- **Tenancy:** `workspace_id`. Scoped.
- **Why:** Settings → Costs → Shipping weight-tiered form.
- **Migration delta:** new table.

#### `transaction_fee_rules`

- **Status:** NEW.
- **Purpose:** One row per connected payment processor.
- **Columns:** `id`, `workspace_id`, `store_id` nullable, `processor` varchar(32) CHECK in (`shopify_payments`, `stripe`, `paypal`, `mollie`, `klarna`, `manual`), `percentage_bps` smallint (basis points; 290 = 2.9%), `fixed_fee_native` numeric(10,4), `currency` char(3), `applies_to_payment_method` varchar(64) nullable (e.g., "paypal"; NULL = all), `is_seeded` bool default false (for the `Seeded — verify against your contract` chip), timestamps.
- **Indexes:** (`workspace_id`, `store_id`, `processor`).
- **Why:** Settings → Costs → Transaction fees; `/profit` Transaction Fees bar; `orders.payment_fee` computation.
- **Migration delta:** new table.

#### `tax_rules`

- **Status:** NEW (seeded EU defaults).
- **Purpose:** Per-country VAT/tax config.
- **Columns:** `id`, `workspace_id` nullable (NULL = global seed), `country_code` char(2), `standard_rate_bps` smallint (basis points), `reduced_rate_bps` smallint nullable, `is_included_in_price` bool default true, `digital_goods_override_bps` smallint nullable, `is_seeded` bool default false, timestamps.
- **Indexes:** partial unique `tax_rules_workspace_country_unique` on (`workspace_id`, `country_code`) WHERE `workspace_id IS NOT NULL`; partial unique `tax_rules_global_country_unique` on (`country_code`) WHERE `workspace_id IS NULL`.
- **Why:** Settings → Costs → VAT section; `/profit` tax treatment.
- **Migration delta:** new table. Seeder `TaxRulesSeeder`.

#### `opex_allocations`

- **Status:** NEW.
- **Purpose:** Monthly fixed costs (salaries, rent, tools) and their allocation strategy.
- **Columns:** `id`, `workspace_id`, `category` varchar(64), `monthly_cost_native` numeric(14,2), `currency` char(3), `allocation_mode` varchar(16) CHECK in (`per_order`, `per_day`), `effective_from` date, `effective_to` date nullable, timestamps.
- **Indexes:** (`workspace_id`, `effective_from`, `effective_to`).
- **Why:** Settings → Costs → OpEx; `/profit` Operating Expenses bar + IncomeStatementTable expandable row.
- **Migration delta:** new table.

#### `platform_fee_rules`

- **Status:** NEW.
- **Purpose:** Per-workspace Shopify plan / app subscription costs (separate from OpEx because they have distinct `Per-order` / `Per-day` semantics tied to the store plan).
- **Columns:** `id`, `workspace_id`, `store_id` nullable, `item_label` varchar(128), `monthly_cost_native` numeric(14,2), `currency` char(3), `allocation_mode` CHECK in (`per_order`, `per_day`), `effective_from`, `effective_to` nullable, timestamps.
- **Why:** Settings → Costs → Platform fees section.
- **Migration delta:** new table.

#### `product_costs` — **DROP** (replaced)

- **Status:** DROP.
- **Rationale:** Overtaken by `product_variants.cogs_amount`. Historical COGS never retroactively restates — so a time-varying `effective_from` / `effective_to` model isn't needed (per `_crosscut_metric_dictionary.md` COGS glossary).

### 1.7 FX

#### `fx_rates`

- **Status:** KEEP.
- **Purpose:** DB-cached FX rates. Never fetched live.
- **Why:** `FxRateService` DB-first contract (CLAUDE.md gotcha).
- **Migration delta:** none.

### 1.8 GSC / SEO

#### `search_console_properties`

- **Status:** MODIFY (strip credentials).
- **Purpose:** GSC OAuth property state.
- **Columns:** `workspace_id`, `store_id` nullable, `property_url`, `status` CHECK in (`active`, `error`, `token_expired`, `disconnected`), `consecutive_sync_failures`, `last_synced_at`, timestamps.
- **Columns DROPPED:** encrypted token fields → `integration_credentials`; `historical_import_*` → `historical_import_jobs`.
- **Why:** `/seo` pre-integration banner; `/integrations` GSC card.
- **Migration delta:** strip.

#### `search_console_daily_rollups`

- **Status:** MODIFY (rename from `gsc_daily_stats` to align with spec; same shape).
- **Purpose:** Per-property daily totals by device + country.
- **Columns:** `workspace_id`, `property_id`, `date`, `clicks`, `impressions`, `ctr`, `position`, `device` default `'all'`, `country` char(3) default `'ZZ'`, `data_state` varchar(16) default `'final'` (**NEW** — CHECK in (`final`, `provisional`), per `/seo` spec §Data sources), timestamps.
- **Indexes:** unique (`property_id`, `date`, `device`, `country`, `data_state`); (`workspace_id`, `date`).
- **Why:** `/seo` time-series chart.
- **Migration delta:** rename + add `data_state`.

#### `search_console_queries` / `search_console_pages`

- **Status:** MODIFY (rename from `gsc_queries` / `gsc_pages`; add `data_state`).
- **Purpose:** Per-query / per-page daily stats.
- **Why:** `/seo` Queries / Pages tabs.
- **Migration delta:** rename; add `data_state`.

#### `gsc_ctr_benchmarks`

- **Status:** KEEP.
- **Why:** `/seo` ConfidenceChip basis + position-bucket expected CTR.
- **Migration delta:** none.

#### `google_algorithm_updates`

- **Status:** NEW.
- **Purpose:** Seeded list of Google core algorithm updates. Rendered as system annotations on `/seo` LineChart (UX §5.6.1).
- **Columns:** `id`, `update_name` varchar(128), `rolled_out_on` date, `rollout_ended_on` date nullable, `update_type` varchar(32) (`core`, `spam`, `helpful_content`, `product_reviews`, …), `description_url` varchar(512), `created_at`.
- **Indexes:** `rolled_out_on`.
- **Why:** `/seo` ChartAnnotationLayer system-authored events.
- **Migration delta:** new table. Seeder `GoogleAlgorithmUpdatesSeeder`.

### 1.9 Integration pipe: credentials, runs, events, imports

#### `integration_credentials`

- **Status:** NEW (extracts encrypted tokens from stores / ad_accounts / search_console_properties).
- **Purpose:** Per-integration OAuth / API-key material, rotated independently of the integration identity rows.
- **Columns:** `id`, `workspace_id`, `integrationable_type` varchar(64), `integrationable_id` bigint (polymorphic to stores / ad_accounts / search_console_properties), `auth_key_encrypted`, `auth_secret_encrypted`, `access_token_encrypted`, `refresh_token_encrypted`, `webhook_secret_encrypted`, `token_expires_at` timestamp nullable, `scopes` jsonb (granted scope list), timestamps.
- **Indexes:** unique (`integrationable_type`, `integrationable_id`); (`workspace_id`).
- **FKs:** `workspace_id` cascade.
- **Tenancy:** `workspace_id`. Scoped.
- **Why:** Centralises secrets, enables per-integration rotation, keeps identity tables clean.
- **Migration delta:** new table. Backfilled from `stores.*_encrypted`, `ad_accounts.*_encrypted`, `search_console_properties.*_encrypted`.

#### `integration_runs`

- **Status:** NEW (replaces `sync_logs` — same data, better name, Elevar-aligned).
- **Purpose:** One row per scheduled sync invocation.
- **Columns:** `id`, `workspace_id`, `integrationable_type`, `integrationable_id`, `job_type` varchar(64), `status` CHECK in (`queued`, `running`, `completed`, `failed`), `records_processed` int, `error_message` text nullable, `started_at`, `completed_at`, `scheduled_at`, `queue` varchar(32), `attempt` smallint, `timeout_seconds` int, `duration_seconds` int, `payload` jsonb (Nexstage-owned), timestamps.
- **Indexes:** (`workspace_id`, `integrationable_type`, `integrationable_id`); (`status`, `created_at`); (`workspace_id`, `created_at` DESC).
- **Why:** `/integrations` Tracking Health + Connected tab freshness; `FreshnessIndicator` per-source popover.
- **Migration delta:** rename `sync_logs` → `integration_runs`.

#### `integration_events`

- **Status:** NEW.
- **Purpose:** Elevar-style per-event delivery log. One row per outbound destination call (Facebook CAPI, Google EC) or inbound webhook.
- **Columns:** `id`, `workspace_id`, `integrationable_type`, `integrationable_id`, `direction` CHECK in (`inbound`, `outbound`), `event_type` varchar(64) (e.g., `purchase`, `view_content`, `lead`), `external_ref` varchar(255) (order id / event id), `destination_platform` varchar(32) (`facebook`, `google`, `gsc`, `shopify`, `woocommerce`), `status` CHECK in (`delivered`, `failed`, `pending`), `error_code` varchar(32) nullable (platform-native, e.g., `#100`), `error_category` varchar(64) nullable (Nexstage-assigned bucket for Error Code Directory), `match_quality` smallint nullable (0-10, Elevar parity), `payload` jsonb, `payload_api_version` varchar(16), `received_at` timestamp, timestamps.
- **Indexes:** (`workspace_id`, `destination_platform`, `received_at` DESC); (`workspace_id`, `status`, `received_at`); (`workspace_id`, `error_code`, `received_at`); partial (`workspace_id`, `received_at` DESC) WHERE `status = 'failed'`.
- **Why:** `/integrations` Tracking Health tab error table + events stream; `/attribution` Tracking Health strip `Match Quality` column.
- **Migration delta:** new table. Webhook ingests + CAPI pushes append rows. Merges responsibilities of `webhook_logs` (see drop note below).

#### `historical_import_jobs`

- **Status:** NEW (replaces `historical_import_*` columns currently scattered on `stores`/`ad_accounts`/`search_console_properties`).
- **Purpose:** One row per in-flight or archived backfill (4 job types per spec).
- **Columns:** `id`, `workspace_id`, `integrationable_type`, `integrationable_id`, `job_type` varchar(32) CHECK in (`shopify_orders`, `woocommerce_orders`, `ad_insights`, `gsc`), `status` CHECK in (`pending`, `running`, `completed`, `failed`, `cancelled`), `from_date` date, `to_date` date, `total_rows_estimated` int nullable, `total_rows_imported` int default 0, `progress_pct` smallint default 0, `checkpoint` jsonb (resume state), `started_at`, `completed_at`, `duration_seconds` int, `error_message` text nullable, timestamps.
- **Indexes:** (`workspace_id`, `status`); (`workspace_id`, `job_type`, `created_at` DESC); partial (`workspace_id`, `status`) WHERE `status IN ('pending','running')`.
- **Why:** `/integrations` Historical imports tab; UX §5.8.1 tab-title status channel; onboarding "Importing 4,200 of 18,400 orders" copy.
- **Migration delta:** new table. Migrate active jobs from current `historical_import_*` state columns.

#### `webhook_logs` — **DROP** (merged into `integration_events`)

- **Status:** DROP.
- **Rationale:** Redundant with `integration_events direction=inbound`. Keep one audit table, one shape.

#### `store_webhooks`

- **Status:** KEEP.
- **Purpose:** Per-store webhook registration audit trail (soft-deleted). Different from `integration_events` — this is "which topics we've subscribed to on the platform", not the delivery log.
- **Why:** `/integrations` advanced diagnostics `Test webhook` button; reconnect flow re-registers.
- **Migration delta:** none.

### 1.10 UX primitives (new tables from UX.md audit)

#### `annotations`

- **Status:** NEW (generalisation of `daily_notes` + `workspace_events` — same primitive, fewer tables).
- **Purpose:** ChartAnnotationLayer source (UX §5.6.1). User-authored and system-authored flags on time-series charts.
- **Columns:**
  | Column | Type | Nullable | Default | Notes |
  |---|---|---|---|---|
  | `id` | bigserial PK | – | – | |
  | `workspace_id` | bigint FK | no | – | |
  | `author_type` | varchar(16) | no | – | CHECK in (`user`, `system`) |
  | `author_id` | bigint | yes | null | FK users when `author_type = 'user'`; nullOnDelete |
  | `title` | varchar(255) | no | – | "BFCM kickoff", "Facebook token expired" |
  | `body` | text | yes | null | |
  | `annotation_type` | varchar(32) | no | – | CHECK in (`user_note`, `promotion`, `expected_spike`, `expected_drop`, `integration_disconnect`, `integration_reconnect`, `attribution_model_change`, `cogs_update`, `algorithm_update`, `migration`) |
  | `scope_type` | varchar(16) | no | `'workspace'` | CHECK in (`workspace`, `store`, `integration`, `product`, `campaign`, `page`, `query`) |
  | `scope_id` | bigint | yes | null | polymorphic id of scope entity |
  | `starts_at` | timestamp | no | – | |
  | `ends_at` | timestamp | yes | null | |
  | `is_hidden_per_user` | jsonb | no | `'[]'` | array of user_ids that hid a system annotation (UX: system-authored can be hidden per-user, not deleted) |
  | `suppress_anomalies` | bool | no | false | when true, anomaly rules skip this range |
  | `created_by` | bigint FK users | yes | null | |
  | `updated_by` | bigint FK users | yes | null | |
  | timestamps | – | – | – | |
- **Indexes:** (`workspace_id`, `starts_at`, `ends_at`); (`workspace_id`, `scope_type`, `scope_id`); (`workspace_id`, `annotation_type`); partial (`workspace_id`, `starts_at`) WHERE `annotation_type = 'promotion'`.
- **FKs:** cascade; user FKs nullOnDelete.
- **Tenancy:** `workspace_id`. Scoped.
- **Why:** UX §5.6.1 ChartAnnotationLayer (every time-series chart); `/seo` migration/algorithm markers; `/ads` token expiry; `/profit` COGS update markers.
- **Migration delta:** new table. Migrate `daily_notes` + `workspace_events` in.

#### `saved_views`

- **Status:** NEW.
- **Purpose:** Named filter + sort + columns + date-range combination (UX §5.19).
- **Columns:** `id`, `workspace_id`, `user_id` nullable (NULL = workspace-shared), `page` varchar(32) CHECK in (`/dashboard`, `/orders`, `/ads`, `/attribution`, `/seo`, `/products`, `/profit`, `/customers`), `name` varchar(128), `url_state` jsonb (URL querystring serialised), `is_pinned` bool default false, `pin_order` smallint default 0, `created_by` bigint FK users, timestamps.
- **Indexes:** (`workspace_id`, `page`, `is_pinned`); (`user_id`); partial (`workspace_id`, `page`) WHERE `user_id IS NULL` (shared views).
- **FKs:** cascade.
- **Tenancy:** `workspace_id`. Scoped.
- **Why:** Every list page "Save view" button. Sidebar pins under each page.
- **Migration delta:** new table.

#### `workspace_targets`

- **Status:** NEW.
- **Purpose:** Metric goals (UX §5.23 Target primitive; Settings → Targets page).
- **Columns:** `id`, `workspace_id`, `metric` varchar(64) (matches dictionary "Our pick" slug — `revenue`, `profit`, `roas`, `mer`, `cac`, `ltv`, `orders`, `aov`, …), `period` varchar(16) CHECK in (`this_week`, `this_month`, `this_quarter`, `custom`), `period_start` date nullable, `period_end` date nullable, `target_value_reporting` numeric(14,2), `currency` char(3) nullable (`null` when metric is unitless like ROAS), `owner_user_id` bigint FK users (accountability), `visible_on_pages` jsonb (array of page slugs for TargetLine / TargetProgress chrome), `status` varchar(16) CHECK in (`active`, `archived`), `created_by`, timestamps.
- **Indexes:** (`workspace_id`, `status`, `period`); (`workspace_id`, `metric`, `period`).
- **FKs:** cascade; user FKs nullOnDelete.
- **Tenancy:** `workspace_id`. Scoped.
- **Why:** Settings → Targets; `/dashboard` Revenue TargetLine; `/ads` ROAS TargetLine; `/profit` Revenue/Profit/Margin TargetProgress; `/customers` Repeat Rate TargetProgress.
- **Migration delta:** new table. Migrate `workspaces.target_roas/target_cpo/target_marketing_pct` into rows here.

#### `public_snapshot_tokens`

- **Status:** NEW.
- **Purpose:** Tokens for `ShareSnapshotButton` (UX §5.29). Public read-only snapshot URLs at `/public/snapshot/{token}`.
- **Columns:** `id`, `workspace_id`, `token` varchar(64) unique, `page` varchar(32), `url_state` jsonb (frozen filter state), `date_range_locked` bool default true, `snapshot_data` jsonb (optional materialised payload — otherwise re-reads `daily_snapshots`), `expires_at` timestamp nullable (`null` = never), `revoked_at` timestamp nullable, `created_by` bigint FK users, `last_accessed_at` timestamp nullable, `access_count` int default 0, timestamps.
- **Indexes:** unique `token`; (`workspace_id`, `created_at` DESC); partial (`workspace_id`) WHERE `revoked_at IS NULL`.
- **FKs:** cascade.
- **Tenancy:** `workspace_id`. Scoped.
- **Why:** Every data page's ShareSnapshotButton; `/seo` provisional-data footer use case.
- **Migration delta:** new table.

#### `notification_preferences`

- **Status:** KEEP (exists; surface unchanged).
- **Purpose:** Per-workspace-per-user alert delivery config.
- **Why:** Settings → Notifications "Who receives what" table.
- **Migration delta:** no shape change; will be read alongside `anomaly_rules` and `digest_schedules`.

#### `digest_schedules`

- **Status:** NEW.
- **Purpose:** Email digest delivery config (UX §Settings Notifications).
- **Columns:** `id`, `workspace_id`, `frequency` CHECK in (`off`, `daily`, `weekly`, `monthly`), `day_of_week` smallint nullable (0-6 for weekly), `day_of_month` smallint nullable (1-28 for monthly), `send_at_hour` smallint (0-23, workspace timezone), `recipients` jsonb (array of emails; recipients don't need Nexstage accounts — `_crosscut_export_sharing_ux.md`), `content_pages` jsonb (array of page slugs to include), `last_sent_at` timestamp nullable, `last_sent_status` varchar(16) nullable, timestamps.
- **Indexes:** (`workspace_id`, `frequency`); partial (`workspace_id`) WHERE `frequency != 'off'`.
- **FKs:** cascade.
- **Tenancy:** `workspace_id`. Scoped.
- **Why:** Settings → Notifications email digest card; ExportMenu → Schedule email.
- **Migration delta:** new table.

#### `slack_webhooks`

- **Status:** NEW.
- **Purpose:** Connected Slack workspace for on-demand "Send to Slack" (v1; scheduled digests v2).
- **Columns:** `id`, `workspace_id`, `slack_team_id` varchar(32), `slack_team_name` varchar(128), `webhook_url_encrypted`, `default_channel` varchar(64) nullable, `connected_by` bigint FK users, `status` varchar(16) CHECK in (`active`, `disconnected`), timestamps.
- **Indexes:** unique (`workspace_id`, `slack_team_id`).
- **FKs:** cascade.
- **Tenancy:** `workspace_id`. Scoped.
- **Why:** Settings → Notifications Slack card; ExportMenu → Send to Slack.
- **Migration delta:** new table.

#### `anomaly_rules`

- **Status:** NEW.
- **Purpose:** Four canonical rules from UX §Settings Notifications (custom rules v2).
- **Columns:** `id`, `workspace_id`, `rule_type` varchar(48) CHECK in (`real_vs_store_delta`, `platform_overreport`, `ad_spend_dod`, `integration_down`), `threshold_value` numeric(10,4), `threshold_unit` varchar(16) (`percent`, `hours`), `enabled` bool default true, `delivery_channels` jsonb (array: `email`, `triage_inbox`, `slack`), `last_fired_at` timestamp nullable, timestamps.
- **Indexes:** unique (`workspace_id`, `rule_type`); (`workspace_id`, `enabled`).
- **FKs:** cascade.
- **Tenancy:** `workspace_id`. Scoped.
- **Why:** Settings → Notifications anomaly card; TriageInbox content feed.
- **Migration delta:** new table.

#### `triage_inbox_items`

- **Status:** MODIFY (rename `inbox_items` → `triage_inbox_items`; add `severity` and `deep_link_url`).
- **Purpose:** UX §5.22 TriageInbox — focused list of items needing a human decision. Dashboard compact + `/alerts` full page.
- **Columns:** `id`, `workspace_id`, `itemable_type` varchar(64), `itemable_id` bigint, `severity` CHECK in (`info`, `warning`, `high`, `critical`), `title` varchar(255), `context_text` varchar(512), `primary_action_label` varchar(64) nullable, `deep_link_url` varchar(512) nullable, `status` CHECK in (`open`, `done`, `dismissed`), `snoozed_until` timestamp nullable, timestamps.
- **Indexes:** (`workspace_id`, `status`, `snoozed_until`, `created_at`); (`itemable_type`, `itemable_id`); unique (`workspace_id`, `itemable_type`, `itemable_id`).
- **Why:** Dashboard TriageInbox compact variant; `/alerts` mobile-first page; anomaly rule output target.
- **Migration delta:** rename + add severity/link columns; keep current morph-to pattern (Alert, AiSummary, DailyNote → Annotation; add IntegrationEvent as morph target for failures).

#### `alerts` — **DROP** (merged into `triage_inbox_items` via polymorphic `itemable`)

- **Status:** DROP.
- **Rationale:** `alerts` + `inbox_items` duplicate each other — one is the payload, one is the presentation. Merge into `triage_inbox_items` directly; the severity/type fields that lived on `alerts` become fields on the inbox row.

#### `ai_summaries` — **DROP** (v1)

- **Status:** DROP.
- **Rationale:** UX §2 explicitly excludes AI chat/assistant; no Dashboard surface ships an AI daily summary in v1. Revisit in v2.

#### `recommendations` — **DROP** (v1)

- **Status:** DROP.
- **Rationale:** Nexstage is deliberately non-prescriptive in v1 (UX §anti-Atria, `/products` no AI prescriptions, `/ads` Triage columns are rule-based not AI). `ProductLifecycleChip` and Triage columns compute on the fly from `daily_snapshot_products` + `ad_insights`. No place surfaces stored "recommendations."

### 1.11 Workspace config + settings audit

#### `workspace_settings` VO

Stored inline on `workspaces.workspace_settings` (jsonb). Not a table. Listed here for completeness — shape documented in §1.1 `workspaces` above.

#### `settings_audit_log`

- **Status:** NEW.
- **Purpose:** UX §Settings "Audit log panel" — last 20 setting changes per sub-page.
- **Columns:** `id`, `workspace_id`, `sub_page` varchar(32) CHECK in (`workspace`, `team`, `costs`, `billing`, `notifications`, `targets`), `actor_user_id` bigint FK users nullable, `entity_type` varchar(64) (`workspace_settings`, `store_cost_settings`, `tax_rule`, `member`, …), `entity_id` bigint nullable, `field_changed` varchar(64), `value_from` text nullable, `value_to` text nullable, `is_reversible` bool default false, `reverted_at` timestamp nullable, `created_at` timestamp.
- **Indexes:** (`workspace_id`, `sub_page`, `created_at` DESC); (`workspace_id`, `created_at` DESC).
- **FKs:** cascade; actor nullOnDelete.
- **Tenancy:** `workspace_id`. Scoped.
- **Why:** Every Settings sub-page's collapsible audit panel; "Revert" action on reversible changes.
- **Migration delta:** new table.

### 1.12 Subscription billing (Stripe / Cashier)

#### `billing_subscriptions`

- **Status:** MODIFY (rename from `subscriptions` to avoid the commerce-domain collision with "subscription revenue").
- **Purpose:** Cashier Stripe subscription record.
- **Columns:** `workspace_id`, `type`, `stripe_id` unique, `stripe_status`, `stripe_price`, `quantity`, `trial_ends_at`, `ends_at`, timestamps.
- **Indexes:** unique `stripe_id`; (`workspace_id`, `stripe_status`).
- **FKs:** `workspace_id` cascade.
- **Why:** Settings → Billing current plan card; revenue-share calculator reads `daily_snapshots.revenue` for MTD projection.
- **Migration delta:** rename table + model class; Cashier parent stays.

#### `billing_subscription_items`

- **Status:** MODIFY (rename from `subscription_items`).
- **Migration delta:** rename.

#### `billing_revenue_share_usage`

- **Status:** NEW.
- **Purpose:** Monthly revenue-share usage records reported to Stripe metered billing (powers the `ReportMonthlyRevenueToStripeJob`).
- **Columns:** `id`, `workspace_id`, `period_month` date (first-of-month), `reporting_currency` char(3), `gross_revenue_reporting` numeric(14,2), `billable_revenue_reporting` numeric(14,2) (after any exclusions), `rate_bps` smallint default 40 (0.4%), `computed_amount_reporting` numeric(14,2), `reported_to_stripe_at` timestamp nullable, `stripe_usage_record_id` varchar(64) nullable, `created_at`.
- **Indexes:** unique (`workspace_id`, `period_month`); (`workspace_id`, `reported_to_stripe_at`).
- **FKs:** `workspace_id` cascade.
- **Tenancy:** `workspace_id`. Scoped.
- **Why:** Settings → Billing current-month cost preview; invoice history reconciliation.
- **Migration delta:** new table.

### 1.13 Channel mappings

#### `channel_mappings`

- **Status:** KEEP.
- **Purpose:** UTM source/medium → named channel mapping. Global seeds + workspace overrides.
- **Columns:** `id`, `workspace_id` nullable (NULL = global seed), `utm_source_pattern`, `utm_medium_pattern` nullable, `channel_name`, `channel_type` CHECK (10 values: `paid_social`, `paid_search`, `organic_search`, `organic_social`, `email`, `sms`, `direct`, `referral`, `affiliate`, `other`), `is_global`, `is_regex`, `priority` smallint default 100 (**NEW** — UX `/integrations` Channel Mapping tab "drag-handle; first match wins, lowest number = highest priority"), `created_by` bigint FK users nullable, timestamps.
- **Indexes:** (`workspace_id`, `utm_source_pattern`); partial unique `channel_mappings_workspace_unique` on (`workspace_id`, `utm_source_pattern`, `COALESCE(utm_medium_pattern,'')`) WHERE `workspace_id IS NOT NULL`; partial unique `channel_mappings_global_unique` on (`utm_source_pattern`, `COALESCE(utm_medium_pattern,'')`) WHERE `workspace_id IS NULL`; partial `idx_channel_mappings_global` on `utm_source_pattern` WHERE `workspace_id IS NULL`; **NEW** (`workspace_id`, `priority`).
- **Why:** `/integrations` Channel Mapping tab; Dashboard "Revenue by channel" BarChart; attribution resolution pipeline.
- **Migration delta:** add `priority` + `created_by`.

### 1.14 Reference + utility tables

#### `holidays`

- **Status:** KEEP.
- **Purpose:** Global holiday / commercial event reference.
- **Why:** anomaly suppression on known spike days; system annotations on charts.
- **Migration delta:** none.

#### `coupon_exclusions`

- **Status:** KEEP.
- **Purpose:** Coupons excluded from auto-promotion detection.
- **Why:** existing anomaly suppression; referenced by `annotations.annotation_type='promotion'` auto-detection.
- **Migration delta:** none.

#### `metric_baselines`

- **Status:** KEEP (only if anomaly detection ships v1 — current surface is TriageInbox via `anomaly_rules`; `metric_baselines` feeds statistical anomaly detection at the data layer).
- **Purpose:** Precomputed rolling median/MAD per metric per weekday.
- **Why:** `anomaly_rules` evaluates against these baselines.
- **Migration delta:** none.

### 1.15 Infrastructure tables (kept as-is)

| Table | Status | Note |
|---|---|---|
| `password_reset_tokens` | KEEP | Laravel default |
| `sessions` | KEEP | DB session driver |
| `cache` / `cache_locks` | KEEP | Laravel cache |
| `jobs` / `job_batches` / `failed_jobs` | KEEP | Horizon primary, DB fallback |

### 1.16 Performance / PSI tables (kept — not on v1 page specs, but load-bearing for future `/site` + used by `/products` inventory snapshot)

| Table | Status | Note |
|---|---|---|
| `store_urls` | KEEP | Used by future `/site` page; no v1 page renders it directly |
| `lighthouse_snapshots` | KEEP | Same |
| `uptime_checks` | KEEP | Partitioned |
| `uptime_daily_summaries` | KEEP | Rollup |

These four are intentionally not on any v1 page spec but are kept because they're cheap to maintain, already partitioned, and belong to the documented "future `/site` surface." Drop them if storage pressure arises.

---

## 2. ERD summary

Major relationships (Mermaid-ish):

```
workspaces ──┬─ workspace_users ─ users
             ├─ workspace_invitations
             ├─ billing_subscriptions ─ billing_subscription_items
             ├─ billing_revenue_share_usage
             ├─ workspace_targets
             ├─ saved_views
             ├─ public_snapshot_tokens
             ├─ digest_schedules / slack_webhooks / anomaly_rules / notification_preferences
             ├─ annotations
             ├─ settings_audit_log
             ├─ triage_inbox_items
             │
             ├─ stores ──┬─ orders ──┬─ order_items ─ products ─ product_variants
             │           │           ├─ order_coupons
             │           │           ├─ refunds
             │           │           └─ customers (via customer_id)
             │           ├─ products ─ product_categories (pivot)
             │           ├─ daily_snapshots
             │           ├─ hourly_snapshots
             │           ├─ daily_snapshot_products
             │           ├─ store_webhooks
             │           └─ store_cost_settings / shipping_rules / transaction_fee_rules / platform_fee_rules
             │
             ├─ customers ── customer_rfm_scores
             │           └─ customer_ltv_overrides
             │
             ├─ ad_accounts ─ campaigns ─ adsets ─ ads ─ ad_insights
             │                                     └─ ad_creative_tags ─ creative_tags ─ creative_tag_categories
             │
             ├─ search_console_properties ─ search_console_daily_rollups / _queries / _pages
             │
             ├─ integration_credentials (morphTo stores / ad_accounts / sc_properties)
             ├─ integration_runs (morphTo same)
             ├─ integration_events (morphTo same)
             ├─ historical_import_jobs (morphTo same)
             │
             ├─ channel_mappings (workspace-scoped overrides over global seeds)
             ├─ opex_allocations
             ├─ tax_rules (workspace-scoped overrides over global seeds)
             ├─ metric_baselines
             ├─ coupon_exclusions
             │
             └─ billing_workspace_id (self-FK → parent workspace for agency consolidation)

GLOBAL (no workspace_id):
  users · fx_rates · holidays · channel_mappings(NULL) · tax_rules(NULL) ·
  google_algorithm_updates · gsc_ctr_benchmarks · creative_tag_categories ·
  creative_tags · password_reset_tokens · sessions · cache · jobs*
```

---

## 3. Index audit

**Cross-cutting rules (enforced):**

1. Every tenant-scoped table has at least one index leading with `workspace_id`.
2. Every FK column is either indexed directly or covered by a composite leading with it.
3. Composite indexes on hot page-query paths (below).

**Hot query paths (from page specs) and covering indexes:**

| Page | Query shape | Index |
|---|---|---|
| `/dashboard` time series | `daily_snapshots WHERE workspace_id=? AND date BETWEEN ? AND ?` | `(workspace_id, date)` existing |
| `/dashboard` TodaySoFar | `hourly_snapshots WHERE workspace_id=? AND date=? AND hour<=?` | `(workspace_id, store_id, date)` existing |
| `/dashboard` ActivityFeed | `orders WHERE workspace_id=? ORDER BY occurred_at DESC LIMIT 50` | partial `idx_orders_ws_occurred_real` existing |
| `/orders` DataTable | `orders WHERE workspace_id=? AND occurred_at BETWEEN … AND status=? ORDER BY occurred_at DESC` | `(workspace_id, status, occurred_at)` existing |
| `/orders` source filter | `orders WHERE workspace_id=? AND attribution_last_touch->>'source'=?` | functional `idx_orders_attr_lt_source` existing |
| `/ads` campaign table | `ad_insights WHERE workspace_id=? AND campaign_id=? AND date BETWEEN …` + `level='campaign'` | `(workspace_id, campaign_id, date)` + partial `idx_ad_insights_ws_campaign_daily` existing |
| `/ads` DaypartHeatmap | `ad_insights WHERE workspace_id=? AND level='campaign' AND hour IS NOT NULL` | partial unique `ai_campaign_hourly_unique` existing |
| `/attribution` matrix | `orders WHERE workspace_id=? GROUP BY attribution_last_touch->>'channel'` | functional indexes existing; `daily_snapshots` per-source columns cover most cases |
| `/seo` Queries tab | `search_console_queries WHERE workspace_id=? AND date BETWEEN … ORDER BY impressions DESC` | `(workspace_id, date)` existing |
| `/products` DataTable | `daily_snapshot_products WHERE workspace_id=? AND snapshot_date BETWEEN … ORDER BY revenue DESC` | `(workspace_id, snapshot_date)` + `idx_dsp_ws_product_date` existing |
| `/products` missing COGS | `product_variants WHERE workspace_id=? AND cogs_amount IS NULL` | **NEW partial** `(workspace_id) WHERE cogs_amount IS NULL` |
| `/profit` line items | `daily_snapshots WHERE workspace_id=? AND date BETWEEN …` — per-source + profit cols on same table, single query | existing |
| `/customers` Segments tab | `customer_rfm_scores WHERE workspace_id=? AND segment=? AND computed_for=?` | **NEW** `(workspace_id, segment, computed_for)` |
| `/customers` Gateway table | `customers WHERE workspace_id=? AND acquisition_product_id=?` | **NEW** `(workspace_id, acquisition_product_id, first_order_at)` |
| `/integrations` freshness | `integration_runs WHERE workspace_id=? AND integrationable_id=? ORDER BY created_at DESC LIMIT 1` | existing `(workspace_id, integrationable_type, integrationable_id)` |
| `/integrations` errors | `integration_events WHERE workspace_id=? AND status='failed' ORDER BY received_at DESC` | **NEW partial** `(workspace_id, received_at DESC) WHERE status='failed'` |
| `/alerts` Triage | `triage_inbox_items WHERE workspace_id=? AND status='open' ORDER BY created_at DESC` | existing (renamed) |

**Partial indexes to keep (documented state):** all current partial indexes on `orders` (`idx_orders_ws_occurred_real`, `idx_orders_first_customer`, `idx_orders_customer_hash_occurred`, `idx_orders_attribution_occurred_real`) remain load-bearing.

**Index NOT recommended at MVP:**
- No BRIN indexes yet — row counts don't justify.
- No GIN indexes on jsonb except the functional expressions already listed. Revisit if `/attribution` Attribution Time Machine starts scanning jsonb at scale.

---

## 4. JSONB + api_version audit

| Table | JSONB column | Owner | Paired `*_api_version` | Notes |
|---|---|---|---|---|
| `orders` | `raw_meta` | platform | `raw_meta_api_version` | correct |
| `orders` | `attribution_first_touch` / `_last_touch` / `_last_non_direct` / `_click_ids` / `_source_survey` | Nexstage | — | correct (no pairing) |
| `orders` | `platform_data` | Nexstage | — | correct |
| `refunds` | `raw_meta` | platform | `raw_meta_api_version` | correct |
| `ads` | `creative_data` | platform | `creative_data_api_version` | correct |
| `ad_insights` | `raw_insights` | platform | `raw_insights_api_version` | correct |
| `lighthouse_snapshots` | `raw_response` | platform | `raw_response_api_version` | correct |
| `integration_events` | `payload` | platform | **NEW** `payload_api_version` | correct — mandated by principle §3 |
| `webhook_logs` | `payload` | platform | — | **DROP THE TABLE** (merged into `integration_events` which has pairing) |
| `workspaces` | `workspace_settings` | Nexstage VO | — | correct |
| `workspaces` | `billing_address` | Nexstage | — | correct |
| `users` | `view_preferences` | Nexstage | — | correct |
| `campaigns` | `parsed_convention` / `previous_names` | Nexstage | — | correct |
| `historical_import_jobs` | `checkpoint` | Nexstage | — | correct |
| `integration_runs` | `payload` | Nexstage | — | correct |
| `integration_credentials` | `scopes` | Nexstage | — | correct |
| `triage_inbox_items` | — | — | — | no jsonb |
| `annotations` | `is_hidden_per_user` | Nexstage | — | correct |
| `saved_views` | `url_state` | Nexstage | — | correct |
| `public_snapshot_tokens` | `url_state` / `snapshot_data` | Nexstage | — | correct |
| `digest_schedules` | `recipients` / `content_pages` | Nexstage | — | correct |
| `anomaly_rules` | `delivery_channels` | Nexstage | — | correct |
| `customers` | `tags` | Nexstage | — | correct |

**Audit outcome:** every external-payload jsonb pairs with an api_version column. Current schema's `webhook_logs.payload` lacks pairing — fixed by retiring the table into `integration_events`.

---

## 5. Deprecations (DROP list)

Tables removed from target schema. All data either migrates into another table or is not load-bearing for any v1 page.

| Table | Drop reason | Data migration |
|---|---|---|
| `ai_summaries` | UX §2 excludes AI assistant in v1. | None — archive. |
| `alerts` | Duplicates `triage_inbox_items` responsibility. | Upsert open rows into `triage_inbox_items`. |
| `recommendations` | Nexstage is non-prescriptive in v1 (anti-Atria). Lifecycle chips + Triage columns compute from snapshots. | None — archive. |
| `product_affinities` | `/products` drawer v1 uses co-occurrence count only (Market Basket is v2). | None. |
| `google_ads_keywords` | Not on any v1 page spec. | None. |
| `daily_notes` | Merged into `annotations`. | Upsert to `annotations` with `author_type='user'`, `annotation_type='user_note'`. |
| `workspace_events` | Merged into `annotations`. | Upsert with `annotation_type IN ('promotion','expected_spike','expected_drop')`. |
| `webhook_logs` | Merged into `integration_events` (`direction='inbound'`). | Upsert recent rows (last 30d); archive older. |
| `sync_logs` | Renamed to `integration_runs`. | Rename in place. |
| `product_costs` | Replaced by `product_variants.cogs_amount`. Historical COGS never retroactively restates (per dictionary). | Fold latest `unit_cost` into `product_variants.cogs_amount`. |
| `inbox_items` | Renamed to `triage_inbox_items` with added columns. | Rename + backfill `severity`, `deep_link_url`. |
| `subscriptions` | Renamed to `billing_subscriptions` (avoid collision with subscription-revenue domain). | Rename in place. |
| `subscription_items` | Renamed to `billing_subscription_items`. | Rename. |
| `gsc_daily_stats` | Renamed to `search_console_daily_rollups`. | Rename + add `data_state`. |
| `gsc_queries` | Renamed to `search_console_queries`. | Rename + add `data_state`. |
| `gsc_pages` | Renamed to `search_console_pages`. | Rename + add `data_state`. |

**Columns dropped on kept tables:**

- `workspaces`: `has_store`, `has_ads`, `has_gsc`, `has_psi`, `country`, `region`, `timezone`, `target_roas`, `target_cpo`, `target_marketing_pct`, `utm_coverage_pct`, `utm_coverage_status`, `utm_coverage_checked_at`, `utm_unrecognized_sources`.
- `stores`: `auth_key_encrypted`, `auth_secret_encrypted`, `access_token_encrypted`, `refresh_token_encrypted`, `webhook_secret_encrypted`, `token_expires_at`, `cost_settings`, all `historical_import_*` state cols, `target_roas`, `target_cpo`, `target_marketing_pct`, `type` (duplicate of `platform`).
- `ad_accounts`: encrypted tokens + `historical_import_*`.
- `search_console_properties`: encrypted tokens + `historical_import_*`.
- `campaigns`: `target_roas`, `target_cpo`.
- `products`: `stock_status`, `stock_quantity`.

---

## 6. Open questions for PLANNING.md

Decisions that couldn't be resolved from UX + page specs + audit alone. Each is an explicit option list — pick in PLANNING before migration.

1. **`integration_credentials` polymorphic vs per-integration FKs.** Polymorphic is chosen above (one table, easy rotation). Alternative: three FK columns (`store_id`, `ad_account_id`, `search_console_property_id` — all nullable with CHECK exactly-one-non-null). Pro polymorphic: adding TikTok/Klaviyo connectors is a zero-schema-change operation. Pro explicit FK: cascades are DB-guaranteed, no trigger needed. **Recommend polymorphic** (pattern already in use for `integration_runs`).

2. **`customers.email_hash` uniqueness scope.** `(workspace_id, email_hash)` unique (chosen above) means the same person shopping at two stores inside one workspace stitches into one customer record. Alternative: `(store_id, email_hash)` — keeps per-store identity separate, matches current `orders.customer_id` per-store scalar. **Recommend `(workspace_id, email_hash)`** because `/customers` LTV and Gateway Table are workspace-level.

3. **`daily_snapshots` per-source revenue columns vs a separate `daily_snapshots_by_source` table.** Inline columns (chosen above) means 7 NULLable columns; query is a single `SELECT`. Alternative: narrow child table keyed on `(workspace_id, store_id, date, source)` — more normalised, same row count × 7. **Recommend inline** for MVP query simplicity; revisit if the row gets too wide (>30 cols).

4. **Subscription commerce (Recharge / Shopify Subscriptions) schema.** `/customers` spec says "info chip on Retention tab: Subscription metrics coming in v2" but the schema should "acknowledge a `subscription_id` dimension on orders if the store has Recharge/Shopify Subscriptions connected." **Option A:** add nullable `orders.subscription_id` + `orders.is_subscription_order` now (minimal). **Option B:** defer entirely until v2. **Recommend A** — one nullable column is cheap and un-blocks the Retention tab info chip.

5. **`customer_rfm_scores` vs a stored segment flag on `customers`.** Nightly full recompute into a new table (chosen above) preserves history and enables cohort pacing. Alternative: a single `customers.current_segment` column, no history. **Recommend the history table** — `/customers` Retention tab pacing view requires prior-cohort segments.

6. **`annotations.scope_id` as polymorphic vs dedicated per-scope tables.** One table with `(scope_type, scope_id)` is chosen. Alternative: seven tables (`workspace_annotations`, `store_annotations`, `campaign_annotations`, …). **Recommend one table** — UX §5.6.1 treats them as a single primitive; per-scope tables would multiply joins.

7. **Agency white-label fields on `workspaces` (UX §2 out-of-scope v1; multistore doc §Agency tier features).** Schema-ready flags now or defer entirely? **Options:** (A) add nullable `agency_logo_url`, `agency_primary_color`, `agency_email_from` now; (B) defer to v2 migration. **Recommend B** — no v1 page reads these, and a v2 migration is cheap.

8. **Where does the `Not Tracked` value materialise for `/attribution` Time Machine scrubber?** Spec says "reconstructed from `daily_snapshots` without rerunning `RevenueAttributionService`." Options: (A) derive at query time from the 7 new per-source revenue columns (chosen by implication); (B) add an 8th column `revenue_not_tracked_computed`. **Recommend A** — derivation is `revenue_real_attributed - (revenue_facebook_attributed + revenue_google_attributed + revenue_gsc_attributed + revenue_direct_attributed + revenue_organic_attributed + revenue_email_attributed)` and storing it would risk drift.

9. **`workspace_targets.metric` as a varchar slug vs a seeded `metrics` lookup table.** Slug chosen above for simplicity. Alternative: `metrics` table keyed by slug with display name, unit, formula. **Recommend the slug** for MVP; revisit once we add user-defined / composite metrics.

10. **Where does the audit log for non-settings actions live (e.g., "User X deleted saved view Y")?** `settings_audit_log` is scoped to the six Settings sub-pages. Options: (A) widen to a single global `audit_log` table; (B) keep Settings-only and punt non-settings audit to logs. **Recommend B** — the UX spec only surfaces audit on Settings sub-pages. Non-settings destructive actions have the 10-second undo Toast (UX §6.2) which the audit trail doesn't need to duplicate.

11. **`triage_inbox_items.itemable_type` morph targets.** Currently target `Alert`, `AiSummary`, `DailyNote`. Target state: `Annotation` (for flagged events), `IntegrationEvent` (for delivery failures surfaced to Triage), `HistoricalImportJob` (for completed/failed imports). No PHP-model `Alert` to morph to once it's dropped. **Recommend**: migrate Alert morphs to `IntegrationEvent` or `Annotation` during drop-alerts migration.

12. **Portfolio / multi-workspace currency at `MetricCardPortfolio`.** Conversion happens at transaction date per UX §9 via `FxRateService`. Does `billing_revenue_share_usage` convert at period-end instead? Multistore billing consolidation makes this ambiguous. **Recommend**: usage record stores native workspace `reporting_currency`; agency invoice FX-converts agency-parent currency at invoice issue date via Stripe-reported totals. Flag for PLANNING to lock.
