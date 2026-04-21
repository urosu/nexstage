# CLAUDE.md — Nexstage

Ecommerce analytics SaaS for WooCommerce (Shopify in Phase 2) SMBs. Laravel 12 + Inertia + React 19 + Postgres + Horizon/Redis. Multi-tenant via `WorkspaceScope`.

**For what to build next, read PROGRESS.md.** For the product spec and architecture, read PLANNING.md. For competitor and API research, read RESEARCH.md.

## Trust thesis (shapes every UI decision)

Ad platforms disagree with the store database. Default view shows one number; drill-down shows the disagreement. Six source badges on `MetricCard`: Store / Facebook / Google / GSC / Site / **Real** (gold lightbulb, computed by Nexstage). "Not Tracked" replaces "unattributed revenue" and can go negative when platforms over-report.

## Codebase map

- `app/Services/` — business logic
- `app/Jobs/` — queue jobs, one queue per external provider (see PLANNING section 22)
- `app/Connectors/` — `StoreConnector` implementations, only place platform APIs are called
- `app/Actions/` — single-purpose actions (e.g. `UpsertWooCommerceOrderAction`)
- `app/ValueObjects/` — plain PHP value objects (`ParsedAttribution`, `WorkspaceSettings`)
- `app/Models/` — Eloquent, all tenant tables have `WorkspaceScope`
- `resources/js/Pages/` — Inertia page components
- `resources/js/Components/` — shared React components

## Common commands

```bash
# After any PHP change, restart BOTH containers:
docker restart nexstage-php nexstage-horizon

# After adding a new route, clear the route cache (routes are cached in production-like envs):
docker exec nexstage-php php artisan route:clear

# Migrations
docker exec nexstage-php php artisan migrate
docker exec nexstage-php php artisan migrate:fresh --seed  # pre-launch only

# Tests
docker exec nexstage-php php artisan test

# Ad-hoc PHP/DB queries — artisan tinker does NOT work in this Docker setup
# (psysh can't write its config dir). Use php -r with a manual bootstrap instead:
docker exec nexstage-php php -r '
require "/var/www/html/vendor/autoload.php";
$app = require "/var/www/html/bootstrap/app.php";
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
// ... your code here
'
```

Local OAuth callback: `tracey-unstiffened-leona.ngrok-free.dev`. AI model string: `claude-sonnet-4-6`.

## Code documentation

Prefer docblocks that explain **what and why** over inline comments that describe mechanics. Every service, job, and model gets a class-level docblock naming its purpose, what it reads, what it writes, and what calls it. Reference PLANNING section numbers via `@see PLANNING.md section N` when a class implements a documented decision. The test: can a new agent open a random file and understand what it does and how it connects without running the app?

Don't comment obvious code. Don't restate type hints. Magic numbers and thresholds need a comment pointing to where they came from.

## Gotchas (things that trip agents up on this codebase)

- **Queue jobs don't inherit request scope.** `WorkspaceScope` is request-bound; inside a job, filter by `workspace_id` explicitly.
- **`raw_meta` on orders contains `fee_lines`, `customer_note`, and PYS data (`pys_enrich_data`, `pys_fb_cookie`).** It does NOT contain WC native attribution fields — those are promoted to dedicated `utm_*` columns. Attribution decisions live in `attribution_*` columns (see PLANNING section 6).
- **`ad_insights` has no country column.** Country-level ad spend comes from `COALESCE(campaigns.parsed_convention->>'country', stores.primary_country_code, 'UNKNOWN')`. See PLANNING section 5.7.
- **`BreakdownView` has zero data-fetching capability.** Controllers pre-join server-side and pass flat `BreakdownRow[]`. `cardData` is a display hint only.
- **`fx_rates` is a cache, not a live fetch.** `FxRateService` is DB-first; never call Frankfurter at query time.
- **Never `SUM` across `ad_insights` levels** — always filter to a single level (campaign / adset / ad).
- **Never aggregate raw `orders` in page requests** — use `daily_snapshots`, `hourly_snapshots`, `daily_snapshot_products`.
- **Divide-by-zero everywhere there's a ratio.** NULLIF in SQL, null check in PHP, display "N/A". Compute CPM/CPC/CPA on the fly, never store them.
- **JSONB holding raw platform API data needs a paired `*_api_version` column.** Applies to `raw_meta`, `raw_insights`, `creative_data`, `lighthouse_snapshots.raw_response`. Does NOT apply to Nexstage-owned JSONB shapes (`attribution_*`, `parsed_convention`, `workspace_settings`).
- **Facebook and Google Ads API versions change every 1-3 months.** Don't trust the version pinned in code — check provider changelog before touching sync jobs.

## Prefer X over Y

- Prefer generalising an existing component (e.g. `QuadrantChart`) over creating a new one
- Prefer adding a column to an existing table over creating a new table
- Prefer seeded database rows over hardcoded PHP constants for lookups
- Prefer feature flags over big-bang cutovers when touching load-bearing services
- Prefer fixing PLANNING.md (after asking) over coding against a doc that disagrees with the code

## When uncertain

Check PLANNING.md for the spec, PROGRESS.md for current state, RESEARCH.md for external facts. If you're about to invent a new abstraction not already in the codebase, stop and ask.
