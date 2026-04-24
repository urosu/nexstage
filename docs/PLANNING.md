# PLANNING

Work order for building Nexstage v1. Dependency-ordered, not phase-ordered — items inside each layer parallelize; layers gate each other.

**Coding agents should only read this file + what it links.** Every bullet links to the detail.

---

## 0. Reading order

1. [CLAUDE.md](../CLAUDE.md) — conventions + gotchas (multi-tenancy, JSONB pairing, snapshot-truth, FX cache).
2. This file (`PLANNING.md`) — work order.
3. [UX.md](UX.md) — design system (37 primitives, 10 routes, interaction rules).
4. [pages/\*.md](pages/) — one file per route. Reference only what the current task needs.
5. [planning/schema.md](planning/schema.md) · [planning/backend.md](planning/backend.md) · [planning/frontend.md](planning/frontend.md) — target architecture. KEEP/MODIFY/NEW/DROP per item. Read the section matching the task.
6. [competitors/\_patterns_catalog.md](competitors/_patterns_catalog.md) · [competitors/\_crosscut_metric_dictionary.md](competitors/_crosscut_metric_dictionary.md) — naming + patterns. Reference when a UX term is unclear.

Everything else in `docs/competitors/` is deep reference only.

---

## 1. Thesis (one paragraph)

Ecommerce analytics for Shopify + WooCommerce SMBs. The product is **source disagreement, surfaced** — every revenue metric carries a six-source badge (Store · Facebook · Google · GSC · Site · Real), "Not Tracked" is a first-class bucket that can go negative, and no platform gets to claim "the truth". MVP connectors: WC, Shopify, Facebook Ads, Google Ads, GSC. No GA4. Pricing: €39/mo + 0.4% revenue share — uncontested in the market.

---

## 2. Stack

