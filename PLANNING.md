# Nexstage — Full Product Plan (Research-Validated)

## Context

Pre-launch, no live users. Full freedom to redesign schema, navigation, and features. Based on extensive market research and competitive analysis, the plan has been restructured around validated positioning.

**Core thesis:** Paid ads + organic search + site performance + ecommerce data in one unified view — no competitor covers all four pillars for WooCommerce SMBs.

**Defensible wedges (validated by research):**
1. WooCommerce-native depth (most serious competitors are Shopify-first)
2. Site health as a first-class ecommerce signal (no major agency tool includes it)
3. Cross-channel anomaly correlation with narrative root-cause explanation (nobody ships this well)
4. EU/GDPR positioning (most competitors are US-based)

**Product framing:** Not a dashboard tool — a diagnostic tool. Every feature evaluated against: "does this help answer 'why did my revenue change?' faster?"

**Do NOT compete on:** integration count (AgencyAnalytics has 80+), SEO tool bundles (rank trackers, backlinks), report-building flexibility (mature drag-and-drop editors). Stay narrow, go deep.

---

## What to Keep

- Tech stack: Laravel 12 + Inertia.js + React 19 + TypeScript + Tailwind
- Multi-tenant architecture: WorkspaceContext singleton, SetActiveWorkspace middleware, WorkspaceScope global scope
- Queue architecture: Horizon + Redis (4 supervisors: critical/high/default/low)
- OAuth integration code: WooCommerce, Facebook Ads, Google Ads, GSC (service classes + jobs)
- Component library: MetricCard, DateRangePicker, LineChart, MultiSeriesLineChart, AppLayout
- Billing foundation: Laravel Cashier + Stripe (restructure tiers, keep Cashier)

---

## Database Schema — Complete Redesign

### Problems in Current Schema (fix in Phase 0)

1. **`daily_snapshots.top_products` and `revenue_by_country` are JSONB blobs** — cannot filter/sort/join at DB level. `revenue_by_country` is redundant (derivable from orders). `top_products` must be normalized.
2. **`order_items` redundantly stores `workspace_id` and `store_id`** — derivable via order_id.
3. **`gsc_pages.page VARCHAR(2000)` unique constraint** — massive index. Replace with TEXT + `page_hash CHAR(64)` (SHA-256).
4. **`ad_insights` nullable FKs with no CHECK constraints** — level/FK integrity not enforced.
5. **`alerts` has no `source` column** — can't distinguish system/rule/AI alerts.
6. **Missing indexes** on orders(workspace_id, status, occurred_at), orders(workspace_id, shipping_country) (for countries page), products(workspace_id, store_id, status), stores(workspace_id, platform_store_id).
7. **`stores.platform_webhook_ids` JSONB** — should be a proper table.

### Schema Changes to Existing Tables

**`orders`** — add columns:
- `payment_method VARCHAR(100) NULL`
- `payment_method_title VARCHAR(255) NULL`
- `shipping_country CHAR(3) NULL`
- `refund_amount DECIMAL(12,2) NOT NULL DEFAULT 0`
- `last_refunded_at TIMESTAMP NULL`
- `customer_id VARCHAR(255) NULL` (WooCommerce per-store user ID — NOT globally unique, always query with store_id. Kept alongside customer_email_hash which serves as the privacy-preserving cross-store dedup key)
- INDEX(store_id, customer_id) — composite, since customer_id is per-store
- `utm_term VARCHAR(500) NULL`
- `raw_meta JSONB NULL` (fee_lines, order_notes, etc.)
- `raw_meta_api_version VARCHAR(20) NULL`

**`products`** — add columns:
- `stock_status VARCHAR(50) NULL` (in_stock, out_of_stock, on_backorder)
- `stock_quantity INT NULL`
- `product_type VARCHAR(50) NULL` (simple, variable, grouped, external)

**`campaigns`** — add columns:
- `daily_budget DECIMAL(12,2) NULL`
- `lifetime_budget DECIMAL(12,2) NULL`
- `budget_type VARCHAR(20) NULL` (daily, lifetime)
- `bid_strategy VARCHAR(100) NULL`
- `target_value DECIMAL(12,2) NULL`

**`ads`** — add columns:
- `effective_status VARCHAR(100) NULL` (real column, not JSONB — needed for correlation engine)
- `creative_data JSONB NULL` (images, headlines, descriptions, thumbnails)
- `creative_data_api_version VARCHAR(20) NULL`

**`ad_insights`** — add columns:
- `frequency DECIMAL(5,2) NULL` (Facebook: avg times person saw ad)
- `platform_conversions DECIMAL(12,2) NULL` (platform-reported conversions)
- `platform_conversions_value DECIMAL(14,4) NULL` (platform-reported conversion value)
- `search_impression_share DECIMAL(5,4) NULL` (Google Ads)
- `raw_insights JSONB NULL` (Facebook actions array, placement breakdowns, etc.)
- `raw_insights_api_version VARCHAR(20) NULL`
- Add CHECK constraint: `(level = 'campaign' AND campaign_id IS NOT NULL AND ad_id IS NULL) OR (level = 'ad' AND ad_id IS NOT NULL AND campaign_id IS NULL)` — Note: if adset-level insights are added later (Phase 3+), this constraint must be extended. Add a code comment in the migration.

**`gsc_daily_stats`** — add columns:
- `device VARCHAR(10) NOT NULL DEFAULT 'all'` (mobile, desktop, tablet, or 'all' for aggregate)
- `country CHAR(3) NOT NULL DEFAULT 'ZZ'` ('ZZ' = aggregated/unknown)
- Update unique constraint to include device + country. Use NOT NULL with sentinel values because PostgreSQL treats NULL as distinct in unique constraints.

**`gsc_queries`** — add columns:
- `device VARCHAR(10) NOT NULL DEFAULT 'all'`
- `country CHAR(3) NOT NULL DEFAULT 'ZZ'`
- Update unique constraint to include device + country

**`gsc_pages`** — change `page VARCHAR(2000)` to `page TEXT` + add `page_hash CHAR(64)`, unique on `(property_id, date, page_hash, device, country)`
- Add `device VARCHAR(10) NOT NULL DEFAULT 'all'` — NOT NULL with sentinel value 'all' (PostgreSQL treats NULL as distinct in unique constraints, so nullable columns would allow duplicate rows)
- Add `country CHAR(3) NOT NULL DEFAULT 'ZZ'` — 'ZZ' = aggregated/unknown (same NULL-in-unique issue)

**`alerts`** — add columns:
- `source VARCHAR(50) DEFAULT 'system'` CHECK (source IN ('system', 'rule', 'ai'))
- `property_id BIGINT FK search_console_properties NULL`
- `is_silent BOOLEAN DEFAULT false`
- `review_status VARCHAR(50) NULL` (unreviewed, true_positive, false_positive, correct_but_uninteresting)
- `reviewed_at TIMESTAMP NULL`
- `estimated_impact_low DECIMAL(12,2) NULL`
- `estimated_impact_high DECIMAL(12,2) NULL`
- `gsc_conversion_rate_at_alert DECIMAL(8,6) NULL` (audit trail)
- `store_aov_at_alert DECIMAL(12,2) NULL` (audit trail)

**`stores`** — add `website_url VARCHAR(500) NULL` (main store domain — used for country detection from TLD, PSI homepage auto-creation, webhook URL base. NOT the same as store_urls which are specific pages to monitor). Drop `platform_webhook_ids` JSONB.

**`workspaces`** — add columns:
- `has_store BOOLEAN DEFAULT false`
- `has_ads BOOLEAN DEFAULT false`
- `has_gsc BOOLEAN DEFAULT false`
- `has_psi BOOLEAN DEFAULT false`
- `country CHAR(2) NULL` (ISO 3166-1 alpha-2, for holiday context)
- `region VARCHAR(100) NULL` (optional, for sub-national holidays)
- `timezone VARCHAR(50) NULL` (e.g. 'Europe/Berlin' — auto-detected from country, used for quiet hours + schedule display. Separate from reporting_timezone which controls data aggregation.)
- Update `billing_plan` CHECK: `('starter', 'growth', 'scale', 'enterprise')`

**`daily_snapshots`** — drop `top_products` JSONB and `revenue_by_country` JSONB columns.

**`order_items`** — drop `workspace_id` and `store_id` columns.

### New Tables

