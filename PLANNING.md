# Metrify — Full Planning Reference

**Purpose:** This is the comprehensive planning document. It contains every business decision, technical rationale, competitor research, infrastructure philosophy, and full phase 2 specifications. When you need to understand *why* a decision was made, this is the source of truth.

**For Claude Code:** Read `CLAUDE.md` to build. Come here when you need deeper context on a decision or want to understand the business model.

Working name: **Metrify**. Find-and-replace when a final name is chosen.

---

## What Metrify Is

Multi-tenant SaaS analytics platform for ecommerce store owners. Connects stores (WooCommerce MVP, Shopify Phase 2) and ad platforms (Facebook Ads, Google Ads, Google Search Console) to provide a unified view of **sales analytics, advertising ROI, and organic search visibility** — all in one dashboard.

**Target user:** EU-based ecommerce store owners and marketing managers running DTC (direct-to-consumer) brands. From growing SMBs doing €2k/month to established brands doing €200k+/month. Common profile: selling on WooCommerce or Shopify, running Facebook and Google ads, investing in SEO. Currently juggling 3-5 separate tools with no unified view.

**The core problem:** Store owners use GA4 for traffic, Facebook Ads Manager for ads, WooCommerce admin for orders, and GSC for SEO — and none of these talk to each other. They can't answer "what was my ROAS yesterday?" without opening four tabs and doing math in a spreadsheet.

**Our answer:** Pull all this data into one place. One dashboard, one currency, one date range, one "how did yesterday go?" view.

---

## Business Model

**Hybrid revenue-based pricing.** Two distinct tiers based on store GMV:

### Flat Tiers (< €10k/month GMV)
For stores where predictable pricing matters. They're scrappy, budget-conscious.
| Tier | Monthly GMV | Monthly Price |
|---|---|---|
| Starter | ≤ €2,000 | €29 |
| Growth | ≤ €5,000 | €59 |
| Scale | ≤ €10,000 | €119 |

### Percentage Model (≥ €10k/month GMV)
For serious stores. Pricing grows with them — we succeed when they succeed.
- **1% of previous month's gross revenue**
- Minimum €149/mo (floor protects us if a big store has a slow month)
- Custom/Enterprise above €250k/month GMV

**Why this model?**
- Flat SaaS pricing ($99/mo for everyone) leaves huge money on the table for large stores. A store doing €100k/month pays the same as one doing €5k.
- Pure percentage is unpredictable for small stores and creates friction.
- The hybrid captures both markets: predictability for smalls, aligned incentives for bigs.
- Similar to Klaviyo's contact-count scaling, but revenue-based which is more natural for ecommerce.

**Annual discount:** 2 months free (~17% discount). Applies only to flat tiers. The % model is already monthly by nature.

**Trial:** 14 days, all features, no credit card. After trial: syncs pause, data retained, redirect to billing. 14 days is enough to see value if they have 30+ days of historical orders imported.

---

## Technology Decisions

### Why PostgreSQL (not MySQL)
- **JSONB columns** for `revenue_by_country`, `top_products`, `billing_address`, `platform_webhook_ids` — MySQL's JSON support is weaker
- **Partial indexes** on `ad_insights` — critical for the partial unique index pattern by level and hour. MySQL does not support partial indexes.
- **`bigserial`** auto-increment type — cleaner than MySQL's `AUTO_INCREMENT`
- **Expression indexes** — `COALESCE(variant_name, '')` index on `order_items` for upsert deduplication
- Version 18.x is current stable with performance improvements over 16.x.

### Why Redis (not database queue)
Laravel's database queue driver creates contention. Redis is purpose-built for this. AOF persistence means no jobs lost on reboot.

### Why Inertia.js (not API + SPA)
We don't need a public API. We don't need separate frontend deployment. Inertia gives us: server-side routing, Laravel's auth/session/middleware, and React for the UI — without the complexity of a decoupled SPA. The tradeoff is that we can't easily offer a mobile app later (Phase 3), but that's acceptable.

### Why Recharts (not Chart.js, D3, Highcharts)
- Recharts is React-native (composable React components). Chart.js/Highcharts are imperative libraries wrapped in React.
- Our heaviest chart is ~2,160 points (hourly, 90 days). Recharts handles up to ~10,000 points with memoisation. No performance issue.
- Recharts has a permissive license (MIT). Highcharts requires commercial license.

### Why Horizon (not just Redis queues)
Horizon provides: real-time dashboard to see queued/processed/failed jobs, automatic balancing across queues, supervisord-style process management, retry management. Essential for debugging sync failures in production.

### Why shadcn/ui
Not a component library in the traditional sense — it's a collection of copy-paste components built on Radix UI primitives. We own the code, can customise freely, and the components are accessible and production-quality. Tailwind-native.

### Why the WorkspaceContext singleton (not session in scopes)
Global scopes (`WorkspaceScope`) run in queue jobs and CLI commands where there is no session. If we stored the workspace ID in `session()` and read it in the scope, jobs would fail silently with no workspace context. The singleton in the service container is set explicitly at the start of each job `handle()` call — it's explicit, auditable, and works everywhere.

### Why throw in WorkspaceScope when context is null
Silent fallback (`if ($id) { query }`) would return ALL data across all workspaces if a job forgot to call `set()`. This is a critical cross-tenant data leak. Throwing forces the developer to explicitly set context — the error is loud and caught immediately in tests, not silently in production.

---

## FX Rates

### Why Frankfurter (not Open Exchange Rates, not ECB directly)
- **Free with no API key.** No monthly/daily quotas. Rate-limited only to prevent abuse.
- **ECB-sourced rates.** The European Central Bank publishes official reference rates — appropriate for EU-focused ecommerce analytics.
- **Date range queries.** One API call returns all dates in a range: `GET /v2/rates?from={start}&to={end}&base=EUR`. Critical for historical import pre-population.
- **Self-hostable.** Can run the Docker image if API becomes unreliable or if volume becomes an issue.
- Alternative: Open Exchange Rates has a 1,000 request/month limit on free tier — useless for a multi-workspace SaaS.

### Why DB-first caching
The Frankfurter API is reliable but external. Dashboard queries should never depend on an external API call. We cache all rates in `fx_rates` table. FxRateService reads from DB only. Two flows populate the DB:
1. `UpdateFxRatesJob` runs daily at 06:00 UTC — fetches today's rates
2. Historical import pre-populates missing dates before processing orders

### Why EUR as base currency
EUR is the most natural base for an EU-focused product. Most WooCommerce stores in our target market use EUR. The ECB publishes EUR-based rates. Storing EUR→X means: `EUR store + GBP reporting` = one direct lookup. `GBP store + EUR reporting` = one lookup + invert. `GBP store + USD reporting` = two lookups + divide. All cases handled by the four-case FxRateService logic.

### Why per-transaction-date rates (not single rate for period)
Industry standard confirmed by research into how Klaviyo handles multi-currency: "For each individual Placed Order event, Klaviyo converts revenue into USD using the up-to-date exchange rate for the day of the transaction." This is correct — a €100 order on Jan 1st and a €100 order on Dec 31st have different GBP values because exchange rates move.

