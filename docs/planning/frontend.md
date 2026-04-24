# Target frontend architecture — v1 MVP

Designed against `docs/UX.md` and `docs/_codebase_audit_frontend.md`. Current components annotated KEEP / MODIFY / NEW / DROP. The audit's gap table in §2 is the ground truth for status.

---

## 0. Guiding principles

1. **URL state is the source of truth.** Every filter, date range, source, sort, tab, breakdown, profit mode, view mode, drawer entity lives in the query string. No client-only state survives a refresh.
2. **Skeleton over spinner.** Always render the final shape as a shimmer. Never block on cached content; revalidate in place.
3. **Cite primitives by PascalCase.** Pages compose `MetricCard`, `TrustBar`, `DataTable`, etc. They never re-invent anatomy.
4. **Optimistic writes with undo Toast.** No confirm modals for reversible actions. Rollback with shake + retry Toast.
5. **Accessibility table under every chart.** A visually-hidden `<table>` fallback so charts pass WCAG AA and SEO crawlers.
6. **Source colors are canonical.** `source-store` slate, `source-facebook` indigo, `source-google` amber, `source-gsc` emerald, `source-site` violet, `source-real` yellow-400. Never reassign.
7. **Inertia owns navigation; SWR owns data within a page.** Partial reloads for route transitions, SWR for cache-first revalidation, optimistic mutations, and filter-driven refetches.
8. **Desktop-first density.** 8–15 widgets above the fold at 1440×900. Mobile-first only for `/dashboard`, `/orders`, `/alerts`.
9. **Tab-title + favicon as the status channel.** Long-running operations mutate `document.title` and favicon; Toast on completion.
10. **No emoji in UI strings.** Icons from `lucide-react` only.

---

## 1. Inertia page routing

Ten top-level routes plus settings subroutes, onboarding, auth, and public snapshot. Data fetching column: `Inertia` = server-rendered page props; `SWR` = client-side keyed fetch on filters; `Hybrid` = Inertia for initial shape, SWR for filter changes.

