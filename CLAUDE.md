# Metrify — Implementation Spec for Claude Code

## Critical: Read This First
Every implementation decision is here. Read in full before writing any code. Do not guess, do not infer defaults, do not introduce anything not specified here. For the *why* behind any decision, see `PLANNING.md`.

Working name: **Metrify**. Find-and-replace when a final name is chosen.

---

## Setup (First Session)

```bash
# 1. Create project
composer create-project laravel/laravel metrify "^12.0"

# 2. Auth + Inertia + React + TypeScript (one command)
composer require laravel/breeze --dev
php artisan breeze:install react --typescript
npm install && npm run build

# 3. Cashier — DO NOT run vendor:publish --tag="cashier-migrations"
#    Billing columns live in workspaces migration. subscriptions/subscription_items in your own migration.
composer require laravel/cashier
php artisan vendor:publish --tag="cashier-config"

# 4. Horizon
composer require laravel/horizon
php artisan horizon:install

# 5. shadcn/ui — IMPORTANT: specify paths when prompted
npx shadcn@latest init
# Prompts: TypeScript=yes, Style=Default, Base color=Zinc, CSS variables=yes
# Components path: resources/js/components/ui   ← must match directory structure
# Utils path: resources/js/lib/utils
npx shadcn@latest add button card badge dialog dropdown-menu select table tabs toast input label separator popover calendar sheet
npm install recharts

# 6. Migrations (in this order)
php artisan migrate
# Order: core tenant → stores → ads → aggregation → SEO → billing → system

# 7. Implement WorkspaceScope + SetActiveWorkspace middleware FIRST — nothing is safe without these

# 8. Verify
php artisan test
```

**Server requirements:** PHP 8.5, Node.js 20+ (Vite/shadcn require Node 18 minimum), PostgreSQL 18, Redis 8.

---

## Tech Stack

| Layer | Choice | Version |
|---|---|---|
| Backend | Laravel | 12.x |
| Frontend bridge | Inertia.js | 2.x |
| Frontend | React | 18.x |
| CSS | Tailwind CSS | 3.x |
| UI | shadcn/ui | latest |
| Charts | Recharts | latest |
| Database | PostgreSQL | 18.x |
| Cache/Queue | Redis | 8.x with AOF |
| Queue dashboard | Laravel Horizon | latest |
| Auth | Laravel Breeze | latest |
| Billing | Laravel Cashier (Stripe) | latest |

**Do not add:** Redux, Zustand, Livewire, additional ORMs, jQuery, or any global state management.

---

## Deployment (Plesk MVP)

**Deploy script** (Plesk Git panel → Additional deployment actions):
```bash
#!/bin/bash
set -e
APP_DIR="/var/www/vhosts/yourdomain.com/httpdocs"
cd "$APP_DIR"
composer install --no-dev --optimize-autoloader --quiet
npm ci --quiet && npm run build
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan event:cache
php artisan queue:restart
sudo supervisorctl restart horizon 2>/dev/null || true
```

**First-time only:** Create `.env` manually from the env vars section. Run `php artisan key:generate`.

**Supervisor** (`/etc/supervisor/conf.d/horizon.conf`):
```ini
[program:horizon]
process_name=%(program_name)s
command=php /var/www/vhosts/yourdomain.com/httpdocs/artisan horizon
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/www/vhosts/yourdomain.com/httpdocs/storage/logs/horizon.log
stopwaitsecs=3600
```
Then: `supervisorctl reread && supervisorctl update && supervisorctl start horizon`

**Redis AOF** (`/etc/redis/redis.conf`):
```
appendonly yes
appendfsync everysec
```

For Phase 2 three-server architecture, Deployer, Ansible, and Hetzner Managed Databases — see PLANNING.md §Deployment.

---

## Architecture

```
Browser (React + Inertia page components)
    ↕ Inertia JSON responses (not a REST API)
Laravel 12 → Middleware → Controllers → Actions → Inertia::render()
    ↕
PostgreSQL 18 (raw data + pre-aggregated snapshots)
    ↕
Redis 8 AOF (sessions, cache, queue)
    ↕
Laravel Horizon (ALL external HTTP calls — never in request cycle)
    ↕
External APIs (WooCommerce, Facebook, Google, GSC, Anthropic, frankfurter.dev)
```

`/api/*` routes exist only for webhook ingestion and import-status polling. Everything else is an Inertia page load.

---

## Multi-Tenancy

Top-level tenant = **Workspace**. All data belongs to a workspace.

**WorkspaceScope implementation** — singleton in service container, never `session()` in scopes:
```php
// app/Services/WorkspaceContext.php
class WorkspaceContext {
    private ?int $workspaceId = null;
    public function set(int $id): void { $this->workspaceId = $id; }
    public function id(): ?int { return $this->workspaceId; }
}
// AppServiceProvider: $this->app->singleton(WorkspaceContext::class);

// WorkspaceScope::apply():
$id = app(WorkspaceContext::class)->id();
if (!$id) { throw new \RuntimeException('WorkspaceContext not set — call set() before querying workspace-scoped models.'); }
$builder->where('workspace_id', $id);

// Every job handle(): app(WorkspaceContext::class)->set($this->workspaceId);
```

**SetActiveWorkspace middleware:** reads `session('active_workspace_id')`, sets `WorkspaceContext`. Fallback: oldest workspace by `workspace_users.created_at`. No workspace → `/no-workspace`. Skip redirecting if already on: `/no-workspace`, `/onboarding`, `/login`, `/register`, `/email/verify`, `/password/*`, `/logout`.

**EnforceBillingAccess middleware** (after SetActiveWorkspace): if `trial_ends_at < now()` and `billing_plan IS NULL`, OR `plan_grace_ends_at IS NOT NULL AND plan_grace_ends_at < now()` → redirect to `/settings/billing`. Exceptions: `/settings/billing`, `/settings/profile`, `/logout`, `/no-workspace`, `/oauth/*`.

**Soft-deleted workspaces:** Workspace model uses Laravel's `SoftDeletes` trait — Eloquent automatically excludes soft-deleted rows from all queries. `PurgeDeletedWorkspaceJob` uses `onlyTrashed()` to find candidates for hard-delete. All sync jobs get the filtering for free via the trait.

**Workspace lifecycle:** Auto-created on first store connection. name = store domain. Create `workspaces` row + `workspace_users` row (role=owner) in one transaction. Set `trial_ends_at = now() + 14 days`.

**reporting_currency change:** Dispatch `RecomputeReportingCurrencyJob`. Show banner until complete.

---

## User Roles

| Permission | Super Admin | Owner | Admin | Member |
|---|---|---|---|---|
| View dashboards | ✓ | ✓ | ✓ | ✓ |
| Connect/disconnect integrations | — | ✓ | ✓ | — |
| Invite/remove users | — | ✓ | ✓ | — |
| Manage billing | — | ✓ | — | — |
| Delete workspace | — | ✓ | — | — |
| Rename workspace/settings | — | ✓ | ✓ | — |
| Super admin panel | ✓ | — | — | — |

No Viewer role in MVP. `is_super_admin` boolean on `users`. Super admin panel: workspace list, user list, impersonation, manual sync trigger, enterprise plan management. Log impersonation: `Log::info('Admin impersonation', ['admin_id' => ..., 'target_user_id' => ...])`.

---

## Timezones

- All timestamps stored in UTC
- Display in store's IANA timezone (`stores.timezone`). Hourly snapshots stored UTC, convert on display.
- Workspace-level views use `workspaces.reporting_timezone` (default `Europe/Berlin`)

---

## Granularity

- ≤ 3 days → hourly default
- 4–90 days → daily default (click bar to drill hourly)
- > 90 days → weekly (aggregate `daily_snapshots` with `DATE_TRUNC('week', date)`)

Comparison mode state in URL: `?from=&to=&compare_from=&compare_to=&granularity=daily`. Use `router.get()` with `preserveState: true`.

---

## Frontend Design

- Sidebar: 220px fixed, collapsible on small screens
- Top bar: title, date range picker (always visible), comparison toggle, alert bell, avatar
- Content: fluid, max-width 1400px, 24px padding
- Skeleton loaders per card/chart, no full-page spinners