---

## Infrastructure Philosophy

### Phase 1: Plesk
Start cheap and simple. A single Plesk server is adequate for hundreds of workspaces with daily sync jobs. Plesk handles SSL, nginx config, and domain management. Git-based deployment via Plesk's native panel keeps things simple.

**When to graduate to Phase 2:** When you hit ~500+ active workspaces, or when sync jobs regularly queue-overflow, or when you need guaranteed uptime during deployments.

### Phase 2: Three Servers
```
Hetzner LB (€6/mo)
├── App Server 1 — Laravel + Nginx + PHP-FPM + Horizon
└── App Server 2 — Laravel + Nginx + PHP-FPM + Horizon
         ↓
    DB Server — PostgreSQL 18 + Redis 8 (8-16GB VPS, ~€35-50/mo)
```
**Total: ~€80-100/mo** including load balancer.

PostgreSQL and Redis on the same server is fine at this scale. Redis is in-memory (~1-4GB), PostgreSQL uses disk + memory cache (~1-4GB). An 8-16GB VPS handles both comfortably.

**PostgreSQL HA options:**
- **Recommended: Hetzner Managed Databases (~€35-50/mo).** They run PG for you — HA, automated backups, zero-downtime maintenance. Worth it to remove all DB ops burden.
- **DIY: PG streaming replication + hot standby.** Only pursue if you have PostgreSQL ops experience.

**Deployer:** Zero-downtime deploys. Deploys to one server at a time, atomic symlink-based releases, health checks before switching traffic.

**Ansible:** Idempotent server provisioning. Write once, run on any fresh VPS. Living infrastructure documentation.

### Queue Failover
1. Job stored in Redis (persisted to disk via AOF)
2. Horizon picks up job, "reserves" it with visibility timeout
3. If server dies, Horizon on surviving server sees reserved job as abandoned after timeout → re-queues
4. Job runs again from scratch. Safe because all writes use `upsert()`.

### Why sessions in Redis (not files)
With two app servers, file sessions would be per-server. Server 1 creates a session, next request hits Server 2 — session not found. Redis is shared across servers. No sticky sessions needed.

---

## Multi-Tenancy Design

### Why workspace-level (not user-level)
Store owners typically have one workspace but may want to share access with team members (a marketing manager, a VA). Workspace-level tenancy lets multiple users access the same data with role-based permissions. User-level tenancy would require complex sharing.

### Why WorkspaceScope (not manual WHERE clauses)
Every developer who forgets a `WHERE workspace_id = ?` creates a cross-tenant data leak. A global scope enforced at the Eloquent model level makes it structurally impossible to forget. The only escape hatch is explicit `withoutGlobalScope()` which is immediately visible in code review.

### Why throw instead of silent no-op when context not set
See FX rates section above — same principle. A silent `if ($id) { query }` would return ALL data when context isn't set. A crash is better than a data leak.

### Why SoftDeletes trait on Workspace
The `deleted_at` column supports 30-day recovery. Using Laravel's `SoftDeletes` trait ensures all Eloquent queries automatically exclude soft-deleted workspaces — no risk of forgetting a manual `whereNull('deleted_at')` check. `PurgeDeletedWorkspaceJob` uses `onlyTrashed()` to find candidates for hard-delete.

---

## Billing Design Decisions

### Why billing_plan on workspaces (not via Cashier's stripe_status)
Cashier tracks subscription state in `subscriptions.stripe_status` (active, trialing, past_due, etc.). But we need our own `billing_plan` column for:
1. Distinguishing between starter/growth/scale/percentage/enterprise (Stripe doesn't know these labels)
2. Fast queries without joining subscriptions table
3. Enterprise plan management (custom contracts set manually, not via Stripe)

### Why grace period instead of immediate lock
Immediate lock on plan limit exceedance would:
- Lock a Black Friday spike that resolves next month (customer churns)
- Create anxiety for customers near the limit

7-day grace period gives customers time to upgrade without panic. The grace period is only set if null (prevents nightly job from infinitely extending it). If revenue drops back below limit, grace period is cleared automatically.

### Why report revenue to Stripe in EUR always
Stripe's metered billing can only have one currency per price. We chose EUR as it's the most natural for EU-focused SaaS. If a workspace uses GBP reporting currency, we convert their GBP-denominated revenue to EUR using the last-day-of-previous-month FX rate before reporting to Stripe. This ensures all workspaces are billed consistently regardless of their reporting currency.

### Why the €149 floor on percentage tier
Metered billing doesn't support native minimums. The floor protects against a workspace with high store revenue but a bad month (say, returns spike) paying €50 while consuming significant sync resources.

### Why enterprise threshold is sales-driven (no automation)
The €250k/mo threshold is a trigger for a sales conversation, not an automated plan change. Enterprise contracts involve custom terms, SLAs, and pricing — automating this would create bad experiences (e.g., locking a workspace mid-month because revenue crossed a line). `CheckWorkspacePlanLimitsJob` only processes flat tiers.

### Why no custom handling for past_due subscriptions
Stripe handles payment retries automatically with configurable retry schedules. Cashier updates `stripe_status` on the subscription. The subscription remains technically active during retries — the user keeps access. If Stripe exhausts retries, it fires `customer.subscription.deleted`, which nulls `billing_plan` and triggers the standard billing redirect. Adding custom `past_due` logic would duplicate Stripe's built-in dunning.

### Why sync billing_plan via Cashier WebhookReceived events
Cashier handles the raw Stripe webhook verification and subscription model updates. We listen to Cashier's `WebhookReceived` event (not raw Stripe webhooks) to sync our custom `billing_plan` column: `customer.subscription.created` → set plan from price ID, `customer.subscription.updated` → update plan, `customer.subscription.deleted` → null plan. This avoids reimplementing webhook verification and keeps billing_plan in sync with Stripe state.

---

## Integration Decisions

### WooCommerce: Why API key auth (not OAuth)
WooCommerce's OAuth implementation is non-standard and poorly supported in older versions. API keys (consumer key + secret) are universally supported, simpler for users to create, and don't expire. Since our target users are non-technical store owners, simplicity matters.

### Facebook: Why long-lived token immediately (not storing short-lived)
Short-lived tokens (~2 hours) are essentially useless for background sync jobs. The long-lived token (~60 days) needs to be obtained immediately after OAuth because the short-lived token is only valid for the exchange window. If we stored the short-lived token and tried to exchange it later, it would have expired.

### Facebook: Why no silent refresh (unlike Google)
Facebook's long-lived tokens don't have refresh tokens. When they expire (~60 days), the user must re-authenticate. This is a Facebook platform limitation. We alert owners 7 days before expiry to give them time to reconnect.

### Google Ads: Why apply for Standard Access early
Basic Access only allows querying test accounts. Without Standard Access (requires live app review), you cannot query real customer data. Review takes 2-3 business days. Build with a test account, but submit the review application from day one of development.

### Facebook: Why Advanced Access early
Facebook's Advanced Access (required for `ads_read` on non-test accounts) takes 2-6 weeks and requires a live demo URL, screencast, privacy policy, and business verification. This is the longest lead-time dependency in the entire project. Submit as soon as you have a working demo.

### Google Ads: Why GAQL (not REST)
Google's legacy AdWords API (SOAP-based) is deprecated. The Google Ads API uses GAQL (Google Ads Query Language), a SQL-like syntax. It's the current standard and the only supported API for new integrations.

### GSC: Why store tokens in session during connection
The OAuth flow returns tokens before the user has selected which property to connect. We can't create the `search_console_properties` row yet (don't know which `siteUrl` to save). Temporary session storage bridges the gap. If session expires between step 4 and step 7, user just reconnects — no harm done.