| Route | Page component (absolute path) | Layout | Data fetching | Status |
|---|---|---|---|---|
| `/dashboard` | `resources/js/Pages/Dashboard.tsx` | `AppLayout` | Hybrid | MODIFY (add TrustBar, 6-source MetricCards, TodaySoFar, TriageInbox compact) |
| `/orders` | `resources/js/Pages/Orders/Index.tsx` | `AppLayout` | Hybrid | NEW (list-first; current code is `Orders/Show.tsx` detail + `Analytics/Daily.tsx` daily agg) |
| `/orders/:id` (drawer) | `resources/js/Pages/Orders/Index.tsx` + `?order={id}` | `AppLayout` | Inertia partial into `DrawerSidePanel` | MODIFY from `Orders/Show.tsx` into drawer |
| `/ads` | `resources/js/Pages/Ads/Index.tsx` | `AppLayout` | Hybrid | MODIFY (from `Campaigns/Index.tsx`; fold AdSets/Ads into ViewToggle + breakdown level) |
| `/attribution` | `resources/js/Pages/Attribution/Index.tsx` | `AppLayout` | Hybrid | NEW (absorbs `Acquisition.tsx` + `Analytics/Discrepancy.tsx`; adds TrustBar, Model Comparison, Time Machine) |
| `/seo` | `resources/js/Pages/Seo/Index.tsx` | `AppLayout` | Hybrid | MODIFY (add SubNavTabs canonical; align to SEO spec) |
| `/products` | `resources/js/Pages/Products/Index.tsx` | `AppLayout` | Hybrid | MODIFY (from `Analytics/Products.tsx`; add DrawerSidePanel, LetterGradeBadge) |
| `/products/:id` (drawer) | same page + `?product={id}` | `AppLayout` | Inertia partial | MODIFY from `Analytics/ProductShow.tsx` into drawer |
| `/profit` | `resources/js/Pages/Profit/Index.tsx` | `AppLayout` | Hybrid | NEW (ProfitWaterfallChart, P&L table, AccountingModeSelector wiring) |
| `/customers` | `resources/js/Pages/Customers/Index.tsx` | `AppLayout` | Hybrid | MODIFY (from `Store/Index.tsx`; add SubNavTabs: Segments/Retention/LTV/Audiences) |
| `/integrations` | `resources/js/Pages/Integrations/Index.tsx` | `AppLayout` | Inertia | MODIFY (from `Settings/Integrations.tsx`; add SubNavTabs: Connected/Tracking/Historical/Mapping) |
| `/settings/profile` | `resources/js/Pages/Settings/Profile.tsx` | `SettingsLayout` | Inertia | KEEP |
| `/settings/workspace` | `resources/js/Pages/Settings/Workspace.tsx` | `SettingsLayout` | Inertia | KEEP |
| `/settings/team` | `resources/js/Pages/Settings/Team.tsx` | `SettingsLayout` | Inertia | MODIFY (use `Entity` primitive) |
| `/settings/billing` | `resources/js/Pages/Settings/Billing.tsx` | `SettingsLayout` | Inertia | KEEP |
| `/settings/notifications` | `resources/js/Pages/Settings/Notifications.tsx` | `SettingsLayout` | Inertia | KEEP |
| `/settings/costs` | `resources/js/Pages/Settings/Costs.tsx` | `SettingsLayout` | Inertia + optimistic PATCH | NEW (absorbs `Manage/ProductCosts.tsx` + `Stores/Settings.tsx` cost form) |
| `/settings/targets` | `resources/js/Pages/Settings/Targets.tsx` | `SettingsLayout` | Inertia + optimistic PATCH | NEW |
| `/settings/events` | `resources/js/Pages/Settings/Events.tsx` | `SettingsLayout` | Inertia | KEEP (annotation source for ChartAnnotationLayer) |
| `/onboarding` | `resources/js/Pages/Onboarding/Index.tsx` | `OnboardingLayout` | Inertia (step state) | MODIFY (align to UX §12 7-step flow) |
| `/login`, `/register`, `/forgot-password`, `/reset-password`, `/confirm-password`, `/verify-email` | `resources/js/Pages/Auth/*.tsx` | `AuthLayout` | Inertia | KEEP |
| `/invitations/:token` | `resources/js/Pages/Invitations/Show.tsx` | `AuthLayout` | Inertia | KEEP |
| `/public/snapshot/:token` | `resources/js/Pages/Public/Snapshot.tsx` | `PublicSnapshotLayout` | Inertia (unauth) | NEW |
| `/admin/*` | `resources/js/Pages/Admin/*.tsx` | `AppLayout` | Inertia | KEEP (super-admin; out of public IA) |

Routes `/alerts` (mobile-first TriageInbox full page), `/countries`, `/performance`, `/analytics/*`, `/stores/*`, `/store`, `/holidays`, `/help`, `/manage/*` — DROP from top-level IA. Country folds into `BreakdownSelector`. Analytics/Stores overlap pages consolidate into the 10 canonical routes. Manage tools move into `/integrations` SubNavTabs.

---

## 2. Layout shells

### `AppLayout` (`resources/js/Components/layouts/AppLayout.tsx` — MODIFY)
TopBar (56px, sticky) over a horizontal split: `Sidebar` (240px expanded / 64px collapsed, user-persisted) on the left and scrollable `<main>` (max-width 1440, 24px padding) on the right. TopBar hosts `WorkspaceSwitcher` (left), the global filter stack `DateRangePicker · AttributionModelSelector · WindowSelector · AccountingModeSelector · SourceToggle · BreakdownSelector · ProfitModeToggle` (center), and `FreshnessIndicator · CommandPaletteTrigger · NotificationsBell · UserMenu` (right). All filter state is URL-bound and propagates to every card, chart, and table. On `md` breakpoint, Sidebar collapses to 64px icons-only; TopBar center collapses behind a "Filters" sheet trigger. On `sm`, Sidebar becomes a slide-in Drawer.

### `SettingsLayout` (`resources/js/Components/layouts/SettingsLayout.tsx` — NEW)
Nested under `AppLayout`. Left-nav list (`Profile · Workspace · Team · Billing · Notifications · Costs · Targets · Events`) at 200px, content right of it with max-width 768. Left-nav collapses to a horizontal `SubNavTabs` strip on `md` and below. Content area uses single-column cards (shadcn `Card` or `SectionCard`) with labeled sections per UX §14.

### `AuthLayout` (`resources/js/Components/layouts/AuthLayout.tsx` — KEEP)
Full-viewport centered card (max-width 480) with Nexstage wordmark top, form in card body, secondary link row below. No chrome. Toaster mounted. Works unchanged from 375px up.