**Tailwind tokens:** bg=`zinc-50`, cards=white/`zinc-200` border, accent=`indigo-600`, positive=`green-700`, negative=`red-700`, headings=`zinc-900`, body=`zinc-600`, labels=`zinc-400`

**Shared components (never duplicate):**
- `MetricCard` — value, label, trend arrow + Δ% badge
- Chart wrappers — accept `data`, `granularity`, `comparisonData?`, `currency`, `timezone`. Y axis compact (€12.4k).
- `DateRangePicker` — presets + custom + comparison. State in URL.
- `AlertBanner` — transient notices only, at most one, highest severity wins. Distinct from alert feed.
- `PageHeader` — title + subtitle + action slot

Empty states: illustration + CTA on every list/chart with no data.

---

## Directory Structure

```
app/
  Http/Controllers/     ← validate → Action → Inertia::render()
  Http/Middleware/      ← SetActiveWorkspace, EnforceBillingAccess, VerifyWebhookSignature, RequireSuperAdmin
  Http/Requests/        ← Form request validation
  Actions/              ← Business logic. One handle() per class.
  Models/               ← Relationships, scopes, accessors only
  Policies/             ← One per model
  Services/Integrations/WooCommerce/
  Services/Integrations/Facebook/
  Services/Integrations/Google/
  Services/Integrations/SearchConsole/
  Services/Aggregation/ ← DailySnapshotService, HourlySnapshotService
  Services/Billing/     ← BillingService
  Services/Fx/          ← FxRateService
  Services/Ai/          ← AiSummaryService
  Jobs/
  Scopes/               ← WorkspaceScope
resources/js/
  Pages/                ← One file per route
  Components/ui/        ← shadcn/ui primitives (do not modify)
  Components/charts/    ← Recharts wrappers
  Components/layouts/   ← AppLayout, AuthLayout
  Components/shared/    ← MetricCard, DateRangePicker, PageHeader, AlertBanner
  Hooks/                ← useMetrics, useDateRange, useWorkspace, useFormatter
  lib/                  ← formatCurrency, formatNumber, formatDate, formatPercent
```

---

## Database Schema

### Conventions
- `snake_case` plural tables; `_encrypted` suffix for credentials; `occurred_at` for external timestamps
- `fx_rates` has `created_at` only (rates never revised). GSC tables have both.
- NOT NULL unless marked `(nullable)`

### Core Tenant

```sql
users
  id bigserial PK, name varchar(255), email varchar(255) UNIQUE
  email_verified_at timestamp (nullable), password varchar(255)
  is_super_admin boolean DEFAULT false, remember_token varchar(100) (nullable)
  last_login_at timestamp (nullable)
  created_at, updated_at

workspaces
  id bigserial PK, name varchar(255), slug varchar(255) UNIQUE
  -- slug: slugified name, append Str::random(4) on collision, never changed after creation
  owner_id bigint → users SET NULL   -- SET NULL is safety net only; always assign new owner before nulling
  reporting_currency char(3) DEFAULT 'EUR'
  reporting_timezone varchar(100) DEFAULT 'Europe/Berlin'
  trial_ends_at timestamp (nullable)
  billing_plan varchar(50) CHECK (billing_plan IN ('starter','growth','scale','percentage','enterprise')) (nullable)
  plan_grace_ends_at timestamp (nullable)
  stripe_id varchar(255) (nullable), pm_type varchar(255) (nullable), pm_last_four varchar(4) (nullable)
  billing_name varchar(255) (nullable), billing_email varchar(255) (nullable)
  billing_address jsonb (nullable)   -- {line1, line2, city, postal_code, country}
  vat_number varchar(50) (nullable)
  is_orphaned boolean DEFAULT false
  deleted_at timestamp (nullable)   -- soft-delete; hard purge runs 30 days after this is set
  created_at, updated_at

workspace_users  -- pivot
  id bigserial PK
  workspace_id → workspaces CASCADE
  user_id → users CASCADE
  role varchar(50) CHECK (role IN ('owner','admin','member'))
  created_at, updated_at
  UNIQUE(workspace_id, user_id); INDEX(user_id)

workspace_invitations
  id bigserial PK, workspace_id → workspaces CASCADE
  email varchar(255), role varchar(50) CHECK (role IN ('admin','member'))
  token varchar(255) UNIQUE, expires_at timestamp
  accepted_at timestamp (nullable)
  created_at, updated_at
  INDEX(workspace_id)
```

### Stores

```sql
stores
  id bigserial PK, workspace_id → workspaces CASCADE
  name varchar(255)
  type varchar(50) CHECK (type IN ('woocommerce','shopify','magento','bigcommerce','prestashop','opencart'))
  domain varchar(255), currency char(3), timezone varchar(100) DEFAULT 'Europe/Berlin'
  platform_store_id varchar(255) (nullable)
  status varchar(50) DEFAULT 'connecting' CHECK (status IN ('connecting','active','error','disconnected'))
  consecutive_sync_failures smallint DEFAULT 0
  auth_key_encrypted text (nullable)      -- WooCommerce: consumer_key | BigCommerce: client_id
  auth_secret_encrypted text (nullable)   -- WooCommerce: consumer_secret | BigCommerce: client_secret
  access_token_encrypted text (nullable)  -- OAuth access token
  refresh_token_encrypted text (nullable) -- OAuth refresh token
  token_expires_at timestamp (nullable)
  webhook_secret_encrypted text (nullable)
  platform_webhook_ids jsonb (nullable)   -- {"order.created": 42} — keyed by event name
  historical_import_status varchar(50) CHECK (historical_import_status IN ('pending','running','completed','failed')) (nullable)
  historical_import_from date (nullable)
  historical_import_checkpoint jsonb (nullable)  -- WooCommerce: {date_cursor: "YYYY-MM-DD"}
  historical_import_progress smallint (nullable) -- 0-100
  historical_import_total_orders integer (nullable) -- X-WP-Total from count request; used for time estimate + progress calc
  historical_import_started_at timestamp (nullable)
  historical_import_completed_at timestamp (nullable)
  historical_import_duration_seconds integer (nullable)
  last_synced_at timestamp (nullable)
  created_at, updated_at
  UNIQUE(workspace_id, domain); INDEX(workspace_id)

-- Always upsert on (store_id, external_id). Platforms reuse IDs after backup restores.
orders
  id bigserial PK
  workspace_id → workspaces CASCADE, store_id → stores CASCADE
  external_id varchar(255), external_number varchar(255) (nullable)
  status varchar(100) CHECK (status IN ('completed','processing','refunded','cancelled','other'))
  currency char(3)
  total numeric(12,4), subtotal numeric(12,4), tax numeric(12,4) DEFAULT 0
  shipping numeric(12,4) DEFAULT 0, discount numeric(12,4) DEFAULT 0
  total_in_reporting_currency numeric(12,4) (nullable)  -- NULL if FX rate unavailable; NEVER treat as 0
  customer_email_hash char(64) (nullable)  -- SHA-256(lowercase(trim(email))); NEVER store raw email
  customer_country char(2) (nullable)
  utm_source varchar(255) (nullable), utm_medium varchar(255) (nullable)
  utm_campaign varchar(255) (nullable), utm_content varchar(255) (nullable)
  occurred_at timestamp, synced_at timestamp
  created_at, updated_at
  UNIQUE(store_id, external_id)
  INDEX(workspace_id, occurred_at)
  INDEX(workspace_id, store_id, occurred_at)
  INDEX(workspace_id, customer_country, occurred_at)
  INDEX(store_id, synced_at)

order_items
  id bigserial PK
  order_id → orders CASCADE
  workspace_id → workspaces CASCADE, store_id → stores CASCADE
  product_external_id varchar(255), product_name varchar(500)
  variant_name varchar(255) (nullable), sku varchar(255) (nullable)
  quantity integer, unit_price numeric(12,4), line_total numeric(12,4)
  created_at, updated_at
  -- No standard UNIQUE — nullable variant_name breaks PG uniqueness. Use expression index:
  -- CREATE UNIQUE INDEX order_items_upsert_key ON order_items (order_id, product_external_id, COALESCE(variant_name, ''));
  INDEX(workspace_id, product_external_id); INDEX(order_id)

products
  id bigserial PK
  workspace_id → workspaces CASCADE, store_id → stores CASCADE
  external_id varchar(255), name varchar(500)
  sku varchar(255) (nullable), price numeric(12,4) (nullable), status varchar(100) (nullable)
  image_url text (nullable), product_url text (nullable)
  platform_updated_at timestamp (nullable)
  created_at, updated_at
  UNIQUE(store_id, external_id); INDEX(workspace_id, store_id)
```