### Why no ad account–store FK link
Matching ad accounts to stores is a fundamentally difficult problem. A Facebook ad account might run ads for multiple stores. A store might have multiple ad accounts. The safe default: ROAS is blended at workspace level. Users who need per-store ROAS can filter by looking at ad account spend separately and comparing to store revenue. This is a documented limitation, not a bug.

---

## Data Architecture Decisions

### Why pre-aggregated snapshots (not query-time aggregation)
A workspace with 3 years of order history might have 100,000+ orders. Summing them on every dashboard request would be slow (500ms+) and increase DB load linearly with workspace size. By computing `daily_snapshots` nightly, dashboard queries are simple `WHERE date BETWEEN ? AND ?` aggregations on a small, well-indexed table.

### Why not store account-level or adset-level ad insights
We only show campaign-level and ad-level breakdowns in the UI. Storing account and adset rows would double the `ad_insights` table size with data we never query. The level column + partial unique indexes enforce this at the DB level.

### Why partial indexes on ad_insights (not composite UNIQUE)
`campaign_id` is nullable (for ad-level rows it's meaningless), and `ad_id` is nullable (for campaign-level rows). PostgreSQL's `UNIQUE` constraint treats NULLs as distinct, so `UNIQUE(campaign_id, date)` would allow duplicate rows where campaign_id is NULL. Partial indexes filter to the specific level, solving this cleanly.

### Why JSONB for top_products and revenue_by_country
These are pre-computed aggregates that are read as a unit (display top 10 products) or iterated (render country map). Storing them in JSONB is simpler and faster than a separate `daily_snapshot_products` table with a FK join. The data doesn't need to be queryable at the element level.

### Why track new_customers/returning_customers at snapshot time
Computing new vs returning at query time would require: for each order in the date range, check if that `customer_email_hash` has appeared before. That's an expensive correlated subquery across potentially thousands of orders. Computing it nightly in `ComputeDailySnapshotJob` means the answer is always a precomputed integer.


---

## Error Handling Philosophy

### Fail Loud, Never Fake

This codebase handles real money and real store data. A bug that silently produces wrong numbers is worse than one that crashes visibly.

**Priority order for every operation:**
1. Works correctly with real data ✓
2. Falls back visibly — signals degraded mode with a banner or warning ✓
3. Fails with a clear exception ✓
4. Silently degrades to look "fine" with wrong/missing/stale data — **never** ✗

**Practical examples:**

```php
// ❌ Wrong — corrupts financial data silently
try {
    $rate = FxRateService::getRate($currency, $reportingCurrency, $date);
} catch (\Exception $e) {
    $rate = 1.0; // "works" but gives completely wrong numbers
}

// ✅ Right — fails loudly, caller handles NULL explicitly
try {
    $rate = FxRateService::getRate($currency, $reportingCurrency, $date);
} catch (FxRateNotFoundException $e) {
    Log::warning('FX rate unavailable', ['currency' => $currency, 'date' => $date]);
    return null; // total_in_reporting_currency stays NULL, excluded from totals
}
```

```php
// ❌ Wrong — WorkspaceScope silently returns all data
public function apply(Builder $builder, Model $model): void {
    $id = app(WorkspaceContext::class)->id();
    if ($id) { $builder->where('workspace_id', $id); }
    // If $id is null (forgot to set in job), returns ALL workspace data — cross-tenant leak
}

// ✅ Right — throws immediately, caught in testing
public function apply(Builder $builder, Model $model): void {
    $id = app(WorkspaceContext::class)->id();
    if (!$id) { throw new \RuntimeException('WorkspaceContext not set'); }
    $builder->where('workspace_id', $id);
}
```

**Why "show an error state" not "show blank":**
A blank card could mean "no data" or "something broke." They look the same to the user. An error state with a retry button communicates what happened and gives the user agency.

---

## Security Model

### Why SHA-256 hashing for customer emails
GDPR makes us a data processor for customer PII. We don't need raw customer emails to answer "has this customer ordered before?" — we only need to compare. SHA-256 of the normalized email serves this purpose without storing the email itself. The hash is deterministic: `user@example.com` always produces the same hash. The normalization step (`lowercase`, `trim`) ensures `USER@EXAMPLE.COM` and `user@example.com` produce the same hash.

### Why payload jsonb in webhook_logs
We store the raw webhook payload for debugging: if an order has wrong data, we can replay the webhook. This may contain customer PII (billing name, email, address in the WooCommerce payload). Justified under GDPR's legitimate interest (debugging) with a 30-day purge. The data is never exposed in the UI.

### Why token-based webhook verification (not IP allowlisting)
WooCommerce doesn't have static IP ranges. HMAC-SHA256 signature verification is more secure than IP allowlisting and works regardless of where the WooCommerce site is hosted.

---

## Competitor Research

### Klaviyo
- Currency: converts each order at the day-of-transaction exchange rate (same approach as us)
- Does NOT convert currencies for regular analytics — only in Benchmarks tab
- No unified ad spend view — pure email/SMS marketing tool

### Metorik
- WooCommerce analytics specialist. Uses Open Exchange Rates for multi-currency (we use Frankfurter which is free and ECB-sourced)
- No ad integration — pure store analytics
- ~€50-150/mo flat pricing (no revenue-based model)

### Triple Whale
- Shopify-focused, limited WooCommerce support
- Strong first-party attribution (pixel), no GSC
- ~$300-500/mo for meaningful features — expensive for EU SMBs

### AdScale / DataFeedWatch
- Ad feed management + analytics. No store revenue view.
- Different use case — feed optimization, not holistic analytics

**Our differentiation:** Combined store revenue + ad spend + GSC in one dashboard, EUR-native, revenue-based pricing that scales with the customer.

---

## Full Implementation Reference

*All of the following mirrors CLAUDE.md but with full explanatory context included.*

### Setup Steps
See CLAUDE.md §Setup for the exact commands. Key rationale:
- **Breeze over Jetstream:** Jetstream is heavier. Breeze gives us just the auth views and leaves us free to build our own UI. We're not using Livewire (Jetstream's default).
- **Cashier migrations manually:** Cashier's bundled migration assumes you bill `users`. We bill `workspaces`. Running Cashier's migration creates the wrong FK structure.
- **shadcn components path:** Breeze creates `resources/js/`, not `src/`. shadcn defaults to `src/` which doesn't exist. The components path must be specified explicitly during init.
- **PostgreSQL for tests:** Our schema uses PG-specific features (partial indexes, jsonb, bigserial, COALESCE in expression indexes) that are silently incompatible with SQLite. Never use SQLite in tests.

