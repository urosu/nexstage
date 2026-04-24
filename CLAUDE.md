# CLAUDE.md — Nexstage

Ecommerce analytics SaaS for WooCommerce and Shopify SMBs. Laravel 12 + Inertia + React 19 + Postgres + Horizon/Redis. Multi-tenant via `WorkspaceScope` (39 models use `#[ScopedBy(WorkspaceScope::class)]`).

## Reading order for any task

1. **[docs/PLANNING.md](docs/PLANNING.md)** — the work order. Dependency-ordered layers (L1 cleanup → L6 polish), every item links out.
2. **[docs/UX.md](docs/UX.md)** — 37 shared primitives (§5), 10 routes (§2), interaction rules (§6), thesis behavior (§7). Every page spec references primitives from §5 by PascalCase name.
3. **[docs/pages/](docs/pages/)** — one file per route. Read only the page you're working on.
4. **[docs/planning/](docs/planning/)** — target architecture with KEEP/MODIFY/NEW/DROP per item:
   - `schema.md` — tables + indexes + JSONB pairing
   - `backend.md` — services / actions / jobs / connectors / controllers / routes
   - `frontend.md` — Inertia pages + React primitives + build order
5. **[docs/competitors/](docs/competitors/)** — deep research reference. Start with `README.md`, then `_patterns_catalog.md` (189 UI patterns) and `_crosscut_metric_dictionary.md` (85 metric names + formulas). Individual competitor files are deep-dive reference only.

**Never invent a UX primitive or metric name.** If it's not in `docs/UX.md §5` or `docs/competitors/_crosscut_metric_dictionary.md`, don't use it.

## Trust thesis (shapes every UI decision)

Ad platforms disagree with the store database. Default view shows Real (the Nexstage-computed reconciliation); drill-down shows the disagreement via six source badges on every `MetricCard`: Store / Facebook / Google / GSC / Site / **Real** (gold lightbulb). "Not Tracked" is a first-class bucket and can go negative when platforms over-report. Never prefix metrics with "True" or "Real" as a sales claim — Hyros anti-pattern.

## Codebase map

- `app/Services/` — business logic (thin controllers, fat services)
- `app/Jobs/` — queue jobs, one queue per external provider (11 queues in `config/horizon.php`)
- `app/Services/Integrations/` — platform API clients (`FacebookAdsClient`, `GoogleAdsClient`, `SearchConsoleClient`, Shopify/WC connectors)
- `app/Actions/` — single-purpose idempotent actions
- `app/ValueObjects/` — plain PHP VOs (`ParsedAttribution`, `WorkspaceSettings`, `StoreCostSettings`)
- `app/Models/` — Eloquent, tenant models use `#[ScopedBy(WorkspaceScope::class)]`
- `resources/js/Pages/` — Inertia page components
- `resources/js/Components/` — shared React components
- `resources/js/Layouts/` — layout shells
- `docs/` — all product specs, research, and planning

## Common commands

```bash
# After any PHP change, restart BOTH containers:
docker restart nexstage-php nexstage-horizon

# After adding a new route, clear the route cache:
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

Every service, job, and model gets a class-level docblock naming its purpose, what it reads, what it writes, and what calls it. Reference architecture decisions via `@see docs/planning/<file>.md#<section>` or `@see docs/UX.md#<primitive>`. Test: can a new agent open a random file and understand what it does and how it connects without running the app?

Don't comment obvious code. Don't restate type hints. Magic numbers and thresholds need a comment pointing to where they came from.

## Gotchas (things that trip agents up)

- **Queue jobs don't inherit request scope.** `WorkspaceScope` throws if `WorkspaceContext::id()` is null. Jobs take `$workspaceId` in constructor and call `app(WorkspaceContext::class)->set(...)` at the top of `handle()`, or use `withoutGlobalScopes()` + explicit `where('workspace_id', …)`.
- **`raw_meta` on orders contains `fee_lines`, `customer_note`, PYS data.** It does NOT contain WC native attribution fields — those are promoted to dedicated `utm_*` columns. Attribution decisions live in `attribution_*` columns (see [docs/planning/schema.md](docs/planning/schema.md) Orders).
- **`ad_insights` has no country column.** Country-level ad spend = `COALESCE(campaigns.parsed_convention->>'country', stores.primary_country_code, 'UNKNOWN')`.
- **`BreakdownView` has zero data-fetching capability.** Controllers pre-aggregate and pre-join server-side when a breakdown is active, handing flat `BreakdownRow[]` to the frontend. The React component is display-only (no SWR); breakdown selection at the TopBar triggers a full page reload via Inertia partial props.
- **`fx_rates` is a cache, not a live fetch.** `FxRateService` is DB-first; `UpdateFxRatesJob` is the sole Frankfurter caller. Never call Frankfurter at query time.
- **Never `SUM` across `ad_insights` levels** — always filter to a single `level` (`campaign` / `adset` / `ad`).
- **Never aggregate raw `orders` in page requests** — use `daily_snapshots`, `hourly_snapshots`, `daily_snapshot_products`. `SnapshotBuilderService` is the only writer.
- **Divide-by-zero discipline.** Ratios (CPM, CPC, CPA, ROAS, CTR, CVR, AOV, MER, LTV:CAC) compute on the fly. SQL uses `NULLIF`. PHP null-checks. UI renders "N/A". Never store ratios.
- **JSONB holding raw platform API data needs a paired `*_api_version` column.** Applies to `raw_meta`, `raw_insights`, `creative_data`, `lighthouse_snapshots.raw_response`, `integration_events.payload`. Does NOT apply to Nexstage-owned JSONB (`attribution_*`, `parsed_convention`, `workspace_settings`, `url_state`).
- **Facebook and Google Ads API versions change every 1-3 months.** Check provider changelog before touching sync jobs.
- **Cost/window/attribution config changes trigger retroactive recalc.** `UpdateCostConfigAction`, `UpdateAttributionDefaultsAction`, channel mapping CRUD → dispatch `RecomputeAttributionJob` and fan out snapshot rebuilds. UI shows Klaviyo-style `"Recomputing…"` banner.

## Prefer X over Y

- Prefer generalising an existing component (e.g. `QuadrantChart`) over creating a new one.
- Prefer adding a column to an existing table over creating a new table.
- Prefer seeded database rows over hardcoded PHP constants for lookups (see `ChannelMappingsSeeder`).
- Prefer feature flags (`config/features.php`) over big-bang cutovers when touching load-bearing services.
- Prefer SWR (client-side fetch with cache-first revalidation) over TanStack Query for data within a page; Inertia owns navigation ([docs/PLANNING.md §4](docs/PLANNING.md#4-blocking-open-questions)).
- Prefer fixing `docs/PLANNING.md` or `docs/UX.md` (after asking) over coding against a doc that disagrees with the code.

## UTM source / medium sync

`resources/js/Components/tools/TagGenerator.tsx` hardcodes UTM source/medium values that MUST stay in sync with `database/seeders/ChannelMappingsSeeder.php`. Both read from `channel_mappings` at runtime, but the generator ships the seeded values as suggestions — update in lockstep.

## When uncertain

Check `docs/PLANNING.md` for the work order, `docs/UX.md` for primitives/interactions, `docs/pages/<name>.md` for the page you're working on, `docs/planning/<layer>.md` for architectural detail, `docs/competitors/` for external facts. If you're about to invent a new abstraction not in `docs/`, stop and ask.