### Ads

```sql
ad_accounts
  id bigserial PK, workspace_id → workspaces CASCADE
  platform varchar(50) CHECK (platform IN ('facebook','google'))
  external_id varchar(255), name varchar(255), currency char(3)
  access_token_encrypted text (nullable), refresh_token_encrypted text (nullable)
  token_expires_at timestamp (nullable)
  status varchar(50) DEFAULT 'active' CHECK (status IN ('active','error','token_expired','disconnected'))
  consecutive_sync_failures smallint DEFAULT 0
  last_synced_at timestamp (nullable)
  created_at, updated_at
  UNIQUE(workspace_id, platform, external_id); INDEX(workspace_id)

campaigns
  id bigserial PK, workspace_id → workspaces CASCADE, ad_account_id → ad_accounts CASCADE
  external_id varchar(255), name varchar(500), status varchar(100) (nullable), objective varchar(100) (nullable)
  created_at, updated_at
  UNIQUE(ad_account_id, external_id); INDEX(workspace_id, ad_account_id)

adsets
  id bigserial PK, workspace_id → workspaces CASCADE, campaign_id → campaigns CASCADE
  external_id varchar(255), name varchar(500), status varchar(100) (nullable)
  created_at, updated_at
  UNIQUE(campaign_id, external_id); INDEX(workspace_id)

ads
  id bigserial PK, workspace_id → workspaces CASCADE, adset_id → adsets CASCADE
  external_id varchar(255), name varchar(500) (nullable), status varchar(100) (nullable)
  destination_url text (nullable)
  created_at, updated_at
  UNIQUE(adset_id, external_id); INDEX(workspace_id)

-- Only 'campaign' and 'ad' level rows stored. Account/adset levels never inserted.
ad_insights
  id bigserial PK
  workspace_id → workspaces CASCADE, ad_account_id → ad_accounts SET NULL (nullable)  -- SET NULL: insight rows survive ad account disconnection
  level varchar(20) NOT NULL CHECK (level IN ('campaign','ad'))
  campaign_id → campaigns SET NULL (nullable)  -- SET NULL: insight rows survive campaign deletion
  adset_id → adsets SET NULL (nullable)
  ad_id → ads SET NULL (nullable)
  date date, hour smallint (nullable)          -- NULL=daily, 0-23=hourly
  spend numeric(12,4) DEFAULT 0
  spend_in_reporting_currency numeric(12,4) (nullable)
  impressions bigint DEFAULT 0, clicks bigint DEFAULT 0, reach bigint (nullable)
  ctr numeric(8,6) (nullable), cpc numeric(10,4) (nullable)
  platform_roas numeric(10,4) (nullable)
  currency char(3)
  created_at, updated_at
  INDEX(workspace_id, ad_account_id, date)
  INDEX(workspace_id, campaign_id, date)
  INDEX(workspace_id, ad_id, date)
  INDEX(workspace_id, date)
  -- Create via DB::statement() in migration:
  -- CREATE UNIQUE INDEX ai_campaign_daily_unique  ON ad_insights (campaign_id, date)       WHERE level='campaign' AND hour IS NULL;
  -- CREATE UNIQUE INDEX ai_campaign_hourly_unique ON ad_insights (campaign_id, date, hour) WHERE level='campaign' AND hour IS NOT NULL;
  -- CREATE UNIQUE INDEX ai_ad_daily_unique        ON ad_insights (ad_id, date)             WHERE level='ad' AND hour IS NULL;
  -- CREATE UNIQUE INDEX ai_ad_hourly_unique       ON ad_insights (ad_id, date, hour)       WHERE level='ad' AND hour IS NOT NULL;
```

### Aggregation

```sql
-- NEVER query raw orders for dashboard requests. Always use snapshots.
-- ComputeDailySnapshotJob accepts constructor params: (int $storeId, Carbon $date)
--   The nightly scheduled entry is a dispatcher: iterates all active stores, dispatches one
--   child job per store for yesterday's date. After historical import: one child job per
--   imported date for that store.
--   INSERT ... ON CONFLICT (store_id, date) DO UPDATE (idempotent)
-- revenue = SUM(total_in_reporting_currency) for status IN ('completed','processing')
-- top_products: [{external_id, name, units, revenue}] top 10 by revenue
-- revenue_by_country: {"DE": 3200.00, "AT": 1800.00} — in reporting_currency
daily_snapshots
  id bigserial PK
  workspace_id → workspaces CASCADE, store_id → stores CASCADE
  date date
  orders_count integer DEFAULT 0
  revenue numeric(14,4) DEFAULT 0
  revenue_native numeric(14,4) DEFAULT 0        -- in store's native currency, no FX
  aov numeric(10,4) (nullable)                  -- NULL if orders_count=0
  items_sold integer DEFAULT 0
  items_per_order numeric(6,2) (nullable)        -- NULL if orders_count=0
  new_customers integer DEFAULT 0               -- customer_email_hash first appearance (non-NULL hashes only)
  returning_customers integer DEFAULT 0
  revenue_by_country jsonb (nullable)
  top_products jsonb (nullable)
  created_at, updated_at
  UNIQUE(store_id, date)
  INDEX(workspace_id, date); INDEX(workspace_id, store_id, date)

-- Retained forever. Stored UTC, convert to store timezone on display.
-- Computed nightly at 00:45 UTC for previous day's hours. "Today" hourly data is not
-- available until the next nightly run — UI defaults today's view to daily granularity.
hourly_snapshots
  id bigserial PK
  workspace_id → workspaces CASCADE, store_id → stores CASCADE
  date date, hour smallint  -- 0-23 UTC
  orders_count integer DEFAULT 0, revenue numeric(14,4) DEFAULT 0
  created_at, updated_at
  UNIQUE(store_id, date, hour); INDEX(workspace_id, store_id, date)
```

### SEO

```sql
search_console_properties
  id bigserial PK, workspace_id → workspaces CASCADE
  store_id → stores SET NULL (nullable)         -- linked if domain matches store
  property_url varchar(500)
  access_token_encrypted text (nullable), refresh_token_encrypted text (nullable)
  token_expires_at timestamp (nullable)
  status varchar(50) DEFAULT 'active' CHECK (status IN ('active','error','token_expired','disconnected'))
  consecutive_sync_failures smallint DEFAULT 0
  last_synced_at timestamp (nullable)
  created_at, updated_at
  UNIQUE(workspace_id, property_url); INDEX(workspace_id)

gsc_daily_stats
  id bigserial PK, property_id → search_console_properties CASCADE
  workspace_id → workspaces CASCADE
  date date, clicks integer DEFAULT 0, impressions integer DEFAULT 0
  ctr numeric(8,6) (nullable), position numeric(6,2) (nullable)
  created_at, updated_at
  UNIQUE(property_id, date); INDEX(workspace_id, date)

gsc_queries  -- top 1,000 per property per day, retained forever
  id bigserial PK, property_id → search_console_properties CASCADE
  workspace_id → workspaces CASCADE
  date date, query varchar(500)
  clicks integer DEFAULT 0, impressions integer DEFAULT 0
  ctr numeric(8,6) (nullable), position numeric(6,2) (nullable)
  created_at, updated_at
  UNIQUE(property_id, date, query); INDEX(workspace_id, date)

gsc_pages  -- top 1,000 per property per day, retained forever
  id bigserial PK, property_id → search_console_properties CASCADE
  workspace_id → workspaces CASCADE
  date date, page varchar(2000)  -- varchar not text: B-tree limit; truncate URLs > 2000 chars
  clicks integer DEFAULT 0, impressions integer DEFAULT 0
  ctr numeric(8,6) (nullable), position numeric(6,2) (nullable)
  created_at, updated_at
  UNIQUE(property_id, date, page); INDEX(property_id, date); INDEX(workspace_id, date)
```

### Billing (Cashier-managed — define in your own migration, not Cashier's)

