# Architecture Decision Records (ADRs)

This directory documents major strategic decisions made during Nexstage research and planning phases.

**Format:** Each file is a single decision with Status, Date, Context, Rationale, Consequences, Alternatives Considered, and Validation.

## Current ADRs

| ADR | Title | Status | Date |
|-----|-------|--------|------|
| [001](001-source-disagreement-as-thesis.md) | Source Disagreement as Product Thesis | Accepted | 2026-04-24 |
| [002](002-pricing-model.md) | Pricing Model (€39/mo + 0.4% Revenue Share) | Accepted | 2026-04-24 |
| [003](003-multi-tenancy-architecture.md) | Multi-Tenancy via WorkspaceScope | Accepted | 2026-04-24 |
| [004](004-snapshot-based-aggregation.md) | Snapshot-Based Aggregation | Accepted | 2026-04-24 |
| [005](005-attribution-last-non-direct-click.md) | Attribution Default (Last Non-Direct Click) | Accepted | 2026-04-24 |
| [006](006-no-ai-assistant-v1.md) | No AI Assistant in v1 | Accepted | 2026-04-24 |
| [007](007-mobile-responsive-only.md) | Mobile = Responsive Web Only | Accepted | 2026-04-24 |

## How to Add a New ADR

1. Create a new file: `NNNN-slug.md` (e.g., `008-feature-flags.md`).
2. Use the template:
   ```
   # ADR NNN: Title
   
   **Status:** Accepted / Proposed / Rejected  
   **Date:** YYYY-MM-DD  
   **Context:** Problem statement  
   
   ## Decision
   
   ...
   
   ## Rationale
   
   1. ...
   2. ...
   
   ## Consequences
   
   - ...
   
   ## Alternatives Considered
   
   1. ...
   
   ## Validation
   
   - ...
   ```
3. Update this README.md with the new ADR row.
4. Reference the ADR in code comments and docs via `@see docs/decisions/NNN.md`.

## Consultation Patterns

- **Architectural questions:** Check docs/decisions/ before proposing a new approach.
- **Ambiguous requirements:** Check PLANNING.md and CLAUDE.md; if still unclear, file a new ADR as a proposal and discuss.
- **Implementation uncertainty:** If you'd implement it differently than docs specify, file an ADR to challenge the existing decision (don't code against a decision you disagree with).
