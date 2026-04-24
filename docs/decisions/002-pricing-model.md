# ADR 002: Pricing Model (€39/mo + 0.4% Revenue Share)

**Status:** Accepted  
**Date:** 2026-04-24  
**Context:** Competitive pricing ranges from $99/mo (Northbeam, Polar, Lifetimely) to $0/mo (Shopify Native, GA4). Nexstage targets WooCommerce + Shopify SMBs (€10k–€1M+ GMV annually).

## Decision

**Hybrid pricing:** €39/mo base + 0.4% of reported store revenue.

- No per-store, per-connector, or per-metric overage.
- No caps on data volume or sync frequency.
- Aligns Nexstage incentives with merchant success (we profit when store does).

## Rationale

1. **€39/mo is the viability floor** — Below this, SMBs assume tool is broken or untrustworthy (Segments/Klaviyo precedent).
2. **0.4% revenue share is uncontested** — No competitor prices this way; Gumroad (10%), Stripe (2.2%), Shopify (1.6%) establish the wedge. 0.4% is cheaper than hiring one contractor for analytics.
3. **Removes buyer regret** — No "I paid €99 last month and didn't use it" guilt; you only pay for revenue.
4. **Scalable to agency tier** — Agencies with multi-store portfolios pay proportionally; no artificial per-workspace caps.

## Consequences

- **Merchant wallet:** €39/mo + 0.4% of store revenue (€50k store → €239/mo; €500k store → €2,439/mo).
- **Competitive positioning:** "Cheaper than hiring one analyst, more transparent than per-seat licensing."
- **Onboarding:** Revenue share pricing must be prominent on signup (no surprise billing charges).
- **Enterprise deviation:** If we ever sell to enterprises, pricing may deviate (e.g., per-API-call, per-seat); document separately.

## Alternatives Considered

1. **Per-store fixed pricing** (Lifetimely $99 base + $29/store) — Penalizes scale; rejected.
2. **Per-connector pricing** (Northbeam: Facebook $1k/mo) — Hidden anchovy; rejected.
3. **Tiered seats** (Linear, Slack) — SMB operators don't have 5 analysts; rejected.
4. **Pure percentage** (Stripe: 2.2% + $0.30 per transaction) — Too variable; €39 floor necessary for unit economics.
5. **Pure subscription** (Polar €299/mo) — Overkill for SMBs; regret risk too high.

## Validation

- Gumroad (10% + $0 base) validates that revenue share is sellable and non-punitive.
- Shopify (1.6% of revenue) validates revenue-based pricing precedent in commerce.
- Lifetimely ($99 + $29/store) validates that per-store caps feel extractive; we avoid this.
