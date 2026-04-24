# ADR 001: Source Disagreement as Product Thesis

**Status:** Accepted  
**Date:** 2026-04-24  
**Context:** Competitive analysis revealed every ecommerce analytics tool claims one "true" revenue number (Shopify Native, Lifetimely, ROAS Monster, Hyros). Users trust none of them.

## Decision

Make **source disagreement visible first-class** instead of hiding it:
- Every metric carries six-source badges (Store / Facebook / Google / GSC / Site / Real).
- "Not Tracked" is a first-class bucket that can go negative when platforms over-report.
- Real (Nexstage-computed reconciliation) is the default, but users can switch to any source 1-click.

## Rationale

1. **Users already know sources disagree** — they reconcile manually in spreadsheets. Making it visible is honest.
2. **Disagreement is the diagnostic** — Not the bug; the feature. "Why do Facebook and store disagree by 15%?" is the exact question Nexstage solves.
3. **Commoditized metrics are worthless** — Every tool computes ROAS. Nexstage computes ROAS + shows why sources disagree.
4. **Pricing leverage** — 0.4% revenue share aligns incentives; we profit only when merchants make better decisions, which requires trust.

## Consequences

- **UI commitment:** Every page must show six-source badges (non-negotiable).
- **Schema commitment:** `daily_snapshots` must track 7 per-source revenue columns (not space-efficient, but query-transparent).
- **Marketing commitment:** This is THE wedge in messaging, not a footnote.
- **Deferred by this decision:** Benchmarking/peer data (would dilute trust thesis with comparison noise).

## Alternatives Considered

1. **Single "Real" number** (ROAS Monster) — Users distrust opaque reconciliation; we rejected this.
2. **Toggle between sources** (Northbeam) — Northbeam requires model selection at top; we make source switching 1-click.
3. **Probabilistic weighted blend** (Fairing) — Too academic for SMB operators; direct source listing is clearer.

## Validation

- Fairing (attribution reconciliation for agencies) validates source disagreement as sellable thesis.
- Rockerbox (dedup-focused) validates that "which source claimed it?" is a real user question.
- ROAS Monster (anti-pattern we invert) validates that hiding disagreement feels like a gotcha.
