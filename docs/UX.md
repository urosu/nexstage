# UX

The single source of truth for navigation, design tokens, shared components, and interaction conventions. Every page spec in [`pages/`](pages/) references primitives by name from this file. Don't restate what's here — link to it.

- **Named UI patterns** → [`competitors/_patterns_catalog.md`](competitors/_patterns_catalog.md)
- **Metric names + formulas** → [`competitors/_crosscut_metric_dictionary.md`](competitors/_crosscut_metric_dictionary.md)
- **Button labels, tooltips, empty states, tone** → [`competitors/_crosscut_ux_copy.md`](competitors/_crosscut_ux_copy.md)

---

## 1. Design principles

Nine rules. Every design decision must be consistent with these.

1. **Show disagreement, don't hide it.** Every revenue metric carries a six-source badge row. "Not Tracked" is a visible bucket, and it can go negative when platforms over-report.
2. **Reconciliation, not truth.** We never claim a number is "real" or "true" as a sales pitch. We label sources and show gaps. (Anti-pattern source: [Hyros](competitors/hyros.md).)
3. **Few pages, deep pages.** 9 top-level routes. Each answers one real question.
4. **URL-stateful everything.** Every filter, date range, active source persists in the URL. Users share links, not screenshots.
5. **Skeleton over spinner.** Every loading state is a skeleton shimmer of the final shape. No spinners.
6. **Never empty.** During sync, pre-populated demo data with a "Demo" banner. After sync, real data. Pattern source: [Putler](competitors/putler.md).
7. **Typography as documentation.** Metric suffixes (`ROAS (7d)`, `CAC (LTV)`, `Revenue (blended)`) replace tooltips wherever possible. Pattern source: [Northbeam](competitors/northbeam.md).
8. **Keyboard first, mouse second.** Cmd+K palette is ubiquitous. Tables openable into side panels without leaving the page.
9. **Desktop-first, mobile-honest.** Three pages must work on mobile (Dashboard, Orders, Alerts). The rest are desktop only and say so.

---

## 2. Information architecture

### Routes (v1)

| # | Route | Page | What it answers |
|---|---|---|---|
| 1 | `/dashboard` | Dashboard | How is my business overall? (today / week / month) |
| 2 | `/orders` | Orders | Which orders came in, from where, with what attribution? |
| 3 | `/ads` | Ads | Which campaigns, adsets, ads, creatives are working? |
| 4 | `/attribution` | Attribution | Why don't my platform numbers match my store? |
| 5 | `/seo` | SEO | How am I ranking on Google? (GSC queries, pages, positions) |
| 6 | `/products` | Products | Which products drive revenue, margin, LTV? |
| 7 | `/profit` | Profit | What's my real P&L after COGS, shipping, fees, taxes, ad spend? Broken down how? |
| 8 | `/customers` | Customers | Who are my customers, and how do they behave? (segments, cohorts, RFM) |
| 9 | `/integrations` | Integrations | Are my connections healthy, what data am I missing? |
| 10 | `/settings/*` | Settings | Workspace, team, **costs (COGS / shipping / fees / VAT)**, billing, notifications |

Auth-only routes (`/auth/*`), onboarding (`/onboarding`), not listed in sidebar.

### Sidebar order

Dashboard · Orders · Ads · Attribution · SEO · Products · Profit · Customers · — · Integrations · Settings

Divider groups "work" vs "config". Hover-to-expand from collapsed state.

### Out of scope for v1 (intentional)

| Deferred | Why | Revisit |
|---|---|---|
| Custom SQL report builder | Templated reports solve 90% of use cases. See [Lifetimely role-based templates](competitors/lifetimely.md). | v2 |
| AI chat / assistant | Data accuracy first. | v2 |
| Benchmarking / peer data | Needs critical mass of stores. Schema-ready now. | v2, triggered by store count |
| White-label / agency portal | v1 supports multi-store, not agency branding. See [`_crosscut_multistore_ux.md`](competitors/_crosscut_multistore_ux.md). | v2 |
| Dark mode | Our ICP skews non-technical. Vercel anti-lesson. | v2 |
| Native mobile apps | Responsive web covers mobile-critical pages. See [`_crosscut_mobile_ux.md`](competitors/_crosscut_mobile_ux.md). | v2 |
| SSO / SCIM | No enterprise ICP in v1. | v2 |
| Scheduled Slack reports | Email digest v1; Slack v2. See [`_crosscut_export_sharing_ux.md`](competitors/_crosscut_export_sharing_ux.md). | v2 |

---

## 3. Global chrome

### Layout

```
┌───────────────────────────────────────────────────────┐
│  TopBar (56px, sticky)                                │
├──────┬────────────────────────────────────────────────┤
│      │                                                │
│ Side │  Content                                       │
│  bar │  (max-width 1440, fluid, 24px padding)         │
│ 240  │                                                │
│ 64↔  │                                                │
│      │                                                │
└──────┴────────────────────────────────────────────────┘
```

### TopBar (left → right)

| Zone | Contents |
|---|---|
| Left | WorkspaceSwitcher (hidden if single workspace) |
| Center | Global filters: DateRangePicker · AttributionModelSelector · WindowSelector · **AccountingModeSelector** (§5.26) · SourceToggle · BreakdownSelector · ProfitMode toggle |
| Right | **FreshnessIndicator** (§5.20) · CommandPaletteTrigger (Cmd+K) · NotificationsBell · UserMenu |

**Global filters propagate to every MetricCard, chart, and table on the page.** Per-card override is allowed but rendered subtly (subdued chip). Filter state is in URL.

### Sidebar

- Width: 240px expanded, 64px collapsed. User preference persisted.
- Logo + product name at top (clickable → `/dashboard`).
- Item = icon + label. Active state = left border accent + bold label.
- Flat structure; no nested items in v1.
- Bottom: StatusDot for "Sync health" (click → `/integrations`).

### WorkspaceSwitcher

- **Hidden entirely if user has 1 workspace.** (Zero chrome for single-store users.)
- Expanded: top-left dropdown, shows current workspace name + icon.
- Dropdown shows all workspaces + "+ Add store" + "Portfolio view" (if ≥3 workspaces).
- Cmd+K integration: type store name to switch.
- Switching preserves current route + filters if the target workspace has the same page. Falls back to `/dashboard` otherwise.

### CommandPalette (Cmd+K)

Fuzzy search across:
- Pages (go to …)
- Workspaces (switch to …)
- Orders (by ID or customer email)
- Customers (by email)
- Campaigns (by name)
- Settings (change currency, invite user, reconnect Facebook)

Recent actions shown when empty. Pattern source: [Linear](competitors/_inspiration_linear.md), [Stripe](competitors/_inspiration_stripe.md), [Vercel](competitors/_inspiration_vercel.md).

---

## 4. Design system

### Stack

Tailwind CSS (already in project). shadcn/ui as component baseline. Recharts for charts (decide vs Tremor during implementation — prefer Recharts for flexibility). No additional icon library beyond lucide-react (shadcn default).

### Color tokens

**Neutral palette** (backgrounds, borders, text):

