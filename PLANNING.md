# Nexstage — Product Plan

**Status (April 2026):** Pre-launch, no live users. Phases 0 through 1.6 complete. Next: **Phase 2 — Shopify**.

This is the source of truth for product decisions. PROGRESS.md tracks build state. CLAUDE.md holds coding conventions. RESEARCH.md holds verified facts.

---

## 1. Core Thesis

**Paid ads + organic search + site performance + ecommerce data in one unified view — for WooCommerce and Shopify SMBs doing €5k–€500k monthly GMV.**

Nexstage answers **"why did my revenue change?"** by joining four data pillars nobody else combines well, then surfacing the disagreement between what platforms claim and what actually happened.

**Defensible wedges:** WooCommerce-native depth, site health as first-class ecom signal, cross-channel anomaly correlation, EU/GDPR positioning, cross-integration recommendations.

**Do NOT compete on:** integration count, SEO tool bundles, report-builder flexibility, GA4 (never), content sites.

Stay narrow, go deep.

---

## 2. Trust Thesis

**Ad platforms lie**, in different specific ways. The store database is the closest thing to ground truth. Facebook over-reports via iOS14 modeled attribution. Google Ads claims credit through its own model. Both claim the same conversion. Both claim conversions that never happened.

Nexstage's job is not to resolve the disagreement — it's to make it visible when users want to see it, and show one clear number when they don't.

**Default = one number. Drill-down = the full story.**

Implications:
- Six source badges on MetricCard: Store, Facebook, Google, GSC, Site, **Real** (gold lightbulb)
- "Not Tracked" terminology replaces "unattributed revenue"
- Signed Not Tracked values (negative = iOS14 inflation). Banner fires once when crosses -5% threshold
- Target ROAS as first-class concept: `actual / target` with green/red when set, bare number when not
- Platform ROAS vs Real ROAS side-by-side on Campaigns
- `<WhyThisNumber />` affordance on every MetricCard

---

## 3. Tech Stack

- Laravel 12 + Inertia + React 19 + TypeScript + Tailwind
- Postgres, Horizon + Redis, Laravel Cashier + Stripe
- Multi-tenant: WorkspaceContext + WorkspaceScope + SetActiveWorkspace middleware
- AI: Claude Sonnet 4.6 (`claude-sonnet-4-6`)

### Multi-tenancy rules

All tenant tables: `workspace_id` FK + CASCADE + WorkspaceScope on model. Exceptions: `order_items` (via order_id), `product_category_product` (pivot), `holidays` (global), `channel_mappings` (workspace + global seed rows).

### Queue rules

All synced data: `upsert()` only. Failure chain: failed → `consecutive_sync_failures` → alert → `status='error'` at 3+. Rate limit: `$this->release($retryAfter ?? 60)`. Token expiry: `$this->fail($e)`. Per-provider queue routing — see section 22.

---

## 4. Platform-Agnostic Discipline (HARD RULE)

Every new feature goes through `StoreConnector`. The test: adding Shopify in Phase 2 means "new connector class" or "rewrite half the codebase"? If the latter, abstraction failed.

**Requirements:**
- Capability flags: `supportsHistoricalCogs()`, `supportedAttributionFeatures()`, `supportsMultiTouch()`
- UI labels platform-neutral ("Store" not "WooCommerce")
- No direct WC/Shopify API calls outside their connector classes
- Webhook handlers normalise payloads before service layer sees them
- `platform_data JSONB` escape hatch where needed

**The database schema is the abstraction layer.** Every connector writes to the same tables with the same column meanings. Platform-specific mapping lives inside each connector's sync job. Service layer reads tables directly and never sees platform differences.

We do NOT create `NormalisedOrder` value classes. They add ceremony without benefit. The database is already the common shape. Extending to BigCommerce/Magento in Phase 4 means writing a connector class that maps their API fields to our existing columns.

### Interface

```php
interface StoreConnector {
    public function testConnection(): bool;
    public function syncOrders(Carbon $since): int;
    public function syncProducts(): int;
    public function syncRefunds(Carbon $since): int;
    public function registerWebhooks(): array;
    public function removeWebhooks(): void;
    public function getStoreInfo(): array;
    public function supportsHistoricalCogs(): bool;
    public function supportedAttributionFeatures(): array;
    public function supportsMultiTouch(): bool;
}
```

Product identity across platforms: same product on Woo + Shopify = separate by default. Manual link action. Never auto-merge by SKU.

---

## 5. Database Schema

Reflects current state plus the Phase 1.5 finalisation pass (section 5.5). After the pass, schema is stable through Phase 2.

### Existing tables with Phase 1.5 additions

**`workspaces`** — existing columns plus **`workspace_settings JSONB NULL`** (section 5.6).

**`stores`** — existing plus **`platform VARCHAR(32) NOT NULL DEFAULT 'woocommerce'`** (connector routing), **`primary_country_code CHAR(2) NULL`** (ISO 3166-1 alpha-2, prompted at store creation — section 5.7).

**`orders`** — existing plus:
- `attribution_source VARCHAR(32) NULL` — which parser source won (pys / wc_native / referrer / none)
- `attribution_first_touch JSONB NULL` — `{source, medium, campaign, content, term, landing_page, timestamp}`
- `attribution_last_touch JSONB NULL` — same shape
- `attribution_click_ids JSONB NULL` — `{fbc, fbp, gclid, msclkid}` (Phase 4 CAPI enabler)
- `attribution_parsed_at TIMESTAMP NULL`
- Index: `idx_orders_attribution_source (workspace_id, attribution_source)`

Orders are **hard-deleted** when they disappear from the store. Nightly `ReconcileStoreOrdersJob` detects disappearances and removes them. We stay synced with the store's truth (test orders, cancellations).

**`order_items`** — existing plus **`unit_cost DECIMAL(12,4) NULL`** (WC order item meta; Shopify daily snapshot), **`discount_amount DECIMAL(12,4) NULL`** (Shopify per-line allocations; WC proportional split).

**`campaigns`** — existing plus:
- `previous_names JSONB NOT NULL DEFAULT '[]'` — rename fallback
- `parsed_convention JSONB NULL` — `{country, campaign, target, raw_target}`

**`ad_insights`** — no changes. Country-level spend attribution derives from `campaigns.parsed_convention->>'country'` with `stores.primary_country_code` as fallback. No country column on `ad_insights`.

**`workspace_events`, `daily_notes`** — each adds `scope_type VARCHAR(16) NOT NULL DEFAULT 'workspace'` with CHECK `IN ('workspace','store','integration')`, `scope_id BIGINT NULL`. Index `(workspace_id, scope_type, scope_id)`.

**`store_webhooks`** — existing plus **`last_successful_delivery_at TIMESTAMP NULL`** (section 21).

**`daily_snapshot_products`** — existing plus **`unit_cost DECIMAL(12,4) NULL`** (Shopify COGS fallback, dormant on WC), **`stock_status VARCHAR(32) NULL`** (`instock` / `outofstock` / `onbackorder`), **`stock_quantity INTEGER NULL`** (historical daily stock state — current state already lives on `products`, but snapshots enable transition detection and stock-aware analytics; see section 5.8).

### New tables created in Phase 1.5

**`channel_mappings`** — classify `(utm_source, utm_medium)` into named channels.
- `id`, `workspace_id BIGINT NULL FK workspaces` (NULL = global seed), `utm_source_pattern VARCHAR(255) NOT NULL` (lowercase exact match), `utm_medium_pattern VARCHAR(255) NULL` (NULL = match any medium), `channel_name VARCHAR(120) NOT NULL` (e.g. "Email — Klaviyo"), `channel_type VARCHAR(32) NOT NULL` (email/paid_social/paid_search/organic_search/organic_social/direct/referral/affiliate/sms/other), `is_global BOOLEAN NOT NULL DEFAULT false`, timestamps
- Unique index `(workspace_id, utm_source_pattern, utm_medium_pattern)` with NULLs distinct
- Seeded with ~40 global rows (section 16.4)

**`product_costs`** — manual COGS fallback.
- `id`, `workspace_id`, `store_id`, `product_external_id VARCHAR(255)`, `unit_cost DECIMAL(12,4)`, `currency CHAR(3)`, `effective_from DATE`, `effective_to DATE NULL`, `source VARCHAR(16)` (csv/manual), timestamps

**`product_affinities`** — FBT output.
- `id`, `workspace_id`, `store_id`, `product_a_id`, `product_b_id`, `support DECIMAL(8,6)`, `confidence DECIMAL(8,6)`, `lift DECIMAL(8,4)`, `margin_lift DECIMAL(12,4)`, `calculated_at`
- `UNIQUE(workspace_id, store_id, product_a_id, product_b_id)`

### Future tables

- `recommendations` — Phase 3

### Schema rules

- JSONB holding raw platform API data MUST have paired `*_api_version VARCHAR(20)` (applies to `raw_meta`, `raw_insights`, `creative_data`, `lighthouse_snapshots.raw_response`). Does NOT apply to Nexstage-internal JSONB shapes (`attribution_*`, `parsed_convention`, `workspace_settings`, `previous_names`).
- Monetary: `DECIMAL(12,2)` for amounts, `DECIMAL(14,4)` for computed, `DECIMAL(12,4)` for unit_cost
- External platform IDs: `VARCHAR(255)`, never integers
- `created_at` on everything; `updated_at` only where records are modified after creation
- Partial unique indexes when nullable columns need uniqueness on the non-null subset