**`daily_snapshot_products`** — replaces daily_snapshots.top_products JSONB
```sql
id BIGSERIAL PK
workspace_id BIGINT FK workspaces CASCADE
store_id BIGINT FK stores CASCADE
snapshot_date DATE NOT NULL
product_external_id VARCHAR(255) NOT NULL
product_name VARCHAR(500) NOT NULL
revenue DECIMAL(14,4) NOT NULL
units INT NOT NULL DEFAULT 0
rank SMALLINT NOT NULL
created_at TIMESTAMP
UNIQUE(store_id, snapshot_date, product_external_id)
INDEX(workspace_id, snapshot_date)
```

**`store_urls`** — pages to monitor via PSI + uptime
```sql
id BIGSERIAL PK
workspace_id BIGINT FK workspaces CASCADE
store_id BIGINT FK stores CASCADE
url VARCHAR(2048) NOT NULL
label VARCHAR(255) NULL
is_homepage BOOLEAN DEFAULT false
is_active BOOLEAN DEFAULT true
created_at, updated_at TIMESTAMP
UNIQUE(store_id, url)
INDEX(workspace_id, store_id)
```

**`lighthouse_snapshots`** — PSI check results
```sql
id BIGSERIAL PK
workspace_id BIGINT FK workspaces CASCADE
store_id BIGINT FK stores CASCADE
store_url_id BIGINT FK store_urls CASCADE
checked_at TIMESTAMP NOT NULL
strategy VARCHAR(10) DEFAULT 'mobile'
performance_score SMALLINT NULL
seo_score SMALLINT NULL
accessibility_score SMALLINT NULL
best_practices_score SMALLINT NULL
lcp_ms INT NULL
fcp_ms INT NULL
cls_score DECIMAL(6,4) NULL
inp_ms INT NULL
ttfb_ms INT NULL
tbt_ms INT NULL
raw_response JSONB NULL
raw_response_api_version VARCHAR(20) NULL
created_at TIMESTAMP
INDEX(store_url_id, checked_at)
INDEX(workspace_id, checked_at)
```

**`uptime_checks`** — HTTP liveness results, ingested via API from external probe scripts. **Partitioned by month** (PostgreSQL declarative partitioning) from day one. At 10-min intervals across many stores, this table grows steadily. Monthly partitioning keeps indexes small and makes retention cleanup a partition drop instead of a DELETE.
```sql
id BIGSERIAL PK
workspace_id BIGINT FK workspaces CASCADE
store_id BIGINT FK stores CASCADE
store_url_id BIGINT FK store_urls CASCADE
probe_id VARCHAR(50) NOT NULL   -- identifies which probe server reported (e.g. 'eu-1', 'us-1')
checked_at TIMESTAMP NOT NULL
is_up BOOLEAN NOT NULL
status_code SMALLINT NULL
response_time_ms INT NULL
error_message VARCHAR(500) NULL
created_at TIMESTAMP
INDEX(store_url_id, checked_at)
INDEX(workspace_id, is_up, checked_at)
PRIMARY KEY (id, checked_at)  -- PostgreSQL requires partition key in PK
) PARTITION BY RANGE (checked_at);
```
**Partition management:** CleanupPerformanceDataJob (Sunday 04:00 UTC) must include an explicit step: "ensure next 2 months of partitions exist, create if missing." This runs weekly, giving ample buffer before month rollover. Without this, the first day of a new month with no partition will hard-fail all inserts.

**External probe architecture:**
- Lightweight stateless scripts deployed on 2+ cheap VPS (e.g. Hetzner €4/mo each, different regions)
- Probe fetches targets: `GET /api/uptime/targets` (returns active store_urls with auth token)
- Probe reports results: `POST /api/uptime/report` (batch of check results with probe_id)
- API endpoints authenticated via a per-probe API key (stored in probe_api_keys config, not user-facing)
- Alert logic in Laravel: EvaluateUptimeJob (10-min schedule) reads uptime_checks. Alert fires after 2 consecutive check windows (~20 min) where ≥2 probes report down for the same URL. 20 min is already significant downtime — don't wait longer. Single-probe-down = log warning, don't alert (probe itself may have network issues). Auto-resolve on first up check from any probe.

**`uptime_daily_summaries`** — aggregated from uptime_checks before raw deletion
```sql
id BIGSERIAL PK
store_url_id BIGINT FK store_urls CASCADE
workspace_id BIGINT FK workspaces CASCADE
date DATE NOT NULL
checks_total INT NOT NULL
checks_up INT NOT NULL
uptime_pct DECIMAL(5,2) NOT NULL
avg_response_ms INT NULL
created_at TIMESTAMP
UNIQUE(store_url_id, date)
INDEX(workspace_id, date)
```

**`store_webhooks`** — replaces stores.platform_webhook_ids JSONB
```sql
id BIGSERIAL PK
store_id BIGINT FK stores CASCADE
workspace_id BIGINT FK workspaces CASCADE
platform_webhook_id VARCHAR(255) NOT NULL
topic VARCHAR(255) NOT NULL
created_at TIMESTAMP
deleted_at TIMESTAMP NULL
UNIQUE(store_id, platform_webhook_id)
```

**`metric_baselines`** — precomputed rolling statistics for anomaly detection
```sql
id BIGSERIAL PK
workspace_id BIGINT FK workspaces CASCADE
store_id BIGINT FK stores NULL CASCADE  -- null = workspace-level metric
metric VARCHAR(100) NOT NULL
weekday SMALLINT NOT NULL               -- 0=Mon..6=Sun (ISO)
median DECIMAL(14,4) NOT NULL
mad DECIMAL(14,4) NOT NULL              -- median absolute deviation
data_point_count INT NOT NULL           -- how many weeks contributed
stability_score DECIMAL(5,4) NULL       -- MAD/median ratio (lower = more stable = higher confidence). NULL when median = 0 (legitimate for metrics with zero-value weekdays like Sunday sales). When NULL, fall back to data_point_count tier only for confidence scoring.
updated_at TIMESTAMP
-- Two partial unique indexes (PostgreSQL treats NULL as distinct in regular UNIQUE):
-- CREATE UNIQUE INDEX metric_baselines_store_unique ON metric_baselines(workspace_id, store_id, metric, weekday) WHERE store_id IS NOT NULL;
-- CREATE UNIQUE INDEX metric_baselines_workspace_unique ON metric_baselines(workspace_id, metric, weekday) WHERE store_id IS NULL;
```

**Confidence tiers based on data_point_count:**
- < 3 weeks: no alerts at all (silent, regardless of is_silent flag)
- 3-5 weeks: alerts only for extreme deviations (>4 MAD)
- 6+ weeks: normal sensitivity (>2.5 MAD for warning, >3.5 MAD for critical)

**Absolute-volume floors (prevents Poisson noise false positives on small stores):**
- Revenue alerts: skip if store averages <€500/day over baseline period
- Order count alerts: skip if store averages <15 orders/day
- These apply in addition to confidence tiers — a store with 6 weeks of data but only 8 orders/day still skips order-count alerts
- Thresholds in config, not hardcoded