### `OnboardingLayout` (`resources/js/Components/layouts/OnboardingLayout.tsx` — MODIFY)
Top wizard rail (7 steps per UX §12) at 56px, centered content (max-width 640) below. No sidebar, no TopBar filters. Progress dot per step; active step emphasized. Sticky footer with `Back / Skip / Continue` buttons. Mobile: rail scrolls horizontally, content full-width with 16px padding.

### `PublicSnapshotLayout` (`resources/js/Components/layouts/PublicSnapshotLayout.tsx` — NEW)
Unauthenticated. Branded header strip with "Snapshot · {workspace_name} · {date_range}" and a subtle "Powered by Nexstage" footer. Content is a read-only render of the source page with all interactivity disabled (no filter mutation, no drawers, no right-click menus). Filters appear as static `FilterChipSentence` at top. Responsive down to 375px.

---

## 3. 37 shared primitives — build plan

Status pulled from the audit gap table in `_codebase_audit_frontend.md` §2. File paths are target locations under `resources/js/Components/`. Complexity: S (<1 day), M (1–3 days), L (3–5 days).

| # | Primitive | File path | Status | Complexity | Dependencies |
|---|---|---|---|---|---|
| 1 | MetricCard | `shared/MetricCard.tsx` | MODIFY | L | SourceBadge, Sparkline, ConfidenceChip, SignalTypeBadge, TargetProgress, Tooltip |
| 2 | MetricCardPortfolio | `shared/MetricCardPortfolio.tsx` | NEW | M | MetricCard, Sparkline, workspace-color hash util |
| 3 | MetricCardMultiValue | `shared/MetricCardMultiValue.tsx` | NEW | S | MetricCard shell |
| 4 | MetricCardCompact | `shared/MetricCardCompact.tsx` | NEW | S | MetricCard shell, SourceBadge |
| 5 | MetricCardDetail | `shared/MetricCardDetail.tsx` | NEW | M | MetricCard, Sparkline, LineChart |
| 6 | SourceBadge | `shared/SourceBadge.tsx` | MODIFY | S | CSS tokens from UX §4 |
| 7 | DateRangePicker | `shared/DateRangePicker.tsx` | MODIFY | S | `react-day-picker`, `useDateRange` hook |
| 8 | FilterChipSentence | `shared/FilterChipSentence.tsx` | NEW | M | shadcn Popover, URL-state helpers |
| 9 | DataTable | `shared/DataTable.tsx` | NEW | L | shadcn Table, SortButton, InlineEditableCell, EmptyState, LoadingState |
| 10 | InlineEditableCell | `shared/InlineEditableCell.tsx` | NEW | M | DataTable, Toast, optimistic-write helper |
| 11 | LineChart | `charts/LineChart.tsx` | MODIFY | M | Recharts, ChartAnnotationLayer, GranularitySelector |
| 12 | BarChart | `charts/BarChart.tsx` | KEEP | S | Recharts |
| 13 | Sparkline | `charts/Sparkline.tsx` | NEW | S | Recharts `ResponsiveContainer` |
| 14 | CohortHeatmap | `charts/CohortHeatmap.tsx` | MODIFY | L | CSS grid, Tooltip; replaces `CohortTable` |
| 15 | DaypartHeatmap | `charts/DaypartHeatmap.tsx` | NEW | M | CSS grid, Tooltip |
| 16 | FunnelChart | `charts/FunnelChart.tsx` | NEW | M | Recharts horizontal bars, StatStripe |
| 17 | QuadrantChart | `charts/QuadrantChart.tsx` | KEEP | S | Recharts ScatterChart |
| 18 | LayerCakeChart | `charts/LayerCakeChart.tsx` | NEW | M | Recharts stacked area |
| 19 | ChartAnnotationLayer | `charts/ChartAnnotationLayer.tsx` | MODIFY | M | ContextMenu, Toast |
| 20 | EmptyState | `shared/EmptyState.tsx` | MODIFY | S | lucide icons (add Syncing variant) |
| 21 | LoadingState | `shared/LoadingState.tsx` | NEW | S | `animate-pulse` util |
| 22 | TabTitleStatus | `shared/TabTitleStatus.tsx` | NEW | S | `document.title`, favicon swap hook |
| 23 | StatusDot | `shared/StatusDot.tsx` | NEW | S | CSS tokens from UX §5.9 |
| 24 | DrawerSidePanel | `shared/DrawerSidePanel.tsx` | NEW | M | shadcn `sheet.tsx` (currently unused), URL-state helpers |
| 25 | AlertBanner | `shared/AlertBanner.tsx` | MODIFY | S | CSS tokens, dismiss persistence |
| 26 | DemoBanner | `shared/DemoBanner.tsx` | NEW | S | AlertBanner variant |
| 27 | Toast | `shared/Toast.tsx` (wrapper over `sonner`) | MODIFY | S | sonner, undo action slot |
| 28 | KpiGrid | `shared/KpiGrid.tsx` | NEW | S | Tailwind grid utilities |
| 29 | TrustBar | `shared/TrustBar.tsx` | NEW | M | SourceBadge, ToggleGroup, Entity cells |
| 30 | BreakdownSelector | `shared/BreakdownSelector.tsx` | MODIFY | M | shadcn DropdownMenu, per-page allowlist; separate from existing `BreakdownView` which becomes renderer |
| 31 | ProfitModeToggle | `shared/ProfitModeToggle.tsx` | NEW | S | shadcn Switch or ViewToggle |
| 32 | ProfitWaterfallChart | `charts/ProfitWaterfallChart.tsx` | NEW | L | Recharts composed chart + custom shapes |
| 33 | EntityHoverCard | `shared/EntityHoverCard.tsx` | NEW | M | shadcn HoverCard, `MiddleTruncate` util |
| 34 | SavedView | `shared/SavedView.tsx` | MODIFY | M | URL-state helpers, persistence API; replaces `BreakdownView` view-preferences path |
| 35 | FreshnessIndicator | `shared/FreshnessIndicator.tsx` | MODIFY | S | `lib/syncStatus.ts`, shadcn Popover; rename from `DataFreshness` |
| 36 | ContextMenu | `shared/ContextMenu.tsx` | NEW | M | shadcn ContextMenu (Radix), keyboard handlers |
| 37 | TriageInbox | `shared/TriageInbox.tsx` | MODIFY | M | Entity, StatusDot; replaces `TodaysAttention` + `Pages/Inbox.tsx` body |
| 38 | Target | `shared/Target.tsx` | MODIFY | M | LineChart overlay, MetricCard slot; supersedes `TargetStatusDot` |
| 39 | ActivityFeed | `shared/ActivityFeed.tsx` | MODIFY | M | Entity, SourceBadge, auto-scroll hook; upgrades `RecentOrdersFeed` |
| 40 | TodaySoFar | `shared/TodaySoFar.tsx` | NEW | M | LineChart, projection math |
| 41 | AccountingModeSelector | `shared/AccountingModeSelector.tsx` | NEW | S | ViewToggle primitive |
| 42 | ConfidenceChip | `shared/ConfidenceChip.tsx` | NEW | S | Tooltip |
| 43 | SignalTypeBadge | `shared/SignalTypeBadge.tsx` | NEW | S | Tooltip, SourceBadge color tokens |
| 44 | ShareSnapshotButton | `shared/ShareSnapshotButton.tsx` | NEW | M | shadcn Dialog, clipboard API |
| 45 | ExportMenu | `shared/ExportMenu.tsx` | NEW | M | shadcn DropdownMenu + Dialog (schedule) |
| 46 | SubNavTabs | `shared/SubNavTabs.tsx` | KEEP | S | Inertia `Link`; rename `DestinationTabs` |
| 47 | ViewToggle | `shared/ViewToggle.tsx` | KEEP | S | Existing `ToggleGroup` |
| 48 | LetterGradeBadge | `shared/LetterGradeBadge.tsx` | NEW | S | CSS tokens |
| 49 | StatStripe | `shared/StatStripe.tsx` | NEW | S | Tailwind inline band |
| 50 | AudienceTraits | `shared/AudienceTraits.tsx` | NEW | M | StatStripe, chip layout |
| 51 | TouchpointString | `shared/TouchpointString.tsx` | NEW | S | SourceBadge at 14px |
| 52 | Entity | `shared/Entity.tsx` | NEW | M | StatusDot, shadcn DropdownMenu (trailing slot) |
| 53 | CommandPalette | `shared/CommandPalette.tsx` | NEW | L | `cmdk` library, global keybinding hook, search index |
| 54 | WorkspaceSwitcher | `shared/WorkspaceSwitcher.tsx` | MODIFY | M | Entity, shadcn DropdownMenu, CommandPalette integration |

