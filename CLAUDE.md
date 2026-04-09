# Nexstage — Coding Agent Instructions

## What is this project?

Nexstage is a pre-launch ecommerce analytics SaaS for WooCommerce SMBs. It unifies paid ads + organic search + site performance + ecommerce data to answer "why did my revenue change?" Built with Laravel 12 + Inertia.js + React 19 + TypeScript + Tailwind.

**No live users.** Full freedom to rewrite anything. Treat this as a greenfield project with existing code to build on.

## Essential files to read first

1. **`PLANNING.md`** — The complete product plan. Schema, billing, anomaly detection, phases, business logic. This is the source of truth for ALL product decisions. Read the relevant section before implementing anything.
2. **`PROGRESS.md`** — Tracks what has been built, what's in progress, and what's next. **Update this file as you complete work.** Check it first to avoid redoing completed work.
3. **`CLAUDE.md`** — This file. Coding conventions and instructions.

## Progress tracking

**You MUST update `PROGRESS.md` after completing each task.** Mark items as done with the date. If you start something but don't finish, mark it as in-progress with notes on what's left. This is how future sessions know where to pick up.

## Architecture overview

### Multi-tenancy
- `WorkspaceContext` singleton (`app/Services/WorkspaceContext.php`) holds the active workspace
- `SetActiveWorkspace` middleware sets it per request
- `WorkspaceScope` global scope on all tenant models — auto-filters by workspace_id
- **Never query tenant data without workspace context.** If you bypass WorkspaceScope (e.g., in jobs), always filter by workspace_id explicitly.

### Queue system
- Horizon + Redis with 4 supervisors: critical, high, default, low
- Critical = webhook processing, High = OAuth refresh, Default = regular syncs, Low = imports/snapshots/cleanup
- All jobs: `upsert()` only, never `insert()` for synced data
- Failure chain: sync_logs → consecutive_sync_failures → alert → status='error' at 3+

### Billing
- Laravel Cashier + Stripe
- 3 tiers: Starter (<=5k, EUR29), Growth (<=25k, EUR59), Scale (>25k, 1% GMV / 2% ad spend, min EUR149)
- Auto-assigned based on last month's billable amount. No plan selection UI.
- Billing basis: has_store=true -> GMV, has_store=false -> ad spend, both -> GMV only

### Key patterns
- FX conversion: DB-first from fx_rates table, never call API at query time. Four-case conversion (see PLANNING.md "FX Rate conversion")
- Ad insights: always filter by single level (campaign OR ad), never SUM across levels
- Revenue: always from daily_snapshots/hourly_snapshots, never aggregate raw orders in page requests
- CPM/CPC/CPA: compute on the fly with NULLIF, never store as columns

## Comment conventions — AI-friendly codebase

Write comments that help the NEXT agent (or human) understand the codebase without reading the entire plan.

### Required comments

**Cross-references** — When a file depends on or is consumed by another file:
```php
// Related: app/Jobs/DetectAnomaliesJob.php (reads baselines computed here)
// Related: app/Models/MetricBaseline.php (model for this table)
```

**Business logic "why"** — When code implements a non-obvious business rule:
```php
// Why: Facebook revises ad metrics for up to 3 days after reporting.
// We re-sync the last 3 days on every run to capture corrections.
// See: PLANNING.md "Integration-specific rules"
```

**Phase markers** — When a table/column/feature exists but isn't used yet:
```php
// Phase 2: This column is populated during sync but only consumed by
// DetectAnomaliesJob (not yet built). See PLANNING.md "Anomaly Detection System"
```

**Constraint explanations** — When a schema choice isn't obvious:
```php
// Why partial unique indexes instead of UNIQUE(workspace_id, store_id, metric, weekday):
// PostgreSQL treats NULL as distinct in unique constraints, so nullable store_id
// would allow duplicate workspace-level rows. Two partial indexes solve this.
// See: PLANNING.md "metric_baselines"
```

**Data flow** — At the top of jobs/services, document what triggers them and what they produce:
```php
/**
 * Computes daily product snapshots from orders.
 *
 * Triggered by: DispatchDailySnapshots (scheduled daily)
 * Reads from: orders, order_items, products
 * Writes to: daily_snapshot_products (top 50 products per store per day)
 * Triggers: ComputeMetricBaselinesJob (Phase 2, after all store snapshots complete)
 *
 * See: PLANNING.md "Job Dispatch Chain"
 */
```

### Comment rules
- **DO** explain WHY, not WHAT. `// Exclude holiday dates from baseline window` is good. `// Loop through dates` is useless.
- **DO** reference PLANNING.md sections for complex business logic decisions.
- **DO** mark Phase 2+ code/tables with phase markers so future agents know what's active vs dormant.
- **DO** add `// Related:` cross-references when files are tightly coupled.
- **DON'T** add comments to self-explanatory code. `$order->total` doesn't need a comment.
- **DON'T** add TODO comments without a phase reference. `// TODO: add Shopify support` -> `// Phase 3: Shopify support via StoreConnector interface`

## Branding — Nexstage

The product is called **Nexstage** (nexstage.io). The codebase currently uses "Nexstage" / "nexstage" in many places (app name, config, comments, frontend text, etc.). **Rename all occurrences to Nexstage / nexstage** as you encounter them. This includes:
- `config/app.php` name
- Any user-facing strings, page titles, emails
- Blade templates, React components
- Comments referencing the old name
- Environment/Docker references