- `bg`: zinc-50 (light mode primary surface)
- `surface`: white (cards, panels)
- `border`: zinc-200
- `text`: zinc-900 (primary), zinc-600 (secondary), zinc-400 (tertiary)

**Accent** (primary action, links, active states):

- `accent`: teal-600 (differentiates from Shopify-green and FB-blue of competitors)
- `accent-hover`: teal-700
- `accent-subtle`: teal-50

**Semantic** (status, metrics):

- `success`: emerald-600
- `warning`: amber-500
- `danger`: rose-600
- `info`: sky-600

**Source colors** (canonical across entire app — every chart, badge, legend must use these):

| Source | Token | Tailwind | Hex | Rationale |
|---|---|---|---|---|
| Store | `source-store` | slate-500 | `#64748b` | Neutral — represents DB baseline |
| Facebook | `source-facebook` | indigo-500 | `#6366f1` | Meta brand adjacent |
| Google Ads | `source-google` | amber-500 | `#f59e0b` | Distinct from semantic red |
| GSC | `source-gsc` | emerald-500 | `#10b981` | Google green for search |
| Site (GA4/first-party) | `source-site` | violet-500 | `#8b5cf6` | Separate from all platforms |
| Real (Nexstage-computed) | `source-real` | yellow-400 | `#facc15` | Gold lightbulb per thesis |

**Do not reassign these colors.** A Facebook line in a chart is always indigo-500. A Real badge is always yellow-400. This consistency is the whole trust UX.

### Typography

- **Body**: Inter, 14px base. Sans-serif.
- **Numbers**: Inter with `font-variant-numeric: tabular-nums`. Always tabular for KPI values.
- **Code / IDs**: JetBrains Mono, 12.5px. Order IDs, customer emails in tables, SQL-like metric formulas.

Size scale: 11 / 12.5 / 14 / 16 / 20 / 28 / 40. No other sizes.

Weight: 400 (body), 500 (emphasis), 600 (headings). Never 700+.

### 4.1 Metric name syntax (typography as documentation)

The canonical metric label composes up to three parts:

```
<Name> [(<Qualifier>)] [· <Source>]
```

- **Name** — metric from [`_crosscut_metric_dictionary.md`](competitors/_crosscut_metric_dictionary.md) "Our pick" column. Examples: `Revenue`, `ROAS`, `CAC`, `LTV`, `MER`.
- **Qualifier** — in parens, always present if metric carries a window or customer-type variant. Examples: `(7d click)`, `(28d)`, `(LTV)`, `(1st Time)`, `(blended)`. **Window first**, then customer-type when both apply: `ROAS (7d, 1st Time)`.
- **Source** — after a middot, only when a single source is active. Examples: `ROAS · Facebook`, `Revenue · Store`. Omitted when source badges row (§5.1) is visible.

When ProfitMode (§5.16) is on, the suffix-less name flips: `Revenue` → `Profit`; `ROAS` → `Profit ROAS`; `CAC` → `Profit-CAC`. Never prefix with "True" / "Real" (except the brand term Real Revenue) — see [`_crosscut_metric_dictionary.md`](competitors/_crosscut_metric_dictionary.md) §Naming principles rule 2.

Pattern source: [Northbeam](competitors/northbeam.md) — the gold standard.

### Density

Target: **8–15 widgets above the fold at 1440×900**. Denser than Plausible, less dense than Triple Whale. Each KPI card minimum 200×120, maximum 280×160.

### Spacing

4px grid. Use only: 4, 8, 12, 16, 24, 32, 48, 64. No other values.

### Light/dark

Light mode only in v1. Dark mode deferred (see IA out-of-scope table).

---

## 5. Shared component vocabulary

The named primitives every page reuses. Page specs reference by **PascalCase name**, not by re-describing.

### 5.1 MetricCard — the signature primitive

The load-bearing component of the thesis.

**Anatomy** (top → bottom):

1. **Source badge row** — horizontal strip of up to 6 SourceBadges. Active source is filled; inactive sources are outlined and dimmer. Clicking a badge changes the active source for this card only.
2. **Label** — metric name with suffix (`Revenue (28d)`, `ROAS (blended)`).
3. **Value** — large number (28px tabular). If `%`, space before unit. If currency, symbol before value.
4. **Delta** — arrow + percentage vs comparison period. Green up / red down semantically only — an up-arrow for "Not Tracked going up" is still green because it reflects the actual direction, even if the implication is bad. (Nuance: see anti-semantic rule in tooltip.)
5. **Sparkline** — 30-point mini-chart of the active source over the current range.
6. **ProfitMode indicator** (top-right, subtle icon) — when the global ProfitMode toggle is ON (or toggled locally on this card), the card flips from revenue-flavor to profit-flavor: "Revenue (28d)" becomes "Profit (28d)", ROAS becomes Profit ROAS, CAC becomes Profit-CAC. Source badges still apply — profit attributed to Facebook = FB-attributed revenue minus proportional costs. Metric definitions: [`_crosscut_metric_dictionary.md`](competitors/_crosscut_metric_dictionary.md) profit section.

**Interactions:**

- Click body (not badge) → navigate to pre-filtered table on the relevant page (e.g., MetricCard "Revenue" on Dashboard → `/orders?source=real&date=...`).
- Click SourceBadge → switch active source without navigation.
- Click ProfitMode icon → flip this card between revenue/profit flavor (overrides page-level ProfitMode).
- Hover value → tooltip: formula + freshness ("Synced 12 min ago").
- Hover card body → 📌 Pin icon top-right. Click → pin to user's personal "Pinned" row at top of Dashboard. Limit 8 pinned per user. Syncs across devices.
- Long-press (mobile) → same as hover.

**Variants:**

- `MetricCard` — default, 240×140
- `MetricCardCompact` — 180×80, no sparkline, for dense grids (portfolio page)
- `MetricCardDetail` — 100% width, includes delta vs previous period AND previous year, plus source-disagreement gap chip

**States:**

- Loading: full skeleton shimmer of card shape, including 6 greyed badge placeholders.
- Empty (no data in range): muted, `—` as value, subtitle "No orders in range".
- Source unavailable: that badge greyed with tooltip "Facebook Ads not connected".
- **Custom date**: when card's date range diverges from page-global, a blue-tinted chip in top-left reads `Card range: Last 7d`. Click → resets to global range. Loud because divergent dates are the #1 "why don't numbers match" support ticket ([Peel](competitors/peel-insights.md)).
- **Low confidence**: when sample size below threshold (§5.27 ConfidenceChip), metric greyed 20%, delta suppressed, sparkline desaturated, chip reads `Based on N orders — low confidence`.

### 5.1.1 MetricCardPortfolio

Variant used only when user has ≥2 workspaces in a portfolio. Extends MetricCard:

- Headline number: aggregated total across workspaces, FX-converted at transaction date (§9).
- **Contribution bar** (16–24px): horizontal segmented bar below headline. One coloured segment per workspace, sized by share of total. Deterministic color per workspace (hash of workspace ID). Max 7 visible segments; overflow → `Other (N)` grey segment.
- Hover the bar: per-workspace breakdown (native amount + FX rate + converted amount).
- **Per-workspace sparklines** (novel): stacked micro-chart of one line per workspace (max 5), in deterministic colors, showing trend divergence at a glance.
- "% of portfolio" chip appears when drilled into single workspace from Portfolio view.