**Known limitation (log, don't fix yet):** weekday dimension captures weekly seasonality but misses day-of-month effects (payday spikes ~25th-1st). Fix in Phase 3 with STL decomposition or a second baseline dimension.

**Edge case — stability_score when median = 0:** Many metrics legitimately have median = 0 on certain weekdays (no Sunday sales, campaign paused on weekends, new GSC page). When median = 0, set stability_score = NULL. Confidence scoring falls back to data_point_count tier only. Document in ComputeMetricBaselinesJob comments.

**Metrics tracked:**
- Per-store: `revenue`, `orders_count`, `aov`, `new_customers`, `items_sold`, `refunds_daily_amount`, `refunds_daily_count` (refund metrics aggregated from refunds table by refunded_at date, not stored in daily_snapshots. **Note:** ComputeMetricBaselinesJob needs a different query path for refund metrics — query refunds table grouped by date, not daily_snapshots. Add a code comment in the job stub.)
- Per-workspace (store_id NULL): `total_revenue`, `total_orders`, `blended_roas`, `total_ad_spend`
- Per-GSC-property: `gsc_clicks`, `gsc_impressions`, `gsc_avg_position`

**`holidays`** — global reference table of public holidays by country
```sql
id BIGSERIAL PK
country_code CHAR(2) NOT NULL    -- ISO 3166-1 alpha-2
date DATE NOT NULL
name VARCHAR(255) NOT NULL
year SMALLINT NOT NULL
created_at TIMESTAMP
UNIQUE(country_code, date, name)
INDEX(country_code, year)
```

**Population:** Use `azuyalabs/yasumi` PHP library. `RefreshHolidaysJob` runs January 1st, regenerates all countries that have at least one workspace. Also triggered on workspace creation if workspace.country has no holidays for current year. No per-workspace duplication — one row per country per holiday per year.

**`workspace_events`** — workspace-specific promotions/context for baseline adjustment
```sql
id BIGSERIAL PK
workspace_id BIGINT FK workspaces CASCADE
event_type VARCHAR(50) NOT NULL  -- 'promotion', 'expected_spike', 'expected_drop'
name VARCHAR(255) NOT NULL
date_from DATE NOT NULL
date_to DATE NOT NULL
is_auto_detected BOOLEAN DEFAULT false  -- auto-detected from coupon usage
needs_review BOOLEAN DEFAULT false      -- for auto-detected promotions
suppress_anomalies BOOLEAN DEFAULT true
created_at, updated_at TIMESTAMP
INDEX(workspace_id, date_from, date_to)
```

**Integration:** DetectAnomaliesJob checks BOTH `holidays` (via workspace.country) AND `workspace_events` before firing. If today matches a holiday for the workspace's country OR falls within a workspace_event where suppress_anomalies = true, skip detection (or log as suppressed for silent mode review). ComputeMetricBaselinesJob excludes both holiday dates and workspace_event dates from the rolling window. Retroactive promotion marking immediately improves baselines on next recalculation.

**`order_coupons`** — normalized coupon tracking
```sql
id BIGSERIAL PK
order_id BIGINT FK orders CASCADE
coupon_code VARCHAR(255) NOT NULL
discount_amount DECIMAL(12,2) NOT NULL
discount_type VARCHAR(50) NULL   -- 'percent', 'fixed_cart', 'fixed_product'
created_at TIMESTAMP
INDEX(order_id)
INDEX(coupon_code, created_at)   -- for coupon usage aggregation
```

**`refunds`** — individual refund events
```sql
id BIGSERIAL PK
order_id BIGINT FK orders CASCADE
workspace_id BIGINT FK workspaces CASCADE
platform_refund_id VARCHAR(255) NOT NULL  -- Woo refund ID, Shopify refund ID, etc.
amount DECIMAL(12,2) NOT NULL
reason TEXT NULL
refunded_by_id VARCHAR(255) NULL
refunded_at TIMESTAMP NOT NULL
raw_meta JSONB NULL
raw_meta_api_version VARCHAR(20) NULL
created_at TIMESTAMP
UNIQUE(order_id, platform_refund_id)
INDEX(workspace_id, refunded_at)
```

Also keep denormalized `refund_amount` and `last_refunded_at` on orders — updated at sync time.

**`product_categories`** — category hierarchy
```sql
id BIGSERIAL PK
workspace_id BIGINT FK workspaces CASCADE
store_id BIGINT FK stores CASCADE
external_id VARCHAR(255) NOT NULL
name VARCHAR(500) NOT NULL
slug VARCHAR(500) NULL
parent_external_id VARCHAR(255) NULL
created_at, updated_at TIMESTAMP
UNIQUE(store_id, external_id)
```

**`product_category_product`** — many-to-many pivot
```sql
product_id BIGINT FK products CASCADE
category_id BIGINT FK product_categories CASCADE
UNIQUE(product_id, category_id)
```

**`google_ads_keywords`** — for keyword cannibalization detection
```sql
id BIGSERIAL PK
workspace_id BIGINT FK workspaces CASCADE
ad_account_id BIGINT FK ad_accounts CASCADE
ad_group_id VARCHAR(255)
keyword_text VARCHAR(500)
match_type VARCHAR(50)   -- BROAD, PHRASE, EXACT
status VARCHAR(100)
created_at, updated_at TIMESTAMP
UNIQUE(ad_account_id, ad_group_id, keyword_text, match_type)
INDEX(workspace_id)
```

**`notification_preferences`** — per-workspace-per-user alert configuration
```sql
id BIGSERIAL PK
workspace_id BIGINT FK workspaces CASCADE
user_id BIGINT FK users CASCADE
channel VARCHAR(20) NOT NULL      -- 'email', 'in_app'
severity VARCHAR(20) NOT NULL     -- 'critical', 'high', 'medium', 'low'
enabled BOOLEAN DEFAULT true
delivery_mode VARCHAR(20) DEFAULT 'immediate'  -- 'immediate', 'daily_digest', 'weekly_digest'
quiet_hours_start TIME NULL
quiet_hours_end TIME NULL
UNIQUE(workspace_id, user_id, channel, severity)
```

**Sensible defaults (95% of users never change):**
- Critical: email immediate + in-app immediate
- High: email daily digest + in-app immediate
- Medium: in-app only, email in daily digest
- Low: in-app only
- Quiet hours default: 22:00-08:00 in workspace timezone. Critical alerts override quiet hours.

**`coupon_exclusions`** — coupons excluded from auto-promotion detection
```sql
id BIGSERIAL PK
workspace_id BIGINT FK workspaces CASCADE
coupon_code VARCHAR(255) NOT NULL
reason VARCHAR(255) NULL
created_at TIMESTAMP
UNIQUE(workspace_id, coupon_code)
```

---

## Data Capture Strategy

**Principle:** If the data is in the API response, cheap to store, and impossible/expensive to backfill — capture it now. Schema changes on a live database are painful.

**Two patterns:**
1. **Promote to real columns** — fields we'll query in Phase 0-2 (correlation engine, pricing, common queries)
2. **JSONB with API version** — fields for Phase 3+ ("might need later"). Every JSONB column gets a paired `*_api_version VARCHAR(20)` column in the same migration.

### What to promote (real columns, listed above in schema changes):
- GSC: device, country
- Orders: payment_method, payment_method_title, shipping_country, refund_amount, customer_id, utm_term
- Products: stock_status, stock_quantity, product_type
- Campaigns: daily_budget, lifetime_budget, budget_type, bid_strategy, target_value
- Ad insights: frequency, platform_conversions, platform_conversions_value, search_impression_share
- Ads: effective_status

### What to JSONB:
- `ads.creative_data` — images, headlines, descriptions, video thumbnails, call-to-action
- `ad_insights.raw_insights` — Facebook actions array, social_spend, placement breakdowns
- `orders.raw_meta` — fee_lines, order_notes (TEXT not JSONB for notes)
- `refunds.raw_meta` — full refund response

**Critical rule:** Store API version alongside every JSONB blob. Without this, API response shape changes silently create structural variation in your JSONB that's invisible until extraction fails months later.

---

## Billing Model

### Pricing philosophy: unified "managed value"
One mental model: **you pay based on how much money flows through the tool.** Ecommerce = GMV. Non-ecommerce = ad spend. Different tier boundaries because ad spend is always a fraction of revenue.

### Tier structure

| Tier | GMV limit | Ad spend limit | Monthly | Annual |
|---|---|---|---|---|
| **Starter** | ≤€5k/mo | ≤€5k/mo | €29 | €290/yr |
| **Growth** | ≤€25k/mo | ≤€25k/mo | €59 | €590/yr |
| **Scale** | >€25k/mo | >€25k/mo | 1% GMV / 2% ad spend (min €149) | N/A |
| **Enterprise** | >€250k GMV/mo | >€100k spend/mo | Custom | Custom |

**All tiers get full features.** No feature gating between Starter/Growth/Scale — the only difference is the billable volume. Correlation engine, anomaly alerts, AI summaries, PDF reports, multi-store — all included from Starter. This avoids "why am I paying more for the same features" friction and keeps the value prop clean: you pay for scale, not features.

**Billing basis auto-derivation:**
- `has_store = true` → bill on GMV (SUM of daily_snapshots.revenue for previous month)
- `has_store = false` → bill on ad spend (SUM of ad_insights.spend_in_reporting_currency for previous month)
- Both store AND ads → GMV only (ad spend drives GMV, don't double-count)
- Billing basis switch (non-ecom → ecom on store connect): billing calculation method changes at next monthly cycle. No mid-month pro-ration.
- Document this explicitly in billing code.

**14-day free trial:**
- `trial_ends_at` set at workspace creation. Full access, no payment method required.
- Trial expires without payment method: **freeze all sync jobs**. Freeze means: scheduler skips the workspace entirely (check `trial_ends_at < now() AND subscription_status != 'active'` in each dispatch filter). Jobs already in the queue when trial expires are discarded on pickup via a middleware check in the job's handle() method. Dashboard stays visible with stale data + prominent upgrade banner. Do NOT keep jobs running at your cost.
- 30 days expired: suspend workspace (archive, don't delete).
- 90 days expired: delete data per GDPR.
- **Reactivation after freeze:** On payment, trigger catch-up sync for the gap period (same mechanism as initial historical import). This prevents data holes that confuse users and break baselines.

**Consolidated agency billing:** Deferred to Phase 3+. Revisit when agencies request it. Concept: `workspaces.billing_workspace_id` → child workspaces share billing owner's subscription. Tier determined by group total.

**Free tier consideration (deferred):** WooCommerce plugin directory rewards products with a free tier for conversion. Worth evaluating post-launch whether a limited free tier (1 store, 1 integration, reports only) would meaningfully accelerate plugin-directory installs or just create support load. Not a Phase 1 decision.

**No white-labeling** at MVP. Full Nexstage branding everywhere. Decision documented: revisit at €20k MRR or 100 active workspaces. When ready: ~2-3 weeks work (custom logo/colors is easy, custom domain needs SSL provisioning).

### Billing mechanics (preserve from current implementation)
- **No plan selection** — auto-assigned based on last full calendar month billable amount
- Annual: 2 months free (Starter/Growth only). Upgrade mid-year, never downgrade mid-year.
- Scale tier: Stripe metered billing. Floor: `max($calculated, 149.00)`. Auto-enter when billable amount >€25k, auto-exit to Growth if drops below.
- Scale rate: 1% of GMV for ecom, 2% of ad spend for non-ecom.
- Always report revenue in EUR to Stripe. Convert via fx_rates (last day of previous month).
- WebhookReceived events sync billing_plan.

---

## Onboarding Flow

**Updated flow:**
1. Register → verify email
2. **Single screen with three connection tiles:** Store, Ad Accounts, GSC. Text: "Connect what you have — you can add more later." No forced ordering, no "what type of business" question.
3. If store connected → import date selection → import progress → dashboard
4. If only ads/GSC connected → straight to dashboard
5. Invited users skip onboarding entirely.

**Country auto-detection** (for holiday context):
1. If store connected and domain has a country-code TLD (.de → DE, .fr → FR, .nl → NL) → use that. Only fires on actual ccTLDs, not .com/.shop/.io/.store.
2. Fallback: IP geolocation on first login (covers .com stores and non-ecom users)
3. Fallback: Stripe billing address country when payment added
4. Always override-able in workspace settings
5. Do NOT add an onboarding step for country.

---

## Navigation Redesign

```
Overview                    → /dashboard       (cross-channel command center)

--- Channels ---
Paid Ads                    → /campaigns       (ad campaign performance)
Organic Search              → /seo             (GSC + organic revenue context)
Site Performance            → /performance     (Lighthouse, CWV, uptime)  [NEW]

--- Analytics ---
Daily Breakdown             → /analytics/daily
By Product                  → /analytics/products
By Country                  → /countries

--- Stores ---
All Stores                  → /stores
[individual store]          → /stores/{slug}/overview

Insights                    → /insights        (alert feed + AI summaries)

--- Settings ---
(existing: profile, workspace, team, integrations, billing)
```

Sidebar sections conditionally rendered based on workspace integration flags (has_store, has_ads, has_gsc, has_psi) from Inertia shared props.

**Note:** Sidebar label "Paid Ads" links to /campaigns route. Update CampaignsController page title and breadcrumbs to match "Paid Ads" label, or rename the route to /paid-ads. Avoid mismatch between sidebar text and URL bar.

---

## Dashboard — Cross-Channel Command Center

### Priority-tier layout (not a flat 16-card grid)

**Hero row (3 cards max):** Revenue (or ROAS if more actionable), Orders, and a composite "attention needed" indicator showing the highest-priority alert from the correlation engine. If no alert, shows neutral status.

**Channel rows below:** Collapsible sections for Store / Paid / Organic / Site Health, each with 3-4 metric cards.
- Default expanded: sections with active alerts auto-expand, quiet sections collapse to one-line summary
- Absent sections: collapse entirely (don't show empty slots). Sidebar prompt with specific value prop: "Connect Meta Ads to see cross-channel ROAS and detect campaign budget issues."

**Store metrics row** (when store connected): Revenue, Orders, AOV, New Customers
**Paid row**: Ad Spend, Blended ROAS, Attributed Revenue, CPO
**Organic row** (when GSC connected): GSC Clicks, GSC Impressions, Avg Position, Unattributed Revenue
**Site health row** (when PSI data exists): Performance Score, LCP, CLS, Uptime

**Chart:** MultiSeriesLineChart with toggleable series: Revenue, Ad Spend, ROAS, GSC Clicks on same timeline.
- GSC on hourly granularity: `connectNulls={false}` (gap, not zero dip). Footnote: "GSC data is daily only. Hourly view shows no organic data."
- Multiple GSC properties: sum clicks/impressions across all. Footnote: "Aggregated across N properties."

**Event overlays on all time-series charts:** Vertical line markers for holidays (from holidays table by workspace.country), workspace_events (promotions), and daily_notes. Different colors per type: holiday (gray), promotion (blue), daily note (green). Same component across Dashboard, Campaigns, SEO, Performance pages.

**AI Summary:** Daily cross-channel narrative.

**Daily Notes:** Prominent input (not hidden in tooltip). Recent notes rendered as visible annotations on main chart timeline. Notes persist in Insights feed alongside AI summaries.

---

## Unattributed Revenue

Tooltip: "Revenue not linked to a tracked ad campaign. Includes organic search, direct, email campaigns (Klaviyo, Mailchimp), affiliates, and any other untagged traffic. To separate email revenue, ensure your email campaigns use UTM parameters with utm_medium=email."

Show on: SEO page, Campaigns page, Dashboard (organic row).

---

## Platform ROAS vs Real ROAS

Tooltip on Campaigns page: "Platform ROAS is what Meta/Google report using pixel-based attribution. Real ROAS is calculated from your actual orders with UTM parameters matching this campaign. The gap indicates attribution overlap — Meta and Google both claim credit for the same orders."

---

## Cross-Channel Page Enhancements

### SEO page
- Add "Total Store Revenue" and "Unattributed Revenue" metric cards
- Unattributed = `max(0, total - UTM-attributed)` via RevenueAttributionService
- CTR Opportunities section (Phase 2): keywords position 1-10, impressions >100, CTR below position benchmark
- Keyword cannibalization section (Phase 3): keywords in both GSC top 10 AND Google Ads

### Campaigns page
- Add "Total Store Revenue" and "Unattributed Revenue" cards above campaign table
- Spend velocity column: `(spent_to_date / budget_amount) / (days_elapsed / days_in_period)`. Requires budget fields on campaigns.
- Platform ROAS vs Real ROAS explanation tooltips

### Products page
- Period-over-period trending (TrendBadge component, green/red pill with delta %)
- Edge case: if compare_from falls before earliest_date, return null for deltas (no badge, not zero)
- Stock status indicator per row (Out of stock / Low stock badge)
- Query daily_snapshot_products (normalized table)

---

## Performance Monitoring

### PSI Rate Limit Planning
- Default: mobile only. Desktop is optional per-URL toggle.
- Stagger across 4-hour window: `store_url_id % 240` minutes offset
- Quota exceeded: skip gracefully, log warning, retry next day

### URL management
- On store connection: auto-create store_urls row for homepage
- Store settings: add up to 9 additional URLs (10 total)
- If GSC connected: suggest top 5 GSC pages by clicks when adding URLs

### Performance page (/performance)
- URL selector / store selector
- Score cards: Performance, Accessibility, SEO, Best Practices (color-coded)
- CWV cards: LCP, CLS, INP with threshold badges
- Score trend chart with annotations on significant changes (>5 points)
- Event overlays (holidays, daily_notes, workspace_events) on the score timeline
- Uptime panel: last 24h / 7d / 30d uptime % (renders when uptime data exists — Phase 2+)
- Table: all monitored URLs with latest scores + change indicator
- Revenue impact estimate on regression cards (labeled "estimated", shown as range)

---

## Anomaly Detection System

### DetectAnomaliesJob
Dispatched after ComputeMetricBaselinesJob completes for each workspace.

**Detection method:** Median + MAD (Median Absolute Deviation). MAD is robust against Black Friday/sale outliers poisoning the baseline, unlike stddev.

**Skip conditions:**
1. No workspace member has `last_login_at` within 7 days
2. No integration in `status = 'active'`
3. Data insufficient (< 3 weeks of baselines)
4. Today matches a holiday (via workspace.country in holidays table) OR falls within a workspace_event with `suppress_anomalies = true`

### Individual signal detection

**Revenue anomaly:** Compare today's revenue to weekday-adjusted median from metric_baselines. Alert if deviation > threshold (based on confidence tier). Stock-awareness guard: before firing, check if affected top products are out_of_stock. Concrete rule: if products accounting for ≥50% of normal daily revenue (based on daily_snapshot_products rolling average) are currently out_of_stock, suppress the revenue-drop alert entirely. If ≥25% but <50%, downgrade severity by one level (critical→high, high→medium). Below 25%, fire normally. These thresholds go in a config, not hardcoded.

**ROAS anomaly:** Same weekday baseline. Warning if ROAS drops >25%.

**Ad spend anomaly:** Any drop >80% or to zero = high priority (likely billing/pause/budget cap). Cross-reference with campaign.daily_budget.

**GSC clicks anomaly:** Compare to 7-day rolling average per property. Alert if total clicks drop >40%.

**GSC position drop:** Keywords where position worsened by >3 spots AND keyword was in top 20 last week. Batch multiple keywords into one alert.

**Performance score drop:** Alert if performance_score drops >10 points, or any CWV crosses grade boundary (LCP: 2.5s/4s, CLS: 0.1/0.25, INP: 200ms/500ms).

**Uptime alert:** Requires 2 consecutive down checks from ≥2 probes (~20 min at 10-min intervals) before firing critical. 20 min is already significant — don't wait longer. Auto-resolve when uptime restores (any single up check from any probe).

**Payment gateway anomaly (Phase 2):** Payment method X averaged Y orders/day for 30 days, had Z orders today where Z < 0.3 × Y → alert.

**Refund anomaly:** High refund day (refunds_daily_amount deviates from baseline). Distinguish from "low order day" — different causes, different fixes. Note: refund baselines need 6+ weeks of history before firing at normal sensitivity. The same confidence tier logic (data_point_count < 3 → no alerts) applies to refund metrics identically to revenue metrics.

### Composite signal correlation — correlateSignals()

After individual signals are collected, run an ordered investigation chain (not flat pattern matching):

1. **Spend check** — did Meta/Google daily spend drop >X% or hit zero? Check against campaign budgets.
2. **Attribution check** — did attributed revenue drop proportionally to unattributed? (Proportional = real demand drop. Disproportional = tracking break)
3. **Platform conversion check** — did platform-reported conversions drop while actual orders held steady? (CAPI/tag break)
4. **Organic check** — did GSC impressions or clicks drop on top-20 revenue pages?
5. **Position check** — did GSC average position degrade >3 on top revenue queries?
6. **Site health check** — Lighthouse score drop >10, LCP regression >500ms, INP regression, uptime incidents
7. **SSL/DNS check** — cert expiring, robots.txt changes
8. **Stock check** — are top revenue products out of stock?
9. **Payment check** — did a specific payment gateway stop processing?
10. **Refund check** — is this a high-refund day, not a low-order day?

Each check has a weight for ranking plausible causes:
- Ad spend drop correlating with revenue: weight 10
- Attribution break (platform ≠ actual): weight 9
- Uptime incident: weight 9
- SSL/DNS issue: weight 8
- GSC clicks drop on revenue pages: weight 7
- Site health regression same day as revenue drop: weight 7
- GSC position drop: weight 5

### Compositing rules
- Single signal + high priority → alert
- Two signals, temporally correlated (within 24h) → alert with ranked causes
- Three+ signals → alert with full narrative
- Stock-out on affected SKUs → suppress revenue-drop for that product

### Starting thresholds (tune in silent mode)
- Revenue: -25% vs baseline = investigate, -40% = high priority
- Orders: -30% = investigate
- ROAS per platform: -35% day-over-day = investigate
- Ad spend: >80% drop or to zero = high priority
- GSC clicks on top-20 revenue pages: -30% week-over-week = investigate
- GSC position: drop of 3+ on top-10 revenue query = investigate
- LCP: regression >500ms = investigate, >1000ms = high priority
- Lighthouse performance score: drop >10 = investigate
- SSL: <14 days to expiry = high priority, <7 = critical

### Narrative output format (prose for MVP, tree visualization Phase 3+)
```
Revenue dropped 40% today (€1,240 vs expected €2,080)

Most likely causes, in order of confidence:

1. [HIGH] Your main Meta campaign "Summer Sale V3" hit its daily
   budget cap at 09:14 — 4 hours earlier than usual.
   Attributed Meta revenue down 60% (€680 lost).

2. [MEDIUM] LCP on /shop regressed from 2.1s to 4.6s after
   yesterday's deploy. GSC clicks on that page down 25%.
   Estimated impact: €200–€470/day.

These appear independent and compounding.

[View details] [Dismiss] [This was expected]
```

The composite alert `data` field carries contributing signals and named pattern for structured frontend rendering.

### Revenue impact estimation
```
estimated_daily_impact = (baseline_gsc_clicks - current_gsc_clicks)
                       × gsc_conversion_rate_30d
                       × store_aov_30d
```

Where gsc_conversion_rate_30d = `SUM(orders_count) / SUM(gsc_clicks)` — GSC-specific, not blended. Avoids double-counting users who click both ad and organic result. For multi-property workspaces: compute per GSC property when the alert is property-specific, fall back to workspace-level blend only when no property attribution is possible.

Display as range: ±40% bounds. Calibrate to ±25% if silent mode data supports tighter bounds.

**Surfaces:** Correlation narratives (always), Performance page regression cards, alert detail in Insights feed. **Never** on dashboard hero metric cards.

Store audit trail on alert: `gsc_conversion_rate_at_alert`, `store_aov_at_alert` as separate columns.

### Alert deduplication
Before creating any alert (rule-based or AI), check for existing unresolved alert with same type + workspace_id (+ optional store_id) created within last 3 days. If one exists, skip. For uptime: auto-resolve when is_up returns true.

### Silent mode
- Ship anomaly engine with `is_silent = true` as default
- Silent alerts don't trigger CriticalAlertMail, don't appear in Insights feed, don't show on dashboard
- Admin panel: "Silent Alerts Review" page with one-click labeling (true_positive / false_positive / correct_but_uninteresting)
- Review UI shows similar past alerts side-by-side when labeling
- After 4 weeks: review true_positive rate. Target ≥70%.
- **SILENT_MODE_GRADUATION.md** with go/no-go criteria: ≥70% TP rate, holiday/promotion context layer live, baseline confidence scoring working
- Flipping is_silent default to false is a conscious product decision, not a code change

### Coupon auto-promotion detection
Track coupon usage baseline (MAD on daily usage per coupon). A coupon is "newly spiking" if:
- <3 days of history AND used on ≥20% of today's orders, OR
- Inactive for 14+ days AND now ≥10 orders/day
- Minimum floor: ≥5 uses in a day
- **Small store guard:** require store has ≥20 orders/day average over last 14 days. Below that, skip auto-detection entirely — these stores should mark promotions manually. Without this, stores with 8 orders/day will false-positive on random coincidences.

Auto-create workspace_event with `is_auto_detected = true`, `needs_review = true`. User can confirm (becomes normal event) or dismiss (coupon added to coupon_exclusions so it doesn't flag again).

Stacked coupons: count each coupon's usage on each order it appears on (not exclusively).

---

## Alert Notification Strategy

**Three-tier delivery:**
- Low (single signal, below critical): in-app only
- Medium (multiple correlated, or single high-severity): in-app + daily email digest
- High (site down, cart broken, gateway dead, severe composite with large revenue impact): in-app + real-time email

**Rules:**
- Never more than one high-priority real-time email per 4 hours per workspace
- Quiet hours (default 22:00-08:00 workspace timezone) queue alerts for next active window. Critical overrides quiet hours.
- Users configure per workspace in notification_preferences table.
- Critical alert emails: one per alert, guard against duplicate same type + workspace within 24 hours. CriticalAlertMail template.
- Future: Slack/Discord/Telegram webhooks (Phase 2+).

---

## Webhook Reliability

### 90-minute fallback poll (existing, keep)
If no webhook activity in last 90 minutes, poll WooCommerce API.

### Daily reconciliation job (new)
`ReconcileStoreOrdersJob` — runs nightly per store:
- Query Woo REST API for orders in last 7 days
- Compare order IDs against local orders table
- Backfill missing, update changed (compare updated_at), log discrepancies
- Alert internally if discrepancies >5% OR store has had webhook issues >48 hours

### Webhook health surfacing (user-facing)
On Integrations settings page per store:
- Last successful sync time
- Sync method: "Real-time (webhooks active)" / "Periodic (every 90 minutes)"
- Data freshness: green (<2h) / amber (2-24h) / red (>24h)
- Manual "Resync now" button
- Amber/red warnings with troubleshooting links
- Only flag discrepancies as failures, not quiet periods from low-volume stores

### Product webhooks
Register `product.updated` alongside `order.*` in ConnectStoreAction. Handle in WooCommerceWebhookController. Captures price changes, status changes, stock changes, category changes in near-real-time.

---

## StoreConnector Interface

Formalize in Phase 0 so Shopify (Phase 3) is just a new implementation class:

```php
interface StoreConnector {
    public function testConnection(): bool;
    public function syncOrders(Carbon $since): int;
    public function syncProducts(): int;
    public function syncRefunds(Carbon $since): int;
    public function registerWebhooks(): array;
    public function removeWebhooks(): void;
    public function getStoreInfo(): array;
}
```

Current WooCommerceClient already does most of this. Extract the interface, adapt the client to implement it.

**Product identity across platforms:** Same product on Woo + Shopify = separate by default. Manual "link products" action. Never auto-merge by SKU — SKUs lie.

---

## Job Dispatch Chain

```
Schedule (daily):
  DispatchDailySnapshots
    → ComputeDailySnapshotJob (per store)
      → ComputeMetricBaselinesJob (per workspace, after ALL store snapshots complete)
        → DetectAnomaliesJob (per workspace, after baselines updated)
          → correlateSignals() (inline, within DetectAnomaliesJob)

  GenerateAiSummaryJob (per workspace, 01:00-02:00 UTC staggered by workspace_id % 60)
  RunLighthouseCheckJob (per URL, staggered across 4-hour window by store_url_id % 240)
  UpdateFxRatesJob (06:00 UTC)
  ReconcileStoreOrdersJob (per store, nightly)
  SyncRecentRefundsJob (per store, nightly, last 7 days)

Schedule (10-minute):
  EvaluateUptimeJob (reads uptime_checks from external probes, alerts after 2 consecutive downs from ≥2 probes)

Schedule (monthly):
  ReportMonthlyRevenueToStripeJob (1st, 06:00 UTC)
  GenerateMonthlyReportJob (1st, 08:00 UTC)

Schedule (yearly):
  RefreshHolidaysJob (January 1st — regenerates global holidays table for all active countries)
```

**Critical dependency:** DetectAnomaliesJob must wait for BOTH daily snapshots AND baselines to complete. Use job chaining or completion check.

### Queue assignments
```
critical  — webhook processing
high      — OAuth token refresh
default   — regular sync jobs, EvaluateUptimeJob
low       — imports, snapshots, baselines, anomaly detection, AI, FX rates, cleanup, Lighthouse, reconciliation, refund sync
```

### GenerateAiSummaryJob skip conditions (updated)
1. At least one workspace member (any role) has last_login_at within 7 days
2. At least one integration in status = 'active'
3. Summary doesn't already exist for today
Same conditions apply to DetectAnomaliesJob.

---

## AI Alerts Enhancement

**AiSummaryService:**
- Increase max_tokens 600 → 900
- Split into two calls: narrative summary + dedicated anomaly-detection call with stricter system prompt
- Anomaly call: output JSON array of 0-3 objects: {type, severity, detail}
- Parsing hardening: strip markdown fences, try/catch json_decode, Log::warning on failures, case-insensitive preg_match
- Strip anomaly block from display text before storing

**GenerateAiSummaryJob:**
- After upsert: validate type and severity against allowlists
- 3-day deduplication before creating Alert records
- Create with source = 'ai'

---

## New Feature: Client Report PDF (Phase 1.5)

Monthly AI-generated PDF per workspace. On-demand download from Insights page + auto-sent 1st of each month.

**MVP content:** Revenue vs prior month with chart, ROAS performance, top 3 anomalies, GSC clicks trend, AI narrative (2-3 paragraphs).

**Tech:** Blade template → PDF via `barryvdh/laravel-dompdf`. Stored on disk/S3.

**Job:** GenerateMonthlyReportJob — 1st of month 08:00 UTC + on-demand trigger. Queue: low.

---

## New Feature: HTTP-Based Checkout Health Check (Phase 2)

Interim alternative to full Playwright synthetic checkout. Defers Playwright to Phase 3+.

**Logic:**
1. Fetch `/cart/?add-to-cart={product_id}` with a known product ID (configurable per store in settings)
2. Check for HTTP 200 + session cookie
3. Fetch `/cart/` and check for `cart-contents` or `woocommerce-cart-form` in HTML
4. If either fails → alert with source='rule', type='checkout_health'

**Catches:** broken checkout plugins, fatal errors, WAF blocks, theme crashes.
**Doesn't catch:** JS-dependent cart failures, payment gateway issues.

~2 hours to implement. Good signal-to-effort ratio.

---

## New Feature: Multi-Workspace Overview (Phase 3)

When user belongs to >1 workspace: "All Workspaces" view from workspace switcher.

**Page: /workspaces/overview**
- Card per workspace: 30d revenue trend, ROAS, GSC clicks trend, performance score, last sync status, unresolved alert count
- Sorted by most unresolved alerts first
- Quick workspace switch

---

## Data Retention Policy

### uptime_checks
- 10-min intervals via external probes. Growth depends on probe count x URL count. Table partitioned by month.
- Keep raw 30 days (not 90 — partitioned table with monthly drops keeps this manageable)
- Aggregate to uptime_daily_summaries before partition drop
- CleanupPerformanceDataJob (Sunday 04:00 UTC) — drops old partitions, creates future partitions

### lighthouse_snapshots
- Daily checks, slow growth. Keep raw 12 months.
- Aggregate monthly averages after 12 months.

### gsc_queries and gsc_pages
- Retain indefinitely for now. Revisit at 6 months — consider 18 months raw, indefinite monthly aggregates.

### sync_logs and webhook_logs
- Existing cleanup jobs. Retention configurable, not hardcoded.

### Cancelled workspace data
- After 90 days of cancelled workspace with no payment attempts: delete per GDPR.

---

## Business Logic to Preserve

### Formulas
- `ROAS = SUM(daily_snapshots.revenue) / SUM(ad_insights.spend_in_reporting_currency WHERE level='campaign')`
- `Blended ROAS` = same, no store/account filter
- `AOV = revenue / orders_count` (completed + processing only)
- `Marketing % = (SUM(ad_insights.spend) / SUM(revenue)) * 100`
- `CPO = SUM(ad_insights.spend_in_reporting_currency) / orders_count` (null if either zero — display "N/A")
- Real ROAS per campaign = UTM-attributed orders matched to campaign name (case-insensitive), revenue sum / campaign spend
- UTM source matching: facebook → ('facebook','fb','ig','instagram'); google → ('google','cpc','google-ads','ppc')
- **Never divide by zero. Never SUM across ad_insights levels. Never aggregate raw orders in page requests.**
- All values pre-converted to reporting_currency at sync time. Never join fx_rates at query time.
- CPM, CPC, CPA: compute on the fly in SQL with NULLIF, do NOT store as columns (derived values drift when base data is fixed).

### Which table to query
- Dashboard metric cards/charts → daily_snapshots, hourly_snapshots
- Ad metrics → ad_insights (always single level filter)
- Top products → daily_snapshot_products (normalized)
- Country breakdown → query orders directly (indexed)
- Weekly range (>90 days) → aggregate daily_snapshots with DATE_TRUNC('week', date)

### FX Rate conversion
DB-first: fx_rates is the cache. Never call Frankfurter API from FxRateService.
1. Query fx_rates WHERE date = $date → found → return
2. Not found → look back up to 3 days → return
3. Still not found → throw FxRateNotFoundException → callers log warning, leave total_in_reporting_currency = NULL
4. RetryMissingConversionJob handles NULLs nightly

Four-case conversion (unchanged from current):
1. order.currency == reporting_currency → return as-is
2. order.currency == 'EUR' → total * rate_EUR_to_reporting
3. reporting_currency == 'EUR' → total / rate_EUR_to_order
4. Neither is EUR → total * (rate_EUR_to_reporting / rate_EUR_to_order)

### Integration-specific rules
- **Facebook Ads**: re-sync last 3 days (Facebook revises recent figures). No refresh token — long-lived only. Alert 7 days before expiry.
- **Google Ads**: API v17+ with GAQL. Refresh token within 5 minutes of expiry. No hourly data.
- **GSC**: query last 5 days every run (2-3 day lag). Sync every 6 hours. Auto-link property to store if domain matches. Mark last 3 days as "data may be incomplete."
- **WooCommerce**: hourly fallback if no webhooks in 90 min. Product webhooks (product.updated) registered alongside order webhooks.

### Job infrastructure rules
- Rate limit: `$this->release($e->retryAfter ?? 60)` — re-queue without consuming retry
- Token expiry: `$this->fail($e)` — permanent failure, create alert
- Failure chain: sync_logs failed → increment consecutive_sync_failures (after all retries) → alert (warning at 1, critical at 3+) → status='error' at 3+. Success → reset, restore active only if was error (never restore disconnected).
- All synced data: upsert() only, never insert()
- New jobs follow same failure handling chain.

### Workspace rules
- One owner always. Cannot leave without transferring.
- Owner deletion → transfer to oldest Admin → if none, is_orphaned = true
- Deletion blocked if open Stripe invoices or active subscription
- Soft-delete → 30-day cancellation → hard delete via PurgeDeletedWorkspaceJob
- Invitations: 7-day expiry, Str::random(64) token

---

## Implementation Sequence

### Phase 0: Foundations (schema + data capture only)
1. Rewrite all migrations cleanly with schema changes above (all tables including holidays, workspace_events, metric_baselines, uptime tables — schema only, no jobs yet for intelligence/uptime)
2. Extract `StoreConnector` interface, adapt WooCommerceClient
3. Extract `RevenueAttributionService` (single method for UTM-attributed revenue)
4. Install `azuyalabs/yasumi` + `RefreshHolidaysJob` (populates global holidays table by country)
5. Register product webhooks (product.updated) in ConnectStoreAction + handle in WooCommerceWebhookController
6. Add budget/bid fields to campaigns, sync from Facebook/Google in existing jobs
7. Add stock_status/stock_quantity sync to SyncProductsJob
8. Add product categories sync (product_categories table + pivot)
9. Add coupon capture (order_coupons table) to UpsertWooCommerceOrderAction
10. Add refunds table + SyncRecentRefundsJob
11. Add GSC device + country dimensions to sync jobs and tables
12. Add all new promoted columns (payment_method, shipping_country, frequency, conversions, search_impression_share, etc.)
13. Add JSONB capture columns (creative_data, raw_insights, raw_meta) with API version pairing
14. Update ComputeDailySnapshotJob → write to daily_snapshot_products
15. Update all controllers reading old JSONB columns
16. Add workspace integration flags (has_store, has_ads, has_gsc, has_psi)
17. Add workspace country field + auto-detection logic (ccTLD → IP geolocation → Stripe fallback)

### Phase 1: MVP launch (reporting + data visibility)
18. New nav structure in AppLayout.tsx using integration flags from shared props
19. Dashboard cross-channel view (priority-tier layout, hero row + collapsible channels). **Day-1 empty state:** raw metrics + week-over-week deltas + "anomaly detection learning your baseline: X/28 days" progress indicator. Design this explicitly — it's what trial users see before intelligence kicks in.
20. MultiSeriesLineChart + GSC clicks series + event overlays (daily_notes, holidays, workspace_events)
21. SEO page: Unattributed Revenue cards
22. Campaigns page: store revenue context cards + spend velocity + ROAS explanation tooltips
23. Products page: trending deltas + stock status badges
24. Performance page (/performance) + PerformanceController + RunLighthouseCheckJob (PSI/Lighthouse only — no uptime polling yet)
25. Store settings: URL management UI
26. Billing restructure: 3-tier (Starter/Growth/Scale), ad-spend-based billing for non-ecom, trial logic, trial expiry freeze
27. Onboarding update: single-screen connection tiles, optional store, country auto-detect
28. Workspace events: manual promotion/event creation UI + chart overlay markers
29. Notification preferences UI + schema
30. Webhook health: ReconcileStoreOrdersJob + sync status display on Integrations page
31. Daily notes: prominent input + chart annotations
32. Admin workspace impersonation (half-day task — needed before first "my data looks wrong" ticket)
33. Data accuracy FAQ + contextual "why don't numbers match?" links in UI (next to Platform ROAS tooltip, GSC numbers with lag note, Woo order counts with webhook/reconciliation timing)
34. Trial reactivation backfill sync (on payment after freeze, trigger catch-up import for the gap period)

**Beta stores:** Onboard 2-3 own stores during Phase 1 build. Data needs to flow for ≥4 weeks before Phase 2 anomaly detection can begin silent mode tuning.

### Phase 1.5: Reports
35. GenerateMonthlyReportJob + Blade template + barryvdh/laravel-dompdf
36. On-demand trigger from Insights page + monthly scheduled dispatch

### Phase 2: Intelligence (anomaly detection + correlation engine)
37. ComputeMetricBaselinesJob (rolling median + MAD per metric per weekday). **On first run, use all available historical daily_snapshots** — not just forward-accumulated data. If a store imports 3 months of orders at connection, baselines are ready on day 1 of Phase 2, not day 42.
38. DetectAnomaliesJob (silent mode) + all individual signal detectors
39. correlateSignals() — ordered investigation chain with weights
40. Composite alerts with prose narratives + revenue impact estimates
41. AI structured anomaly output (two-call approach) + Alert creation with deduplication
42. Coupon auto-promotion detection
43. CTR Opportunities section on SEO page
44. HTTP-based interim checkout health check
45. Payment gateway failure detection
46. Refund anomaly detection
47. Uptime system: external probe scripts (10-min intervals) + API endpoints (`GET /api/uptime/targets`, `POST /api/uptime/report`) + `EvaluateUptimeJob` (2 consecutive downs from ≥2 probes before alerting)
48. Flip is_silent default to false (after SILENT_MODE_GRADUATION criteria met)

### Phase 3: Depth
49. Shopify connector (implements StoreConnector interface)
50. Google Ads keyword sync (GAQL) + google_ads_keywords table population
51. Keyword cannibalization detection + SEO page surface
52. Multi-workspace overview (/workspaces/overview)
53. Extended report content (performance, per-store, cannibalization)
54. Consolidated agency billing (billing_workspace_id, group tier calculation)
55. Add performance signals to correlateSignals()

### Phase 4: Advanced
56. Full Playwright synthetic checkout (Node microservice, dedicated queue/supervisor)
57. Multi-region uptime probes
58. ML seasonality service (separate Python FastAPI, own schema, Phase 3+ trigger at 100-500 active stores)
59. Causal tree visualization for correlation narratives
60. Advanced attribution modeling
61. Slack/Discord/Telegram notification webhooks
62. Additional connectors (BigCommerce, Magento)

---

## Phase Enforcement Rule

Phase N+1 cannot ship to production until Phase N verification checklist is complete. Parallel development on feature branches is allowed — but merging to main requires the prior phase to be signed off. Each phase ends with a sign-off checklist matching verification steps below.

## Operational Prerequisites (before Phase 1 launch)

**Database backups:** Automated point-in-time recovery configured. Retention period documented. Test-restore procedure executed at least once before first real customer data flows.

**GDPR data export/deletion:** Must support within 30 days of request per GDPR. Workspace deletion flow (90-day) exists. Add: user data export endpoint (all data for a user/workspace as JSON/CSV) — ship in Phase 1.5 or Phase 2 at latest.

**Observability:** Horizon gives basic job failure visibility but not business-metric monitoring. Add internal system_health dashboard (not customer-facing) in Phase 2 showing: per-job success rates, baseline update lag, anomaly detection firing rates, sync freshness per workspace. This catches "ComputeMetricBaselinesJob silently failing on 10% of workspaces."

## Risk: Beta Data Contingency

Silent mode tuning needs 2-3 real stores (own stores) with real data for ≥4 weeks. Stores must be onboarded and syncing during Phase 1 so baselines are ready when Phase 2 anomaly detection begins. Alert feed remains in silent mode until stores have ≥4 weeks of baseline data and ≥20 silent alerts have been reviewed. Making this explicit prevents flipping silent mode early under schedule pressure.

---

## Critical Files to Modify

| File | Change |
|---|---|
| `database/migrations/*` | Rewrite clean migrations |
| `app/Jobs/ComputeDailySnapshotJob.php` | Write to daily_snapshot_products |
| `app/Jobs/SyncAdInsightsJob.php` | Capture frequency, conversions, search_impression_share, raw_insights JSONB |
| `app/Jobs/SyncSearchConsoleJob.php` | Add device + country dimensions |
| `app/Jobs/SyncProductsJob.php` | Add stock, categories, product_type |
| `app/Jobs/SyncStoreOrdersJob.php` | Capture payment_method, shipping_country, coupons, customer_id |
| `app/Actions/UpsertWooCommerceOrderAction.php` | Map new order fields + create order_coupons |
| `app/Actions/ConnectStoreAction.php` | Register product.updated webhook |
| `app/Http/Controllers/DashboardController.php` | Full cross-channel priority-tier data |
| `app/Http/Controllers/AnalyticsController.php` | Products: daily_snapshot_products + trending |
| `app/Http/Controllers/SeoController.php` | Add revenue context |
| `app/Http/Controllers/CampaignsController.php` | Add organic revenue + spend velocity |
| `app/Http/Controllers/BillingController.php` | 3-tier billing + ad-spend billing + trial expiry |
| `app/Jobs/ReportMonthlyRevenueToStripeJob.php` | Ad-spend billing for non-ecom, 1% GMV / 2% ad spend Scale tier |
| `app/Services/Ai/AiSummaryService.php` | Two-call approach, structured anomaly output |
| `app/Jobs/GenerateAiSummaryJob.php` | Updated skip logic, Alert creation |
| `app/Services/Integrations/Facebook/FacebookAdsClient.php` | Request frequency, creative data |
| `app/Services/Integrations/Google/GoogleAdsClient.php` | Request conversions, search_impression_share, budgets |
| `app/Services/Integrations/SearchConsole/SearchConsoleClient.php` | Add device + country dimensions |
| `app/Http/Controllers/WooCommerceWebhookController.php` | Handle product.updated |
| `app/Http/Controllers/InsightsController.php` | Render prose narratives with [View details] [Dismiss] [This was expected] actions, handle is_silent filtering |
| `resources/js/Components/layouts/AppLayout.tsx` | New nav, conditional sections |
| `resources/js/Components/charts/MultiSeriesLineChart.tsx` | Event overlays, GSC series |
| `resources/js/Pages/Dashboard.tsx` | Priority-tier layout |
| `config/billing.php` | 3-tier structure (Starter/Growth/Scale), ad-spend equivalents |
| `config/horizon.php` | Phase 4: Add browser supervisor for Playwright synthetic checkout |

## New Files to Create

| File | Purpose |
|---|---|
| `app/Contracts/StoreConnector.php` | Interface for multi-platform support |
| `app/Services/RevenueAttributionService.php` | UTM-attributed revenue query |
| `app/Jobs/ComputeMetricBaselinesJob.php` | Rolling median + MAD per metric per weekday |
| `app/Jobs/DetectAnomaliesJob.php` | Rule-based anomaly detection + correlateSignals() |
| `app/Jobs/RunLighthouseCheckJob.php` | PSI API check per URL |
| `app/Jobs/EvaluateUptimeJob.php` | Reads probe results, fires alerts when ≥2 probes confirm down |
| `app/Http/Controllers/Api/UptimeProbeController.php` | API endpoints for external probe scripts (targets + report) |
| `app/Jobs/ReconcileStoreOrdersJob.php` | Daily order reconciliation against Woo API |
| `app/Jobs/SyncRecentRefundsJob.php` | Refund backfill, last 7 days |
| `app/Jobs/RefreshHolidaysJob.php` | Global holiday table population via yasumi |
| `app/Models/Holiday.php` | Global holiday reference model |
| `app/Jobs/GenerateMonthlyReportJob.php` | PDF report generation |
| `app/Jobs/CleanupPerformanceDataJob.php` | Uptime/lighthouse data retention |
| `app/Services/PerformanceMonitoring/PsiClient.php` | PSI API wrapper with quota guard |
| `app/Http/Controllers/PerformanceController.php` | /performance page |
| `app/Http/Controllers/WorkspaceOverviewController.php` | Multi-workspace overview |
| `app/Models/StoreUrl.php` | Monitored URL model |
| `app/Models/LighthouseSnapshot.php` | PSI result model |
| `app/Models/UptimeCheck.php` | Uptime result model |
| `app/Models/UptimeDailySummary.php` | Aggregated uptime model |
| `app/Models/DailySnapshotProduct.php` | Normalized product snapshot |
| `app/Models/MetricBaseline.php` | Precomputed baseline model |
| `app/Models/WorkspaceEvent.php` | Promotion/event context model |
| `app/Models/OrderCoupon.php` | Normalized coupon model |
| `app/Models/Refund.php` | Individual refund event model |
| `app/Models/ProductCategory.php` | Category model |
| `app/Models/GoogleAdsKeyword.php` | Keyword-level Google Ads data |
| `app/Models/NotificationPreference.php` | Per-workspace-per-user alert config |
| `app/Models/CouponExclusion.php` | Auto-promotion exclusion list |
| `app/Models/StoreWebhook.php` | Webhook audit model |
| `resources/js/Pages/Performance/Index.tsx` | Performance page |
| `resources/js/Pages/Workspaces/Overview.tsx` | Multi-workspace overview |
| `SILENT_MODE_GRADUATION.md` | Go/no-go criteria for enabling live alerts |

---

## Verification

### Phase 0
- `php artisan migrate:fresh --seed` — all tables with correct columns/indexes/constraints
- Product webhook: trigger product.updated in Woo, confirm stock_status/categories updated
- Holidays: dispatch RefreshHolidaysJob for country=DE, confirm German holidays in holidays table (NOT workspace_events)
- Coupons: create order with coupon via Woo, confirm order_coupons row created
- Refunds: issue partial refund in Woo, dispatch SyncRecentRefundsJob, confirm refunds row + orders.refund_amount updated
- GSC: dispatch sync, confirm device + country columns populated on gsc_daily_stats/gsc_queries/gsc_pages
- Ad insights: dispatch sync, confirm frequency, conversions, search_impression_share, raw_insights populated
- order_items: no direct queries bypass tenant isolation (all go through Order relationship)

### Phase 1
- Dashboard: all four conditional rows render correctly based on integration flags
- Dashboard: absent sections collapse, show connection prompts
- Performance: dispatch RunLighthouseCheckJob, confirm lighthouse_snapshots row with scores
- Billing: non-ecom workspace on ad-spend tier calculates correctly (2% rate)
- Billing: trial expiry freezes sync jobs
- Billing: Scale tier auto-assigned when GMV >€25k
- Reconciliation: inject missing order, run ReconcileStoreOrdersJob, confirm backfilled
- Event overlay: create workspace_event + holiday marker both visible on dashboard chart
- Workspace events: manual promotion creation, verify chart overlay renders
- Data accuracy help links present at: Platform ROAS tooltip, GSC cards (lag note), Woo order counts (webhook/reconciliation timing)
- Admin impersonation: admin can view any workspace's dashboard without being a member
- Trial reactivation: freeze workspace, wait, pay — confirm catch-up sync fills the data gap
- Empty-state dashboard: new workspace shows raw metrics + baseline progress indicator, not blank cards

### Phase 2
- Baselines: dispatch ComputeMetricBaselinesJob with 6+ weeks of test data, confirm metric_baselines rows
- Anomaly detection: seed 35% revenue drop vs baseline, dispatch DetectAnomaliesJob, confirm Alert with source='rule' and is_silent=true
- Alert deduplication: dispatch twice on same condition, confirm only one Alert
- Holiday suppression: anomaly on a holiday date is suppressed (checks holidays table by workspace.country)
- Uptime: external probe POSTs to /api/uptime/report (10-min intervals), confirm uptime_checks row. EvaluateUptimeJob fires alert after 2 consecutive downs from ≥2 probes (~20 min)
- AI alerts: dispatch GenerateAiSummaryJob, confirm AI-sourced alerts in Insights feed
- Correlation: seed multi-signal anomaly (revenue drop + GSC drop), confirm composite narrative
- Revenue impact: confirm estimated_impact_low/high populated on alert, shown as range in UI
- CTR gaps: seed GSC query with high impressions + low CTR, confirm appears in SEO page
- Checkout health: configure test product, run check against working store (pass) and broken URL (alert)
- Silent mode graduation: review silent alerts, confirm ≥70% TP rate before flipping default