---

## Database Schema

*Identical to CLAUDE.md §Database Schema. Reproduced here so PLANNING.md is complete.*

### Core Tenant

```sql
users
  id bigserial PK, name varchar(255), email varchar(255) UNIQUE
  email_verified_at timestamp (nullable), password varchar(255)
  is_super_admin boolean DEFAULT false, remember_token varchar(100) (nullable)
  last_login_at timestamp (nullable)    -- GenerateAiSummaryJob skips workspaces where owner.last_login_at > 7 days
  created_at, updated_at

workspaces
  id bigserial PK, name varchar(255), slug varchar(255) UNIQUE
  -- slug: slugified name, Str::random(4) on collision, immutable after creation
  owner_id bigint → users SET NULL    -- SET NULL is safety net; always assign new owner first
  reporting_currency char(3) DEFAULT 'EUR'
  reporting_timezone varchar(100) DEFAULT 'Europe/Berlin'
  trial_ends_at timestamp (nullable)
  billing_plan varchar(50) CHECK (billing_plan IN ('starter','growth','scale','percentage','enterprise')) (nullable)
  plan_grace_ends_at timestamp (nullable)    -- 7-day grace on flat tier limit exceedance
  stripe_id varchar(255) (nullable), pm_type varchar(255) (nullable), pm_last_four varchar(4) (nullable)
  billing_name varchar(255) (nullable), billing_email varchar(255) (nullable)
  billing_address jsonb (nullable)           -- {line1, line2, city, postal_code, country}
  vat_number varchar(50) (nullable)          -- EU VAT for reverse charge
  is_orphaned boolean DEFAULT false          -- true when owner deleted with no admins
  deleted_at timestamp (nullable)            -- soft-delete; PurgeDeletedWorkspaceJob hard-deletes after 30 days
  created_at, updated_at

workspace_users  -- pivot
  id bigserial PK
  workspace_id → workspaces CASCADE, user_id → users CASCADE
  role varchar(50) CHECK (role IN ('owner','admin','member'))
  created_at, updated_at
  UNIQUE(workspace_id, user_id); INDEX(user_id)

workspace_invitations
  id bigserial PK, workspace_id → workspaces CASCADE
  email varchar(255), role varchar(50) CHECK (role IN ('admin','member'))
  token varchar(255) UNIQUE       -- Str::random(64), stored as-is (not hashed)
  expires_at timestamp, accepted_at timestamp (nullable)
  created_at, updated_at
  INDEX(workspace_id)
```

### Store Tables

```sql
stores
  id bigserial PK, workspace_id → workspaces CASCADE
  name varchar(255)
  type varchar(50) CHECK (type IN ('woocommerce','shopify','magento','bigcommerce','prestashop','opencart'))
  -- Add new platforms to CHECK when implementing. WooCommerce is only MVP platform.
  domain varchar(255), currency char(3), timezone varchar(100) DEFAULT 'Europe/Berlin'
  platform_store_id varchar(255) (nullable)
  status varchar(50) DEFAULT 'connecting' CHECK (status IN ('connecting','active','error','disconnected'))
  consecutive_sync_failures smallint DEFAULT 0    -- status→'error' at 3
  -- Generic credential columns (map to each platform's auth model):
  -- WooCommerce: auth_key=consumer_key, auth_secret=consumer_secret
  -- Shopify: access_token=OAuth token
  -- Magento 2: auth_key=consumer_key, auth_secret=consumer_secret, access_token=access_token
  -- BigCommerce: auth_key=client_id, auth_secret=client_secret, access_token=access_token
  -- PrestaShop/OpenCart: auth_key=api_key
  auth_key_encrypted text (nullable)
  auth_secret_encrypted text (nullable)
  access_token_encrypted text (nullable)
  refresh_token_encrypted text (nullable)
  token_expires_at timestamp (nullable)
  webhook_secret_encrypted text (nullable)
  platform_webhook_ids jsonb (nullable)          -- {"order.created": 42, "order.updated": 43}
  historical_import_status varchar(50) CHECK (historical_import_status IN ('pending','running','completed','failed')) (nullable)
  historical_import_from date (nullable)
  historical_import_checkpoint jsonb (nullable)  -- WooCommerce: {date_cursor: "YYYY-MM-DD"}; Shopify: {cursor: "..."}
  historical_import_progress smallint (nullable) -- 0-100
  historical_import_total_orders integer (nullable) -- X-WP-Total from count request; used for time estimate + progress calc
  historical_import_started_at timestamp (nullable)
  historical_import_completed_at timestamp (nullable)
  historical_import_duration_seconds integer (nullable)
  last_synced_at timestamp (nullable)
  created_at, updated_at
  UNIQUE(workspace_id, domain); INDEX(workspace_id)

orders
  id bigserial PK
  workspace_id → workspaces CASCADE, store_id → stores CASCADE
  external_id varchar(255), external_number varchar(255) (nullable)
  status varchar(100) CHECK (status IN ('completed','processing','refunded','cancelled','other'))
  currency char(3)
  total numeric(12,4), subtotal numeric(12,4)
  tax numeric(12,4) DEFAULT 0, shipping numeric(12,4) DEFAULT 0, discount numeric(12,4) DEFAULT 0
  total_in_reporting_currency numeric(12,4) (nullable)
  -- NULL if FX rate unavailable within 3 days. RetryMissingConversionJob handles nightly.
  -- Dashboard excludes NULL values from totals and shows a warning.
  -- NEVER treat NULL as 0.
  customer_email_hash char(64) (nullable)    -- SHA-256(lowercase(trim(email))). GDPR: never store raw email.
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
  -- Expression index for upsert (nullable variant_name breaks standard UNIQUE):
  -- CREATE UNIQUE INDEX order_items_upsert_key ON order_items (order_id, product_external_id, COALESCE(variant_name, ''));
  INDEX(workspace_id, product_external_id); INDEX(order_id)

products
  id bigserial PK, workspace_id → workspaces CASCADE, store_id → stores CASCADE
  external_id varchar(255), name varchar(500)
  sku varchar(255) (nullable), price numeric(12,4) (nullable), status varchar(100) (nullable)
  image_url text (nullable), product_url text (nullable)
  platform_updated_at timestamp (nullable)    -- used for incremental sync filter
  created_at, updated_at
  UNIQUE(store_id, external_id); INDEX(workspace_id, store_id)
```

### Ad Tables

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
  destination_url text (nullable)    -- Phase 2 ad↔page correlation
  created_at, updated_at
  UNIQUE(adset_id, external_id); INDEX(workspace_id)