Source badges, ProfitMode, Breakdown all still apply. Pattern source: [Glew](competitors/glew.md) (benchmark). Novel extension: per-workspace sparkline overlay.

### 5.1.2 MetricCardMultiValue

For skewed distributions: three small stacked values side-by-side (Mean / Median / Mode). Used only for AOV on Dashboard, AOP on Profit page, LTV variants on Customers. Never more than three values — anything more goes in a chart. Pattern source: [Triple Whale](competitors/triple-whale.md) AOV tile.

### 5.2 SourceBadge

Small pill: 20×20 icon circle + optional label. Canonical colors (see §4). Tooltip on hover: "Source: Facebook Ads · Last sync: 12 min ago". Use inside MetricCard, in chart legends, in table cells indicating source of a row.

### 5.3 DateRangePicker

- Presets: Today, Yesterday, Last 7d, Last 30d, Last 90d, MTD, QTD, YTD, Custom.
- Comparison: **Previous period (day-of-week aligned)** (default for ranges ≤ 28d — shifts by whole weeks so Mon compares against Mon), Previous period (plain), Previous year, None, Custom.
- Sits in TopBar. URL-stateful (`?from=2026-03-01&to=2026-03-31&compare=prev_period`).
- **Week boundaries match Shopify** (Sunday → Saturday) to avoid off-by-one confusion when users cross-check.

### 5.4 FilterChipSentence

Shows current filter state as an editable sentence above content:

> Showing **orders** from **Last 30d** where **Source = Facebook** and **Status = Completed**

Each bold span is a removable chip. `+` at end adds a new filter. Pattern source: [Linear](competitors/_inspiration_linear.md), [Plausible](competitors/_inspiration_plausible.md).

### 5.5 DataTable

- Sortable columns. Click header → sort ASC/DESC/none.
- Sticky header on scroll.
- Column picker icon in toolbar → modal to add/remove columns. Column sets savable per-user-per-table ("Saved views").
- Row click → DrawerSidePanel with full detail. Does NOT navigate.
- Default page size: 50. Infinite scroll (not pagination) for list pages.
- CSV export button in toolbar (exports current filter + sort). See §5.30 ExportMenu for full export flow.
- Loading: 5 skeleton rows.
- Empty (filter returns zero): illustration + copy from [`_crosscut_ux_copy.md`](competitors/_crosscut_ux_copy.md).

### 5.5.1 Inline-editable cell (DataTable sub-primitive)

Any DataTable cell can be marked `editable`. Click → cell transforms into an input with current value selected; Enter saves; Esc reverts. Changes are **optimistic** (see §6.2) — the cell shows the new value instantly with a subtle "saving" ring; rollback toast on server failure. No explicit "Save" button.

Used for: per-product COGS, per-campaign convention overrides, per-metric workspace targets, chart annotations. Pattern source: [Linear](competitors/_inspiration_linear.md), [Glew](competitors/glew.md) (inline COGS on products).

### 5.6 Chart primitives

Names used in page specs:

| Component | Use |
|---|---|
| `LineChart` | Time series. Multi-source overlay uses canonical source colors. Rightmost incomplete period (today, WTD, MTD) renders as **dotted segment at reduced opacity, always-on, not disableable**; tooltip reads "Partial — range closes [X] hours from now". Prevents users over-reading the dip. Pattern source: [Plausible](competitors/_inspiration_plausible.md). |
| `BarChart` | Categorical comparisons. Stacked variant for contribution. |
| `Sparkline` | Inside MetricCard + per-store in portfolio. |
| `CohortHeatmap` | Retention grid. Rows = cohort, columns = time-since-acquisition. Color scale = retention rate. |
| `DaypartHeatmap` | 7×24 grid (rows = day of week, columns = hour-of-day). Cell = metric value (CPA, sales, purchases). Used on `/ads` ad scheduling, `/products` top-10 SKU sales. Pattern source: [Putler](competitors/putler.md). |
| `FunnelChart` | Horizontal bars (rows), each labeled with stage name · absolute count · drop-off % from prior step. Bar width scales with session count. Sticky top bar shows overall conversion. **Never stacked bars for funnels** ([Plausible](competitors/_inspiration_plausible.md)). [BigCommerce](competitors/bigcommerce-analytics.md) Purchase Funnel as benchmark for DTC. |
| `QuadrantChart` | Scatter with 1–100 relative indexing ([Northbeam](competitors/northbeam.md) pattern). |
| `LayerCakeChart` | Stacked cohort revenue over time ([Daasity](competitors/daasity.md) pattern). For LTV on Customers page. |

All charts: tooltip on hover, click to filter, right-click opens ContextMenu (§5.21).

**GranularitySelector** — every LineChart includes a local-toolbar switcher: `Hourly · Daily · Weekly · Monthly`. Available granularities depend on date range (hourly only when range ≤ 3 days; monthly only when range ≥ 60 days). Default: **Weekly** when range ≥ 14 days, otherwise Daily. URL-stateful. Pattern source: [Northbeam](competitors/northbeam.md).

### 5.6.1 ChartAnnotationLayer

Cross-cutting layer available on every time-series chart. Renders user-authored and system-authored events as dashed vertical markers with a flag label (e.g., "BFCM kickoff", "Facebook token expired", "COGS updated"). Hover the flag: full annotation text + author + timestamp.

- Right-click any point on a time series → "Add annotation here" (via ContextMenu §5.21).
- Annotations are workspace-scoped, visible across every chart whose range covers the annotation date.
- System-authored annotations (integration disconnects, attribution model changes) cannot be deleted, only hidden per-user.
- Annotations survive to CSV export (as footnote rows) and scheduled email digests (inline list at bottom).

Pattern source: [Peel](competitors/peel-insights.md) Annotations.

### 5.7 EmptyState

Three flavors:

- **Syncing**: skeleton + "Importing 4,200 of 18,400 orders (23%) — est. 8 minutes". Pattern source: [Lifetimely](competitors/lifetimely.md). Copy in [`_crosscut_ux_copy.md`](competitors/_crosscut_ux_copy.md).
- **No data in range**: illustration + "No orders between Mar 1 and Mar 7" + button "Expand to Last 30d".
- **Pre-integration**: "Connect Facebook Ads to see this page" + primary CTA "Connect Facebook Ads".

### 5.8 LoadingState

Always skeleton shimmer of the final shape. Colors from zinc-100 → zinc-200 → zinc-100 at 1.5s pulse. Never spinners. Never blur overlays.

### 5.8.1 Tab-title status channel

For long-running operations (historical sync, report generation, scheduled export rendering):

- Favicon animates (pulsing ring) while operation runs; reverts on completion.
- Tab title gets a prefix: `▶ Importing (42%) · Nexstage` → `✓ Import complete · Nexstage` → normal.
- Operation completion triggers a Toast even if the user is on a different tab.

Pattern source: [Vercel](competitors/_inspiration_vercel.md).

### 5.9 StatusDot

4px filled circle + optional label.