```sql
subscriptions
  id bigserial PK
  billable_type varchar(255)   -- 'App\Models\Workspace'
  billable_id bigint
  type varchar(255)            -- always 'default'
  stripe_id varchar(255) UNIQUE
  stripe_status varchar(255)
  stripe_price varchar(255) (nullable)
  quantity integer (nullable)
  trial_ends_at timestamp (nullable), ends_at timestamp (nullable)
  created_at, updated_at
  INDEX(billable_type, billable_id, stripe_status)

subscription_items
  id bigserial PK, subscription_id → subscriptions CASCADE
  stripe_id varchar(255) UNIQUE
  stripe_product varchar(255) (nullable), stripe_price varchar(255)
  quantity integer (nullable)
  created_at, updated_at
  INDEX(subscription_id, stripe_price)
```

### System

```sql
-- DB is the cache. Always query fx_rates first; call API only for missing dates.
-- No quotas on Frankfurter API; rate-limited to prevent abuse only.
fx_rates
  id bigserial PK, base_currency char(3) DEFAULT 'EUR'
  target_currency char(3), rate numeric(16,8), date date
  created_at timestamp    -- no updated_at: historical rates never revised
  UNIQUE(base_currency, target_currency, date); INDEX(date)

sync_logs
  id bigserial PK, workspace_id → workspaces CASCADE
  syncable_type varchar(255), syncable_id bigint
  job_type varchar(100)
  status varchar(50) CHECK (status IN ('running','completed','failed'))
  records_processed integer (nullable), error_message text (nullable)
  started_at timestamp (nullable), completed_at timestamp (nullable), duration_seconds integer (nullable)
  created_at, updated_at
  INDEX(workspace_id, syncable_type, syncable_id); INDEX(status, created_at)

-- Payload may contain PII. Justified under legitimate interest. Purge after 30 days.
webhook_logs
  id bigserial PK, store_id → stores CASCADE, workspace_id → workspaces CASCADE
  event varchar(255), payload jsonb
  signature_valid boolean
  status varchar(50) DEFAULT 'pending' CHECK (status IN ('pending','processed','failed'))
  error_message text (nullable), processed_at timestamp (nullable)
  created_at, updated_at
  INDEX(store_id, created_at)

ai_summaries
  id bigserial PK, workspace_id → workspaces CASCADE
  date date, summary_text text
  payload_sent jsonb (nullable), model_used varchar(100) (nullable), generated_at timestamp
  created_at, updated_at
  UNIQUE(workspace_id, date); INDEX(workspace_id, date)

alerts
  id bigserial PK, workspace_id → workspaces CASCADE
  store_id → stores (nullable, CASCADE)
  ad_account_id → ad_accounts (nullable, CASCADE)
  type varchar(100), severity varchar(50) CHECK (severity IN ('info','warning','critical'))
  data jsonb (nullable), read_at timestamp (nullable), resolved_at timestamp (nullable)
  created_at, updated_at
  INDEX(workspace_id, resolved_at, created_at)
```

---

## Key Business Logic

### Formulas
```
ROAS        = SUM(daily_snapshots.revenue) / SUM(ad_insights.spend_in_reporting_currency WHERE level='campaign')
Blended ROAS = same, no store/account filter
AOV          = revenue / orders_count  (completed+processing only)
Marketing %  = (SUM(ad_insights.spend WHERE level='campaign') / SUM(revenue)) × 100
```
- Always filter `ad_insights` by a single `level`. Never SUM across levels.
- `SUM(spend) = 0` → display "N/A". Revenue = 0 → display "N/A". Never divide by zero.
- All values pre-converted to `reporting_currency` at sync time. Never join `fx_rates` at query time.

### FX Rate Conversion — FxRateService
DB-first: `fx_rates` table is the cache. **Never call Frankfurter API from FxRateService.**
```
Lookup logic:
1. Query fx_rates WHERE date = $date → found → return
2. Not found → look back up to 3 days for nearest earlier rate → return
3. Still not found → throw FxRateNotFoundException
   Callers: log warning, leave total_in_reporting_currency = NULL
   RetryMissingConversionJob handles NULLs nightly

Four-case conversion:
1. order.currency == reporting_currency → return total as-is
2. order.currency == 'EUR'             → total × rate_EUR_to_reporting
3. reporting_currency == 'EUR'         → total / rate_EUR_to_order
4. neither is EUR                      → total × (rate_EUR_to_reporting / rate_EUR_to_order)
```

### Which Table to Query
- Dashboard metric cards/charts → `daily_snapshots`, `hourly_snapshots`
- Ad metrics → `ad_insights`
- Top products → `daily_snapshots.top_products` (JSONB)
- Country breakdown → `daily_snapshots.revenue_by_country` (JSONB)
- Country top products → `orders JOIN order_items WHERE customer_country = ?` (filtered query, not workspace scan)
- Raw data re-processing → `orders` only in background jobs. NEVER aggregate raw orders in page requests.
- Weekly range (>90 days) → aggregate `daily_snapshots` with `DATE_TRUNC('week', date)`
- No FK between `stores` and `ad_accounts` — ROAS is always blended at workspace level

---

## Integrations

### WooCommerce
**Auth:** Consumer key + secret (user creates in WooCommerce → Settings → Advanced → REST API)
- `auth_key_encrypted` = consumer key, `auth_secret_encrypted` = consumer secret
- **Validate:** `GET {domain}/wp-json/wc/v3/system_status` with Basic auth → 200 = connected
- **Store metadata from system_status:** `stores.name` = `environment.site_title`, `stores.currency` = `settings.currency`, `stores.timezone` = `environment.timezone`

**Webhooks:** `POST /wp-json/wc/v3/webhooks` for `order.created`, `order.updated`, `order.deleted`
- URL: `{app_url}/api/webhooks/woocommerce/{store_id}`
- Store response `secret` → `stores.webhook_secret_encrypted` immediately
- Store webhook IDs → `stores.platform_webhook_ids` (e.g. `{"order.created": 42}`)
- Verify `X-WC-Webhook-Signature`: `base64_encode(hash_hmac('sha256', $rawBody, $webhookSecret))`
- On reconnect: delete old webhooks first (`DELETE /wp-json/wc/v3/webhooks/{id}`), ignore 404s
- `order.deleted` → set status = 'cancelled', never hard-delete

**Scheduled fallback sync** (`SyncStoreOrdersJob`, runs hourly): checks `webhook_logs WHERE created_at >= now()-90min LIMIT 1`. If no webhook rows found → fetch orders modified in last 2 hours via `GET /wp-json/wc/v3/orders?orderby=modified&order=desc&per_page=100&after={now-2h ISO}` (min 500ms between requests). If webhooks are arriving normally, the API call is skipped.

**Historical import:** `GET /wp-json/wc/v3/orders?after={chunk_start}&before={chunk_end}&orderby=date&order=asc&per_page=100&page={n}` — 30-day chunks, paginate until empty

**Order field mapping (WooCommerce → orders):**
| DB column | WC field | Notes |
|---|---|---|
| `external_id` | `id` | cast to string |
| `external_number` | `number` | |
| `status` | `status` | completed→completed, processing→processing, refunded→refunded, cancelled→cancelled, else→other |
| `currency` | `currency` | |
| `total` | `total` | string→float |
| `subtotal` | `subtotal` | |
| `tax` | `total_tax` | |
| `shipping` | `shipping_total` | |
| `discount` | `discount_total` | |
| `customer_email_hash` | SHA-256(lowercase(trim(`billing.email`))) | NULL if empty |
| `customer_country` | `billing.country` | 2-char ISO |
| `utm_source` | meta `_utm_source` | from meta_data array |
| `occurred_at` | `date_created_gmt` | UTC ISO → timestamp |

**Order items from `line_items`:**
| DB column | WC field |
|---|---|
| `product_external_id` | `product_id` cast to string |
| `product_name` | `name` |
| `variant_name` | concatenate attribute meta_data display values; NULL if variation_id=0 |
| `sku` | `sku` |
| `quantity` | `quantity` |
| `unit_price` | `price` string→float |
| `line_total` | `total` string→float |

**Products:** bulk import on connection `GET /wp-json/wc/v3/products?per_page=100`. Nightly: `GET /wp-json/wc/v3/products?modified_after={ISO}&per_page=100`. Upsert into `products`.