| Layer | Choice |
|---|---|
| Backend | Laravel 12 · PHP 8.3 · Postgres · Horizon/Redis |
| Frontend | Inertia · React 19 · Tailwind · shadcn/ui · Recharts · SWR |
| Multi-tenancy | `#[ScopedBy(WorkspaceScope::class)]` — 39 models already use it |
| Queues | 11 queues, queue-per-provider ([backend §9](planning/backend.md#9-queue-strategy)) |
| Cache | Redis for classifier + snapshot locks |

---

## 3. Work order

### L1 — Cleanup (no dependencies, do first)

| Task | Detail |
|---|---|
| Drop 15 deprecated tables | [schema.md §5](planning/schema.md#5-deprecations) |
| Drop 2 AI services (`AiSummaryService`, `AiCreativeTaggerService`) + 3 AI jobs + AI controllers | [backend.md §1.4](planning/backend.md#14-drop-services-no-longer-called), §3.4 |
| Delete legacy redirect controllers (`AdsController`, `AdSetsController`, `DiscrepancyController`, `WinnersLosersController`, `CountriesController`) | [backend.md §6](planning/backend.md#6-controllers--breakup-plan) |
| Delete legacy Inertia pages + components flagged DROP | [frontend.md §3](planning/frontend.md#3-37-shared-primitives--build-plan) |

### L2 — Schema migrations (gates backend + page work)

Execute in order: DROP → MODIFY → NEW. Everything specced in [planning/schema.md](planning/schema.md).

| Group | Reference |
|---|---|
| Tenancy / billing tables MODIFY + NEW | [schema.md §1 Tenancy](planning/schema.md#1-per-table-reference) |
| `orders`, `order_items`, `customers` MODIFY (add FK, add per-source revenue cols) | schema.md §1 Orders/Customers/Products |
| `daily_snapshots` MODIFY (13 new columns: 7 per-source revenue + 6 profit components) | schema.md §1 Snapshots |
| Cost tables NEW (`store_cost_settings`, `shipping_rules`, `transaction_fee_rules`, `platform_fee_rules`, `tax_rules`, `opex_allocations`) | schema.md §1 Costs |
| UX-primitive tables NEW (`annotations`, `saved_views`, `workspace_targets`, `public_snapshot_tokens`, `customer_rfm_scores`, `customer_ltv_overrides`, `triage_inbox_items`, `metric_baselines`, `anomaly_rules`, `digest_schedules`, `slack_webhooks`, `notification_preferences`, `settings_audit_log`) | schema.md §1 UX primitives + Config + Billing |
| Integration pipe tables: rename `sync_logs`→`integration_runs`, subsume `webhook_logs`→`integration_events` (Elevar pattern), NEW `historical_import_jobs`, NEW `integration_credentials` polymorphic | schema.md §1 Integrations |
| GSC renames + `data_state` column | schema.md §1 GSC/SEO |
| `product_variants` NEW (hosts canonical COGS + stock at SKU level) | schema.md §1 Products |
| Index audit pass (every hot query path from page specs) | [schema.md §3](planning/schema.md#3-index-audit) |
| JSONB + `*_api_version` pairing pass | [schema.md §4](planning/schema.md#4-jsonb--api_version-audit) |

### L3 — Backend foundation (parallel with L4 once L2 lands)

| Task | Reference |
|---|---|
| New Value Objects (`AttributionWindow`, `SourceDisagreement`, `SignalType`, revised `WorkspaceSettings`) | [backend.md §5](planning/backend.md#5-value-objects-appvalueobjects) |
| New services (14 items: `SnapshotBuilder`, `ChannelMappingResolver`, `ConfidenceThreshold`, `AnomalyDetection`, `SavedView`, `Annotation`, `Target`, `ShareSnapshotToken`, `RfmScoring`, `LtvModeling`, `IntegrationHealth`, `HistoricalImportOrchestrator`, `BillingUsage`, `SettingsAudit`) | [backend.md §1.3](planning/backend.md#13-new-services) |
| MODIFY services (`ProfitCalculator` → reads new cost tables; `MonthlyReportService` → per-source sections) | [backend.md §1.2](planning/backend.md#12-modify-services) |
| New actions (11 items) + MODIFY `StartHistoricalImportAction`, `ConnectShopifyStoreAction`, `ConnectStoreAction` | [backend.md §2](planning/backend.md#2-actions-appactions) |
| New jobs (9 items: `BuildDailySnapshot`, `BuildHourlySnapshot`, `ComputeRfmScores`, `DetectAnomalies`, `DeliverDigest`, `RecomputeAttribution`, `ExpirePublicSnapshotTokens`, `CleanupOldIntegrationEvents`, `IngestAlgorithmUpdates`) | [backend.md §3.3](planning/backend.md#3-jobs-appjobs) |
| Controller breakup (15 fat controllers → page-aligned) | [backend.md §6](planning/backend.md#6-controllers--breakup-plan) |
| Route map rebuild (web.php aligned to 10 pages + onboarding + settings subroutes + public snapshot) | [backend.md §7](planning/backend.md#7-routes) |
| Feature flags (`config/features.php` — phased unlock + v2 gates) | [backend.md §10](planning/backend.md#10-feature-flag-approach) |
| Integration clients: add test coverage (Facebook 629 LoC / Google 466 / GSC 514 / Shopify 655 — all currently 0 tests) | [backend.md §4](planning/backend.md#4-connectors-platform-api-clients) |

### L4 — Design system primitives (parallel with L3)

Build phases are strict; later phases depend on earlier ones. Detail in [frontend.md §3](planning/frontend.md#3-37-shared-primitives--build-plan) + [§4](planning/frontend.md#4-build-order-phased).

| Phase | Contents |
|---|---|
| P1 — atoms | `SourceBadge`, `StatusDot`, `Sparkline`, `Skeleton`, `Entity`, Tailwind tokens for 6 source colors |
| P2 — molecules | `MetricCard` (+ Portfolio/MultiValue/Compact/Detail variants), `KpiGrid`, `DateRangePicker`, `FilterChipSentence`, `ConfidenceChip`, `SignalTypeBadge`, `LetterGradeBadge`, `StatStripe`, `TouchpointString` |
| P3 — organisms | `DataTable` + `InlineEditableCell`, chart primitives (`LineChart`, `BarChart`, `CohortHeatmap`, `DaypartHeatmap`, `FunnelChart`, `QuadrantChart`, `LayerCakeChart`, `ProfitWaterfallChart`), `EmptyState`, `LoadingState`, `AlertBanner` + `DemoBanner`, `Toast`, `DrawerSidePanel`, `SavedView`, `SubNavTabs`, `ViewToggle` |
| P4 — global chrome | TopBar filter stack (`AttributionModelSelector`, `WindowSelector`, `AccountingModeSelector`, `SourceToggle`, `BreakdownSelector`, `ProfitModeToggle`), Sidebar, `WorkspaceSwitcher`, `CommandPalette`, `ContextMenu`, `FreshnessIndicator` |
| P5 — thesis composites | `TrustBar` (signature), `TriageInbox`, `Target` (+TargetLine/TargetProgress), `ActivityFeed`, `TodaySoFar`, `ChartAnnotationLayer`, `EntityHoverCard`, `AudienceTraits`, `ShareSnapshotButton`, `ExportMenu`, `TabTitleStatus` |

Layouts: `AppLayout` MODIFY, `SettingsLayout` NEW, `AuthLayout` KEEP, `OnboardingLayout` MODIFY, `PublicSnapshotLayout` NEW. [frontend.md §2](planning/frontend.md#2-layout-shells).

### L5 — Pages (require L3 + L4 primitives for that page)

Order by user value + unblocks:

| # | Route | Spec | Rationale |
|---|---|---|---|
| 1 | `/onboarding` | [UX §12](UX.md#12-onboarding-flow-summary) | Blocks everything — users must connect stores first |
| 2 | `/integrations` | [pages/integrations.md](pages/integrations.md) | Users reconnect from here; Tracking Health is our differentiation surface |
| 3 | `/dashboard` | [pages/dashboard.md](pages/dashboard.md) | Landing route, highest traffic |
| 4 | `/orders` | [pages/orders.md](pages/orders.md) | Core data page; populates Dashboard drawers |
| 5 | `/attribution` | [pages/attribution.md](pages/attribution.md) | Thesis surface (TrustBar, Source Disagreement Matrix, Time Machine) |
| 6 | `/ads` | [pages/ads.md](pages/ads.md) | Attribution-adjacent; validates ad reconciliation end-to-end |
| 7 | `/profit` | [pages/profit.md](pages/profit.md) | Requires cost config; builds on Dashboard ProfitMode plumbing |
| 8 | `/seo` | [pages/seo.md](pages/seo.md) | GSC is simple pipe; low risk |
| 9 | `/products` | [pages/products.md](pages/products.md) | Inline COGS is load-bearing; cross-checks Profit page |
| 10 | `/customers` | [pages/customers.md](pages/customers.md) | Deepest UX (RFM + 3-view cohort + LayerCake); do last |
| 11 | `/settings/*` | [pages/settings.md](pages/settings.md) | Refined last — users don't configure before they see data |

Page public-snapshot view (`/public/snapshot/{token}`) built alongside pages that use it.

### L6 — Polish

| Task | Reference |
|---|---|
| Component tests for all new primitives (Vitest + RTL) | [frontend.md §10](planning/frontend.md#10-testing-strategy) |
| Playwright e2e: onboarding flow, filter-on-click, source switch, inline COGS edit, Cmd+K, right-click annotation | frontend.md §10 |
| A11y audit pass (WCAG AA, focus rings, chart `<table>` fallback, keyboard shortcuts) | [UX §11](UX.md#11-accessibility--keyboard) |
| Performance: attribution recompute target <5 min / 100k orders | [backend.md §11](planning/backend.md#11-attribution-pipeline) |
| Pricing page (marketing site, not app) | [competitors/\_crosscut_pricing_ux.md](competitors/_crosscut_pricing_ux.md) |

---

## 4. Blocking open questions

Resolve before touching the cited layer. Deep lists live in each planning file (§6 schema, §16 backend, §12 frontend).

| # | Question | Blocks | Proposed default |
|---|---|---|---|
| 1 | Keep separate `orders.attribution_first/last/last_non_direct` JSONB columns, or collapse to one `attribution_set` JSONB? | L2 schema | Keep separate (query-path clarity wins over storage cost) |
| 2 | SWR vs TanStack Query for revalidation? | L4 P4+ | SWR (lighter, Vercel pattern, simpler mental model) |
| 3 | `CohortHeatmap`: Recharts `ScatterChart` hack or custom SVG? | L4 P3 | Custom SVG (Recharts can't do proper heatmaps) |
| 4 | `ProfitWaterfallChart`: Recharts stacked BarChart or custom SVG? | L4 P5 | Custom SVG (waterfall semantics are off-label for Recharts) |
| 5 | Feature flags: env-only or DB-backed + UI? | L3 | Env-only v1 via `config/features.php`; DB-backed if workspaces need per-workspace toggles |
| 6 | Historical import progress: polling via Inertia partial reload or Laravel Reverb (WebSocket)? | L3 + L5 onboarding | Polling (simpler; Reverb for v2) |
| 7 | Public snapshot: materialize `snapshot_data` jsonb, or compute live from frozen `daily_snapshots`? | L3 `ShareSnapshotTokenService` | Materialize on token creation (isolates share view from live-data drift) |
| 8 | CommandPalette search: client-side fuzzy or server-side per workspace? | L4 P4 | Client-side for pages/settings; server-side for orders/customers/campaigns |

---

## 5. Non-goals (v1)

Explicit scope guards. Enumerated in [UX.md §2 out-of-scope](UX.md#out-of-scope-for-v1-intentional). One-line summary:

- No AI assistant / NL query bar (v2)
- No benchmarking / peer data (v2 — schema-ready)
- No native mobile apps (responsive web only)
- No SSO / SCIM (v2)
- No white-label / agency portal (v2 — `billing_workspace_id` ready)
- No custom SQL report builder (templated reports only)
- No dark mode (v2)
- No scheduled Slack digests (email digest v1; on-demand Slack only)
- No Site / GA4 connector (v2)
- No TikTok / Pinterest / Snap / Microsoft Ads (v2 — schema-ready)