| Color | Meaning |
|---|---|
| emerald-500 | Healthy (synced, recent, no errors) |
| amber-500 | Warning (stale > 2h, partial sync, rate-limited) |
| rose-600 | Failed (disconnected, auth expired, API down) |
| zinc-400 | Not connected |

Used: in sidebar footer for aggregate health, on Integrations page per integration, in tables for per-row status.

### 5.10 DrawerSidePanel

- Slides in from right, 480px wide (desktop) / full-width (mobile).
- Triggered by row click in DataTable.
- Contains: entity detail + related entities + quick actions.
- Close: Esc, click outside, close button.
- URL-stateful (`?order=12345`) so detail views are shareable.

### 5.11 AlertBanner

Horizontal banner above content. Three priorities:

| Severity | Color | Dismissable | Example |
|---|---|---|---|
| info | sky-50 / sky-800 text | Yes | "Historical import complete (18,400 orders)." |
| warning | amber-50 / amber-900 text | No (until resolved) | "Facebook Ads last synced 6 hours ago — reconnect." |
| danger | rose-50 / rose-900 text | No | "Your trial ends in 3 days." |

### 5.11.1 DemoBanner (AlertBanner variant)

Pinned non-dismissible banner across full content width when workspace `state = 'demo'` or when a MetricCard is rendering sample data during backfill:

> "You're viewing the Acme Coffee demo workspace. [Connect your store] to see your own numbers — takes 30 seconds."

- Sky-50 background (info-tinted).
- Orange-50 border-left (subtle "not-real" signal without overclaiming).
- Always includes concrete CTA "Connect your store" that opens the onboarding step.
- When partial-demo (some cards real, some demo during backfill): each demo card gets a small `Demo` chip in its corner; banner says "Some metrics are still demo data while we import yours. [See import progress]".

Pattern source: [Putler](competitors/putler.md). Anti-pattern: empty dashboards with dash placeholders ([Triple Whale](competitors/triple-whale.md) on first sync).

### 5.12 Toast

Bottom-right corner. 5-second auto-dismiss. Undo button on destructive actions (pattern source: [Linear](competitors/_inspiration_linear.md) optimistic UI).

### 5.13 KpiGrid

Container primitive. Fixed 2/3/4/6 column grids. Responsive: 4→2 at <768px, 6→3 at <1024px. Used on Dashboard and per-entity pages.

### 5.14 TrustBar (Nexstage-specific, Nexstage-owned)

The thesis-expressing component. A horizontal strip showing:

```
  Store: 1,249 orders  |  Facebook: 1,198 (−51)  |  Google: 1,221 (−28)  |  Real: 1,249  |  Not Tracked: +0
```

Each cell is an entity with count + delta from Store baseline. "Not Tracked" shown as signed value. Click any source → drill into "Which orders are missing from Facebook?". Lives on Dashboard and Attribution pages.

**ToggleGroup** at bar top: `Orders / Revenue` — switches cell format. Default = Orders (the simplest disagreement to explain). Revenue shows the same structure as currency values per source with delta vs Store. URL-stateful (`?trust=revenue`).

### 5.15 BreakdownSelector

Dropdown in TopBar. Groups the entire page's charts and tables by a single dimension.

**Canonical dimension set**: `None` (default) · `Country` · `Channel` · `Campaign` · `Ad set` · `Ad` · `Product` · `Device` · `Customer segment` · `Platform` · `Search Appearance` · `Page`.

**Naming**: always `Ad set` (two words, lowercase s). Not `Adset`, not `AdSet`. Page specs must use this canonical form.

**Per-page allowlist** (available dimensions per page — `•` = enabled, `—` = disabled with tooltip):

| Dimension | `/dashboard` | `/orders` | `/ads` | `/attribution` | `/seo` | `/products` | `/profit` | `/customers` |
|---|---|---|---|---|---|---|---|---|
| None | • | • | • | • | • | • | • | • |
| Country | • | • | • | • | • | • | • | • |
| Channel | • | • | — | • | — | • | • | • |
| Campaign | • | • | • | • | — | • | • | • |
| Ad set | — | — | • | • | — | — | — | — |
| Ad | — | — | • | — | — | — | — | — |
| Product | • | • | — | • | — | — | • | • |
| Device | — | • | • | • | • | — | — | — |
| Customer segment | • | • | — | • | — | • | — | • |
| Platform | • | • | • | • | — | • | • | — |
| Search Appearance | — | — | — | — | • | — | — | — |
| Page | — | — | — | — | • | — | — | — |

Behavior:

- When active, MetricCards show the top 3 values stacked vertically within the card (e.g., `US $42k · DE $18k · UK $12k`). Charts become grouped/stacked by the dimension. Tables add the dimension as a leading column.
- URL-stateful (`?breakdown=country`).
- Pattern sources: [RoasMonster](competitors/roasmonster.md) four-level hierarchy Total → Country → Shop → Product; [Shopify Plus ShopifyQL](competitors/shopify-plus-reporting.md) `BY country`.

### 5.16 ProfitMode toggle

Small switch in TopBar next to BreakdownSelector, labeled **"Revenue / Profit"**. When flipped to Profit:

- Every revenue-flavored MetricCard on the page swaps to its profit equivalent (see 5.1 step 6).
- Every chart showing revenue swaps to profit.
- Tables gain Profit and Margin % columns where Revenue is shown.
- Source badges still apply — profit is source-attributed, same as revenue.

URL-stateful (`?mode=profit`). Off by default. Pattern source: [Lifetimely](competitors/lifetimely.md) role-based dashboards where the CFO template defaults profit-on.

### 5.17 ProfitWaterfallChart

Horizontal waterfall showing: Gross revenue → (discounts) → (refunds) → Net revenue → (COGS) → (shipping) → (transaction fees) → Contribution margin → (ad spend) → Gross profit → (OpEx) → Net profit.

- Positive bars = emerald; negative = rose.
- Hover each bar: source + formula + % of gross revenue.
- Click a deduction bar → drill into the underlying transactions/costs.
- Used on `/profit` (primary) and in a compact form on `/dashboard` when ProfitMode is on.
- Cost inputs come from `StoreCostSettings` (Settings → Costs). Missing costs render as a dashed bar labeled "Not configured — click to add".

### 5.18 EntityHoverCard

Hovering any entity ID (order, customer, campaign, ad, product, workspace) anywhere in the app shows a rich preview card after 400ms dwell: name/title, 3–5 key metrics, status badge, primary action. Click the ID opens full detail (DrawerSidePanel or page).

- IDs rendered with `MiddleTruncate` utility (`ord_abc...xyz789`) preserve suffix readability.
- Use JetBrains Mono for the ID itself (see §4).
- Tab order reaches the hover card via focus; Esc dismisses.

Pattern source: [Linear](competitors/_inspiration_linear.md); utility source: [Vercel Geist](competitors/_inspiration_vercel.md) MiddleTruncate.

### 5.19 SavedView

Any filter + group + sort + columns + date-range combination on a list/breakdown page can be promoted to a named SavedView. Saved views appear in the sidebar under the owning page (e.g., `Ads › US Prospecting`, `Ads › EU Retargeting`). URL-stateful; same URL opens the same view for any teammate with access.

