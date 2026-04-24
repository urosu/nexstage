# Stripe — dashboard UX reference

**URL:** https://dashboard.stripe.com (app) · https://docs.stripe.com/dashboard/basics (docs)
**Why it's referenced:** The canonical example of a financial dashboard that packs metrics, transactions, and charts into one dense-but-calm surface; pioneered the "number + sparkline + delta" metric card that most SaaS dashboards now copy.

## Layout & navigation
- **Nav pattern:** Left sidebar (roughly 240–260px) with two logical groups — a **Primary navigation** block (Home, Balances, Transactions, Payments, Customers, Product catalog) and a **Shortcuts** block for pinned + most recently visited pages. Top bar holds the global search, account switcher, test/live toggle, notifications, and help.
- **Page structure:** Header with page title + primary action button (right-aligned) → optional sub-tab row → filter/date strip → content region. The content region is usually either (a) 3–4 metric cards + a hero chart, or (b) a filtered table with inline facet chips.
- **Density:** Dense but calm. The home page shows ~4 KPI cards, a line chart, an alerts/insights strip, and a recent-activity list without feeling crowded — because whitespace is generous *inside* each card while cards are packed close together on the grid.

## Components worth borrowing
- **KPI card with sparkline + delta:** Four cards (e.g. Gross volume, Net volume, New customers, Successful payments) each showing a large number, a trend arrow with percent delta vs. the comparison period, and a tiny axis-less sparkline. The sparkline has no labels, no grid, no legend — pure trajectory. This is the single component most worth cloning directly for `MetricCard`.
- **Test mode toggle:** A highly visible top-bar switch that tints the entire UI orange when in test mode. Good pattern for us for "Sandbox / Demo data" vs. live.
- **Shortcuts sidebar section:** Pinned pages + recently visited auto-populated. Solves navigation fatigue in large apps without demanding the user learn new IA.
- **Global omnibox search (Cmd+K):** Searches across customers, transactions, invoices, docs, and settings in one box. Results are grouped by type with subtle section labels.
- **Skeleton loaders:** Content-shaped placeholders with a shimmer pulse instead of spinners — chart area, card area, and table rows each have their own skeleton shape.
- **Inline alert strip on Home:** "You have 2 unresolved disputes" type bar at the very top of the Home page — actionable, dismissable, and it disappears when there's nothing to alert on.
- **Compare-to picker inside the date range dropdown:** Not a separate control — the date range popover itself has a "Compare to: previous period / previous year / custom" sub-section.

## Interactions worth borrowing
- **Click-through from metric to underlying transactions:** Click the "Successful payments" card → lands on a filtered Payments table with the same date range and status filter already applied. Filters are visible as removable chips at the top.
- **Hover on chart reveals a "ghost" tooltip:** Shows the value, the compare-to value, and the delta — all three numbers stacked, with the delta color-coded.
- **Saved views:** Any filtered table can be saved as a named view that appears in the sidebar. The view remembers filters, columns, and sort.
- **Keyboard shortcut surfacing:** Pressing `?` opens a cheat sheet modal; most actions show their shortcut on hover in the menu.
- **Row-level quick actions on hover:** Transaction rows reveal "Refund", "View details", "Copy ID" icons on hover — no always-on action column cluttering the table.
- **Report export with column customiser:** Every table has a column picker that lets you toggle columns before exporting — so the CSV matches the screen.

## Color, typography, visual
- **Palette:** Neutral-dominant — whites, off-whites, near-blacks. Signature Stripe purple (#635BFF) is used sparingly, mostly for primary CTAs and brand chrome. Status colors are muted: green for success, red/orange for failed/disputed, grey for pending. Gradients are reserved for brand/marketing surfaces, never in data viz.
- **Typography:** Custom geometric sans (Sohne-derived) for UI, with a monospaced variant for IDs, amounts, and code. **Tabular numbers everywhere** — amounts in tables line up vertically. Size hierarchy is small: KPI number ~28–32px, card label ~13px, body ~14px, table cells ~13px.
- **Data viz style:** Sparse axes, soft gridlines, no legends when the chart only has one series. Area charts use a subtle gradient fade under the line. Tooltips on hover, not legends.
- **Empty states:** Illustrative but minimal — a simple line drawing + one sentence + one primary action. For tables with filters applied, a text-only "No results match your filters — clear filters" state.
- **Loading states:** Skeleton screens shaped like the content (card outlines, chart rectangles, table row stripes). No full-page spinners; the shell renders immediately.

## Specific screens worth stealing
- **Home:** Top strip of 4 KPI cards with sparklines → big gross-volume chart with compare overlay → "Today so far" widget (intra-day progress) → recent disputes/alerts strip → product shortcuts grid. The **"Today so far"** micro-widget is genius for operational dashboards: it shows today's running count + a dotted projection line against yesterday's curve.
- **Payments list:** Table with facet chips at the top (Status, Payment method, Amount, Date) that expand into inline filter popovers. Batch actions appear in a sticky footer bar when rows are selected.
- **Balance / Payouts:** Two-column layout — a timeline of payouts on the left, a breakdown of in-transit/available/reserved on the right. Worth copying for our reconciliation-style views.
- **Reports:** A library of pre-built report templates where each template shows a thumbnail preview. Clicking one instantiates a new report with your account data.

## What NOT to copy
- **Too many products crammed into one sidebar** — Stripe now has 40+ products and the sidebar has become a scrolling overflow. We are a single-purpose app; resist the temptation.
- **Test mode as a global toggle that tints the chrome** — fine for Stripe where live vs. test is a massive correctness concern, but confusing in an analytics app with no such duality.
- **Hover-only row actions on mobile/touch** — Stripe's row actions rely on hover; on tablets this hides discoverability. Provide a kebab menu as an always-visible fallback.
- **"Customize your overview" drag-and-drop** — Stripe lets you add/rearrange home widgets; in practice almost nobody customises dashboards. We should ship opinionated defaults and skip the drag handles.

## Screenshot refs
- https://support.stripe.com/questions/dashboard-home-charts-overview
- https://pageflows.com/screens/2e9c0c08-14de-4946-bfa9-b1fe8a8cacf7/ (Stripe Analytics/Stats edit flow)
- https://support.stripe.com/questions/dashboard-update-may-2024 (navigation refresh)
- https://ezdashboard.co/ (community clone that mirrors the patterns)
- https://docs.stripe.com/dashboard/basics