---

## 5.5 Schema Finalisation Pass (Phase 1.5 Step 1)

**Pre-launch advantage:** no real customer data yet, so all schema changes land as one batch at the start of Phase 1.5. `migrate:fresh --seed` runs once with the final shape. No incremental alter-tables across phases.

### Migration batch

1. `stores` ALTER — add `platform`, `primary_country_code`
2. `workspaces` ALTER — add `workspace_settings JSONB`
3. `orders` ALTER — add 5 attribution columns + index
4. `order_items` ALTER — add `unit_cost`, `discount_amount`
5. `daily_snapshot_products` ALTER — add `unit_cost`, `stock_status`, `stock_quantity`
6. `campaigns` ALTER — add `previous_names`, `parsed_convention`
7. `workspace_events` ALTER — add `scope_type`, `scope_id` with CHECK constraint
8. `daily_notes` ALTER — same scope additions
9. `store_webhooks` ALTER — add `last_successful_delivery_at`
10. `channel_mappings` CREATE + seed
11. `product_costs` CREATE
12. `product_affinities` CREATE

### Verification (before proceeding to Step 2)

- [x] `SELECT platform, primary_country_code FROM stores LIMIT 1` — platform `'woocommerce'`, country NULL
- [x] `\d orders` shows all attribution columns + index
- [x] `\d order_items`, `\d campaigns`, `\d workspace_events`, `\d daily_notes`, `\d store_webhooks` show additions
- [x] `SELECT COUNT(*) FROM channel_mappings WHERE is_global = true` returns ~40
- [x] `\d daily_snapshot_products` shows `unit_cost`, `stock_status`, `stock_quantity`
- [x] `\d product_costs`, `\d product_affinities` show full shape
- [x] `php artisan migrate:rollback` reverses cleanly

Only after this passes does Phase 1.5 proceed to Step 2.

---

## 5.6 `workspace_settings` JSONB

Catch-all for structured UI config that doesn't warrant dedicated columns:

```json
{
  "naming_convention": {
    "enabled": true,
    "separator": "|",
    "shape": "country_campaign_target"
  },
  "dashboard_preferences": {
    "show_source_badges_on_hero": false,
    "default_chart_series": ["revenue", "orders", "ad_spend"]
  },
  "ios14_banner_dismissed_at": null,
  "negative_not_tracked_banner_dismissed_at": null
}
```

Access via `WorkspaceSettings` value object or Eloquent cast — never raw array access. Schema changes to this JSONB do NOT require migrations. Queries never filter on subkeys at DB level. If something becomes a query filter, promote it to a dedicated column.

**Note:** fallback country for ad attribution lives on `stores.primary_country_code`, not here — it's a store-level query dimension, not workspace UI config.

---

## 5.7 Store primary country

`stores.primary_country_code CHAR(2) NULL` — the country a store primarily sells to. Used as fallback in campaign country attribution when the naming convention doesn't parse a country from the campaign name.

### When it's set

- **At store creation** — every store-creation flow (onboarding step 3, "add another store" in settings) shows a `<StoreCountryPrompt />` component with a country dropdown. ccTLD detection from `stores.website_url` pre-fills.
- **Skippable** — explicit "Skip for now" sets NULL. No nagging modal afterwards.
- **Always reversible** — store settings page shows the field prominently when NULL.

### UI behavior

- When NULL, store settings shows persistent informational notice: "No primary country set. Ad spend without country tagging will show as 'Country unknown' on analytics pages. [Set now]"
- When set, normal editable dropdown in store settings
- Never blocks — multi-country stores legitimately leave it NULL

### Query usage

Three-tier fallback for ad spend country attribution:

```sql
COALESCE(
  campaigns.parsed_convention->>'country',
  stores.primary_country_code,
  'UNKNOWN'
)
```

Implemented as a query scope or helper function so pages don't reimplement it.

### Why store-level, not workspace-level

- Multi-store workspaces can have a German store, a French store, an international store — each needs its own fallback
- It's a query dimension (join on it), not UI config
- Stores serve markets, workspaces don't

---

## 5.8 Stock tracking

`products.stock_status` and `products.stock_quantity` already exist from Phase 0 and hold the **current** stock state, synced from WooCommerce via `product.updated` webhook and periodic product sync. This is enough to answer "is X in stock right now" but not "when did X go out of stock" or "how long has it been out."

The Phase 1.5 schema pass adds `stock_status` and `stock_quantity` to `daily_snapshot_products` to capture **historical** daily state. `ComputeDailySnapshotJob` populates them from the current `products` row at snapshot time. No new job, no new webhook handler, daily resolution (which is enough — if a product flickers OOS at 10am and back at 2pm, we don't need to know, that's noise).

### Why `daily_snapshot_products` and not a new table

Stock and COGS are conceptually different (operational state vs economic state) but they share the same grain: one row per top product per store per day. Adding stock columns to the existing snapshot table keeps the daily product-state join simple and avoids a second table with the same shape. The conceptual separation lives in the code — different services read stock vs cost — not in the schema.

### What this enables

- **Out-of-stock transition detection** (Phase 1.6) — today's snapshot shows `outofstock`, yesterday's showed `instock` → fire a simple state-diff alert. No anomaly engine needed.
- **Days-of-cover calculation** — `stock_quantity / avg_daily_units_sold` for a low-stock warning badge on `/analytics/products` ("runs out in 3 days").
- **Winners/Losers OOS exclusion** — filter out currently-OOS products from the `/analytics/products` Winners/Losers ranking so sold-out top products don't look like they're dying from low sales.
- **Stock-aware campaign alerts** (Phase 3 recommendation, PLANNING section 18) — "You're spending €200/day on Facebook ads for a product that's been out of stock for 2 days." Needs history to answer "how long OOS."
- **Stock outage as anomaly correlation signal** — already in the Phase 3 single-cause chain (PLANNING section 17): "revenue dropped because your top product went OOS yesterday."

### Shopify parity

