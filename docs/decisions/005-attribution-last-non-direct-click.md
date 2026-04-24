# ADR 005: Attribution Default (Last Non-Direct Click, 7d Click / 1d View Window)

**Status:** Accepted  
**Date:** 2026-04-24  
**Context:** Users see revenue attributed to different sources under different models. Defaults matter because ~80% won't customize. Options: (a) first-touch, (b) last-click, (c) last-non-direct, (d) linear.

## Decision

**Last-non-direct click** with **7-day click / 1-day view window**.

This means: "Give credit to the last ad platform that was clicked, ignoring Direct. If no ad platform, credit organic. If no organic, default to Store/Not Tracked."

## Rationale

1. **Most defensible for most merchants** — Removes Direct-order noise (typer-inners, direct bookmarks).
2. **7-day click window aligns with iOS ATT** — Apple allows 7d click attribution; using the same window meets iOS reality.
3. **1-day view window is conservative** — View-through attribution is always weaker than clicks; 1 day (vs 30d) is honest.
4. **Changeable per-workspace** — Settings page allows admin to override; default is just a safe starting point.
5. **Testable** — Real vs Attribution page shows "what if we used last-click?" or "first-touch?"; users can validate.

## Consequences

- **Underattribution risk** — Some revenue will land in "Not Tracked" (honest vs inflated platform claims).
- **Performance incentive alignment** — Merchants optimize for clicks (easy to measure); view-through (hard to prove) gets less credit.
- **Explainability:** Each order's attribution is traced in OrderDetailDrawer; users can audit.

## Alternatives Considered

1. **First-touch** — Attributes to acquisition source; useful for CAC. But hides mid-funnel retargeting value.
2. **Last-click (all)** — Includes Direct; inflates attributability but misses true platform impact.
3. **Linear** — Fair but unactionable; platforms aren't paid for linear credit.
4. **Data-driven probabilistic** (Fairing) — Requires survey data; deferred to v2.

## Validation

- Shopify Native (last-click default) validates the pattern.
- Klaviyo (last-click-in-email) validates same-channel bucketing.
- Northbeam (last-non-direct as standard) validates the choice.