### Shopify
**Phase 2 — do not build in MVP.** Schema is already prepared with generic column names. See PLANNING.md for full spec.

### Facebook Ads
**Auth:** OAuth 2.0, scopes: `ads_read`, `business_management`
- Redirect: `https://www.facebook.com/v20.0/dialog/oauth?client_id={FACEBOOK_APP_ID}&redirect_uri={URI}&scope=ads_read,business_management&state={state}`
- **State parameter (all OAuth flows):** base64url-encode a JSON payload: `base64url({"csrf":"...","workspace_id":1,"type":"facebook"})`. On callback: decode, verify CSRF matches session, extract workspace_id and type. This avoids delimiter collision issues.
- State: verify CSRF matches session; extract workspace_id
- Exchange code: `GET https://graph.facebook.com/oauth/access_token?client_id=...&client_secret=...&redirect_uri=...&code={code}`
- Immediately exchange for long-lived token: `GET https://graph.facebook.com/oauth/access_token?grant_type=fb_exchange_token&client_id=...&client_secret=...&fb_exchange_token={short}`
- Store long-lived token → `access_token_encrypted`. Set `token_expires_at = now() + expires_in`. No refresh token — requires re-auth after expiry. Alert owner 7 days before expiry.

**Insights API:** `GET https://graph.facebook.com/v20.0/act_{adAccountId}/insights`
- `level=campaign` (or `ad`), `fields=spend,impressions,clicks,reach,ctr,cpc,purchase_roas,account_currency`
- `time_range={"since":"YYYY-MM-DD","until":"YYYY-MM-DD"}`, `time_increment=1` (or `hourly`)
- Always re-sync last 3 days (Facebook revises recent figures). Historical limit: ~37 months.

**Field mapping (Facebook → ad_insights):**
| DB | Facebook field |
|---|---|
| `spend` | `spend` string→float |
| `impressions` | `impressions` string→int |
| `clicks` | `clicks` string→int |
| `reach` | `reach` string→int |
| `ctr` | `ctr` string→float |
| `cpc` | `cpc` string→float |
| `platform_roas` | `purchase_roas[0].value` string→float |
| `currency` | `account_currency` |
| `date` | `date_start` |
| `hour` | hourly index 0-23, NULL for daily |

**Campaign/Adset/Ad structure:** sync on every `SyncAdInsightsJob` run (idempotent upsert). `effective_status` → `status`.

**Development:** Use test app with own ad account. Apply for Advanced Access early — takes 2-6 weeks.

### Google Ads
**Auth:** OAuth 2.0, scope: `https://www.googleapis.com/auth/adwords`
- Redirect: `https://accounts.google.com/o/oauth2/v2/auth?...&state={state}` (same base64url JSON format: `{"csrf":"...","workspace_id":1,"type":"google_ads"}`)
- Exchange code: `POST https://oauth2.googleapis.com/token`
- Store `access_token_encrypted`, `refresh_token_encrypted`, `token_expires_at`
- Token expires in 1 hour. Refresh transparently inside `GoogleAdsClient` when within 5 minutes of expiry.
- Developer token required separately (Google Ads Manager Account → API Centre). Apply for Standard Access early (2-3 business days).

**API:** Google Ads API v17+, GAQL. Do NOT use legacy AdWords API.

**GAQL example (campaign-level daily insights):**
```sql
SELECT campaign.id, campaign.name, campaign.status, campaign.advertising_channel_type,
       metrics.cost_micros, metrics.impressions, metrics.clicks, metrics.ctr, metrics.average_cpc,
       segments.date
FROM campaign
WHERE segments.date BETWEEN '{start}' AND '{end}' AND campaign.status != 'REMOVED'
```
Send via `POST https://googleads.googleapis.com/v17/customers/{customerId}/googleAds:searchStream`
Headers: `Authorization: Bearer {token}`, `developer-token: {GOOGLE_ADS_DEVELOPER_TOKEN}`

**Field mapping (GAQL → ad_insights):**
| DB | GAQL |
|---|---|
| `spend` | `metrics.cost_micros` ÷ 1,000,000 |
| `impressions` | `metrics.impressions` |
| `clicks` | `metrics.clicks` |
| `ctr` | `metrics.ctr` |
| `cpc` | `metrics.average_cpc` ÷ 1,000,000 |
| `currency` | ad account currency |
| `date` | `segments.date` |
| `hour` | always NULL (Google Ads has no hourly data) |
| `reach`, `platform_roas` | always NULL |

### Google Search Console
**Auth:** OAuth 2.0, scope: `https://www.googleapis.com/auth/webmasters.readonly`
- Same `GOOGLE_REDIRECT_URI` as Google Ads; differentiate by `type` field in state JSON (`gsc` vs `google_ads`)
- List properties: `GET https://searchconsole.googleapis.com/webmasters/v3/sites`
- Store tokens temporarily in session until user selects property, then move to `search_console_properties` row
- Auto-link to store if domain matches

**Data sync (every 6 hours):**
`POST https://searchconsole.googleapis.com/webmasters/v3/sites/{encodedSiteUrl}/searchAnalytics/query`
```json
{
  "startDate": "today-5",
  "endDate": "today-1",
  "dimensions": ["date"],
  "rowLimit": 1000,
  "dataState": "all"
}
```
Use `dimensions: ["date","query"]` for queries, `["date","page"]` for pages. Query last 5 days every run (covers 2-3 day lag). Upsert on conflict.

GSC has 2-3 day lag. Mark last 3 days in UI as "data may be incomplete."
Token refresh: same pattern as Google Ads. `RefreshOAuthTokenJob` also handles GSC daily at 05:00 UTC.

---

## Billing

### Tiers
| Tier | Revenue limit | Price |
|---|---|---|
| Starter | €2,000/mo | €29/mo |
| Growth | €5,000/mo | €59/mo |
| Scale | €10,000/mo | €119/mo |
| Percentage | ≥€10,000/mo | 1% prev month, min €149 |
| Enterprise | >€250k/mo | custom |

Annual: 2 months free (flat tiers only).

### Mechanics
- **Flat tiers:** standard Stripe subscriptions with fixed Price IDs (monthly + annual)
- **% tier:** Stripe metered billing. Unit = €1 revenue at €0.01 = 1%. €149 floor enforced in code: `$revenueEur = max($calculated, 149.00)` before reporting to Stripe
- **Always report revenue in EUR to Stripe.** Convert using `fx_rates` for last day of previous month if `reporting_currency != EUR`
- **Revenue source:** `SUM(daily_snapshots.revenue)` for status IN (completed, processing) for previous calendar month across all stores

### Cashier Configuration
```php
// app/Models/Workspace.php
use Laravel\Cashier\Billable;
class Workspace extends Model { use Billable; }

// config/cashier.php
'model' => App\Models\Workspace::class,
```

Sync billing details to Stripe on every save:
```php
$workspace->updateStripeCustomer([
    'name' => $workspace->billing_name,
    'email' => $workspace->billing_email,
    'address' => $workspace->billing_address,
    'tax_id_data' => $workspace->vat_number ? [['type' => 'eu_vat', 'value' => $workspace->vat_number]] : [],
]);
```
Call `createOrGetStripeCustomer()` before first `newSubscription()`.

### config/billing.php
```php
'flat_plans' => [
    'starter' => ['price_id_monthly' => env('STRIPE_PRICE_STARTER_M'), 'price_id_annual' => env('STRIPE_PRICE_STARTER_A'), 'revenue_limit' => 2000],
    'growth'  => ['price_id_monthly' => env('STRIPE_PRICE_GROWTH_M'),  'price_id_annual' => env('STRIPE_PRICE_GROWTH_A'),  'revenue_limit' => 5000],
    'scale'   => ['price_id_monthly' => env('STRIPE_PRICE_SCALE_M'),   'price_id_annual' => env('STRIPE_PRICE_SCALE_A'),   'revenue_limit' => 10000],
],
'percentage_plan' => [
    'price_id' => env('STRIPE_PRICE_PERCENTAGE'), 'rate' => 0.01, 'minimum_monthly' => 149,
    'revenue_threshold' => 10000, 'enterprise_threshold' => 250000,
],
```