Shopify exposes `InventoryItem.available` and variant inventory via GraphQL. Phase 2 `ShopifyConnector` populates the same `stock_status` and `stock_quantity` columns on `products` (mapped from Shopify's inventory model) and on `daily_snapshot_products` via the same daily snapshot job. No service-layer changes — the schema is the abstraction.

---

## 6. Attribution Architecture

Every order passes through `AttributionParserService` to produce a normalised attribution record written to `orders.attribution_*` columns. The foundation every analytics page reads from.

### `AttributionParserService`

Single service with registered sources tried in priority order. **First source that returns a non-null result wins. Loop exits immediately.** No blending, no cross-source field filling, no enrichment.

```php
class AttributionParserService {
    public function __construct(
        private readonly array $sources,
        private readonly ChannelClassifierService $classifier,
    ) {}

    public function parse(Order $order): ParsedAttribution {
        foreach ($this->sources as $source) {
            $result = $source->tryParse($order);
            if ($result !== null) {
                return $result->withChannel(
                    $this->classifier->classify(
                        $result->lastTouch['source'] ?? null,
                        $result->lastTouch['medium'] ?? null,
                        $order->workspace_id,
                    )
                );
            }
        }
        return ParsedAttribution::notTracked();
    }
}
```

### `AttributionSource` interface

```php
interface AttributionSource {
    public function tryParse(Order $order): ?ParsedAttribution;
}
```

### Registered sources (priority order)

**WooCommerce:**

1. **`PixelYourSiteSource`** — reads `pys_enrich_data` meta, parses pipe-delimited UTMs, handles "undefined" literal as null. Also captures `pys_fb_cookie.fbc/fbp` (Phase 4 CAPI enabler). Section 6.2.
2. **`WooCommerceNativeSource`** — reads existing `orders.utm_*` columns (already populated from `_wc_order_attribution_*` at sync time). Maps flat columns into new JSONB shape. Single-touch — first_touch and last_touch identical.
3. **`ReferrerHeuristicSource`** — inspects `orders.source_type` and any referrer URL. Small set of rules (direct / organic / referral by domain).

**Shopify (Phase 2):**

1. **`ShopifyCustomerJourneySource`** — reads `Order.customerJourneySummary.firstVisit/lastVisit.utmParameters`. Native multi-touch.
2. **`ShopifyLandingPageSource`** — fallback when customerJourneySummary empty
3. **`ReferrerHeuristicSource`** — same shared class

Adding a new source later = adding one class and registering it. Service layer knows only about `ParsedAttribution`. Connectors know only about their own API.

### `ParsedAttribution` value object

Plain PHP value object (not Eloquent):
- `source_type: string` — `pys` / `wc_native` / `shopify_journey` / `shopify_landing` / `referrer` / `none`
- `first_touch: array|null` — `{source, medium, campaign, content, term, landing_page, timestamp}`
- `last_touch: array|null` — same; if only one touch known, first === last
- `click_ids: array|null` — `{fbc, fbp, gclid, msclkid}`
- `channel: string|null` — derived from ChannelClassifierService
- `raw_data: array|null` — optional debug blob

### Sync job integration

```php
$parsed = $this->attributionParser->parse($order);
$order->update([
    'attribution_source' => $parsed->source_type,
    'attribution_first_touch' => $parsed->first_touch,
    'attribution_last_touch' => $parsed->last_touch,
    'attribution_click_ids' => $parsed->click_ids,
    'attribution_parsed_at' => now(),
    // Existing utm_* columns continue to be written by WC native extraction
    // so RevenueAttributionService keeps working unchanged during rollout.
]);
```

**Feature flag during rollout:** parser writes to new columns but `RevenueAttributionService` continues reading from existing `orders.utm_*` columns until Phase 1.5 Step 14 (cutover) explicitly switches reads. Parser can ship and be tested in Step 3 without affecting the live `/campaigns` page.

### Debug route

`/admin/attribution-debug/{order_id}` renders the full parser pipeline: every source tried, whether it returned a match, what it extracted, why earlier sources were skipped. Built in Phase 1.5 Step 3. Essential for beta debugging.

### Backfill

After parser and sources are built, `BackfillAttributionDataJob` runs per workspace on the `low` queue. Re-processes every existing order through the pipeline. Full historical backfill, no 90-day window. Dispatched per workspace in Phase 1.5 Step 8. Progress visible via `/admin/system-health`.

---

## 6.2 PixelYourSite parser (verified format)

PYS stores attribution in a single serialized meta array `pys_enrich_data`:

```php
[
  'pys_landing'      => 'https://store.com/hu/',
  'pys_source'       => 'google.com',
  'pys_utm'          => 'utm_source:Klaviyo|utm_medium:email|utm_campaign:SPRING25|utm_content:undefined|utm_term:undefined',
  'pys_utm_id'       => 'fbadid:undefined|gadid:undefined|padid:undefined|bingid:undefined',
  'last_pys_landing' => 'https://store.com/hu/',
  'last_pys_source'  => 'google.com',
  'last_pys_utm'     => 'utm_source:Klaviyo|utm_medium:email|utm_campaign:SPRING25mail201|...',
  'last_pys_utm_id'  => 'fbadid:undefined|gadid:undefined|padid:undefined|bingid:undefined',
  'pys_browser_time' => '09-10|Monday|March',
]
```

Sibling meta keys also present:
- **`pys_fb_cookie`** — `{fbc, fbp}` (Phase 4 CAPI enabler)
- **`pys_ga_cookie`** — `{clientId, sessions}`
- **`pys_enrich_data_analytics`** — `{orders_count, avg_order_value, ltv}`

**Parser rules:**
- Treat literal string `"undefined"` as null
- Parse pipe-delimited `pys_utm` strings into structured fields
- Detect PYS by checking for `pys_enrich_data` key on any order in current sync batch; cache detection on store record

**Real-world validation:** A test order placed via Klaviyo email on Android. WC native recorded `source_type: organic, utm_source: google` because the most recent session referrer was google.com. PYS recorded the actual: `utm_source: Klaviyo, utm_medium: email`. For any store running Klaviyo, PYS-first is a correctness requirement.

---

## 7. COGS Architecture

### WooCommerce — order item meta

WC snapshots cost into order item meta at creation. Three sources priority-ordered:
1. **WC core COGS feature** — `WC_Order_Item::get_cogs_value()`
2. **WPFactory free plugin** — `_alg_wc_cog_item_cost`, `_alg_wc_cog_item_profit`, etc.
3. **WooCommerce.com Cost of Goods extension** — `_wc_cog_*`

Read whichever is present, write to `order_items.unit_cost`. Free historical accuracy.

### Shopify — daily snapshot fallback

Shopify does NOT snapshot cost into orders. Only `InventoryItem.unitCost` (current) is exposed.
- Snapshot `InventoryItem.unitCost` nightly into `daily_snapshot_products.unit_cost`
- For each order, look up snapshot from order date (or closest prior)
- Pre-snapshot orders use current cost with "historical estimate" badge

### No platform source

Prompt to install Cost of Goods for WooCommerce (free) or upload CSV. Manual data in `product_costs` table.

### Capability flag

```php
WooCommerceConnector::supportsHistoricalCogs(): bool {
    // true if any of the three plugin sources detected
}
ShopifyConnector::supportsHistoricalCogs(): bool { return false; }
```

---

## 8. Scope Filtering (cross-cutting, Phase 1.5)

Every analytics page filterable by `(store, integration, date_range)`. Third axis on top of BreakdownView's `breakdownBy` and `cardData`.

- Sticky scope selector at top of every analytics page. Default: all stores, all integrations.
- Persists in URL (for sharing) and `view_preferences` (for user default)
- Every metric query accepts `(workspace_id, store_ids?, integration_ids?, date_range)`
- Charts: "compare Store A vs Store B" shows two series; "all" shows totals

Built once as shared component in Phase 1.5 Step 9. Used by every page in Phase 1.6. Daily notes and workspace events gain `scope_type` + `scope_id` so annotations render only when scope matches.

---

## 9. Pricing

| Tier | Target | Price | Cap |
|---|---|---|---|
| Trial | Everyone | €0 | 14 days, Growth features |
| **Starter** | Small ecom | **€119/mo** | 1 store, ≤€20k GMV OR ≤€5k spend |
| **Growth** | Mid-market | **€249/mo** | ≤€80k GMV OR ≤€20k spend |
| **Scale** | Above €80k GMV | **0.55% GMV + 1% spend, min €499/mo** | uncapped |
| **Enterprise** | Multi-store, agencies | custom ~€1500+/mo | custom |

All tiers get full features. Only billable volume differs.

**Annual discount:** 20% off Starter and Growth. Auto-tier assignment after trial.

**Billing basis:** `has_store` → GMV, otherwise ad spend. Both → GMV only (no double-count).

**14-day trial:** full access, no card. Expiry → freeze → 30d suspend (archive) → 90d delete. Reactivation backfill dispatches catch-up sync for the gap.

Site tier dropped. Content sites are different ICP.

---

## 10. Onboarding

1. Register → verify email
2. Single screen with three connection tiles: Store, Ad Accounts, GSC. No forced order.
3. Store connected → **`<StoreCountryPrompt />`** → import date → import → dashboard
4. Only ads/GSC → straight to dashboard
5. Invited users skip

### Country auto-detection

1. ccTLD from domain (.de → DE) — pre-fills store prompt
2. IP geolocation on first login — Phase 1.5
3. Stripe billing country when payment added — Phase 1.5
4. Always override-able in store settings

Country triggers `RefreshHolidaysJob`.

---

## 11. Navigation

```
Overview                    → /dashboard

--- Channels ---
Paid Ads                    → /campaigns
Organic Search              → /seo
Site Performance            → /performance
Acquisition                 → /acquisition         [Phase 1.6]

--- Analytics ---
Daily Breakdown             → /analytics/daily
By Product                  → /analytics/products
By Country                  → /countries
Discrepancy                 → /analytics/discrepancy [Phase 1.6]

--- Stores ---
All Stores                  → /stores
[individual store]          → /stores/{slug}/overview

Insights                    → /insights

--- Manage ---
Tag Generator               → /manage/tag-generator
Naming Convention           → /manage/naming-convention [Phase 1.6]
Channel Mappings            → /manage/channel-mappings  [Phase 1.6]
Winners | Losers ▾          → dropdown: Campaigns / Products / Stores

--- Settings ---
Profile, Workspace, Team, Integrations, Billing, Notifications, Events
```

Sidebar sections render conditionally on workspace integration flags.

---

## 12. Dashboard

Mental progression: **big numbers → real numbers → where they come from → recommendations.**

**Hero row** (3 cards, large): Revenue, Orders, Attention indicator. `<WhyThisNumber />` on each.

**Real row** (4 cards, gold lightbulb; only when `has_store && has_ads`):
- Real ROAS (vs target)
- Marketing % (vs target)
- Real CPO (vs target)
- Not Tracked % (signed)

**Recommendations card** (Phase 3) — after Real row.

**Channel rows** (collapsible): Store / Paid / Organic / Site Health. Sections with active alerts auto-expand. Absent sections collapse with connection prompt. "Show advanced metrics" expands to CPM/CPC/per-platform.

**Chart:** MultiSeriesLineChart with toggleable series. Event overlays (holidays, workspace_events, daily_notes — scope-filtered). Prior-period dashed overlay.

**Widgets:** Daily avg delta ("Last 7 days vs prior 7"), Latest orders feed (webhook-gated).

**`<DataFreshness />` indicator** — top-right corner of every page header.

### Design principles

1. One screen with progressive disclosure
2. Product images on every product row
3. Saved filtered views per user
4. `<DataFreshness />` visible on every screen
5. Period comparison built into every metric card
6. `<WhyThisNumber />` on every MetricCard
7. Action language — "ROAS held at 1.8x — below target. See campaigns ↓"
8. Recommendations prominent (Phase 3)

**Cut from scope:** Recap-as-page, TV Mode, team message board, multi-store comparison within workspace.

---

## 12.5 Analytics Page Specs

Every analytics page answers ONE question. If a page can't answer a single clear question, it shouldn't exist. This section is the coding agent's acceptance test.

### `/campaigns` — refinement

**Question:** Which paid campaigns are hitting target, and which aren't?

**Current state:** Good. Trust-thesis columns present. Stacked bar chart, QuadrantChart toggle, period delta, W/L chips. **Refinement not rewrite.**

**Hero (4 cards):** Total ad spend, Real ROAS (vs target), Real CPO (vs target), Not Tracked %.

**Table columns (keep existing):** Campaign + platform badge, Status, Spend, Platform ROAS (muted blue), Real ROAS (prominent, target-relative coloring), Real CPO, Attr. Revenue, Attr. Orders, Velocity, Impressions, Clicks, CTR, CPC, drill arrow.

**Default sort:** Real ROAS descending.

**W/L classifier:** default `vs Target` when set, `vs Peer Average` otherwise. Period available via dropdown, not default.

**Backend:** server-side ranking `GET /campaigns?filter=winners|losers&classifier=target|peer|period`. Phase 1.5 Step 10.

**Drill-down:** `/campaigns/{id}` with adset-level BreakdownView, creative previews from `ads.creative_data`, day-of-week heatmap, Platform-vs-Real side-by-side.

### `/analytics/products` — rewrite

**Question:** Which products make me money, not just revenue?

**Current state:** Revenue, Rev vs prior, Units, Units vs prior. W/L is top-10-by-revenue (pure rank per code comment). No ROAS/margin/profit. Rewrite answers profit.

**Hero (4 cards):** Total products sold, Total revenue, Total contribution margin (Real, when COGS configured), Avg margin % (Real, when COGS configured).

**Table columns:** Product image + name, Orders, Units, Revenue, COGS, Contribution margin, Margin %, Attributed ad spend (UTM match via naming convention), Real profit/unit, Stock status, Days of cover (when stock tracked), 14-day trend dots.

**Stock-aware behavior:** Stock status column renders with a coloured dot (green in-stock, amber low-stock, red out-of-stock). Days of cover computed as `stock_quantity / avg_daily_units_sold_last_14_days`, displayed as "3 days left" when ≤7, blank otherwise. Winners/Losers classifier excludes currently-OOS products from ranking — a sold-out top performer shouldn't appear in "Losers" just because its sales collapsed when it went out of stock.

**Default sort:** Contribution margin desc when COGS configured, revenue desc otherwise.

**Empty state without COGS:** hide margin columns, show dismissible banner: "Add product costs to see real profit. Install Cost of Goods for WooCommerce (free) or upload a CSV." Sort defaults to revenue.

**W/L classifier:** default `vs Peer Average` (margin above/below workspace-average, min ≥N orders floor). Period available.

**Graph view:** scatter using generalised QuadrantChart. x = revenue, y = margin %, size = units, color = stock status. Top-right = profit winners. Top-left = low-volume high-margin. Bottom-right = revenue traps.

**Drill-down:** product detail page with order history, variation breakdown, FBT list, attributed sources.

### `/countries` — rewrite (side-by-side)

**Question:** Which countries bring me profitable orders, and which am I wasting spend on?

**Current state:** Table-only. Rank, Country, Share bar, Revenue. Drill panel with top products. No visualisations, no BreakdownView.

**Hero (4 cards):** Countries with orders, Top country revenue share, Countries above workspace-avg margin, Total profitable Real ROAS countries (when has_ads).

**Table columns (side-by-side):** Country flag + name, Orders, Revenue, Share (keep inline bar), GSC clicks (Organic), FB spend (Facebook), Google spend (Google), Real ROAS (Real), Contribution margin (Real), Real profit (Real).

Ad spend by country comes from `COALESCE(campaigns.parsed_convention->>'country', stores.primary_country_code, 'UNKNOWN')`. "Country unknown" row at bottom of table.

**Default sort:** Real profit desc.

**W/L classifier:** default `vs Peer Average` (Real ROAS above/below workspace-avg), min ≥20 orders.

**Drill-down:** `/countries/{code}` with top products (KEEP existing two-column panel), top campaigns by spend, top GSC queries, Real ROAS breakdown.

### `/seo` — refinement

**Question:** Where is my organic search revenue coming from, and where's the upside?

**Current state:** 4 metric cards (Clicks, Impressions, CTR, Position), GscMultiSeriesChart, Queries table, Pages table. No CTR callouts.

**Hero:** keep existing 4, add **Organic revenue** (Real) from `orders.attribution_last_touch` where `channel_type = 'organic_search'`. 5 cards total.

**Queries/Pages tables:** keep existing columns, add estimated organic revenue (clicks × CVR × AOV for organic orders, displayed as range).

**W/L classifier:** default `vs Peer Average` — CTR above/below benchmark for position bucket (1-3, 4-10, 11-20, 21+). SEO has no target concept.

**CTR opportunities section:** Phase 3.

**Drill-down:** query → `/seo/query/{query}` with position history, pages ranking, clicks over time.

### `/acquisition` — new page (Phase 1.6 flagship)

**Question:** Which traffic sources bring me orders, not just visitors?

**Core:** Rows = channels (via `ChannelClassifierService`), columns = volume + conversion + profit. Answers "where should my next euro go?" by exposing CVR alongside volume.

**Hero (4 cards):** Total orders, Total revenue, Top converting source (Real — highest CVR above volume floor), Total Real profit (Real).

**Main table:**

| Source | Clicks/Visits | Orders | CVR | Revenue | Real Profit |

- **Clicks/Visits** populated for sources with click data (GSC, ad platforms). Email/Direct/Social/Referral/SMS show `—` with tooltip: "Visit tracking for this channel requires our Nexstage plugin (Phase 4)."
- **CVR** computed only when Clicks populated. Blank for email/direct/etc.
- **Orders** and **Revenue** always populated from store attribution.

**Default sort:** Real profit desc.

**"Other tagged" row** at bottom aggregates unclassified `(utm_source, utm_medium)` combos. Expand to sheet with one-click classify (section 16.7).

**"Not Tracked" row** at bottom drills to `/analytics/discrepancy`.

**Graphs:**
- **QuadrantChart (default):** x = clicks, y = CVR, size = revenue. Generalised QuadrantChart. Plots only sources with click data.
- **Line chart:** Revenue over time, top-5 sources as lines. Stacked area mode deferred to Phase 3.

**W/L classifier:** default `vs Peer Average` (CVR above/below workspace average for sources with click data).

**Drill-down:** Paid — Facebook → `/campaigns?platform=facebook`. Organic — Google → `/seo`. Email / Other → `/acquisition/{channel_slug}` (Phase 3).

**Dependency:** requires `AttributionParserService` and `ChannelClassifierService` live. Cannot build before Phase 1.5 Steps 3-4.

### `/analytics/daily` — migration

**Question:** What happened yesterday, and how does it compare to a normal day?

**Current state:** Manual table (not BreakdownView).

**Hero (4 cards):** Yesterday's revenue, Yesterday's orders, Yesterday's Real ROAS (when has_ads), vs weekday avg delta ("+14% vs 4-Monday avg").

**Main:** BreakdownView `breakdownBy='date'`, day-of-week-aware columns. Default sort: date desc.

**W/L classifier:** default `vs Peer Average` where peer = same weekday in last 4 weeks.

**Drill-down:** click day → side panel with top orders, top sources, top products, events fired.

### `/analytics/discrepancy` — new (Phase 1.6)

**Question:** Where do my ad platforms disagree with my store data, and how much revenue is at stake?

**Core:** "Trust thesis on demand" page. Full Platform ROAS vs Real ROAS breakdown. Destination of every "Why this number?" click on a ROAS metric.

**Layout:** Table of campaigns with Platform-reported revenue, Store-attributed revenue, Delta, Delta %, source badges. Chart of gap over time. Tooltip explains iOS14 modeled conversions.

No Winners/Losers — investigation tool, not ranking.

### Pages NOT in 12.5

- `/performance`, `/insights`, `/stores/*` — current structure fine
- `/dashboard` — section 12

---

## 13. Not Tracked

**Terminology:** "Not Tracked" throughout. Never "unattributed revenue." Formula: `max(0, total_revenue - total_tagged)`.

**Display:** Both absolute (€8,400) and percentage (17.5%).

**Sign-aware:** Negative when platforms over-report.
- Positive: "Not tracked by any ad platform"
- Negative: tooltip explains iOS14 modeled conversions
- **Banner trigger:** first time negative Not Tracked > 5%, show dismissible workspace banner. Fires once.

Shown on: SEO, Campaigns, Dashboard Real row, Acquisition, Discrepancy.

---

## 14. Source-Tagged MetricCard

### Badge taxonomy

| Badge | Color | Icon | Meaning |
|---|---|---|---|
| Store | Green | Cart | Store DB |
| Facebook | Meta blue | f | Meta Ads API |
| Google Ads | Solid blue | G Ads | Google Ads API |
| GSC | Dark grey | Magnifier | Search Console |
| Site | Teal | Pulse | Lighthouse/uptime |
| Real | Gold | Lightbulb | Computed by Nexstage |

Glyphs carry the load, color reinforces only.

### Props

```tsx
<MetricCard
  value={4.83}
  label="Real ROAS"
  source="real"
  target={3.60}
  targetDirection="above"
  trendDots={last14Days}
  showSourceBadge={false}
  whyThisNumber={<WhyThisNumberContent metric="real_roas" />}
/>
```

**`targetDirection` is not optional for any card with a target.** Marketing % and CPO are inverted (below = good). Getting this wrong produces cards where red means "great job."

**Target default:** No target set → bare number, no notation, no color.

**Source badge default:** Off on dashboard hero, on in drill-downs and analytics pages.

**14-day trend dots:** Phase 1.4 binary (teal hit / red miss / gray no data). Phase 3 graded to teal/amber/red.

---

## 14.1 `<WhyThisNumber />` shared primitive

Built once in Phase 1.5 Step 9. Every MetricCard with `whyThisNumber` prop renders this modal when clicked.

**Contents:**
- Formula used to compute the value
- Data sources involved (SourceBadge components)
- Raw input values
- Conflicting values from other platforms when applicable
- "View raw data" link to admin query tool

One component. Consistent across every page.

---

## 14.2 `<DataFreshness />` shared primitive

Built once in Phase 1.5 Step 9. Rendered in `PageHeader` on every page.

**Reads:** `sync_logs.last_successful_at` (or `store_webhooks.last_successful_delivery_at` for webhook-primary stores).

**Display:**
- Green dot + "Updated 14 min ago" (<30 min)
- Amber dot + "Last synced 2h ago" (stale)
- Red dot + "Data may be out of date — last synced 27h ago" (genuinely behind)

**Tooltip** shows per-integration freshness: "Store ✓ 12 min | Facebook ✓ 8 min | Google Ads ⚠ 2h | GSC ✓ 30 min"

Single most reliable signal that the data is current.

---

## 15. BreakdownView Component

Two orthogonal axes: breakdown dimension × data source.

```tsx
<BreakdownView
  breakdownBy="campaign"   // 'product' | 'country' | 'campaign' | 'advertiser' | 'date'
  cardData="facebook"       // display hint only — determines card badge
  defaultView="table"       // 'cards' | 'table' | 'graph'
  scope={...}               // store_ids, integration_ids
  columns={columnDefs}
  data={rows}               // pre-joined BreakdownRow[] with flat metrics dict
/>
```

**Per codebase audit:** `cardData` is a display hint only. Page passes pre-joined `BreakdownRow[]` with flat `metrics: Record<string, number | null>`. BreakdownView has zero data-fetching capability. Controllers do all joining server-side.

Separate pages stay separate routes. BreakdownView is the shared implementation.

### Winners/Losers classifier

Three classifiers answering different questions:
- **`vs Target`** — above/below committed goal (requires target set)
- **`vs Peer Average`** — above/below workspace average of peers (always available)
- **`vs Previous Period`** — improving/declining (always available, noisy)

Classifier dropdown on every W/L page. Default `vs Target` when set, `vs Peer Average` otherwise. Period never default.

Backend ranking endpoint accepts `classifier=target|peer|period` from Phase 1.5.

---

## 16. UTM Coverage + Naming Convention + Tag Generator

### 16.1 Why coverage matters

Low UTM coverage makes every attribution number unreliable. Treating coverage as onboarding health prevents users from blaming the tool.

### 16.2 Coverage check

`ComputeUtmCoverageJob` runs after store + ad connect, on last 30 days:
- Green ≥80% / Amber 50-80% / Red <50%

Persistent indicator near attribution metrics. Recalculates daily.

**Phase 1.5 adds active onboarding modal** when coverage <50% after ad account connect. Links to Tag Generator.

### 16.3 Naming Convention

**Template:** `{country} | {campaign} | {target}` with fixed `|` separator.

**Three shapes:**
- `{country} | {campaign} | {target}` — full
- `{campaign} | {target}` — no country
- `{campaign}` — minimal

**Country detection:** parser checks if first field is 2 letters uppercase. If yes → country code. If no → skip country slot. Users not segmenting by country just have a 2-field convention.

**Separator fixed at `|` in Phase 1.6.** Not user-configurable. If pushback comes, add option in Phase 3.

**Target field matching:**
1. Try as product slug (match `products.slug`)
2. Try as category slug (match `product_categories.slug`)
3. If neither, store raw in `campaigns.parsed_convention.raw_target` and fall back to UTM-only attribution with "Using UTM only" badge

### 16.4 Channel mappings seed

~40 global rows on first migration:

**Email:** klaviyo, mailchimp, omnisend, activecampaign, brevo, convertkit, sendinblue, hubspot (medium=email → "Email — {Source}")

**Paid social:** facebook/meta/fb/instagram/ig (medium cpc/paid → "Paid — Facebook"), tiktok (paid → "Paid — TikTok"), linkedin, pinterest, twitter/x

**Paid search:** google/adwords (cpc/ppc/paid → "Paid — Google Ads"), bing/microsoft (cpc → "Paid — Microsoft Ads")

**Organic search:** google (organic → "Organic — Google"), bing, duckduckgo

**Organic social:** facebook (organic/social → "Social — Facebook"), instagram, tiktok

**SMS:** postscript, attentive, klaviyo-sms (sms → "SMS — {Source}")

**Affiliate:** impact, cj, shareasale, awin, partnerize (affiliate → "Affiliate — {Network}")

**Referral/direct:** `utm_source=direct` → "Direct", fallback referral → "Referral"

All global rows: `workspace_id = NULL`, `is_global = true`. Workspaces override by creating their own row with same `(utm_source_pattern, utm_medium_pattern)` — service lookup prefers workspace row over global.

### 16.5 `/manage/naming-convention`

Settings page. Read-only template explainer. Shows:
- Three supported shapes with examples
- Table of user's campaigns grouped by parse status: Clean parses (count + sample), Failed parses (full list with reason + suggested rename)
- Coverage badge: % of campaigns with spend last 30 days that parse cleanly
- Link to Tag Generator

### 16.6 Tag Generator extended

Current page at `/manage/tag-generator`. Phase 1.6 adds second panel:

**Left (existing):** URL generator — form → live URL preview with UTMs → copy buttons

**Right (new):** Campaign name generator — same form source of truth → output:
- Campaign name: `{country} | {campaign} | {target}`
- Adset name: `{country} | {campaign} | {target} | {audience}`
- Ad name: `{country} | {campaign} | {target} | {audience} | {variant}`
- Copy buttons for each

Pre-configured templates for common ad platform flows. Consolidated page, growing existing rather than splitting into three settings pages.

### 16.7 `/manage/channel-mappings` + inline classify

Full management page: table of every mapping with edit/delete, "Import defaults" re-seed, workspace overrides visible.

**Inline classify** on Acquisition page: expanding an "Other tagged" row shows:

```
Found: utm_source=hey, utm_medium=newsletter  (18 orders, €920)

Classify this as:
  ○ Email — Hey
  ○ Email — (new, let me name it)
  ○ Referral
  ○ Direct
  ○ Paid Social
  ○ Ignore (don't surface again)

[Apply to workspace]
```

Writes workspace-scoped row to `channel_mappings` and re-classifies historical orders matching that `(utm_source, utm_medium)` via dispatched job. "Apply to workspace" only — no agency button until Phase 4.

---

## 17. Anomaly Detection (Phase 3)

Simpler than earlier drafts. Median + weekday split, not MAD + z-score.

### ComputeMetricBaselinesJob

Per metric per workspace: last 28 days, split by day of week, compute median of each weekday bucket. Store `metric_baselines(workspace_id, metric, weekday, median, sample_size, computed_at)`.

### DetectAnomaliesJob

Flag when current value differs from weekday median by > threshold. Default 30% drop, 50% rise.

**Volume floors:** revenue <€500/day, orders <15/day, GSC clicks <50/day baseline.

**Skip conditions:** no workspace login in 7 days, no active integration, <3 weeks of baselines, holiday, `workspace_events.suppress_anomalies = true`.

### Single-cause correlation chain

For each flagged candidate, walk ordered investigation. First match wins:
1. Spend changed (FB/Google dropped or hit zero)
2. Attribution changed (Not Tracked spiked → tracking break, not real demand drop)
3. Site health degraded (Lighthouse drop >10, LCP regression >500ms)
4. Stock outage (top revenue products out)
5. Refund spike (high refund day, not low order day)
6. Payment gateway failure (specific gateway stopped processing)

If none match: "we don't know why — investigate."

Each check logs weight in alert `data` for silent-mode review refinement.

### Silent mode graduation

Ship with `is_silent = true`. Alerts stored, not delivered. Founder reviews via `/admin/silent-alerts`. **Graduation:** ≥70% TP rate on ≥20 reviewed alerts, ≥4 weeks runtime. Reviewer is founder personally.

### Narrative output

```
Revenue dropped 32% today (€1,240 vs expected €1,820)

Likely cause: Your Lighthouse LCP score on /shop degraded from
2.1s to 4.8s after yesterday's theme update, coinciding with a
28% drop in conversion rate. Site issue, not marketing.

[View details] [Dismiss] [This was expected]
```

### Coupon auto-promotion detection

Track coupon usage baseline. Coupon is "newly spiking" if: <3 days history AND ≥20% of today's orders, OR inactive 14+ days AND now ≥10 orders/day. Floor ≥5 uses/day. Small store guard ≥20 orders/day avg over 14 days.

Auto-create workspace_event with `is_auto_detected=true, needs_review=true`. User confirms or dismisses (coupon added to `coupon_exclusions`).

---

## 18. Recommendations Layer (Phase 3)

Beyond anomaly detection (past), the Recommendations layer suggests the future. Each joins multiple data sources nightly, produces narrative card with impact estimate and suggested action.

### Examples

- **Organic-to-paid substitution:** keywords ranking top 5 organically AND paying for Google Ads on same keyword. Suggest reducing bid.
- **GSC product opportunity:** product pages with rising GSC impressions but no corresponding ads.
- **Site health revenue impact:** compute CVR sensitivity to LCP from historical data, translate Lighthouse drops into euro impact.
- **Stock-aware campaign alerts:** campaigns spending on out-of-stock products.
- **Cohort × channel quality:** CAC payback adjusted for LTV differences by channel.
- **Basket bundling** (uses Phase 1.6 FBT): "Product X and Y co-occur in 18% of orders, Y has 2.3x margin, bundling could lift profit per order by €4.20."

### Architecture

Each recommendation is a nightly job running a query across data sources, producing a `recommendations` record with narrative, impact estimate, suggested action. Dashboard card with collapse/expand. Users dismiss, snooze, or act. **No auto-execution.**

### Named saved segments

User-named reusable filter sets as workspace resource ("VIP customers", "At-risk churners"). Extends `view_preferences` JSONB with `saved_segments` key. Sidebar "My Segments" section. Applicable across analytics pages.

---

## 19. Frequently-Bought-Together (Phase 1.6)

Basket affinity analysis. Computed weekly via Apriori-style query over last 90 days of `order_items`.

Phase 1.6 because only needs WooCommerce order data we already have. Validates algorithm on real data before Shopify lands.

### Output

`product_affinities` (schema in section 5). `margin_lift` is differentiator: co-occurrence × contribution margin from COGS data.

### Display

- Product detail pages: "Frequently bought with X"
- Recommendations layer (Phase 3): "Bundle X with Y for €4.20 margin lift"

Cheap to compute, strong upsell signal, no competitor ships with margin awareness for WC.

---

## 20. Alert Notifications

**Three-tier delivery:**
- Low: in-app only
- Medium: in-app + daily email digest
- High: in-app + real-time email

**Rules:** Max one high-priority real-time email per 4h per workspace. Quiet hours 22:00–08:00 in workspace timezone (critical overrides). Per-user-per-workspace config in `notification_preferences`. Alert deduplication: check existing unresolved alert same type + workspace + (optional store) within 3 days.

**Defaults:** Critical → immediate email + in-app. High → daily digest + in-app. Medium/Low → in-app only.

**Future (Phase 4+):** Slack, Discord, Telegram webhooks.

---

## 21. Webhook Reliability + Reconciliation

### Webhooks primary

Webhooks handle sub-minute latency. `order.created`, `order.updated`, `order.deleted`, `order.refunded`, `product.updated`. Tracked in `store_webhooks.last_successful_delivery_at`.

### Polling fallback

`PollStoreOrdersJob` hourly per store on `sync-store` queue:
1. Read `last_successful_delivery_at`
2. If <90 min ago → webhooks healthy, skip poll (no wasted API calls)
3. If >90 min or NULL → poll last 2 hours from store API, upsert new/changed
4. Log decision either way

Catches webhook delivery failures without adding load when webhooks work.

### Daily reconciliation with hard-delete

`ReconcileStoreOrdersJob` nightly per store on `low` queue:
1. Fetch last 7 days of orders from store API
2. Compare order IDs against DB for same window
3. **Orders in our DB but not in store response → hard-delete.** User deleted them on the store (test orders, cancellations). We stay synced.
4. **Orders in store but not DB → upsert.** Webhook missed.
5. **Orders in both with different fields (status, total, date_modified) → update.** Status change webhook missed.
6. Internal alert if discrepancies >5% OR webhook health shows issues >48h

Hard-delete because we want to stay synced. Soft-delete would leave test orders in totals forever. If user re-creates a deleted order, it comes back through normal webhook/sync path as new order.

### Store deletion → webhook cleanup

When a store is deleted:
1. Call `StoreConnector::removeWebhooks()` **before** deleting the store record
2. `removeWebhooks()` iterates `store_webhooks` rows and calls the platform API to delete each registered webhook
3. Only after cleanup completes does the store record get deleted (CASCADEs to `store_webhooks` and `orders`)
4. If cleanup fails (network, API down), log but continue deletion — surface warning in admin health dashboard for manual cleanup on platform side

Prevents orphaned webhooks firing into our endpoint for a store that no longer exists.

### User-facing health

On Integrations settings and via `<DataFreshness />`:
- Last successful sync time per integration
- Sync mode per store ("Real-time webhooks" / "Polling fallback")
- Freshness indicator (green <2h / amber 2-24h / red >24h)
- Manual "Resync now" button

---

## 22. Queue System

Restructured per-provider in Phase 1.5 to prevent cross-integration starvation. A slow GSC sync must never block FB insights.

### 22.1 Queue supervisors

```
critical-webhooks     — webhook processing            — 10 workers
sync-facebook         — FB Marketing API              —  2 workers  (strict, dev app)
sync-google-ads       — Google Ads API                —  2 workers  (strict, dev app)
sync-google-search    — GSC API                       —  2 workers  (quota sensitive)
sync-store            — Woo/Shopify store sync        — 10 workers  (no shared API)
sync-psi              — Lighthouse/PSI                —  2 workers  (quota sensitive)
imports               — historical imports            —  2 workers  (isolated)
default               — everything else               —  5 workers
low                   — snapshots, AI, FX, cleanup    —  3 workers
```

**Why this shape:**
- Webhooks get their own lane for fast turnaround
- Each external-API sync gets its own lane with concurrency cap respecting provider limits
- Store sync is NOT rate-limited externally (each WordPress is independent) so it runs wide
- Imports isolated to prevent large historical imports from starving regular sync
- `default` picks up everything not needing provider-specific handling
- `low` for non-urgent background work

**FB/Google caps are deliberately conservative (2 workers each) because we're on dev apps.** Production app approval lifts global rate limits, then caps can be raised. Tuning signals come from `/admin/system-health` once it ships.

### 22.2 Dispatch

Jobs declare queue via `public $queue` or `onQueue()`:

- `SyncAdInsightsJob` → `sync-facebook` or `sync-google-ads` (per `integration.provider`)
- `SyncSearchConsoleJob` → `sync-google-search`
- `SyncStoreOrdersJob`, `SyncProductsJob`, `SyncRecentRefundsJob`, `PollStoreOrdersJob` → `sync-store`
- `RunLighthouseCheckJob` → `sync-psi`
- `WooCommerceHistoricalImportJob`, `AdHistoricalImportJob`, `GscHistoricalImportJob` → `imports`
- `ProcessWebhookJob` → `critical-webhooks`
- `ComputeDailySnapshotJob`, `ComputeMetricBaselinesJob`, `GenerateAiSummaryJob`, `UpdateFxRatesJob`, `BackfillAttributionDataJob`, `ReconcileStoreOrdersJob` → `low`
- Everything else → `default`

### 22.3 Rate limit handling

Rate limits → `$this->release($retryAfter)` (re-queue without consuming retry). Per-provider queue means rate-limited FB job only blocks 2 FB workers, not everything else.

Token expiry → `$this->fail($e)` (permanent failure, create alert).

### 22.4 Schedule

```
Hourly:
  PollStoreOrdersJob (per store)                           — sync-store

Daily:
  DispatchDailySnapshots → ComputeDailySnapshotJob          — low
    → DetectStockTransitionsJob (per store) [Phase 1.6]     — low
    → ComputeMetricBaselinesJob (Phase 3)
      → DetectAnomaliesJob (Phase 3)
      → GenerateRecommendationsJob (Phase 3)
  GenerateAiSummaryJob (01:00-02:00 UTC staggered)          — low
  RunLighthouseCheckJob (per URL, store_url_id % 240 min)   — sync-psi
  UpdateFxRatesJob (06:00 UTC)                              — low
  ReconcileStoreOrdersJob (nightly hard-delete)             — low
  SyncRecentRefundsJob (last 7 days)                        — sync-store
  ComputeUtmCoverageJob (03:45 UTC)                         — low

Weekly:
  ComputeProductAffinitiesJob (Sunday 04:00 UTC) [1.6]      — low

10-minute:
  EvaluateUptimeJob [Phase 4]

Monthly (1st):
  ReportMonthlyRevenueToStripeJob (06:00 UTC)
  GenerateMonthlyReportJob (08:00 UTC) [Phase 1.6]

Yearly:
  RefreshHolidaysJob (Jan 1)
```

### 22.5 Observability

`/admin/system-health` shows per-queue depth, wait time, failure rate. Phase 1.5 Step 15. Surfaces signals telling us whether to raise worker caps post-dev-app approval.

---

## 23. AI Summary

**AiSummaryService:**
- Provider: Claude (`claude-sonnet-4-6`)
- max_tokens 900
- Two calls: narrative summary + dedicated anomaly-detection with stricter system prompt
- Anomaly output: JSON array of 0-3 objects `{type, severity, detail}`
- Parsing hardening: strip markdown fences, try/catch json_decode, log warning on failure
- Cost: ~€0.50-1.00/month per active workspace

**API versions:** FB Marketing v25.0 (Feb 2026), Google Ads v23.1 (Feb 2026). Before sync job changes, verify minimum supported version in RESEARCH.md.

---

## 24. Business Logic Reference

### Formulas

- `ROAS = SUM(daily_snapshots.revenue) / SUM(ad_insights.spend WHERE level='campaign')`
- `Real ROAS per campaign` = UTM-attributed orders matched to campaign external_id then name, revenue sum / spend
- `AOV = revenue / orders_count` (completed + processing only)
- `Marketing % = (SUM(spend) / SUM(revenue)) * 100`
- `CPO = SUM(spend) / orders_count` (null if either zero — display "N/A")
- `Contribution margin = revenue - COGS - shipping - fees - returns` (Phase 1.6)
- UTM source matching was `FACEBOOK_SOURCES` / `GOOGLE_SOURCES` constants — **replaced by `ChannelClassifierService` in Phase 1.5 Step 4**

### Hard rules

- Never divide by zero — NULLIF in SQL, null in PHP, display "N/A"
- Never SUM across `ad_insights` levels — always filter single level
- Never aggregate raw orders in page requests — use snapshots
- All values pre-converted to reporting_currency at sync time — never join fx_rates at query time
- CPM/CPC/CPA compute on the fly with NULLIF — never store as columns
- Platform-agnostic: every new feature through StoreConnector — no direct WC API calls in new code

### FX rate conversion

DB-first: `fx_rates` is the cache. Never call Frankfurter API from FxRateService.
1. Query fx_rates WHERE date → found → return
2. Not found → look back up to 3 days → return
3. Still not found → throw `FxRateNotFoundException` → log warning, leave `total_in_reporting_currency = NULL`
4. RetryMissingConversionJob handles NULLs nightly

### Integration-specific rules

- **Facebook Ads** — re-sync last 3 days (FB revises recent figures). No refresh token, long-lived only. Alert 7 days before expiry. v25.0.
- **Google Ads** — GAQL. Refresh token within 5 min of expiry. No hourly data. v23.1.
- **GSC** — query last 5 days every run (2-3 day lag). Sync every 6 hours. Auto-link property to store if domain matches. Mark last 3 days "may be incomplete."
- **WooCommerce** — webhook-primary with hourly `PollStoreOrdersJob` fallback when quiet >90 min. Hard-delete via reconciliation. Product webhooks registered alongside orders.
- **Shopify (Phase 2)** — multi-touch attribution native. No order item cost field — use daily snapshot fallback.

### Workspace rules

- One owner always. Cannot leave without transferring.
- Owner deletion → transfer to oldest Admin → if none, `is_orphaned = true`
- Deletion blocked if open Stripe invoices or active subscription
- Soft-delete → 30-day cancellation → hard delete via `PurgeDeletedWorkspaceJob`
- Invitations: 7-day expiry, `Str::random(64)` token

---

## 25. Implementation Phases

### ✅ Phase 0 — Foundations (complete)
Schema rewrite, all tables, sync job updates, model layer, StoreConnector interface, RevenueAttributionService, holiday system, product webhooks, integration flags, ccTLD country detection.

### ✅ Phase 1 — MVP launch (complete)
Nav restructure, dashboard cross-channel view, MultiSeriesLineChart, SEO/Campaigns/Products/Performance pages, store URL management, billing restructure, onboarding tiles, workspace events UI, notification preferences, webhook health, daily notes, admin impersonation, trial reactivation backfill.

### ✅ Phase 1.1-1.4 (complete)
MetricCard primitive, BreakdownView, dashboard refactor + attribution, UTM coverage + Tag Generator, per-page features + Winners/Losers chips.

### 🔄 Phase 1.5 — Foundation & Data Layer (current)

**Goal:** Data foundation every Phase 1.6 page reads from. Schema pass + parser + classifier + COGS reader + queue restructure + sync reliability + operational readiness. No new visible pages.

**Step 1: Schema finalisation pass** — section 5.5. One migration batch, `migrate:fresh --seed`, verification checklist. All Phase 1.5 AND 1.6 schema lands here.

**Step 2: Workspace settings + Store country prompt** — `WorkspaceSettings` value object/cast, `<StoreCountryPrompt />` wired into onboarding and store creation, store settings page exposes `primary_country_code`.

**Step 3: `AttributionParserService` + sources** — service class, `AttributionSource` interface, `PixelYourSiteSource`, `WooCommerceNativeSource`, `ReferrerHeuristicSource`, `ParsedAttribution` value object. Tests for each source in isolation + full-chain feature test. `/admin/attribution-debug/{order_id}` route.

**Step 4: `ChannelClassifierService` + seed** — service class, `channel_mappings` seeder (~40 global rows per section 16.4), `classify(utm_source, utm_medium, workspace_id)` method. Tests for workspace override precedence.

**Step 5: COGS reader** — `CogsReaderService` with three WC plugin readers. Writes to `order_items.unit_cost`. `WooCommerceConnector::supportsHistoricalCogs()` detects any of three plugins.

**Step 6: StoreConnector capability flags** — extend interface with `supportsHistoricalCogs()`, `supportedAttributionFeatures()`, `supportsMultiTouch()`. Implement on `WooCommerceConnector`.

**Step 7: Sync job refactor (feature-flagged)** — `UpsertWooCommerceOrderAction` calls parser and writes to new `attribution_*` columns. Existing `utm_*` columns continue to be written unchanged. COGS reader called on each line item. Feature flag `ATTRIBUTION_PARSER_ENABLED`. `ComputeDailySnapshotJob` extended to populate `daily_snapshot_products.stock_status` and `stock_quantity` from the current `products` row at snapshot time.

**Step 8: `BackfillAttributionDataJob`** — per workspace on `low` queue. Re-processes every existing order through parser pipeline. Full historical backfill. Dispatched manually from admin UI during beta. Progress via `/admin/system-health`.

**Step 9: Shared UI primitives + scope filtering + QuadrantChart generalisation**
- `<WhyThisNumber />` modal primitive
- `<DataFreshness />` indicator primitive
- Scope filtering component + `ScopedQuery` helper trait for models
- QuadrantChart generalisation — accept `xField`, `yField`, `sizeField`, `colorField` props; existing campaigns behavior remains default

**Step 10: Winners/Losers backend + classifier** — server-side ranking for `/campaigns`, `/analytics/products`, `/stores`, `/countries` (when rewritten). Accept `filter=winners|losers&classifier=target|peer|period`. Default classifier logic per section 15. Frontend gains classifier dropdown.

**Step 11: BreakdownView adoption** — migrate `/countries` and `/analytics/daily` from manual tables to BreakdownView.

**Step 12: Queue restructure** — `config/horizon.php` rewrite per section 22.1. Sync jobs declare per-provider queues. Rate-limit handling verified.

**Step 13: Sync reliability**
- `PollStoreOrdersJob` hourly fallback
- `ReconcileStoreOrdersJob` extended with hard-delete detection
- Webhook health tracking via `store_webhooks.last_successful_delivery_at`
- Store deletion path calls `removeWebhooks()` before deleting store record

**Step 14: Attribution service cutover** — switch `RevenueAttributionService` to read from new `attribution_last_touch`. Delete hardcoded `FACEBOOK_SOURCES` / `GOOGLE_SOURCES` constants — classification now happens in `ChannelClassifierService`. Existing `/campaigns` keeps working with cleaner code. **Parser becomes load-bearing here.**

**Step 15: Operational prerequisites**
- UTM coverage active onboarding modal (<50% nudge)
- Silent alert admin UI at `/admin/silent-alerts`
- Campaign `previous_names` fallback in `RevenueAttributionService`
- IP geolocation + Stripe billing country detection
- Database backups (automated PITR + test restore)
- GDPR data export endpoint
- `/admin/system-health` observability (per-queue metrics, sync freshness, NULL FX counts, backfill progress)
- Secret rotation procedure documentation

### Phase 1.5 verification checklist

- [x] Schema finalisation verified per section 5.5
- [x] `AttributionParserService` returns correct results for PYS store, WC-native-only store, referrer fallback case (three feature tests)
- [x] `ChannelClassifierService` correctly applies workspace overrides over global rows
- [x] COGS reader populates `unit_cost` from each of three WC plugin sources
- [x] `BackfillAttributionDataJob` completes for beta store, all orders have non-null `attribution_*` columns
- [x] `<StoreCountryPrompt />` fires at store creation, persists `primary_country_code`
- [x] `/admin/attribution-debug/{order_id}` renders full parser pipeline
- [x] Winners/Losers endpoint serves all three classifiers on `/campaigns`
- [x] BreakdownView adopted on `/countries` and `/analytics/daily`
- [x] Queue restructure: FB rate limit does not block Google Ads or Store sync
- [x] `PollStoreOrdersJob` activates only when webhooks quiet >90 min
- [x] `ReconcileStoreOrdersJob` hard-deletes order that no longer exists in store
- [x] Deleting a store removes platform webhooks before deletion
- [x] Test-restore from backup completes
- [x] GDPR export produces valid bundle
- [x] `/admin/system-health` renders per-queue metrics with real data
- [x] Feature tests: attribution parser, trial freeze + reactivation, UTM parsing, billing tier, webhook reconciliation

### Phase 1.6 — Pages & UX

**Goal:** Visible product improvements. Every page reads from Phase 1.5 data layer. No schema changes.

- **Per-page implementations** (section 12.5):
    - `/campaigns` refinement — classifier dropdown, hero row cleanup
    - `/analytics/products` rewrite — contribution margin, Real profit, scatter via generalised QuadrantChart, COGS-not-configured empty state
    - `/countries` rewrite — side-by-side integration columns, three-tier country fallback, peer-average classifier
    - `/seo` refinement — organic revenue hero card, estimated organic revenue columns
    - `/analytics/daily` hero row + BreakdownView layout
- **New `/acquisition` page** — flagship differentiator
- **New `/analytics/discrepancy` drill-down** — Platform vs Real, destination of ROAS "Why this number?" clicks
- **`/manage/naming-convention`** — read-only explainer, parse status table, coverage badge
- **Naming convention parser** — `CampaignNameParserService` on every sync, writes to `campaigns.parsed_convention`. Fixed `|`, three shapes, country detection, product/category target matching.
- **`/manage/channel-mappings`** — full CRUD, import defaults
- **Tag Generator companion panel** — second panel generating campaign/adset/ad names from same form
- **Inline classify UI** on Acquisition — expandable sheet for "Other tagged" rows
- **Order detail page with attribution journey** — click into any order, see first-touch, last-touch, click IDs, source badge. Uses parser data.
- **Frequently-Bought-Together** — `ComputeProductAffinitiesJob` weekly, display on product detail
- **Out-of-stock transition detection** — `DetectStockTransitionsJob` daily after `ComputeDailySnapshotJob`. State-diff on `daily_snapshot_products`: any product that was `instock` yesterday and is `outofstock` today creates an alert. Reverse transition (back in stock) also creates a lower-severity alert. Simple query, no anomaly engine needed. Alert links to product detail page.
- **Monthly PDF reports** — `GenerateMonthlyReportJob` + Blade template via dompdf. On-demand from Insights + scheduled 1st of month. Includes contribution margin when COGS configured.
- **Dashboard design principles** — `<WhyThisNumber />` on every MetricCard, `<DataFreshness />` in every PageHeader, action language, product images on product rows

### Phase 1.6 verification checklist

- [x] Every page in section 12.5 matches spec
- [x] `/acquisition` renders with real parser data
- [x] `/countries` side-by-side shows ad spend via naming convention + primary_country_code fallback
- [x] `/analytics/products` shows contribution margin when COGS configured, graceful empty state otherwise
- [x] Naming convention parser handles all three shapes, product-or-category matching works
- [x] Tag Generator produces matching URL + campaign names from one form
- [x] Inline Acquisition classify writes `channel_mappings` and re-classifies historical orders
- [x] Order detail shows full attribution journey
- [x] FBT populates `product_affinities` for test store with sufficient history
- [x] `DetectStockTransitionsJob` fires an alert when a test product transitions from in-stock to out-of-stock between two daily snapshots
- [x] Monthly PDF generates without errors
- [x] `<WhyThisNumber />` fires on every MetricCard
- [x] `<DataFreshness />` renders on every page header

### Phase 2 — Shopify

**Approach:** Database is the abstraction. `ShopifyConnector` writes into the same tables `WooCommerceConnector` writes into. No new value classes, no service-layer changes.

- `ShopifyConnector` implementation
- OAuth flow
- Order sync via GraphQL Admin API:
    - `Order.customerJourneySummary.lastVisit.utmParameters` → `ShopifyCustomerJourneySource` → `orders.attribution_last_touch`
    - `Order.customerJourneySummary.firstVisit.utmParameters` → `orders.attribution_first_touch`
    - `Order.displayFinancialStatus` → normalised to existing `orders.status` enum
    - `Order.currentTotalPriceSet.shopMoney` → `orders.total`
    - `Order.lineItems[].discountAllocations` → `order_items.discount_amount`
    - Anything Shopify-specific → `platform_data JSONB` on orders
- `ShopifyConnector::supportedAttributionFeatures()` → `['first_touch','last_touch','multi_touch_journey','referrer_url','landing_page']`
- `supportsHistoricalCogs()` → false
- Daily `InventoryItem.unitCost` snapshot job → `daily_snapshot_products.unit_cost`
- For each order, look up cost from snapshot → `order_items.unit_cost`
- Webhook normalisation
- Multi-platform feature parity audit
- Shopify-specific connector test suite

### Phase 2 verification

- [ ] Connect Shopify dev store, sync orders
- [ ] Multi-touch attribution captured on test orders with UTMs
- [ ] COGS lookups via daily snapshot
- [ ] Pre-snapshot orders show "historical estimate" badge
- [ ] All Phase 1.6 features work on Shopify without service-layer changes
- [ ] No regressions on WooCommerce

### Phase 3 — Intelligence

- `ComputeMetricBaselinesJob` — historical backfill on first run, then daily
- `DetectAnomaliesJob` — silent mode default, % threshold, volume floors, skip conditions
- `correlateSignals()` — single-cause investigation chain
- Composite alerts with prose narratives — single-cause MVP
- AI structured anomaly output + alert deduplication
- Coupon auto-promotion detection — auto-create workspace_event with `needs_review`
- HTTP interim checkout health check
- Payment gateway failure detection
- Refund anomaly detection (distinct from low-order day)
- **Recommendations layer** — `recommendations` table + nightly job + dashboard card. Section 18 examples.
- **Named saved segments** — reusable filter sets as workspace resource
- Bundle recommendations (uses Phase 1.6 FBT with margin_lift)
- CTR opportunities section on `/seo`
- Theme-campaign entity (for themes that aren't products/categories)
- Stacked area mode on MultiSeriesLineChart
- Sankey diagram on Acquisition page
- Graded 14-day dot strips (replaces binary)
- Flip `is_silent` default to false after graduation criteria met

### Phase 4 — Advanced / Plugins

- **Native uptime monitoring** — external probe scripts on Hetzner VPS, `/api/uptime/targets` + `/api/uptime/report`, `EvaluateUptimeJob`
- **CAPI conversion sync to Facebook** — uses `pys_fb_cookie.fbc/fbp` captured in Phase 1.5. Push order conversions to Marketing API.
- **Nexstage WooCommerce plugin** — for stores without PYS, minimal plugin capturing UTMs/fbc/fbp/click IDs via custom REST endpoint
- **Agency white-label** — custom domain, logo, colors per workspace. Revisit at €20k MRR.
- **Multi-workspace overview** — "All Workspaces" view
- **Full Playwright synthetic checkout** — replaces Phase 3 HTTP interim
- **ML seasonality service** — separate Python FastAPI, STL decomposition. Trigger at 100-500 active stores.
- **Causal tree visualisation** for correlation narratives
- **Slack / Discord / Telegram notification webhooks**
- **Additional connectors** — BigCommerce, Magento, PrestaShop. All implementations of `StoreConnector`.

### Future considerations (Phase 4+, validate demand first)

- **Abandoned cart recovery** — SMS/email win-back on abandoned checkouts + coupon-triggered follow-ups (CartBoss/CartFox style). Revenue product, not analytics. Tight scope: WC detection + one outbound channel + coupon templating. Don't rebuild Klaviyo.
- **WooCommerce Subscriptions analytics** — MRR, churn, LTV, cohorts for WC Subscriptions stores. Metorik owns this segment.
- **Investor-ready dashboard export** — template variant of Phase 1.6 monthly PDF, reframed for investors. Template swap, not new code.

### What's NOT in any phase

- GA4 integration (never)
- Multi-store comparison within a workspace (no demand)
- Recap-as-separate-page (already cut)
- TV Mode (already cut)
- Per-page report builder (wrong segment)
- Custom SQL access (wrong segment)
- Site tier / content-site customers (out of scope)

---

## 26. Risk: Beta Data Contingency

Silent mode tuning needs 2-3 real stores with real data for ≥4 weeks. Stores must be onboarded and syncing during Phase 1.5 / 1.6 / Phase 2 so baselines are ready when Phase 3 anomaly detection begins. Alert feed stays silent until stores have ≥4 weeks of baseline data AND ≥20 silent alerts reviewed.

Beta source: uWeb (founder's agency). Reviewer: founder personally.

---

## 27. Phase Enforcement Rule

Phase N+1 cannot ship to production until Phase N verification checklist is complete. Parallel dev on feature branches allowed, but merging to main requires prior phase sign-off.

Verification checklists in PROGRESS.md are sign-off gates, not aspirations.
