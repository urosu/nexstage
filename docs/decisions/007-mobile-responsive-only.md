# ADR 007: Mobile = Responsive Web Only (No Native App v1)

**Status:** Accepted  
**Date:** 2026-04-24  
**Context:** Some analytics tools ship native iOS/Android (Shopify, Triple Whale). Nexstage has limited engineering. Decision: responsive web or native app?

## Decision

**Responsive web only in v1.** Mobile-first for Dashboard + Orders; desktop-only for complex pages (Attribution, Cohorts).

Native apps deferred to v3 if usage justifies (after reaching 1000 active workspaces).

## Rationale

1. **Engineering ROI** — Responsive web reaches 95% of use-cases (quick check on phone). Native app reaches 5% with 3× engineering cost.
2. **Field-sales UX risk** — Agencies are on desktop during client meetings; field reps use web on iPad. Desktop-responsive > native app.
3. **Update cadence** — App Store review delays (24h) vs web deploy (1min). For a trust product, speed matters.
4. **Operational burden** — Separate iOS/Android codebases, device testing, OS version support. Deferred.

## Consequences

- **Mobile card-stack layout** (not tables) — Preserves hierarchy without horizontal scroll.
- **Desktop-only page banner** — Attribution, Profit pages render "Built for desktop. Want us to email this?" → sends snapshot.
- **Email digest as mobile UI** — Weekly summary shipped to phone; addresses "I want this data on my phone" without native app.

## Alternatives Considered

1. **Native iOS first** (Triple Whale pattern) — Better UX but 3× cost; deferred.
2. **PWA with offline** — Useful for field reps with patchy connectivity; deferred to v3.
3. **Hybrid (React Native)** — Code sharing benefit outweighed by platform-specific bugs; responsive web wins.

## Validation

- Fairing (responsive web only) validates the pattern.
- Plausible (responsive web, no app) validates that web is sufficient for analytics.