Note: the canonical UX §5 list has 37 numbered sections but several sections contain sub-variants (§5.1.1, §5.1.2) plus §5.5.1 and §5.6.1, yielding 54 buildable primitives when each sub-variant, chart primitive, and `WorkspaceSwitcher` is counted individually.

### Primitives to DROP (not in the 37)

- `AdDetailModal` — replaced by `DrawerSidePanel` + Ads-specific detail content.
- `AnalyticsTabBar`, `CampaignsTabBar` — replaced by generic `SubNavTabs`.
- `ChannelMatrix` — becomes a `DataTable` instance on `/attribution`.
- `CohortTable` — replaced by `CohortHeatmap`.
- `CreativeCard`, `CreativeGrid` — fold into Ads Creative Gallery view (StatStripe + composite).
- `CwvBand` — Performance page drops from top-level IA; keep internally if needed under `/integrations` tracking-health tab.
- `DataFreshness` — rename to `FreshnessIndicator`.
- `DestinationTabs` — rename to `SubNavTabs`.
- `JourneyTimeline` — content moves into `DrawerSidePanel`; `TouchpointString` replaces inline usage.
- `MotionScoreGauge` — compose from `LetterGradeBadge` stack on Ads Creative Gallery; no standalone primitive.
- `OpportunitiesSidebar`, `OpportunityBadge`, `OpportunityPanel` — content moves into `TriageInbox` + `LetterGradeBadge`.
- `PacingChart` — becomes a Target "Pacing variant" overlay on `LineChart`.
- `PageNarrative` — keep as `PageHeader` prop; not in UX §5.
- `PlatformBadge` — replaced by canonical `SourceBadge`.
- `PlatformVsRealTable` — becomes a `DataTable` instance under the `/attribution` TrustBar.
- `RecentOrdersFeed` — upgraded to `ActivityFeed`.
- `RFMGrid` — remains page-local under `/customers`; not a shared primitive (use `CohortHeatmap` building blocks).
- `ScopeFilter`, `StoreFilter` — replaced by `FilterChipSentence` + TopBar filters.
- `SectionCard` — keep as internal wrapper; not in UX §5.
- `SiteHealthStrip` — becomes a `StatStripe` instance; page drops from IA.
- `SortButton` — absorbed into `DataTable` header.
- `StatusBadge` — replaced by `StatusDot` + label.
- `TargetStatusDot` — superseded by `Target` primitive.
- `TodaysAttention` — superseded by `TriageInbox` compact form.
- `TrendBadge` — folded into `MetricCard` delta.
- `UtmCoverageNudgeModal` — becomes a `TriageInbox` item deep-linking to `/integrations`.
- `WhyThisNumber` — already orphan; content moves into `MetricCard` tooltip + `SignalTypeBadge` popover.
- `WlFilterBar` — becomes `LetterGradeBadge` + `FilterChipSentence` chip.

