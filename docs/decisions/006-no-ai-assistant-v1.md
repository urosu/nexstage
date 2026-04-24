# ADR 006: No AI Assistant in v1 (Deferred to v2)

**Status:** Accepted  
**Date:** 2026-04-24  
**Context:** Competitors ship AI (Atria Raya, Peel Magic Insights, Segments FilterGPT) to reduce cognitive load. Tempting for SMBs. Nexstage could ship a "tell me what to do" chatbot.

## Decision

**No AI assistant or NL query bar in v1.** Trust thesis is too young; AI recommendations risk eroding transparency.

FilterChipSentence + saved views cover 80% of query needs. AI is v2 after trust foundation is solid.

## Rationale

1. **Trust thesis incompatible with black-box AI** — Users already mistrust revenue numbers; adding AI that recommends "disable this campaign" without showing the math erodes trust.
2. **Atria/Segments anti-pattern** — Both ship AI recommendations that are opaque. Users ask "did you recommend this because it's true or because I'm paying more?" We reject this.
3. **FilterChipSentence is sufficient** — "Show me Not Tracked orders from April where country = US" via chips covers 95% of real queries.
4. **Saved Views** — "Saved view: Not Tracked (30d)" is repeatable; users don't re-filter.
5. **Post-launch opportunity** — v2 can ship "anomaly detector" after snapshot foundation is rock-solid.

## Consequences

- **No NL chat on home** — Search is Cmd+K for pages/saved-views; no "ask Claude anything" escape hatch.
- **No AI prescriptions** — Lifecycle chips (Rockstar/Hot/Cold/At Risk) are rule-based and auditable, not ML-scored.
- **No proactive insights** — Peel/Atria ship daily "3 actions you should take"; we don't. Users initiate questions.

## Alternatives Considered

1. **Lightweight AI to generate summaries** (Metabase-style)  — Useful but risky for trust thesis; deferred.
2. **Segment builder with NL** (Segments) — Deferred; FilterChipSentence + RFM covers v1.
3. **Anomaly detector** (Northbeam) — Shipped separately; not core to MVP.

## Validation

- Fairing (no AI; pure transparency) validates the transparency-first thesis.
- Linear (AI features are post-1.0) validates deferral as credible product strategy.