-- level IN ('campaign','ad') only. 'account' and 'adset' levels never inserted.
-- campaign_id/adset_id/ad_id are SET NULL (not CASCADE) so historical insight rows
-- survive when an ad account is disconnected and its campaigns are deleted.
ad_insights
  id bigserial PK
  workspace_id → workspaces CASCADE, ad_account_id → ad_accounts SET NULL (nullable)  -- SET NULL: insight rows survive ad account disconnection
  level varchar(20) NOT NULL CHECK (level IN ('campaign','ad'))
  campaign_id → campaigns SET NULL (nullable)
  adset_id → adsets SET NULL (nullable)
  ad_id → ads SET NULL (nullable)
  date date, hour smallint (nullable)    -- NULL=daily, 0-23=hourly
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
  -- Partial unique indexes (PostgreSQL partial index syntax: ON table (cols) WHERE condition)
  -- CREATE UNIQUE INDEX ai_campaign_daily_unique  ON ad_insights (campaign_id, date)       WHERE level='campaign' AND hour IS NULL;
  -- CREATE UNIQUE INDEX ai_campaign_hourly_unique ON ad_insights (campaign_id, date, hour) WHERE level='campaign' AND hour IS NOT NULL;
  -- CREATE UNIQUE INDEX ai_ad_daily_unique        ON ad_insights (ad_id, date)             WHERE level='ad' AND hour IS NULL;
  -- CREATE UNIQUE INDEX ai_ad_hourly_unique       ON ad_insights (ad_id, date, hour)       WHERE level='ad' AND hour IS NOT NULL;
```

### Aggregation Tables

```sql
-- NEVER query raw orders for dashboard page requests.
-- ComputeDailySnapshotJob accepts constructor params: (int $storeId, Carbon $date)
--   The nightly scheduled entry is a dispatcher (DispatchDailySnapshots): iterates all active
--   stores, dispatches one child job per store for yesterday's date. After historical import:
--   one child job per imported date for that store. Fully idempotent (ON CONFLICT DO UPDATE).
-- Revenue source: SUM(total_in_reporting_currency) for status IN ('completed','processing')
-- top_products computed from orders JOIN order_items, line revenue = line_total × (total_in_reporting_currency / NULLIF(total, 0))
daily_snapshots
  id bigserial PK, workspace_id → workspaces CASCADE, store_id → stores CASCADE
  date date
  orders_count integer DEFAULT 0
  revenue numeric(14,4) DEFAULT 0          -- in reporting_currency
  revenue_native numeric(14,4) DEFAULT 0   -- in store's native currency (no FX)
  aov numeric(10,4) (nullable)             -- revenue/orders_count; NULL if 0 orders
  items_sold integer DEFAULT 0
  items_per_order numeric(6,2) (nullable)
  new_customers integer DEFAULT 0          -- first-time customer_email_hash (non-NULL only)
  returning_customers integer DEFAULT 0
  revenue_by_country jsonb (nullable)      -- {"DE": 3200.00, "AT": 1800.00}
  top_products jsonb (nullable)            -- [{external_id, name, units, revenue}] top 10
  created_at, updated_at
  UNIQUE(store_id, date)
  INDEX(workspace_id, date); INDEX(workspace_id, store_id, date)

-- Retained forever.
-- Stored UTC; convert to store timezone on display.
-- Computed nightly at 00:45 UTC for previous day's hours. "Today" hourly data is not
-- available until the next nightly run — UI defaults today's view to daily granularity.
hourly_snapshots
  id bigserial PK, workspace_id → workspaces CASCADE, store_id → stores CASCADE
  date date, hour smallint    -- 0-23 UTC
  orders_count integer DEFAULT 0, revenue numeric(14,4) DEFAULT 0
  created_at, updated_at
  UNIQUE(store_id, date, hour); INDEX(workspace_id, store_id, date)
```

### SEO Tables

```sql
-- GSC has 2-3 day reporting lag. Last 3 days marked as "data may be incomplete" in UI.
-- All GSC data upserted (ON CONFLICT DO UPDATE) on every sync — GSC revises recent data.
search_console_properties
  id bigserial PK, workspace_id → workspaces CASCADE
  store_id → stores SET NULL (nullable)    -- auto-linked if domain matches; survives store deletion
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

gsc_queries   -- top 1,000 per property per day. Retained forever.
  id bigserial PK, property_id → search_console_properties CASCADE
  workspace_id → workspaces CASCADE
  date date, query varchar(500)    -- 500 chars keeps well under PG B-tree ~2704 byte limit
  clicks integer DEFAULT 0, impressions integer DEFAULT 0
  ctr numeric(8,6) (nullable), position numeric(6,2) (nullable)
  created_at, updated_at
  UNIQUE(property_id, date, query); INDEX(workspace_id, date)

gsc_pages   -- top 1,000 per property per day. Retained forever.
  id bigserial PK, property_id → search_console_properties CASCADE
  workspace_id → workspaces CASCADE
  date date, page varchar(2000)    -- varchar not text; truncate URLs > 2000 chars
  clicks integer DEFAULT 0, impressions integer DEFAULT 0
  ctr numeric(8,6) (nullable), position numeric(6,2) (nullable)
  created_at, updated_at
  UNIQUE(property_id, date, page); INDEX(property_id, date); INDEX(workspace_id, date)
```

### Cashier / Billing Tables

```sql
-- Define in your own migration. Do NOT run Cashier's bundled migration.
subscriptions
  id bigserial PK
  billable_type varchar(255)    -- 'App\Models\Workspace'
  billable_id bigint
  type varchar(255)             -- always 'default'
  stripe_id varchar(255) UNIQUE, stripe_status varchar(255)
  stripe_price varchar(255) (nullable), quantity integer (nullable)
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

### System / Operational Tables