---

## 4. Build order (phased)

### Phase 1 — tokens + atoms
- Tailwind v4 theme: source color tokens, semantic tokens, spacing scale, typography scale
- `SourceBadge`
- `StatusDot`
- `Sparkline`
- `LoadingState` (skeleton primitive)
- `Entity`

### Phase 2 — molecules
- `MetricCard` (rebuilt with 6-badge row, sparkline, ProfitMode indicator, Custom-date chip, Pin)
- `MetricCardCompact`
- `MetricCardDetail`
- `MetricCardMultiValue`
- `KpiGrid`
- `DateRangePicker` (modify: day-of-week-aligned compare default)
- `FilterChipSentence`
- `ConfidenceChip`
- `SignalTypeBadge`
- `LetterGradeBadge`
- `StatStripe`
- `TouchpointString`

### Phase 3 — organisms
- `DataTable`
- `InlineEditableCell`
- `LineChart` (partial-period dotted tail, GranularitySelector)
- `BarChart`
- `CohortHeatmap`
- `DaypartHeatmap`
- `FunnelChart`
- `QuadrantChart`
- `LayerCakeChart`
- `EmptyState` (add Syncing variant)
- `AlertBanner` (color tokens, persistence)
- `DemoBanner`
- `Toast` (undo action slot)
- `DrawerSidePanel`
- `SavedView`
- `SubNavTabs` (rename from `DestinationTabs`)
- `ViewToggle` (rename from `ToggleGroup`)