### billing_plan transitions
- `null` = during trial
- `null` → `starter|growth|scale` when user subscribes
- `null|flat` → `percentage` when user manually subscribes to % plan (job never auto-changes this)
- Any → `enterprise` = super admin sets manually
- Enterprise threshold (€250k/mo) is sales-driven only — no automated conversion or alert
- Listen to Cashier's `WebhookReceived` event to sync `billing_plan`:
  - `customer.subscription.created` → set `billing_plan` based on price ID lookup in `config/billing.php`
  - `customer.subscription.updated` → update `billing_plan`, clear `plan_grace_ends_at`
  - `customer.subscription.deleted` → set `billing_plan = null`
- `past_due`: no custom action in MVP. Stripe retries automatically. Cashier updates `stripe_status`. If Stripe gives up → fires `customer.subscription.deleted` → we null the plan

### Tier Enforcement (CheckWorkspacePlanLimitsJob, nightly 01:30 UTC)
Only processes `billing_plan IN ('starter','growth','scale')`.
- Over limit: set `plan_grace_ends_at = now() + 7 days` **only if currently NULL** (never reset running grace period)
- Within limit: clear `plan_grace_ends_at = null` if it was set (temporary overages auto-unlock)

### Trial
14 days, no credit card. Trial starts at workspace creation (`trial_ends_at = now() + 14 days`). At expiry: syncs paused, redirect to `/settings/billing`.

---

## Workspace Owner Rules
- One Owner always. Owner cannot leave (must transfer first). Owner cannot be demoted directly.
- Owner deletes account → transfer to oldest Admin → if none, mark `is_orphaned = true`
- User removed from workspace → if that workspace was their `session('active_workspace_id')`, clear it. On next request, `SetActiveWorkspace` middleware falls back to their oldest remaining workspace (or `/no-workspace` if none). Membership is checked per-request via middleware, so no full session invalidation needed.
- Enforce all in `WorkspaceMemberPolicy`

---

## Workspace Deletion

Only the Owner can delete a workspace. Deletion is blocked unless both conditions are met:
1. No open (unpaid) invoices in Stripe — check via `$workspace->invoices()` filtered to `status = 'open'`
2. No active Stripe subscription — the Owner must manually cancel the subscription before deletion is allowed. Show a clear prompt: "Please cancel your subscription before deleting this workspace."

**Deletion flow:**
1. Owner initiates deletion → set `deleted_at = now()` (soft-delete)
2. All background sync jobs skip workspaces where `deleted_at IS NOT NULL`
3. Workspace hidden from all UI immediately
4. Owner receives confirmation email with a cancellation link valid for 30 days
5. Owner can cancel the deletion at any time within 30 days → set `deleted_at = null` to restore
6. After 30 days: `PurgeDeletedWorkspaceJob` (scheduled daily) hard-deletes the workspace row — all related data cascades via FK `ON DELETE CASCADE`

**Notes:**
- `PurgeDeletedWorkspaceJob` runs daily (Sunday 05:00 UTC), processes all workspaces where `deleted_at < now() - 30 days`
- All cascade deletes happen in a single DB transaction
- Log the purge: `Log::info('Workspace purged', ['workspace_id' => ..., 'deleted_at' => ...])`

---

## Workspace Invitations
- 7-day expiry. Token: `Str::random(64)` stored as-is (not hashed).
- Admins can invite only Member or Admin (not Owner).
- **New user path** (`/register?invitation={token}`): store token in session on submit. After email verification: read from session, validate, create `workspace_users`, clear token, redirect `/dashboard`. Skip onboarding.
- **Existing user path** (`/login?invitation={token}`): after login, validate token (not expired, not accepted, email matches), create `workspace_users`, set active workspace, redirect `/dashboard`.

---

## Store Status Lifecycle
```
connecting → active (validation passes) or delete row (validation fails)
active     → error (3+ consecutive sync failures)
error      → active (successful sync resets failures)
active/error → disconnected (user manually disconnects)
disconnected → never auto-restored to active
```
`historical_import_status` tracks import separately from store `status`.

---

## Historical Import Flow
1. User selects start date → `historical_import_status = 'pending'`, `historical_import_from = date`
2. Dispatch `WooCommerceHistoricalImportJob` to `low` queue
3. Job starts → write `sync_logs` row, set `historical_import_status = 'running'`
4. **FX prefetch (DB-first):** query `fx_rates` for missing dates in range. If any missing: `GET {env('FRANKFURTER_API_URL')}/rates?from={first_missing}&to={last_missing}&base=EUR`. Upsert. No API call if all dates cached.
5. Read checkpoint → resume from there (or from start_date if null)
6. Fetch orders: `GET /wp-json/wc/v3/orders?after={start}&before={end}&orderby=date&order=asc&per_page=100&page={n}`. Paginate until empty. Advance 30-day chunks.
7. After each page: upsert orders+items, update checkpoint, update progress (0-100)
8. Frontend polls `GET /api/stores/{id}/import-status` every 5 seconds (endpoint must verify store belongs to active workspace via WorkspaceScope)
9. On complete: set status=completed, clear checkpoint, dispatch one `ComputeDailySnapshotJob` per imported date (all for this store)
10. On failure: status=failed, create alert, retry resumes from checkpoint (FX already cached)
11. **Billing gate:** If trial has expired (or `plan_grace_ends_at` has passed) when the job runs, set `historical_import_status = 'failed'` with error "Import paused — subscription required." Do not start new imports. Frontend shows this reason on the polling endpoint response.

**Time estimate:** Before dispatching the import job, make a lightweight count request: `GET /wp-json/wc/v3/orders?after={start_date_ISO}&per_page=1` and read the `X-WP-Total` header. This returns the total order count without fetching data. Store as `historical_import_total_orders` (or pass to frontend via the polling endpoint).
- If prior `sync_logs` rows exist for this workspace: `X-WP-Total × AVG(duration_seconds / records_processed)` → display as "~N minutes"
- If no prior logs exist: use a rough heuristic of ~1 minute per 1,000 orders → display as "~N minutes"
- During import: update progress based on `records_processed / X-WP-Total × 100`

---

## Webhook Deduplication
```php
$alreadyProcessed = WebhookLog::where('store_id', $store->id)
    ->where('event', $event)->where('payload->id', $externalOrderId)
    ->where('status', 'processed')->where('created_at', '>=', now()->subHours(24))
    ->exists();
if ($alreadyProcessed) return response()->json(['status' => 'duplicate'], 200);
```
Always return 200 for duplicates.

---

## AI Daily Summary
- Skip if no active store or owner `last_login_at` > 7 days ago
- Data: yesterday, day-before, same-weekday-last-week from `daily_snapshots` + `ad_insights WHERE level='campaign'`
- Omit `gsc` key entirely if no `search_console_properties` row exists
- API: `env('ANTHROPIC_MODEL')` (default: `claude-sonnet-4-6`), max_tokens: 600
- System prompt: `"You are a senior ecommerce analyst reviewing daily store performance. Be concise and direct. Highlight the single most important change, flag anomalies, give one actionable recommendation. 3–4 short paragraphs, plain business English, no bullet points, no generic filler."`
- Display: top of dashboard. No emails in MVP.

---

## Critical Alert Emails
Email the workspace **owner** when an alert with `severity = 'critical'` is created. One email per alert, no batching, no digest.

**Triggers:** sync failure at 3+ consecutive failures, OAuth token expired, any `critical` alert row insertion.

**Template:** Single Mailable (`CriticalAlertMail`). Subject: `"[Metrify] {alert.type} — {workspace.name}"`. Body: alert type, affected integration name, timestamp, link to `/insights`. Plain text + HTML.

**Guards:**
- Do not email if owner has no verified email
- Do not email the same alert type + workspace more than once per 24 hours (query `alerts` for recent duplicates before sending)
- Queue on `default` queue, not `critical`

---

## Background Jobs

### Queues
```
critical  — webhook processing
high      — OAuth token refresh
default   — regular sync jobs
low       — imports, snapshots, AI, FX rates, cleanup
```

### Horizon Config (config/horizon.php)
```php
'environments' => ['production' => [
    'balance' => 'simple',
    'supervisor-critical' => ['queue' => ['critical'], 'processes' => 3, 'tries' => 5, 'timeout' => 30],
    'supervisor-high'     => ['queue' => ['high'],     'processes' => 2, 'tries' => 3, 'timeout' => 60],
    'supervisor-default'  => ['queue' => ['default'],  'processes' => 4, 'tries' => 3, 'timeout' => 300],
    'supervisor-low'      => ['queue' => ['low'],      'processes' => 2, 'tries' => 3, 'timeout' => 7200],
]],
```
Reduce `processes` to 1-2 on single Plesk server.