- "Save view" button in every page toolbar.
- Workspace-shared by default; private views opt-in ([Northbeam](competitors/northbeam.md) pattern).
- Max 12 pinned views per user per page; overflow accessible via Cmd+K.

Pattern source: [Stripe](competitors/_inspiration_stripe.md), [Linear](competitors/_inspiration_linear.md), [Metorik](competitors/metorik.md).

### 5.20 FreshnessIndicator

Top-right of every data page, between UserMenu and NotificationsBell. Shows relative time:

- `● Live` (< 2 min, pulsing green)
- `Updated 3 min ago` (< 1h, neutral)
- `Updated 3h ago` (amber chip) at 1–24h
- `Last synced 3d ago` (rose chip) over 24h

Clicking opens a popover with per-source sync table:

| Source | Last sync | Next sync | Status |
|---|---|---|---|
| Store (Shopify) | 2 min ago | 13 min | Healthy |
| Facebook Ads | 14 min ago | 16 min | Healthy |
| Google Ads | 2h ago | — | Rate-limited until 14:22 |
| GSC | 18h ago | 6h | Delayed (Google 48h delay) |

Pattern source: [Stripe](competitors/_inspiration_stripe.md) "Live" dot, [Vercel](competitors/_inspiration_vercel.md) relative deploy timestamps. Anti-pattern: [GA4](competitors/_inspiration_ga4.md) silent 24h latency, [Putler](competitors/putler.md) "Pulse" on 15-min-stale data.

### 5.21 ContextMenu

Right-click any data cell, chart point, chart bar, table row, or legend chip opens ContextMenu. Fixed item set (not page-configurable):

- **Filter to this** — adds value as filter chip to page FilterChipSentence and re-queries.
- **Exclude this** — adds negated filter chip.
- **Create segment from this** — opens segment builder with selection prefilled (v2).
- **Open in …** — page-specific escape hatch (e.g., cell in Ads → "Open in Attribution").
- **Copy value**
- **Copy link** — URL that opens this exact row/point with filter applied.
- **Add annotation here** — only on chart points (see §5.6.1).

### 5.22 TriageInbox

A focused list of items needing a human decision. Rendered on Dashboard (compact, top of page) and as a full page at `/alerts` (mobile-first tier — see §8). Never grows beyond 20 items; older items archived silently. Items come from:

- **Integration failures** (Facebook token expired, Google Ads rate-limited > 24h).
- **Attribution anomalies** (Not Tracked delta crossed ±15%, platform over-report > 20%).
- **Data-quality issues** (campaigns missing parsed_convention, orders with unmatched attribution).
- **Commerce events** (disputes, refund spike, inventory risk).

Each item: severity chip · title · one-sentence context · primary action ("Reconnect Facebook", "Review 42 unmatched orders") · dismiss. Dismissed items do not return unless they recur. Every item deep-links to an explanatory view (never a dead-end alert).

Pattern source: [Linear](competitors/_inspiration_linear.md) Triage, [Stripe](competitors/_inspiration_stripe.md) home alert strip. Anti-pattern: [GA4](competitors/_inspiration_ga4.md) undismissable ML callouts.

### 5.23 Target

Workspace-level goals attach to any metric. Targets render three ways:

- **TargetProgress** — thin horizontal bar beneath a MetricCard's headline number showing `$42k of $60k (70%)`. Fills green when on pace, amber when behind trend, rose when terminal miss.
- **TargetLine** — horizontal dashed line on a LineChart at target value, labelled at right edge. Pairs with pacing visualization.
- **Pacing variant** — chart also shows a dotted "on-pace" trend line the metric must meet to close the month on target.

Targets are Settings → Targets scope; authored by Admin+. Non-goal metrics never show target chrome.

Pattern source: [Putler](competitors/putler.md) Pulse, [Lifetimely](competitors/lifetimely.md) forecast-as-goal, [Polar](competitors/polar-analytics.md) Custom Targets, [RoasMonster](competitors/roasmonster.md).

### 5.24 ActivityFeed

Chronological stream of commerce events (orders, refunds, disputes, subscription cancels) with source badge per row. Newest at top, auto-scrolls with subtle slide-in animation on new arrivals. "Pause stream" toggle freezes updates while reading.

- Fixed row schema: timestamp (relative, e.g., "12s ago") · entity icon · masked entity (e.g., `b***@gmail.com`) · value · source pill (§5.2).
- Refresh cadence: visible in header (`Auto-refresh · every 10s`); matches platform data freshness, never marketed as faster.
- Click row → DrawerSidePanel (entity detail).

Pattern source: [Putler](competitors/putler.md), [Shopify Native](competitors/shopify-native.md) Live View, [Triple Whale](competitors/triple-whale.md) Live Orders. Anti-pattern: real-time branding on delayed data ([Putler](competitors/putler.md)) — freshness pill must be truthful.

### 5.25 TodaySoFar

Intra-day progress widget. Shows today's running count against a projection line derived from the same-day-last-week curve at the same hour-of-day. Three layers on one chart:

- Solid line: today's actual cumulative.
- Dotted ghost line: same weekday last week, same hour range.
- Shaded band: last 4 same-weekdays' P25–P75 range.

Headline number with "projected end-of-day" range annotation (honest range, not point estimate).

Used on Dashboard (always-on) and as a sidebar widget on Orders during BFCM / launch periods.

Pattern source: [Stripe](competitors/_inspiration_stripe.md) "Today so far", [Shopify Native](competitors/shopify-native.md) Live View.

### 5.26 AccountingModeSelector (global, TopBar)

Sits in TopBar center, right of WindowSelector. Pill toggle: `Cash Snapshot / Accrual Performance`.