### Phase 4 — global chrome
- TopBar filter stack composition
- `Sidebar` rebuild (hover-to-expand, collapse state persistence)
- `WorkspaceSwitcher` (hide when 1 workspace, Portfolio item when ≥3)
- `CommandPalette` (Cmd+K, cmdk lib, search index for pages/workspaces/orders/customers/campaigns/settings)
- `ContextMenu` (right-click infrastructure)
- `FreshnessIndicator` (rename from `DataFreshness`)
- `BreakdownSelector` (TopBar dropdown + page-allowlist logic)
- `AccountingModeSelector`
- `ProfitModeToggle`
- `TabTitleStatus`

### Phase 5 — thesis + composites
- `TrustBar`
- `TriageInbox` (full primitive, powers Dashboard compact + `/alerts` page)
- `Target` (TargetProgress + TargetLine + Pacing variant)
- `ActivityFeed`
- `TodaySoFar`
- `ProfitWaterfallChart`
- `ChartAnnotationLayer` (cross-cutting across all time-series charts)
- `EntityHoverCard`
- `AudienceTraits`
- `MetricCardPortfolio` (multi-workspace contribution + per-store sparklines)
- `ShareSnapshotButton`
- `ExportMenu`

---

## 5. State management

Four layers, composed:

1. **URL state (source of truth)** — every filter, date range, source toggle, breakdown, profit mode, sort, pagination, drawer entity, active tab. Read through a shared `useUrlState<T>(key, schema)` hook; writes use `router.get(route, { preserveState: true, preserveScroll: true })` or `history.replaceState` for rapid-fire changes. This satisfies UX §1 rule 4 and makes every view shareable.
2. **React context (workspace / user / permissions only)** — `WorkspaceContext` exposes the current workspace, role, feature flags, cost-data completeness flags. `UserContext` exposes the authenticated user + preferences (sidebar collapse, pinned cards). Both populated from Inertia `usePage().props` once; never mutated outside page-prop refresh.
3. **SWR for data fetching + optimistic updates** — `swr` (not TanStack Query; lighter footprint, simpler mental model, matches the Vercel primitive referenced throughout UX). Keys are the URL state shape. Global `fetcher = (url) => axios.get(url).then(r => r.data)`. `mutate` drives optimistic writes per UX §6.2 — the UI updates instantly; failure reverts with shake + Toast. SWR also owns the cache-first revalidation ring spinner (UX §6 last row).
4. **Inertia partial reloads for navigation** — route changes use `router.visit(url, { only: ['metrics', 'chart'] })` to refresh only the props needed; avoids full re-render. Drawer open/close is a partial reload with `?order={id}` only updating the `order` prop key.

No Redux, Zustand, Jotai, or Recoil. No `react-hook-form`; Inertia `useForm` for settings pages is sufficient.

---

## 6. Styling

- **Tailwind v4** (`^4.2.2` already installed). Custom theme defined in `resources/css/app.css` via `@theme` directive. Color tokens from UX §4: `--color-source-store`, `--color-source-facebook`, `--color-source-google`, `--color-source-gsc`, `--color-source-site`, `--color-source-real`, plus `--color-accent` (teal-600), semantic `success/warning/danger/info`. Spacing scale restricted to `4, 8, 12, 16, 24, 32, 48, 64`. Type scale `11 / 12.5 / 14 / 16 / 20 / 28 / 40`.
- **shadcn/ui** (style `base-nova`, already configured). Pull in additionally (currently unused or missing): `sheet.tsx` (for `DrawerSidePanel`), `table.tsx` (for `DataTable`), `tabs.tsx` (for `SubNavTabs` fallback), `select.tsx` (filter dropdowns), `card.tsx` (SettingsLayout sections), `hover-card.tsx` (for `EntityHoverCard`), `context-menu.tsx` (for `ContextMenu`), `switch.tsx` (ProfitMode), `command.tsx` (CommandPalette fallback wrapper), `tooltip.tsx` (canonical Tooltip), `skeleton.tsx` (LoadingState). Total ~10 to add.
- **Icons**: `lucide-react` only (already installed). No other icon library.
- **Fonts**: Inter as body (`font-variant-numeric: tabular-nums` on every KPI value container); JetBrains Mono for IDs / code / metric formulas. Load both via `@fontsource-variable` (Geist is installed but UX §4 specifies Inter + JetBrains Mono — replace).
- **Light mode only** in v1. `next-themes` is installed but stays unwired until v2.
- **Drop**: `@headlessui/react` (unused), `@fontsource-variable/geist` (wrong font).