Don't do a single massive find-and-replace PR — just fix references in files you're already touching. If you create new files, use Nexstage from the start.

## Code ownership — you own this codebase

**This is pre-launch with zero users.** You have full authority to rewrite, delete, or replace any existing code. There is no backwards compatibility to maintain. If rewriting a file from scratch produces cleaner code than patching the existing version, do that. If an entire directory of old code doesn't fit the new schema, delete it and start fresh. Don't preserve old code out of caution — preserve it only if it's genuinely useful.

Specific guidance:
- **Migrations:** Rewrite from scratch. Don't add ALTER TABLE migrations on top of the old ones. One clean set of migrations that represents the final schema.
- **Models/Jobs/Controllers:** If the existing implementation is >50% wrong for the new schema, delete and rewrite rather than trying to patch around it.
- **Old routes/views:** If something is unused or superseded by the plan, remove it entirely. Dead code is worse than no code.
- **Don't ask permission to delete.** If code contradicts PLANNING.md, the plan wins. Remove the old code.

## Code quality standards

### No shortcuts, no faking

**Never return hardcoded, stubbed, or fake data from any endpoint, job, or service.** If you can't implement something fully, leave it unimplemented with a clear phase marker comment — don't fake it with placeholder responses. A controller action that returns `['revenue' => 0, 'orders' => 0]` because you didn't know what to query is worse than a `throw new \RuntimeException('Not implemented — see PLANNING.md Phase 1')`.

Specific rules:
- **No placeholder API responses.** Every endpoint must return real data from real queries, or explicitly throw/404.
- **No dummy implementations.** Don't create a service method that returns an empty array or hardcoded value "to be implemented later." Either implement it properly or don't create it yet.
- **No silent swallowing.** Don't catch exceptions and return empty results. If something fails, let it fail visibly.
- **No "good enough" approximations.** If PLANNING.md says to query `daily_snapshot_products`, don't approximate by querying `orders` directly because it's easier. Follow the plan.

### Write production-quality code from the start

- **Type hints everywhere.** PHP 8.2+ return types, parameter types, property types. TypeScript strict mode.
- **Validate at boundaries.** Form requests for controller input, type checking for job payloads. Don't validate internal method calls.
- **Use existing patterns.** Before writing a new job/service/controller, look at how existing ones work. Match the patterns (failure handling, queue assignment, logging).
- **No dead code.** Don't leave commented-out code, unused imports, or unused variables. If you remove a feature, remove all traces of it.
- **Meaningful names.** `$revenueByStore` not `$data`. `calculateBlendedRoas()` not `getRoas()`. Variable and method names should tell you what they do without reading the implementation.
- **One responsibility per class.** Jobs do one thing. Services encapsulate one domain. Controllers delegate to services/actions. Don't put business logic in controllers.

### Error handling philosophy

- **Fail loudly in development, gracefully in production.** Jobs should throw and retry via the queue system. Controllers should let exceptions bubble to the handler.
- **Never suppress errors to make something "work."** A passing test that hides a real bug is worse than a failing test.
- **Log with context.** `Log::warning('GSC sync returned 0 rows', ['property_id' => $id, 'date_range' => ...])` not `Log::warning('no data')`.

## Implementation phases

We're currently in **Phase 0** — foundations. Schema + data capture only. No UI changes, no intelligence logic.

### Phase 0 scope (current)
All work is structural — migrations, models, sync job updates, data capture. The goal is: every table exists, every API field we'll ever need is landing in the database, all new columns are populated during sync.

Key principle: **capture all data now, build features later.** If the API returns it and it's cheap to store, capture it. Add the table even if the feature using it is Phase 2+.

### What NOT to build in Phase 0
- No new UI pages or components
- No anomaly detection logic (ComputeMetricBaselinesJob, DetectAnomaliesJob)
- No correlation engine
- No billing changes
- No onboarding changes

## Database conventions

- All tenant tables MUST have `workspace_id` with FK + CASCADE + WorkspaceScope
- Exception: `order_items` (derived via order_id), `product_category_product` (pivot), `holidays` (global reference table, not tenant-scoped)
- JSONB columns MUST have a paired `*_api_version VARCHAR(20)` column
- Use `upsert()` for all synced data — never `insert()`
- Timestamps: `created_at` on everything, `updated_at` only where records are modified after creation
- Monetary values: `DECIMAL(12,2)` for amounts, `DECIMAL(14,4)` for computed values (AOV, rates)
- External IDs from platforms: `VARCHAR(255)` not integers (platforms change ID formats)

## Testing

- Run `php artisan migrate:fresh --seed` after migration changes to verify clean state
- Existing tests in `tests/Feature/` — don't break them
- For new sync job changes: test with actual API responses where possible, mock where not
- For migrations: verify indexes, constraints, and unique constraints work as expected

## Docker

- Use `docker compose restart <service>` to restart services, not container-specific commands
- Main services: app, horizon, postgres, redis

## When you're stuck

1. Read the relevant PLANNING.md section — most decisions are documented there
2. Check PROGRESS.md for context on what's been done
3. Look at existing similar code (e.g., existing sync jobs for patterns)
4. Ask the user — don't guess on business logic decisions