- **Cash Snapshot** — revenue counts on order date; spend counts on spend date. Reporting/budgeting-aligned (matches Shopify's P&L).
- **Accrual Performance** — revenue attributes back to the click/impression date that drove it; spend stays on spend date. Media-buying-aligned (matches ad platforms).

When a page switches mode, every metric recomputes with a 200ms transition. URL-stateful (`?mode=cash` vs `?mode=accrual`). Default: **Cash Snapshot** (matches what merchants cross-check against).

Pattern source: [Northbeam](competitors/northbeam.md) — the signature differentiator we're copying verbatim.

### 5.27 ConfidenceChip

Grey-tinted pill rendered below any metric computed on n < `workspace.confidence_threshold` (default: 30 orders / 100 sessions / 1000 impressions, per metric type). Text: `Based on N orders — low confidence`. When present:

- Delta % is suppressed (no green/red arrow).
- Sparkline is desaturated.
- The metric value itself is greyed 20%.
- Tooltip explains: "Too few samples to detect a reliable trend. Wait for more data, or widen your date range."

Never hide a metric for low confidence — disclosure beats suppression.

Pattern source: [Varos](competitors/varos.md). Anti-pattern: [MonsterInsights](competitors/monsterinsights.md) deltas on n=3 samples.

### 5.28 SignalTypeBadge

Small superscript chip next to any metric whose value is partially or fully modeled:

- `Deterministic` — green outline, solid matching-source color fill. Appears when data is 100% observed (click IDs matched, pixel data confirmed).
- `Modeled` — amber outline, same source color fill at 50% opacity. Appears when ≥30% of the metric is computed from ML attribution models (not directly observed). Example: "Meta attributed this order, but 40% was predicted views."
- `Mixed` — half-split chip when attribution model combines both (e.g., Northbeam Clicks + Modeled Views). Appears when the metric blends observed and modeled data.

**Badge appearance thresholds:**
- Show badge when any single source is ≥30% modeled or ≥50% mixed.
- Hide badge when all sources are ≥95% deterministic (observed without inference).

Click the badge → popover explains methodology, sample size, confidence interval. Hover → one-sentence summary.

For entire surfaces that need calibration, an amber AlertBanner (§5.11) announces the state: "Modeled Views are calibrating — 18 of 30 days. Numbers become reliable on May 12." Pattern source: [Northbeam](competitors/northbeam.md) day-30/60/90 framing.

### 5.29 ShareSnapshotButton

Toolbar button on every data page. Click → modal with:

- Generated URL `https://app.nexstage.com/public/snapshot/{token}` (no login required).
- "Lock date range to [current range]" toggle (default on — snapshot is time-frozen).
- Expires in: `24h · 7d · 30d · never` (default 30d).
- "Revoke all links" button in a "Manage links" tab.

Snapshot URL renders a mobile-friendly read-only view of the same page, using a point-in-time copy of `daily_snapshots` data. No interactivity; all filters visible as static FilterChipSentence at top.

Pattern source: [Triple Whale](competitors/triple-whale.md), [Metabase](competitors/metabase.md), [Looker Studio](competitors/looker-studio.md). v1 scope per [`_crosscut_export_sharing_ux.md`](competitors/_crosscut_export_sharing_ux.md).

### 5.30 ExportMenu

Primary button label "Export" (never "Download"). Opens dropdown:

- **CSV** — server-rendered, includes six-source columns (`revenue_store`, `revenue_facebook`, `revenue_google`, `revenue_gsc`, `revenue_site`, `revenue_real`, `not_tracked`), never collapsed to a single revenue. Download starts immediately if < 10k rows; otherwise opens a "Preparing export" modal with email-on-complete.
- **Schedule email** — opens scheduler modal (v1). Scheduler ALWAYS includes a `Send test now` button before Save that delivers the exact payload to the current user. Recipient field accepts **any email** (commas between multiple) — recipient does not need a Nexstage account (prevents seat inflation).
- **Send to Slack** — on-demand, top-10 rows + link (v1).
- **Google Sheets** — v2 (greyed, "Coming in v2" tooltip).
- **PDF** — agency tier only (v2 greyed).

Per page, not per widget — never nest export inside a card's kebab menu (Metabase anti-pattern).

### 5.31 SubNavTabs

URL-stateful tab strip for sibling views inside a single page. Preserves global TopBar filters across tabs; per-tab URL params scoped to the active tab (`?tab=queries&sort=...`).

Used on `/customers` (Segments · Retention · LTV · Audiences), `/seo` (Queries · Pages · Countries · Devices · Search Appearance), `/integrations` (Connected · Tracking Health · Historical · Channel Mapping). Distinct from sidebar routing (new route) because tabs share data scope.

Rendering: horizontal pill row beneath the page header. Active tab has accent-color underline. On mobile: horizontal-scrollable strip. Pattern source: [`_patterns_catalog.md` "Tab-based sub-nav within a page"](competitors/_patterns_catalog.md).

### 5.32 ViewToggle

Pill segmented control for swapping the primary visualisation of the same dataset. Distinct from `SubNavTabs` because the underlying query is identical — only the render changes.

Used on `/ads` (Table · Creative Gallery · Triage), `/customers` Retention (Heatmap · Curves · Pacing), `/profit` time-grain toggle (Monthly · Weekly · Quarterly).

URL-stateful (`?view=triage`). Default view is page-specified. Pattern source: [Peel](competitors/peel-insights.md) three-view cohort toggle.

### 5.33 LetterGradeBadge

Superscript A/B/C/D chip bucketing entities by relative performance (percentile within workspace over active window).

- `A` = emerald, top decile.
- `B` = sky, 60–90th percentile.
- `C` = amber, 30–60th percentile.
- `D` = rose, bottom 30%.

Greyed when `ConfidenceChip` threshold unmet. Used on `/ads` Creative Gallery + Triage. Pattern source: [Atria](competitors/atria.md).

### 5.34 StatStripe

3–6 inline label-value pairs rendered as a single horizontal band beneath a thumbnail or entity header. Uses tabular-nums, no badges, no sparklines. Compact alternative to `MetricCardCompact` when you need many metrics under one entity.

Examples:
- `/ads` Creative Gallery: `Spend · CTR · Thumb-Stop · Hold Rate · ROAS · CAC`
- `/orders` OrderDetailDrawer summary row: `Total · Items · Customer type · Fulfillment`
- `/customers` Customer drawer: `Orders · LTV · AOV · Avg Days Between Orders · Predicted Next Order · Churn Risk`

Pattern source: [Motion](competitors/motion.md) creative-card stat strip.

### 5.35 AudienceTraits

4–8 small "top-N attribute" chips (top countries, top channels, top discount codes, gender mix, typical order cadence) rendered as a compact grid under a segment or entity.

Each chip: label · top 3 values + share of the segment. Used on `/customers` Audiences, `/customers` Customer detail drawer, `/products` drawer (top purchasing segments). Pattern source: [Peel Audience Traits](competitors/peel-insights.md).

### 5.36 TouchpointString

Compact per-entity journey representation: source icons in canonical colors joined by arrows. Overflow renders as `+N`; clicking opens the full journey (Customer Journey Timeline in a drawer).

Format: `Facebook → Google → Direct` (short names). Icons at 14px, tabular-monospace for the arrows. Canonical source colors (§4).

Used in table cells on `/orders` (Touchpoints column), `/ads` (AdDetail drawer), `/attribution` (Customer Journey cards), `/customers` (Customer drawer). All four must reference this primitive — no page-local reimplementation. Pattern source: [Northbeam Orders](competitors/northbeam.md).

### 5.37 Entity (Vercel Geist primitive)

A row primitive for listing entities (integrations, team members, invoices, workspaces, saved views). Composition:

`[icon] [primary label] [metadata chip row] [trailing action slot]`

- Icon (24px): logo, source badge, avatar, or semantic glyph.
- Primary label: the entity name (link to detail).
- Metadata chip row: 2–4 small labels (`Status · Last active · Role · Connected by X`).
- Trailing action slot: right-aligned controls (kebab, Reconnect button, Role dropdown, etc.).

Used by `/integrations` (connector cards are `Entity` instances), `/settings/team` (member rows), `/settings/billing` (invoice rows), `WorkspaceSwitcher` (workspace rows). Pattern source: [Vercel Geist](competitors/_inspiration_vercel.md) Entity component.

---

## 6. Interaction conventions

| Convention | Rule |
|---|---|
| URL state | Every filter, date range, active source, sort, pagination is in the URL. No client-only state survives a refresh. |
| Filter propagation | TopBar global filters apply to every MetricCard, chart, and table on the page unless a per-card override is set (rendered as subtle chip). |
| Click metric body | Navigate to pre-filtered table on relevant page. |
| Click source badge | Switch active source without navigation. |
| Click chart point/bar | Drill into filtered table showing that segment. |
| Click table row | Open DrawerSidePanel (not a new page). |
| Double-click chart | Open explorer view for that metric (v2; v1 opens side panel). |
| Right-click chart/cell | Context menu: "Filter to this", "Create segment from this", "Copy value". |
| Hover metric value | Tooltip with formula + source + freshness. |
| Cmd+K | Open CommandPalette from any page. |
| Cmd+/ | Open keyboard shortcuts help modal. |
| Esc | Close drawer, modal, palette. |
| / | Focus the nearest search input. |
| Optimistic writes | User actions feel instant; rollback with Toast on server failure. See §6.2 for full spec. |
| Retroactive recalc | Changing window (7d → 28d) recomputes all historical values on the page. Pattern source: [Klaviyo](competitors/klaviyo.md). |
| SWR (cache-first revalidation) | Content renders from local cache instantly; a 14px ring spinner in the top-right of the revalidating region signals freshness check; never blocks interaction on cached content. Aligns with Inertia partial reloads. Pattern source: [Vercel](competitors/_inspiration_vercel.md), [Linear](competitors/_inspiration_linear.md). |

### 6.1 Click hierarchy on data primitives

| Target | Left-click | Right-click |
|---|---|---|
| MetricCard body | Navigate to filtered page (§5.1) | ContextMenu (Copy formula / Pin / Open in Explorer) |
| SourceBadge in MetricCard | Switch active source (§5.1) | ContextMenu (Connect source / View source details) |
| Chart point or bar | Drill into filtered table | ContextMenu |
| Chart line / legend chip | Toggle visibility | ContextMenu |
| DataTable cell (non-header, non-editable) | Add filter for this value ([Plausible](competitors/_inspiration_plausible.md)-style) | ContextMenu |
| DataTable cell (editable) | Enter inline edit mode (§5.5.1) | ContextMenu |
| DataTable row (blank area) | Open DrawerSidePanel | ContextMenu |
| DataTable header | Sort (asc / desc / none cycle) | ContextMenu (Pin column / Hide / Customize) |
| Entity ID anywhere | Navigate to entity | ContextMenu |

### 6.2 Optimistic writes + undo window

Every user-initiated state change is **optimistic**:

1. UI reflects the new value instantly.
2. Request goes to server in background with a subtle "saving" ring indicator.
3. On success → ring fades; no notification (silence = success).
4. On failure → value reverts with slight shake animation + Toast: `Could not save — [one-line reason]. Retry`.

**Destructive actions** (delete view, remove integration, archive workspace) skip the confirmation modal and use a Toast with a 10-second undo window instead: `Deleted "US Retargeting" view · Undo`. Only true data-loss actions (delete workspace with billing implications) still use a typed-confirmation modal.

Never use browser-native `confirm()` dialogs. Pattern source: [Linear](competitors/_inspiration_linear.md).

---

## 7. Attribution model behavior (load-bearing)

The thesis requires careful, consistent behavior across every page that shows revenue.

### 7.0.1 Global selector stack

The global selectors compose — they are not independent. Display order in TopBar (left→right):

`AttributionModelSelector · WindowSelector · AccountingModeSelector · SourceToggle · BreakdownSelector · ProfitMode`

All URL-stateful. All propagate to every card, chart, table on the page. Per-card override allowed but rendered as subdued chip (see §5.1 Custom date state). Changing any of them triggers a Klaviyo-style retroactive recalc with a brief `"Recomputing…"` banner.

### Global selectors (TopBar)

- **AttributionModelSelector**: First-touch · Last-touch · Last-non-direct · Linear · Data-driven.
- **WindowSelector**: 1d · 7d · 28d · LTV.
- **AccountingModeSelector**: Cash Snapshot · Accrual Performance. (See §5.26.)
- **SourceToggle**: multi-select of the 6 sources, defaults to `[Real]`.

All are URL-stateful and propagate to every metric on the page.

### Source-disagreement surfacing

- MetricCard always shows the SourceBadge row, even when only Real is active (dimmer when not active).
- Hover on a non-Real source shows: "Facebook reports $45,200 — differs from Store by +$2,100 (+4.9%)".
- TrustBar on Dashboard and Attribution shows the row of platform-by-platform counts with deltas.

### "Not Tracked"

- Computed: `Store - max(platform_attributed)` per channel. Can go negative.
- Negative value = platforms over-report (more attributed revenue than actual orders exist).
- Positive value = platforms under-report (tracking gaps).
- Clickable bucket — shows the orders missing from each platform.

### Defaults

- Default attribution model: Last-non-direct click. (Most pragmatic for SMBs.)
- Default window: 7d click / 1d view. (Matches Meta default; avoids complaints.)
- Default source: Real.
- Default ProfitMode: Off (revenue view).
- Default Breakdown: None.
- All defaults overridable per-workspace in Settings.

### Profit variants of attribution metrics

Every revenue-flavored attribution metric has a profit equivalent, reachable via the ProfitMode toggle. Nexstage computes profit as source-attributed: Facebook-attributed profit = FB-attributed revenue × gross margin − allocated ad spend.

| Revenue metric | Profit equivalent | Formula |
|---|---|---|
| ROAS | Profit ROAS | Profit / Ad spend |
| ROAS | Contribution ROAS | (Revenue − COGS) / Ad spend |
| CAC | Profit-CAC | CAC / (Contribution margin per order) |
| LTV | Profit LTV | Σ (order revenue × gross margin) per customer |
| MER | Profit MER | Total profit / Total ad spend (blended) |
| AOV | AOP (Avg order profit) | Profit / Orders |

Full definitions: [`_crosscut_metric_dictionary.md`](competitors/_crosscut_metric_dictionary.md). Never call these "True ROAS" — that framing is the [Hyros](competitors/hyros.md) anti-pattern.

### Cost inputs

All profit math depends on cost data configured in Settings → Costs. Required inputs per workspace:

- **Per-product COGS** — from product variant meta, CSV upload, or platform native.
- **Shipping costs** — flat, per-order, or weight-tiered.
- **Transaction fees** — per connected payment processor (Shopify Payments, Stripe, PayPal).
- **Platform fees** — Shopify subscription, app subscriptions.
- **Taxes / VAT** — per-country rates, or "included in price" toggle.
- **OpEx allocation** — monthly fixed (salaries, rent, tools) spread per-order or per-day.

Missing inputs make ProfitMode degrade gracefully: cards render with amber StatusDot + "Add COGS to see profit" CTA. We do not fake or estimate costs.

---

## 8. Responsive stance

Three tiers of mobile support. Per-page spec must declare which tier it targets.

| Tier | Works on | Pages / surfaces |
|---|---|---|
| Mobile-first | 375×812 upward | `/dashboard`, `/orders`, `/alerts` (notifications) |
| Mobile-usable | 768×1024 upward | `/ads` (lists + Creative Gallery 2-col), `/seo`, `/products`, `/profit` (KPI + Waterfall; P&L table horizontal-scrolls), `/customers` (Segments + LTV tabs; CohortHeatmap blocked <1280px), `/integrations`, `/settings/workspace|team|billing|notifications|targets` |
| Desktop-only | 1280×800 upward | `/attribution` (Source Disagreement Matrix, Model Comparison, Attribution Time Machine), `/customers` Retention's `CohortHeatmap`, `/settings/costs` (inline-editable tables), `QuadrantChart`, `DaypartHeatmap`, custom report builder (v2) |

Breakpoints: `sm: 640px`, `md: 768px`, `lg: 1024px`, `xl: 1280px` (Tailwind defaults).

On mobile-first pages, MetricCards stack single-column, charts shrink to sparkline-only, tables reduce to card-stack (Linear-mobile pattern). Cohort heatmaps explicitly replace with a "View on desktop" banner.

Push notifications: email digest + Slack (v2). No native push v1.

See [`_crosscut_mobile_ux.md`](competitors/_crosscut_mobile_ux.md) for full mobile stance.

---

## 9. Multi-store / portfolio behavior

See [`_crosscut_multistore_ux.md`](competitors/_crosscut_multistore_ux.md) for full rationale. Summary rules:

| User workspace count | Default landing | Chrome behavior |
|---|---|---|
| 1 | `/dashboard` | WorkspaceSwitcher hidden entirely |
| 2 | `/dashboard` (active workspace) | Switcher visible, no Portfolio option |
| 3+ | `/dashboard?view=portfolio` | Switcher visible + "Portfolio view" item at top of dropdown |

In portfolio view, every KPI card is stacked contribution: the card shows aggregate + a stacked bar beneath it showing each store's contribution. Per-store sparklines (one tiny chart per store, horizontally aligned) give the "who's moving" signal in one glance.

Currency: single display currency (workspace setting). FX conversion visible on hover ("$12,400 USD · €11,310 native"). Pattern source: [Putler](competitors/putler.md) transaction-date FX.

Roles v1: Owner · Admin · Member, per-workspace. Guest/Client role deferred to v2.

---

## 10. Empty / loading / error states (summary)

| State | Handling | Copy source |
|---|---|---|
| First signup, no sync yet | Demo data + "Demo" banner + progress bar ([Putler](competitors/putler.md)) | [`_crosscut_ux_copy.md`](competitors/_crosscut_ux_copy.md) onboarding section |
| Sync in progress | Skeleton + `"Importing X of Y orders (Z%) — est. N minutes"` ([Lifetimely](competitors/lifetimely.md)) | [`_crosscut_ux_copy.md`](competitors/_crosscut_ux_copy.md) empty-state section |
| Filter returns zero | Illustration + explanation + CTA to widen | [`_crosscut_ux_copy.md`](competitors/_crosscut_ux_copy.md) |
| Pre-integration | "Connect X to see this" + primary CTA | [`_crosscut_ux_copy.md`](competitors/_crosscut_ux_copy.md) |
| Integration disconnected | AlertBanner (warning) + StatusDot (amber) + "Reconnect" button | |
| Sync failed | AlertBanner (danger) + last successful timestamp + "Retry now" button | |
| Data stale > 2h | StatusDot (amber) on metric + freshness tooltip | |
| Source unavailable (e.g., Facebook not connected) | SourceBadge greyed + tooltip "Not connected" | |

---

## 11. Accessibility + keyboard

Baseline (no exceptions):

- WCAG AA contrast on all text.
- Every icon-only button has `aria-label`.
- Every chart has an accessible `<table>` fallback (a11y + SEO benefit).
- Focus visible on all interactive elements (Tailwind `focus-visible` ring).
- Keyboard navigation: tab order follows reading order; arrow keys navigate tables and palettes.
- No content-blocking overlays without close-on-Esc.

Keyboard shortcuts (global):

| Keys | Action |
|---|---|
| Cmd+K | Open CommandPalette |
| Cmd+/ | Open shortcuts help |
| Esc | Close drawer, modal, palette |
| / | Focus nearest search |
| g d | Go to Dashboard |
| g o | Go to Orders |
| g a | Go to Ads |
| g s | Go to SEO |
| g p | Go to Products |
| g c | Go to Customers |
| g f | Go to Profit (finance) |
| g i | Go to Integrations |

---

## 12. Onboarding flow (summary)

Full detail: [`_crosscut_onboarding_ux.md`](competitors/_crosscut_onboarding_ux.md). Proposed Nexstage path:

1. **Signup** (email + password or Google OAuth) → land on `/onboarding`
2. **Connect first store** (Shopify via OAuth, or WooCommerce via plugin/API key) → async historical sync triggers
3. **Connect ad platforms** (Facebook, Google) — optional, can skip → async sync
4. **Connect GSC** — optional → async sync
5. **Land on `/dashboard` with demo data** and "Demo" banner. Sidebar shows sync progress (per-source: "Importing 42% · ETA 8 min").
6. **As each source finishes**, the banner updates ("Shopify ready · Facebook 60%") and corresponding cards swap from demo to real.
7. **Day 7, 14, 30**: phased-unlock messages ("Cohort analysis unlocks today — you now have enough data"). Pattern source: [Northbeam](competitors/northbeam.md) day-30/60/90 framing.

---

## 13. Pricing page (marketing site)

Detail + rationale: [`_crosscut_pricing_ux.md`](competitors/_crosscut_pricing_ux.md). Summary:

- Single tier visible on the pricing page: **39€/mo + 0.4% of all connected-store revenue**.
- Savings calculator: "Your store does $X/mo. Your Nexstage cost: $Y/mo." Drags up to $5M/mo. Compares to Triple Whale + Northbeam + Polar at same volume.
- Agency tier (v2): teaser only in v1.
- No credit card for 14-day trial. No "contact sales" gate.

---

## 14. What goes in page specs (vs. here)

Every page spec in [`pages/*.md`](pages/) follows this fixed template:

```
# <Page name>

Route: /path

## Purpose
One sentence.

## User questions this page answers
- Bullet list.

## Data sources
Table: Source (from §4 colors) · Required? · API / DB provenance · Freshness cadence.

## Above the fold (1440×900)
Component tree using names from §5. Example:
- KpiGrid (4 cols)
  - MetricCard "Revenue (28d)" · sources=[Real, Store]
  - MetricCard "Orders" · sources=[Real, Store]
  - MetricCard "Not Tracked" · sources=[computed]
  - MetricCard "Ad Spend" · sources=[Facebook, Google]
- TrustBar (see UX.md §5.14)
- LineChart "Revenue over time" · multi-source overlay

## Below the fold
Same tree format.

## Interactions specific to this page
Only page-unique interactions. Shared conventions live in UX.md §6.

## Competitor references
Links to competitor files + specific screens that informed this page.

## Mobile tier
Mobile-first | Mobile-usable | Desktop-only (from §8).

## Out of scope v1
What we explicitly don't include on this page.
```

Page specs do not restate design tokens, component anatomy, interaction conventions, or competitor pattern descriptions. They reference.