---

## 7. Chart library

All charts wrap **Recharts** (`^3.8.1`, already installed). Three categories:

**Direct Recharts wrappers** — thin component that passes data + config:
- `LineChart` (`Recharts.LineChart` inside `ResponsiveContainer`)
- `BarChart` (`Recharts.BarChart`)
- `Sparkline` (`Recharts.LineChart` with no axes/grid)
- `QuadrantChart` (`Recharts.ScatterChart` with quadrant divider lines)
- `LayerCakeChart` (`Recharts.AreaChart` stacked)

**Custom composition** — SVG or CSS grid, built on Recharts primitives where useful:
- `CohortHeatmap` — CSS grid of colored cells with tooltip overlay
- `DaypartHeatmap` — 7×24 CSS grid
- `FunnelChart` — horizontal bars, absolute counts + drop-off % (never stacked, per UX §5.6)
- `ProfitWaterfallChart` — `Recharts.ComposedChart` with custom `<Bar shape={WaterfallBar}>` for floating bars

**Shared infrastructure**:
- `ChartTooltip` (`shared/ChartTooltip.tsx`) — canonical Recharts tooltip render; source badge row, tabular-nums values, click-through CTA.
- `ChartAnnotationLayer` — cross-cutting overlay of dashed vertical markers with flag labels; applied to every time-series chart.
- `GranularitySelector` — local toolbar `Hourly / Daily / Weekly / Monthly`; URL-stateful, defaults per UX §5.6.
- **Accessible `<table>` fallback** — every chart renders a visually-hidden `<table>` of the same data. Shared `ChartAccessibleTable` component; chart components accept `accessibleRows: Array<{label, values}>` and render it off-screen.

Do not evaluate Tremor or Nivo in v1. Re-evaluate post-v1 if Recharts composition cost grows.

---

## 8. Accessibility

- **Focus-visible ring** on every interactive element via Tailwind `focus-visible:ring-2 focus-visible:ring-accent`.
- **`aria-label`** on every icon-only button (enforced via ESLint rule `jsx-a11y/control-has-associated-label`).
- **Chart `<table>` fallback** (see §7). Screen-reader users get a real table; sighted users see the chart.
- **Keyboard**: `Cmd+K` (CommandPalette), `Cmd+/` (shortcut help modal), `Esc` (close drawer/modal/palette), `/` (focus nearest search), `g d/o/a/s/p/c/f/i` (route shortcuts per UX §11). Arrow keys navigate tables and palettes.
- **WCAG AA contrast** verified on all six source colors against `bg-white` and `bg-zinc-50` surfaces. `source-real` yellow-400 has contrast risk on white — pair with border + darker text; never rely on background alone.
- **Tab order** follows reading order; skip links at top of `AppLayout` main.
- **No content-blocking overlay** lacks close-on-Esc.

---

## 9. Responsive implementation

Breakpoints are Tailwind defaults: `sm: 640px`, `md: 768px`, `lg: 1024px`, `xl: 1280px`.

| Tier | Range | Collapse strategy |
|---|---|---|
| Mobile-first | 375–767 | Sidebar → slide-in drawer; TopBar filters → "Filters" sheet trigger; `KpiGrid` → single column; tables → card-stack; charts → sparkline-only; `CohortHeatmap` → banner "View on desktop". Applies to `/dashboard`, `/orders`, `/alerts`. |
| Mobile-usable | 768–1023 | Sidebar 64px icons; TopBar filters wrap to 2 lines; `KpiGrid` 4→2 cols, 6→3 cols; tables keep full width; `CohortHeatmap` blocked <1280. Applies to `/ads`, `/seo`, `/products`, `/profit`, `/customers` (non-Retention), `/integrations`, `/settings/*`. |
| Desktop-only | 1024+ full fidelity at 1280+ | Full chrome, `CohortHeatmap`/`DaypartHeatmap`/`QuadrantChart` render, inline-editable cost tables usable. Applies to `/attribution`, `/customers` Retention tab, `/settings/costs`. Below 1280 show "Best viewed on desktop" banner. |

