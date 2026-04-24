# Linear — dashboard UX reference

**URL:** https://linear.app
**Why it's referenced:** The benchmark for *perceived speed* in a B2B SaaS — keyboard-first, optimistic UI, obsessive alignment and contrast tuning. If Nexstage wants to feel faster than GA4 while showing more data, Linear is the UX reference, even though it's a project-management tool not analytics.

## Layout & navigation
- **Nav pattern:** Two-pane app shell — persistent left sidebar (collapsible with `Cmd+/`), main content region on the right. Sidebar is slightly *dimmer* than the content area on purpose so the focus stays on the main canvas.
- **Page structure:** Thin app header (breadcrumbs + view controls) → view toolbar (filters, grouping, sort, display options) → the main view (list / board / timeline). Consistent header chrome across all object types (issues, projects, docs, reviews).
- **Density:** High but scannable. Issue lists show ~25–30 rows on a 13" screen, each row: status icon, ID, title, assignee avatar, priority flag, labels, due date — all on one line with truncation.

## Components worth borrowing
- **Command palette (Cmd+K):** A single keyboard-invoked omnibox that covers navigation, creation, state changes, and search. Groups results by type (Actions, Issues, Projects, Views, Help). Worth cloning almost verbatim for Nexstage.
- **View toolbar:** Filter chips + Group-by + Sort + Display options live in one row above every list. Display options (which columns to show, row density, ordering) are per-view and persisted.
- **Collapsible sidebar with dimmer tone:** Two-state (expanded/icons-only), opens on hover when collapsed. The subtle color dimming vs. the main area is a small but huge move for visual hierarchy.
- **Custom views saved to sidebar:** Any filtered/grouped list can be promoted to a named view that appears in the sidebar. Solves the "dashboard sprawl" problem elegantly.
- **Inline editable properties:** Click a priority/status/assignee on any row to edit it without navigating. Essential for tables where the user wants to act, not just read.
- **Status icon as primary identity:** Every issue shows state as a small circular icon at the left (backlog = dashed, in-progress = partial fill, done = check). Pattern translates well to our order/sync/integration status indicators.
- **Progress pips and radial progress:** Projects show completion as a small donut, milestones as a linear bar. Both use the same "progress as primary indicator" design language.
- **Hover card previews:** Hovering on an issue ID anywhere in the app shows a rich preview card — title, description snippet, status, assignee. No navigation needed. Perfect for order IDs / campaign IDs in our tables.

## Interactions worth borrowing
- **Keyboard-everywhere:** `Cmd+K` command palette, `/` to filter current view, `E` to edit/assign, `G then I` to go to Issues, `C` to create. Arrow keys navigate list items. `Space` or `Enter` opens row in split pane.
- **Optimistic UI:** Status changes, assignee changes, creation all reflect instantly — the server confirmation happens in the background, errors roll back with a toast. Nothing ever shows a spinner for user-initiated state changes.
- **Split pane on row click:** Clicking a list row opens a detail panel to the right of the list instead of navigating away. Keeps context. Closes with `Esc`.
- **Multi-select with Shift+Click / X key:** Multi-select in lists with range select and bulk action toolbar at the bottom.
- **Undo toast:** Every destructive action (delete, archive, bulk move) gets a toast with a 6–10 second undo window. No modal confirmation dialogs.
- **Quick-create inline:** `C` opens a tiny modal — title + submit, all other properties inferred from current view filters. Creating 5 items takes 10 seconds.
- **Filter chip as a pill that expands:** Click the chip, get an inline popover to edit the filter value. Stacked filters read like a sentence: "Status is *In progress* AND Assignee is *me*".

## Color, typography, visual
- **Palette:** Neutral-dominant with very restrained accent use. Linear migrated to **LCH color space** for perceptually uniform scales — a yellow and red at the same L value look equally light. Custom themes are driven by just three tokens: base color, accent color, contrast. Text/icon contrast is tuned: darker text in light mode, lighter text in dark mode (they actually pushed *away* from pure-black in dark mode to reduce glare).
- **Typography:** **Inter Display** for headings (more character, tighter letterforms), regular **Inter** for body. Tabular numbers in metrics. Size hierarchy is *small* — headlines are 20–22px, not 32px. Density wins over drama.
- **Data viz style:** Linear is not data-viz-heavy, but when it does visualise (cycle burndown, project progress), it uses thin strokes, no gridlines, and only 1–2 series per chart. Donut charts use very thin rings.
- **Empty states:** Monochrome, geometric line illustrations that blend with the UI rather than cartoons. One-line explanation + one action button. Feels like part of the product, not a separate marketing moment.
- **Loading states:** Almost invisible — content renders from local cache instantly, then revalidates. When a spinner is needed, it's a thin 16px ring in the top-right of the relevant region, never blocking interaction.

## Specific screens worth stealing
- **Triage (unassigned inbox):** A single focused list of "new things that need a human decision" with big keyboard hotkeys. We should have a "needs attention" inbox on the Home screen for unmatched orders, broken integrations, campaigns missing parsed conventions.
- **Project page:** Top strip with progress donut + key dates + health + lead + team; below it a timeline/list split. Worth copying for a Campaign detail page.
- **Workflow state icons in every list:** The visual language of status is consistent across every surface. Our order statuses (pending / processing / completed / refunded) should get this treatment.
- **`New Issue` modal (C shortcut):** Tiny, fast, keyboard-driven, minimal required fields. Great model for our "New Campaign" or "Import CSV" flows.

## What NOT to copy
- **Monochrome extremism in data-viz.** Linear gets away with 1–2 color accents because it doesn't show 8-series line charts. Our attribution overlays need color to distinguish Facebook/Google/Real — don't over-neutralize.
- **Keyboard-only affordances.** Linear hides features behind shortcuts; discoverability for non-power-users is poor. We need keyboard parity *plus* visible buttons.
- **The Linear-clone aesthetic (dark, pure grayscale, Inter, thin borders) is now a cliché.** Adopt the *mechanics* (speed, alignment, density) without copying the *look* — differentiate with our gold "Real" accent and source-badge color-coding.
- **Opinionated workflow states you can't change.** Linear enforces a rigid issue state model. For analytics, our users' mental models vary more — keep the grouping/filtering flexible.

## Screenshot refs
- https://linear.app/now/how-we-redesigned-the-linear-ui (Part II, LCH color space, typography)
- https://linear.app/changelog/2026-03-12-ui-refresh (most recent visual pass)
- https://linear.app/method (opinion/principles doc)
- https://www.saasui.design/pattern/empty-state/linear (empty state gallery)
- https://shortcuts.design/tools/toolspage-linear/ (full shortcut list)
- https://review.firstround.com/linears-path-to-product-market-fit/ (product philosophy)