```sql
-- DB is the cache. Two fetch flows:
-- 1. Daily: UpdateFxRatesJob → GET {FRANKFURTER_API_URL}/rates?base=EUR (ongoing)
-- 2. Historical import: fetch missing date ranges → GET /rates?from={start}&to={end}&base=EUR
-- Frankfurter: no monthly/daily quotas, rate-limited to prevent abuse only. Self-host via Docker if needed.
fx_rates
  id bigserial PK, base_currency char(3) DEFAULT 'EUR'
  target_currency char(3), rate numeric(16,8), date date
  created_at timestamp    -- no updated_at: historical rates are fixed, never revised
  UNIQUE(base_currency, target_currency, date); INDEX(date)

sync_logs    -- every job writes on start, updates on completion/failure
  id bigserial PK, workspace_id → workspaces CASCADE
  syncable_type varchar(255), syncable_id bigint    -- polymorphic
  job_type varchar(100)
  status varchar(50) CHECK (status IN ('running','completed','failed'))
  records_processed integer (nullable), error_message text (nullable)
  started_at timestamp (nullable), completed_at timestamp (nullable)
  duration_seconds integer (nullable)    -- enables import time estimates
  created_at, updated_at
  INDEX(workspace_id, syncable_type, syncable_id); INDEX(status, created_at)

-- Raw webhook payload for debugging and replay.
-- GDPR: may contain customer PII. Legitimate interest (debugging). Purged after 30 days.
-- Never expose payload in UI.
webhook_logs
  id bigserial PK, store_id → stores CASCADE, workspace_id → workspaces CASCADE
  event varchar(255), payload jsonb
  signature_valid boolean
  status varchar(50) DEFAULT 'pending' CHECK (status IN ('pending','processed','failed'))
  error_message text (nullable), processed_at timestamp (nullable)
  created_at, updated_at
  INDEX(store_id, created_at)

ai_summaries    -- Retained forever. ON CONFLICT DO UPDATE for re-generation.
  id bigserial PK, workspace_id → workspaces CASCADE
  date date, summary_text text
  payload_sent jsonb (nullable)       -- JSON sent to Anthropic (debugging)
  model_used varchar(100) (nullable), generated_at timestamp
  created_at, updated_at
  UNIQUE(workspace_id, date); INDEX(workspace_id, date)

alerts    -- system-detected issues surfaced in the UI alert feed
  id bigserial PK, workspace_id → workspaces CASCADE
  store_id → stores (nullable, CASCADE)
  ad_account_id → ad_accounts (nullable, CASCADE)
  type varchar(100)    -- sync_failed|token_expired|ssl_expiry|etc.
  severity varchar(50) CHECK (severity IN ('info','warning','critical'))
  data jsonb (nullable)    -- arbitrary metadata (e.g. property_id for GSC alerts)
  read_at timestamp (nullable), resolved_at timestamp (nullable)
  created_at, updated_at
  INDEX(workspace_id, resolved_at, created_at)
```

---

## Key Business Logic

### ROAS and Blended ROAS
```
ROAS = SUM(daily_snapshots.revenue) / SUM(ad_insights.spend_in_reporting_currency WHERE level='campaign')
Blended ROAS = same without store_id or ad_account_id filter
```
- Pre-converted at sync time. No FX lookup at query time.
- `SUM(spend) = 0` → display "N/A"
- Always filter `ad_insights` by exactly one `level`. Never SUM across levels.

### FX Rate Lookup
```
1. Query fx_rates WHERE date = $date              → found → return
2. Not found → look back 3 days for nearest rate  → return
3. Still not found → throw FxRateNotFoundException
   → callers: log warning, leave NULL
   → RetryMissingConversionJob retries nightly

Four-case conversion (all FX rates base=EUR):
1. order.currency == reporting_currency → return total (no-op)
2. order.currency == 'EUR'             → total × rate_EUR_to_reporting
3. reporting_currency == 'EUR'         → total / rate_EUR_to_order
4. neither is EUR                      → total × (rate_EUR_to_reporting / rate_EUR_to_order)
```

### Dashboard Query Rules
- Cards/charts → `daily_snapshots`, `hourly_snapshots`
- Ad metrics → `ad_insights`
- Top products → `daily_snapshots.top_products` JSONB
- Country revenue → `daily_snapshots.revenue_by_country` JSONB
- Country top products → `orders JOIN order_items WHERE customer_country = ?` (filtered, acceptable)
- Weekly (>90 days range) → `DATE_TRUNC('week', date)` on `daily_snapshots`
- Raw `orders` → background jobs only. Never in page requests.

---

## Integrations

### WooCommerce
**Auth:** Consumer key + secret, Basic auth.
**Validate:** `GET {domain}/wp-json/wc/v3/system_status` → 200 = connected
**Webhooks:** Register `order.created`, `order.updated`, `order.deleted`. Store `webhook_secret_encrypted` from response. Verify `X-WC-Webhook-Signature`: `base64(HMAC-SHA256(rawBody, secret))`.
On reconnect: delete old webhooks first (`DELETE /wp-json/wc/v3/webhooks/{id}`), ignore 404.
`order.deleted` → set status='cancelled', never hard-delete.

**Historical import endpoint (30-day chunks):**
`GET /wp-json/wc/v3/orders?after={chunk_start_ISO}&before={chunk_end_ISO}&orderby=date&order=asc&per_page=100&page={n}`

**Order field mapping:**
| DB | WooCommerce |
|---|---|
| external_id | id (cast to string) |
| external_number | number |
| status | completed→completed, processing→processing, refunded→refunded, cancelled→cancelled, else→other |
| total | total (string→float) |
| subtotal | subtotal |
| tax | total_tax |
| shipping | shipping_total |
| discount | discount_total |
| customer_email_hash | SHA-256(lowercase(trim(billing.email))) |
| customer_country | billing.country |
| utm_source/medium/campaign/content | meta_data _utm_* keys |
| occurred_at | date_created_gmt UTC |

**Order items from line_items:**
| DB | WooCommerce |
|---|---|
| product_external_id | product_id cast to string |
| product_name | name |
| variant_name | concatenate attribute meta_data display values; NULL if no variation |
| sku | sku |
| quantity | quantity |
| unit_price | price string→float |
| line_total | total string→float |

**Products:**
- On connection: `GET /wp-json/wc/v3/products?per_page=100` (paginated, upsert all)
- Nightly: `GET /wp-json/wc/v3/products?modified_after={ISO}&per_page=100`

| DB | WooCommerce |
|---|---|
| external_id | id cast to string |
| name | name |
| sku | sku |
| price | price string→float |
| status | status |
| image_url | images[0].src |
| product_url | permalink |
| platform_updated_at | date_modified_gmt |

**Store metadata (from system_status):**
- name → environment.site_title
- currency → settings.currency
- timezone → environment.timezone

**Historical time estimate:** Before dispatching the import job, make a lightweight count request: `GET /wp-json/wc/v3/orders?after={start_date_ISO}&per_page=1` and read `X-WP-Total`. If prior `sync_logs` exist: `X-WP-Total × AVG(duration_seconds / records_processed)` → show "~N minutes". If no prior logs: use ~1 minute per 1,000 orders heuristic.

### Shopify (Phase 2)

**Auth:** Admin API access token (user creates in Shopify Admin → Settings → Apps → Develop Apps).
**API version:** `2026-04` (current stable). Always specify in URL. Update quarterly.
**Validate:** `GET /admin/api/2026-04/shop.json` with `X-Shopify-Access-Token` header → 200 = connected.
**Webhooks:** `POST /admin/api/2026-04/webhooks.json` for `orders/create`, `orders/updated`, `orders/cancelled`. Store webhook secret from response. Verify `X-Shopify-Hmac-Sha256`.
`orders/cancelled` → set status='cancelled' (same as WooCommerce order.deleted).
**Pagination:** cursor-based via `Link` header. Never use page numbers.

**Order field mapping:**
| DB | Shopify |
|---|---|
| external_id | id cast to string |
| external_number | order_number |
| status | paid→completed, partially_paid/pending/authorized/partially_refunded→processing, refunded→refunded, voided/cancelled_at→cancelled, else→other |
| currency | presentment_currency |
| total | total_price string→float |
| subtotal | subtotal_price |
| tax | total_tax |
| shipping | SUM(shipping_lines[].price) |
| discount | total_discounts |
| customer_email_hash | SHA-256(lowercase(trim(email))) |
| customer_country | billing_address.country_code |
| utm_source/medium/campaign/content | note_attributes or parse landing_site |
| occurred_at | created_at UTC |