---

## 10. Testing strategy

- **Unit**: Vitest + React Testing Library for every primitive in §3. One `.test.tsx` per primitive (status transitions, a11y, keyboard, optimistic write rollback).
- **Integration**: Vitest for page-level compositions. Mock Inertia `usePage()` + SWR `mutate`. Verify URL state round-trips, filter propagation, drawer open/close.
- **E2E**: Playwright for critical flows:
  - Onboarding (signup → connect Shopify → land on dashboard with demo banner)
  - Filter-on-click (click MetricCard source badge → URL updates → downstream charts re-key)
  - Source switch (click `source-facebook` badge → card active, siblings unchanged)
  - COGS inline edit (click cell → type → blur → optimistic save → reload → persisted)
  - Cmd+K → "orders 12345" → navigate to order drawer
  - Right-click chart → "Add annotation" → persists + appears on other charts in range

All three layers run in CI on every PR. Storybook deferred to v2.

---

## 11. Onboarding flow

Per UX §12, seven steps. Frontend responsibilities only:

1. **Signup** (`/register`) — Inertia form, email + password or Google OAuth button. On success, redirect to `/onboarding`.
2. **Connect first store** (`/onboarding/step/1`) — two-tab card: Shopify OAuth (redirect to admin auth URL) or WooCommerce (URL + plugin install instructions + API key form). Shows connection state from polled `/api/integrations/status`.
3. **Connect ad platforms** (`/onboarding/step/2`) — Facebook + Google OAuth buttons. Skippable.
4. **Connect GSC** (`/onboarding/step/3`) — Google OAuth; property picker if multiple. Skippable.
5. **Land on `/dashboard` with demo data** (`/onboarding/complete`) — `DemoBanner` pinned; `KpiGrid` renders `Acme Coffee` sample data. Sidebar shows per-source sync progress chips ("Shopify 42% · ETA 8 min") that poll every 3s.
6. **Partial-to-real swap** — as each source's historical backfill completes, the corresponding `MetricCard` sheds its `Demo` chip, data swaps from sample to workspace data with a 200ms fade, and `DemoBanner` copy updates ("Shopify ready · Facebook 60%"). When all sources ready, banner dismisses.
7. **Day 7/14/30 unlock notices** — `AlertBanner` (info) appears on dashboard top ("Cohort analysis unlocks today"); dismisses after first view; state persisted on user.

Frontend never blocks on sync; every page renders from demo data until real data exists.

---

## 12. Open questions

1. **SWR vs TanStack Query** — picked SWR for lightness, but TanStack's built-in mutation queue + devtools may matter once optimistic writes span multiple pages. Confirm post-phase-3.
2. **Sidebar collapse behavior** — UX §3 says "hover-to-expand from collapsed". Does hover-expand apply only when user explicitly collapsed, or also at `md` breakpoint? Spec ambiguous; propose explicit-only, auto-collapsed on `md` stays collapsed.
3. **CommandPalette search backend** — is the search index client-side (fuzzy over entity names injected into page props) or server-side (new `/api/search?q=`)? Server-side scales but adds a round trip; Linear uses client-side for <10k entities. Propose client-side v1, migrate to server when workspace entity count exceeds 5k.
4. **Drawer URL key collisions** — `/orders?order=123&product=456` opens which drawer? Proposal: single `?drawer=orders:123` key per page to prevent stacking.
5. **Public snapshot rendering** — do we re-run the same page components (needs auth-guards lifted) or build a dedicated `SnapshotDashboard` + `SnapshotOrders` etc.? Re-running is cheaper but risks auth leaks; dedicated components are 2× maintenance but safer. Propose dedicated, shared via same primitives.
6. **Recharts vs custom SVG for `ProfitWaterfallChart`** — Recharts `ComposedChart` with custom bar shape is feasible but fiddly (floating bars, connector lines). Visx or plain D3/SVG may be cleaner. Decide during Phase 5.
7. **Pinned MetricCards persistence** — per-user (syncs across devices per UX §5.1) requires a new `user_pinned_cards` table. Confirm schema + limit (8 per user) before Phase 2.