### Schedule (routes/console.php — Laravel 12, no Kernel.php)
```php
use Illuminate\Support\Facades\Schedule;
// Every scheduled job MUST use withoutOverlapping(10):
Schedule::call(new DispatchDailySnapshots)->dailyAt('00:30')->withoutOverlapping(10);
// DispatchDailySnapshots iterates active stores, dispatches ComputeDailySnapshotJob($storeId, $yesterday) per store
// Repeat withoutOverlapping(10) for every scheduled job below.
```

| Job | Queue | When |
|---|---|---|
| `ProcessWebhookJob` | critical | On receipt |
| `RefreshOAuthTokenJob` | high | Daily 05:00 UTC |
| `SyncStoreOrdersJob` | default | Hourly |
| `SyncProductsJob` | default | Daily 02:00 UTC |
| `SyncAdInsightsJob` | default | Every 3 hours |
| `SyncSearchConsoleJob` | default | Every 6 hours |
| `DispatchDailySnapshots` (dispatcher) | low | 00:30 UTC daily — dispatches `ComputeDailySnapshotJob($storeId, $yesterday)` per active store |
| `DispatchHourlySnapshots` (dispatcher) | low | 00:45 UTC daily — dispatches `ComputeHourlySnapshotsJob($storeId, $yesterday)` per active store |
| `GenerateAiSummaryJob` | low | 01:00-02:00 UTC staggered (workspace_id % 60) |
| `CheckWorkspacePlanLimitsJob` | low | 01:30 UTC daily |
| `UpdateFxRatesJob` | low | 06:00 UTC daily |
| `RetryMissingConversionJob` | low | 07:00 UTC daily |
| `WooCommerceHistoricalImportJob` | low | On store connection |
| `RecomputeReportingCurrencyJob` | low | On reporting_currency change |
| `ReportMonthlyRevenueToStripeJob` | low | 1st of month 06:00 UTC |
| `CleanupOldSyncLogsJob` | low | Sunday 03:00 UTC |
| `CleanupOldWebhookLogsJob` | low | Sunday 03:15 UTC |
| `PurgeDeletedWorkspaceJob` | low | Sunday 05:00 UTC |

### Timeouts

| Job | `$timeout` | `$tries` | Backoff |
|---|---|---|---|
| `WooCommerceHistoricalImportJob` | 7200 | 3 | default |
| `RecomputeReportingCurrencyJob` | 7200 | 3 | default |
| `ComputeDailySnapshotJob` | 600 | 3 | default |
| `SyncAdInsightsJob` | 300 | 3 | default |
| `SyncSearchConsoleJob` | 300 | 3 | default |
| `SyncProductsJob` | 300 | 3 | default |
| `RetryMissingConversionJob` | 300 | 3 | default |
| `ComputeHourlySnapshotsJob` | 300 | 3 | default |
| `ReportMonthlyRevenueToStripeJob` | 300 | 5 | `[60,300,900,1800,3600]` |
| `SyncStoreOrdersJob` | 120 | 3 | default |
| `GenerateAiSummaryJob` | 120 | 2 | `[60,300]` |
| `CheckWorkspacePlanLimitsJob` | 120 | 3 | default |
| `CleanupOldSyncLogsJob` | 120 | 2 | `[60,300]` |
| `CleanupOldWebhookLogsJob` | 120 | 2 | `[60,300]` |
| `RefreshOAuthTokenJob` | 60 | 3 | `[30,120,300]` |
| `UpdateFxRatesJob` | 60 | 3 | `[30,120,300]` |
| `PurgeDeletedWorkspaceJob` | 300 | 3 | default |
| `ProcessWebhookJob` | 30 | 5 | `[5,15,30,60,120]` |

Default backoff: `[60, 300, 900]`

### Rate Limit Handling
```php
catch (RateLimitException $e) {
    $this->release($e->retryAfter ?? 60); // re-queue, attempt count unchanged
    return;
}
catch (TokenExpiredException $e) {
    $this->markIntegrationTokenExpired();
    $this->fail($e); // permanent failure
    return;
}
```

### Job Failure Handling
1. Write `sync_logs` `status='failed'`
2. Increment `consecutive_sync_failures` once per dispatch (after all retries exhausted, not per retry)
3. Create `alerts` row: `warning` at first failure, `critical` at 3+
4. `consecutive_sync_failures >= 3` → set `status = 'error'`
5. Success → reset `consecutive_sync_failures = 0`, set `status = 'active'` only if was `'error'` (never restore `'disconnected'`)

### Sync Deduplication
- All synced data: `upsert()` only — never `insert()`
- Sync jobs skip integrations where `status != 'active'`
- `SyncStoreOrdersJob` fallback: check `webhook_logs WHERE created_at >= now()-90min LIMIT 1`. If no rows → fetch orders modified in last 2 hours.
- `RecomputeReportingCurrencyJob`: use `chunk(1000)` on all queries to prevent OOM

---

## Onboarding Flow
1. Register → email verification
2. Connect first store (mandatory)
3. Import selection: 30 days / 90 days / 1 year / All history (always show time estimate — count orders via `X-WP-Total` before dispatching)
4. Import progress (poll every 5s)
5. Dashboard loads when ≥1 day data available — banner: "Connect ad accounts to see ROAS →"

Workspace auto-created at step 2. If user navigates to `/onboarding` after completing → redirect to `/dashboard`.

**Invitation:** Skip onboarding. Store token in session on register form submit. After email verify → validate token from session → create `workspace_users` → redirect `/dashboard`.

---

## Navigation
```
/health                          → 200 {"status":"ok"} — no auth, no rate limit
/                                → /dashboard or /login
/login, /register                → auth (AuthLayout)
/email/verify                    → email verification
/password/forgot, /password/reset/{token}
/onboarding                      → store connection wizard
/dashboard                       → workspace overview
/countries                       → workspace-level country breakdown
/stores                          → store list
/stores/{id}/overview
/stores/{id}/products
/stores/{id}/countries
/stores/{id}/seo
/advertising
/advertising/facebook
/advertising/google
/insights                        → AI summary + alert feed
/settings/profile
/settings/workspace
/settings/integrations
/settings/team
/settings/billing                → Owner only (BillingPolicy)
/admin/*                         → RequireSuperAdmin middleware
/workspaces/{id}/switch          → sets active_workspace_id, redirect /dashboard
/no-workspace
/oauth/facebook/callback
/oauth/google/callback           → routes by state JSON type field (gsc or google_ads)
/api/stores/{id}/import-status   → polling, session auth + EnforceBillingAccess + verify store belongs to active workspace
/api/webhooks/woocommerce/{id}   → no auth, HMAC validated
```

---

## Security Rules

1. **Never store secrets in plaintext.** `Crypt::encryptString()` / `Crypt::decryptString()`. All credential columns end in `_encrypted`.
2. **Never return decrypted credentials to frontend.** Return masked strings only (e.g. `"****4f2a"`).
3. **Never log raw tokens or customer emails.** Log IDs and status codes only.
4. **Verify every webhook signature** before processing. Invalid → 401 + log to `webhook_logs`.
5. **Verify workspace ownership** via Policies before every data operation.
6. **Customer emails:** SHA-256(lowercase(trim(email))) → `customer_email_hash`. Never store raw email. GDPR.
7. **Rate limits:** auth routes `throttle:10,1`; webhook routes `throttle:100,1` keyed by store_id.
8. **Parameterised queries only.** Never interpolate user input into SQL.
9. **CSRF** is automatic for Inertia forms — maintain it.
10. **Stripe webhooks:** `Cashier::routes()` in `routes/web.php`. Exclude `'stripe/*'` from `VerifyCsrfToken::$except`.
11. **Invitation roles:** Admins invite as Member or Admin only. Enforce in `WorkspaceInvitationPolicy`.
12. **Billing is Owner-only.** `/settings/billing` and all billing mutations → `BillingPolicy`. Admins/Members get 403.
13. **Update `users.last_login_at`** on every successful login.