**Order items from line_items:**
| DB | Shopify |
|---|---|
| product_external_id | product_id cast to string |
| product_name | title |
| variant_name | variant_title (NULL if "Default Title") |
| sku | sku |
| quantity | quantity |
| unit_price | price string→float |
| line_total | (price × quantity) - total_discount |

**Store metadata (from shop.json):**
- name → shop.name
- currency → shop.currency
- timezone → shop.iana_timezone

**Products:** `GET /admin/api/2026-04/products.json?limit=250` (cursor-paginated on connection). Nightly: `GET /admin/api/2026-04/products.json?updated_at_min={ISO}&limit=250`.

| DB | Shopify |
|---|---|
| external_id | id cast to string |
| name | title |
| sku | variants[0].sku |
| price | variants[0].price string→float |
| status | status (active/draft/archived) |
| image_url | image.src |
| product_url | {store_domain}/products/{handle} |
| platform_updated_at | updated_at |

### Facebook Ads
See CLAUDE.md §Facebook Ads for full OAuth flow and field mappings. Key notes:

**Why long-lived token immediately:** Short-lived token expires in ~2 hours. Exchange window is small. Must exchange immediately after OAuth before returning to the user.

**Developer approval:** Apply for Advanced Access as early as possible. 2-6 weeks with: live demo URL, screencast video, privacy policy, business verification. Without it, can only use own test ad account.

**Historical data limit:** ~37 months. Inform users at connection time.

**Insights endpoint:** `GET https://graph.facebook.com/v20.0/act_{adAccountId}/insights`
Params: `level`, `fields=spend,impressions,clicks,reach,ctr,cpc,purchase_roas,account_currency`, `time_range={"since":"...","until":"..."}`, `time_increment=1` (or `hourly`)

Always re-sync last 3 days (Facebook revises recent figures with late attribution data).

### Google Ads
See CLAUDE.md §Google Ads for full OAuth flow and field mappings. Key notes:

**Developer token:** Two tiers: Basic Access (test accounts only, granted immediately) and Standard Access (production, 2-3 business days, requires live app). Apply from day one of development.

**GAQL (Google Ads Query Language):** SQL-like. Do not use legacy AdWords API.

**No hourly data:** Google Ads API does not provide hourly breakdown. `hour` is always NULL for Google rows.

**GAQL endpoint:** `POST https://googleads.googleapis.com/v17/customers/{customerId}/googleAds:searchStream`
Headers: `Authorization: Bearer {token}`, `developer-token: {GOOGLE_ADS_DEVELOPER_TOKEN}`

**Token refresh:** Access tokens expire in 1 hour. Refresh transparently in `GoogleAdsClient` when within 5 minutes of expiry using the stored refresh token.

### Google Search Console
See CLAUDE.md §Google Search Console for full connection flow.

**searchAnalytics endpoint:** `POST https://searchconsole.googleapis.com/webmasters/v3/sites/{encodedSiteUrl}/searchAnalytics/query`
Use URL-encoding for siteUrl (e.g. `https%3A%2F%2Fmystore.de%2F`).

**Request body:**
```json
{
  "startDate": "YYYY-MM-DD",
  "endDate": "YYYY-MM-DD",
  "dimensions": ["date"],         // or ["date","query"] or ["date","page"]
  "rowLimit": 1000,
  "dataState": "all"              // includes fresh unprocessed data
}
```

Run every 6 hours. Query last 5 days on every run (2-3 day lag + 2 buffer days). Upsert on conflict.

**Token refresh:** Same pattern as Google Ads. `RefreshOAuthTokenJob` also refreshes GSC daily at 05:00 UTC as safety net.

**Timezone:** GSC doesn't report a timezone. Use linked store's timezone, or workspace.reporting_timezone if no store linked.

---

## Background Jobs

### Why withoutOverlapping(10)
When two app servers both run the scheduler, scheduled tasks fire twice. `withoutOverlapping(10)` uses an atomic Redis cache lock. Argument is TTL in minutes (10 is sensible). Default TTL is 1440 minutes — a crashed job blocks re-runs for 24 hours. Always pass explicit TTL.

