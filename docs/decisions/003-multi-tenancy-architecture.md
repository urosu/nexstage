# ADR 003: Multi-Tenancy via WorkspaceScope (Not Row-Level Isolation)

**Status:** Accepted  
**Date:** 2026-04-24  
**Context:** Nexstage needs to support agencies managing multiple client stores. Options: (a) WorkspaceScope with per-workspace databases, (b) row-level security with shared DB, (c) separate Heroku dynos per customer.

## Decision

Implement **WorkspaceScope** (shared Postgres DB, scoped queries via `#[ScopedBy(WorkspaceScope::class)]` on 39+ models).

Each workspace = one billing account with 1..* stores (WooCommerce + Shopify).

## Rationale

1. **Operational simplicity** — One DB, one backup, one schema migration path. Row-level security requires trigger-heavy schema.
2. **Price-per-workspace model** — Agencies invoice per client; each workspace = one invoice. Clean billing.
3. **Workspace switcher as retention lever** — Agencies stay logged in, flipping between client workspaces (Slack/Linear pattern).
4. **Deferred problem:** Data residency / GDPR — Not MVP; future option to shard by region.

## Consequences

- **Query safety:** Every data fetch MUST go through WorkspaceScope. Unscoped queries are security holes (CLAUDE.md gotcha).
- **Schema:** Workspace-keyed tables have `workspace_id` + unique constraints scoped to workspace (not global).
- **Jobs:** Queue jobs do NOT inherit request scope; they take `$workspaceId` in constructor and call `WorkspaceContext::set()` at top of `handle()`.
- **Future:** v2 can shard by region (e.g., EU workspaces live on separate DB instance) without changing app logic.

## Alternatives Considered

1. **Row-level security (RLS)** — Postgres RLS is powerful but requires policy per table; adds complexity for marginal security benefit (WorkspaceScope is equally safe if enforced consistently).
2. **Separate DB per workspace** — Billing isolation; complicates migrations and introduces N×DBs operational burden.
3. **Separate Heroku dynos per customer** — Salesforce Hyperforce model; overkill for SMB SaaS.

## Validation

- Slack (workspace as billing unit) validates the pattern.
- Linear (one org per workspace) validates the architecture.
- Shopify (multiple stores per partner account) validates multi-store-per-workspace modeling.
