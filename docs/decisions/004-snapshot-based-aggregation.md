# ADR 004: Snapshot-Based Aggregation (Not Real-Time Raw Queries)

**Status:** Accepted  
**Date:** 2026-04-24  
**Context:** Page requests need to display KPIs like "Revenue (last 30d)". Options: (a) SUM(orders.total) on-demand, (b) pre-computed snapshots, (c) materialized views.

## Decision

**Pre-computed daily + hourly snapshots** (`daily_snapshots`, `hourly_snapshots`, `daily_snapshot_products`).

- SnapshotBuilderService runs nightly + on-demand (cost/window/attribution config changes).
- Page requests NEVER aggregate raw orders.
- Old snapshot rows are not deleted (enables Time Machine on attribution page).

## Rationale

1. **Page performance** — KPI cards render in <100ms (snapshot row fetch vs 100k-order SUM).
2. **Correctness under concurrency** — snapshots are atomic; raw SUM across orders during webhook ingest can double-count or miss orders.
3. **Retroactive recalc** — Changing attribution window or cost model re-runs SnapshotBuilderService; all historical values recompute.
4. **Time Machine** — Attribution page can show "how would this metric look under a different model?" by re-querying historical snapshots.

## Consequences

- **Schema:** `daily_snapshots` has 7 per-source revenue columns + 6 profit components = 30+ columns (wide row, but query-transparent).
- **Job:** SnapshotBuilderService is load-bearing; if it fails, all pages show stale data (alerting required).
- **Data staleness:** KPIs are delayed 1-2 hours after order webhook (SnapshotBuilderService run).
- **Storage:** Snapshots retain all historical rows (no TTL); ~1M rows/year for 10k daily orders is manageable.

## Alternatives Considered

1. **Real-time SUM(orders)** — Fast for <1k orders; catastrophic for 100k+ (timeout risk).
2. **Materialized views** — Postgres MVs require manual refresh; SnapshotBuilderService is more flexible.
3. **Clickhouse / analytics DB** — Overkill for SMB; Postgres snapshots sufficient.

## Validation

- Shopify Analytics (snapshots per order) validates pre-computed aggregations.
- Lifetimely (cohort snapshots) validates retention of historical snapshots.
- Putler (daily rollups) validates the pattern.