### FX Rate Job Notes
- `UpdateFxRatesJob` stores `date` from the API response (not today — on weekends the API returns Friday's rates)
- Historical import pre-populates FX rates before processing orders (DB-first: only fetches dates not already cached)

### Job Failure Philosophy
Increment `consecutive_sync_failures` once per dispatch (after all retry attempts), not once per retry. A job that fails on all 3 retry attempts counts as 1 failure, not 3. Status→'error' at 3 consecutive dispatch failures.

`RecomputeReportingCurrencyJob`: use `chunk(1000)` on all queries. A workspace with 500k orders would OOM without chunking.

### PurgeDeletedWorkspaceJob
Runs weekly (Sunday 05:00 UTC). Finds all workspaces where `deleted_at < now() - 30 days` and hard-deletes them in a single DB transaction. All related data (stores, orders, snapshots, ad accounts, etc.) cascades via FK `ON DELETE CASCADE`. Logs each purge. Timeout: 300s, 3 tries.

Workspace model uses Laravel's `SoftDeletes` trait — all Eloquent queries automatically exclude soft-deleted rows. `PurgeDeletedWorkspaceJob` uses `onlyTrashed()` to find candidates. Sync jobs and `SetActiveWorkspace` get the filtering for free via the trait.

---

## Workspace Deletion

### Why require subscription cancellation before deletion
If we cancelled the subscription automatically on workspace deletion, the owner could initiate deletion by mistake and lose their subscription status immediately. Requiring manual cancellation first ensures the decision is deliberate and two-step. It also means any refund/cancellation dispute is handled through Stripe's normal flow before data is touched.

### Why soft-delete + 30-day recovery window
Standard SaaS practice. Accidental deletion is common. 30 days is long enough for a user to notice and recover, but short enough that it doesn't create indefinite limbo for orphaned data. During the soft-delete window: all syncs paused, workspace hidden from UI, data fully intact. Owner can self-serve restore via a link in the confirmation email.

### Deletion blocked conditions
1. Outstanding unpaid invoices in Stripe (`status = 'open'`)
2. Active subscription (must cancel through `/settings/billing` first)

### Deletion flow
1. Owner confirms deletion → `deleted_at = now()`
2. Confirmation email sent with restore link (valid 30 days)
3. Owner can restore at any time within 30 days → `deleted_at = null`
4. After 30 days: `PurgeDeletedWorkspaceJob` hard-deletes with full cascade

---

## Data Retention Rationale
| Data | Retention | Rationale |
|---|---|---|
| Orders, items | Forever | Historical revenue is the product's core value |
| Products | Forever | Reference metadata for top products display |
| Ad insights | Forever | Long-term ROAS trends |
| Daily snapshots | Forever | Historical charts and trend analysis |
| Hourly snapshots | Forever | Customers expect hourly drill-down for any historical date (e.g. Black Friday last year). ~8,760 rows/store/year — negligible storage cost. |
| FX rates | Forever | Historical order conversion needs historical rates |
| GSC daily stats | Forever | Long-term SEO trend lines |
| GSC queries/pages | Forever | GSC API only goes back ~16 months; once stored, data is irreplaceable. Customers expect to see trends from day one of connection. Storage cost is negligible. |
| AI summaries | Forever | Users value looking back at AI analysis for any historical period. Tiny rows (~1 KB each). |
| Alerts | Forever | Historical failure patterns are useful for diagnostics and trend analysis. Small data. |
| Sync logs | 90 days | Pure operational — no customer value after debugging window |
| Webhook logs | 30 days | Contains customer PII in payload (GDPR minimization). Short-term debugging and replay only. |

---

## Phased Roadmap

### MVP (Build Now)
- Multi-workspace: Owner / Admin / Member roles, workspace switcher
- WooCommerce integration (API key auth)
- Facebook Ads + Google Ads (OAuth)
- Google Search Console (OAuth)
- Dashboard: revenue, orders, ROAS, AOV, items/order, marketing spend %
- Date range picker with comparison mode, hourly/daily/weekly granularity
- Store detail: metrics, top products, country breakdown
- Per-country view: revenue, orders, top products (cross-store and per-store)
- Advertising section: per-platform and blended view
- AI daily summary card (no email)
- Currency normalisation with FX rates
- Historical import with resumability, progress tracking, time estimates
- Alert feed (sync failures, token expiry)
- Hybrid billing: flat tiers + revenue % model
- 14-day free trial, no card
- Super admin panel

### Phase 2
- **Shopify integration** — full spec in this document §Shopify above
- **Data export** — CSV export for orders, revenue, ad spend by date range. Available on dashboard, store overview, and advertising pages. Queued job for large exports (>10k rows), direct download for small ones.
- Uptime monitoring (ping stores every 5 minutes from second-region VPS)
- TTFB + Core Web Vitals via PageSpeed Insights API
- SSL expiry monitoring
- Site audit crawler: 404s, redirect chains, missing meta, hreflang errors
- Ad ↔ page correlation: match ads.destination_url to site audit data
- First-party tracking pixel (Hyros-style session tracking and ad attribution)
- Abandoned cart detection (requires pixel)
- Email digest (AI summary by email, opt-in)
- Google Postmaster Tools (domain reputation for email health)
- SMS credit system (consumption-based billing add-on)
- Two-VPS migration with Deployer + Ansible

### Phase 3
- Email marketing flows (abandoned cart, welcome, post-purchase sequences)
- Mobile app (REST API at `/api/v1/` alongside Inertia routes)
- White-label for agencies
- Predictive analytics / AI budget recommendations
- Customer cohort analysis + LTV reporting
- TikTok Ads, Pinterest Ads
- Inventory alerts (low stock, slow movers)
- Ahrefs / Semrush API passthrough (users bring own key)
- Health score per store (composite metric)
- Custom user-defined alert rules

**Do not build any Phase 2 or Phase 3 feature without explicit instruction.**

---

## Security Model

1. **Never store secrets in plaintext.** `Crypt::encryptString()` / `Crypt::decryptString()`. All credential columns end in `_encrypted`.
2. **Never return decrypted credentials to frontend.** Masked strings only (e.g. `"****4f2a"`).
3. **Never log raw tokens or customer emails.** Log IDs and status codes only.
4. **Verify every webhook signature** before processing. Invalid → 401 + log.
5. **Verify workspace ownership** via Policies before every data operation.
6. **Customer emails:** SHA-256(lowercase(trim(email))). Never store raw. GDPR.
7. **Rate limits:** auth `throttle:10,1`; webhook `throttle:100,1` keyed by store_id.
8. **Parameterised queries only.** No raw SQL interpolation.
9. **CSRF:** automatic for Inertia. Exclude `'stripe/*'` from VerifyCsrfToken.
10. **Stripe webhooks:** `Cashier::routes()` in routes/web.php; STRIPE_WEBHOOK_SECRET auto-verified.
11. **Invitation roles:** Admins invite Member or Admin only (WorkspaceInvitationPolicy).
12. **Billing is Owner-only:** BillingPolicy; Admins/Members get 403.
13. **Update last_login_at** on every successful login.

---

## Code Conventions

### PHP
- `declare(strict_types=1)` in every file
- PHP 8.5: match, readonly, constructor promotion, enums, named args
- Controllers: validate → Action → Inertia::render() only
- Actions: single `handle()` method. Business logic lives here.
- Models: relationships, scopes, accessors only
- All tests: `Http::fake()`, `Queue::fake()`, `Event::fake()` — never hit real APIs
- `DB::transaction()` for multi-table writes
- `upsert()` for all synced data — never `insert()`
- `select()` specific columns, never `SELECT *`

### TypeScript / React
- TypeScript everywhere
- Functional components + hooks only
- `React.memo` on MetricCard and chart wrappers; `useMemo` for chart data transforms
- Tailwind only, no inline styles
- Shared utils: `formatCurrency(amount, currency, compact?)`, `formatNumber(value, compact?)`, `formatPercent(value)`, `formatDate(date, granularity, timezone)`

### Database
- Every migration has `down()`
- Indexes in same migration as table
- Tests MUST use PostgreSQL:
  ```xml
  <!-- phpunit.xml inside <php> -->
  <env name="DB_CONNECTION" value="pgsql"/>
  <env name="DB_DATABASE" value="metrify_test"/>
  ```
  Run `createdb metrify_test` before first test run.

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
SESSION_LIFETIME=480              # 8 hours — users leave dashboards open all day
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
STRIPE_PRICE_PERCENTAGE=          # metered, €0.01/unit (= 1% of revenue in €)

FACEBOOK_APP_ID=
FACEBOOK_APP_SECRET=
FACEBOOK_REDIRECT_URI=

GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI=              # handles both Google Ads and GSC callbacks via state JSON type field
GOOGLE_ADS_DEVELOPER_TOKEN=       # Standard Access required for production — apply early

ANTHROPIC_API_KEY=                # separate from your Claude Code subscription key
ANTHROPIC_MODEL=claude-sonnet-4-6

FX_BASE_CURRENCY=EUR
FRANKFURTER_API_URL=https://api.frankfurter.dev/v2  # no key, no quotas, self-hostable
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

## Claude MUST Always
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
- Filter `ad_insights` by a single `level` value
- Reset `consecutive_sync_failures = 0` on success; `status='active'` only if was `'error'`

## Claude MUST Never
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
- Hardcode the Frankfurter API URL — use `env('FRANKFURTER_API_URL')`
- Store raw customer email addresses — SHA-256 hash after normalizing
- Swallow exceptions silently
- Return hardcoded/placeholder/fake data — show error state
- Treat NULL `total_in_reporting_currency` as 0
- Produce code that hides a silent failure