---

## Code Conventions

### PHP
- `declare(strict_types=1)` in every file
- PHP 8.5: match, readonly, constructor promotion, enums, named args
- Controllers: validate → Action → `Inertia::render()` only
- Actions: single `handle()` method. Example:
  ```php
  class ConnectStoreAction {
      public function handle(Workspace $workspace, array $validated): Store {
          $store = Store::create([...$validated, 'workspace_id' => $workspace->id]);
          dispatch(new WooCommerceHistoricalImportJob($store->id));
          return $store;
      }
  }
  ```
- Models: relationships, scopes, accessors only
- `Http::fake()`, `Queue::fake()`, `Event::fake()` in tests — never hit real APIs

### TypeScript / React
- TypeScript everywhere, functional components + hooks only
- `React.memo` on MetricCard and all chart wrappers; `useMemo` for chart data
- Tailwind only, no inline styles
- Shared utils: `formatCurrency(amount, currency, compact?)`, `formatNumber(value, compact?)`, `formatPercent(value)`, `formatDate(date, granularity, timezone)`

### Database
- Every migration has `down()`
- Indexes in same migration as table
- `upsert()` for synced data — never plain `insert()`
- `DB::transaction()` for multi-table writes
- `select()` specific columns, never `SELECT *`
- Tests MUST use PostgreSQL (not SQLite — partial indexes, jsonb, bigserial are incompatible):
  ```xml
  <!-- phpunit.xml inside <php> -->
  <env name="DB_CONNECTION" value="pgsql"/>
  <env name="DB_DATABASE" value="metrify_test"/>
  ```
  Run `createdb metrify_test` before first test run.

---

## Error Handling
- Never swallow exceptions. Catch → log + rethrow, or surface to user.
- Never show zeros/blanks when real data fails — show error state.
- `total_in_reporting_currency = NULL` → exclude from totals + show warning. Never treat as 0.
- FX fallback to prior day → log it. Never pretend data is current.
- Every failure must leave a trace: `sync_logs`, `alerts`, Laravel logs.

---

## Data Retention
| Data | Retention |
|---|---|
| Orders, items, products, ad insights, daily/hourly snapshots, FX rates, GSC data | Forever |
| AI summaries | Forever |
| Alerts | Forever |
| Sync logs | 90 days |
| Webhook logs | 30 days (GDPR — payload contains customer PII) |

---

## Environment Variables
```env
APP_NAME=Metrify
APP_ENV=production
APP_DEBUG=false
APP_KEY=                          # php artisan key:generate
APP_URL=https://app.metrify.io
APP_TIMEZONE=UTC
APP_LOCALE=en
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=en_US

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=metrify
DB_USERNAME=metrify
DB_PASSWORD=

REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
SESSION_LIFETIME=480              # 8 hours
CACHE_STORE=redis

MAIL_MAILER=smtp
MAIL_HOST=
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=hello@metrify.io
MAIL_FROM_NAME=Metrify

STRIPE_KEY=
STRIPE_SECRET=
STRIPE_WEBHOOK_SECRET=
CASHIER_CURRENCY=EUR
STRIPE_PRICE_STARTER_M=
STRIPE_PRICE_STARTER_A=
STRIPE_PRICE_GROWTH_M=
STRIPE_PRICE_GROWTH_A=
STRIPE_PRICE_SCALE_M=
STRIPE_PRICE_SCALE_A=
STRIPE_PRICE_PERCENTAGE=

FACEBOOK_APP_ID=
FACEBOOK_APP_SECRET=
FACEBOOK_REDIRECT_URI=

GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI=              # handles both Google Ads and GSC callbacks
GOOGLE_ADS_DEVELOPER_TOKEN=       # Standard Access required for production (apply early)

ANTHROPIC_API_KEY=                # separate from Claude Code subscription key
ANTHROPIC_MODEL=claude-sonnet-4-6

FX_BASE_CURRENCY=EUR
FRANKFURTER_API_URL=https://api.frankfurter.dev/v2  # no key required, no quotas
```

---

## Common Commands
```bash
php artisan test
php artisan test --filter=Roas
php artisan migrate
php artisan migrate:fresh --seed          # dev only
php artisan horizon
php artisan horizon:status
php artisan horizon:terminate
php artisan schedule:work
php artisan tinker
php artisan make:job SomeName
php artisan make:policy SomeName
php artisan make:request SomeName
npm run dev
npm run build
createdb metrify_test                      # one-time PostgreSQL test database
stripe listen --forward-to localhost/stripe/webhook
```

---

## MUST Always
- Apply `WorkspaceScope` to every workspace-scoped model
- In every queue job: call `app(WorkspaceContext::class)->set($this->workspaceId)` at start of `handle()`
- Set `trial_ends_at = now() + 14 days` on every new `workspaces` row
- Encrypt credentials before storing; decrypt only at point of use
- Verify webhook signatures before processing
- `upsert()` for all synced external data
- `withoutOverlapping(10)` on all scheduled tasks (explicit TTL)
- `down()` for every migration
- Business logic in Action classes only
- Queue jobs for all external HTTP calls
- Tests for all calculation logic, permission boundaries, and queue jobs
- `declare(strict_types=1)` in every PHP file
- Reuse shared components — never duplicate MetricCard, DateRangePicker, chart wrappers
- Update `users.last_login_at` on every successful login
- Filter `ad_insights` queries by a single `level` value
- Reset `consecutive_sync_failures = 0` on success; set `status = 'active'` only if was `'error'`

## MUST Never
- Store secrets in plaintext
- Return decrypted credentials in Inertia props or API responses
- Make synchronous HTTP requests in the request cycle
- Query workspace data without workspace scope active
- Put business logic in controllers or models
- Commit `dd()`, `dump()`, `var_dump()`, `console.log()`
- Use `SELECT *` in performance-sensitive queries
- Build Phase 2 or Phase 3 features without explicit instruction
- Add Redux, Zustand, or global state management
- Duplicate chart or metric card code
- SUM `ad_insights.spend_in_reporting_currency` across multiple `level` values
- Call Frankfurter API from `FxRateService` — it reads `fx_rates` DB only
- Hardcode the Frankfurter API URL — always use `env('FRANKFURTER_API_URL')`
- Store raw customer email addresses — SHA-256 hash after normalizing
- Swallow exceptions silently
- Return hardcoded/placeholder/fake data — show error state
- Treat NULL `total_in_reporting_currency` as 0
- Produce code that hides a silent failure

---

## Working With Claude Code

### Task size
One bounded feature per session. Good: *"Implement `workspaces` migration and `SetActiveWorkspace` middleware per CLAUDE.md §Multi-Tenancy."* Bad: *"Build auth."* Fresh conversation per feature.

### Subagent definitions
Create in `.claude/agents/`:

**security-reviewer.md:**
```markdown
---
name: security-reviewer
description: Reviews code for security vulnerabilities. Invoke after any auth, webhook, credential, or permission feature.
model: claude-opus-4-6
tools: Read, Grep, Glob, Bash
---
Senior security engineer reviewing Laravel PHP.
Check: missing WorkspaceScope, WorkspaceScope not throwing when context null, missing Policy checks, plaintext credentials, missing webhook signature verification, SQL injection in raw queries, missing rate limits, CSRF gaps, decrypted credentials leaking to frontend.
Reference CLAUDE.md §Security Rules.
```

**billing-reviewer.md:**
```markdown
---
name: billing-reviewer
description: Reviews billing and FX logic. Invoke after any billing, FX conversion, ROAS, or Stripe change.
model: claude-opus-4-6
tools: Read, Grep, Glob, Bash
---
Senior engineer specialising in financial correctness.
Check: division by zero in ROAS/AOV/FX, wrong ad_insights level filter, FxRateService calling Frankfurter API directly, Frankfurter URL hardcoded, FX fallbacks masking NULL as 0, billing_plan transitions wrong, grace period reset on every nightly run, €149 floor not applied, revenue in wrong currency to Stripe, updateStripeCustomer not called when billing details change.
Reference CLAUDE.md §Billing and §Key Business Logic.
```

### When Claude Code goes off-spec
Signs: new files not in spec, dependencies not in tech stack, schema column name deviations. Stop immediately. New session. Bounded task with CLAUDE.md section reference.
