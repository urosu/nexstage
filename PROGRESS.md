# PROGRESS — Nexstage v1 Implementation

Task board for L1–L6 layers. Agents: find the next `[ ]` task, work it, mark `[x]` when done, and commit.

**Reading order for coding agents:**
1. CLAUDE.md (conventions + gotchas)
2. docs/PLANNING.md (layer dependencies + work order)
3. This file (PROGRESS.md) — find the first `[ ]` checkbox
4. docs/planning/{schema,backend,frontend}.md (detail for your task)
5. docs/pages/*.md (UX specs you need)

---

## L1 — Cleanup (no dependencies, do first)

- [ ] Delete 5 legacy redirect controllers — [backend.md §6](docs/planning/backend.md#6-controllers--breakup-plan): AdsController, AdSetsController, DiscrepancyController, WinnersLosersController, CountriesController. Remove routes from web.php.
- [ ] Delete 2 AI services + 4 AI jobs — [backend.md §1.4, §3.4](docs/planning/backend.md#14-drop-services-no-longer-called): AiSummaryService, AiCreativeTaggerService; GenerateAiSummaryJob, TagCreativesWithAiJob, ComputeProductAffinitiesJob, CleanupOldWebhookLogsJob. Remove console routes.
- [ ] Delete legacy AI models — AiSummary.php, CreativeTag.php, CreativeTagCategory.php, ProductAffinity.php
- [ ] Create migration: DROP deprecated tables — [schema.md §5](docs/planning/schema.md#5-deprecations): ai_summaries, recommendations, product_affinities, google_ads_keywords, creative_tags, creative_tag_categories, ad_creative_tags
- [ ] Delete legacy Inertia pages + components — [frontend.md §3](docs/planning/frontend.md#3-37-shared-primitives--build-plan): Pages: Analytics/, Stores/, Countries.tsx, Performance/, Manage/, Help/. Components: 24 marked DROP (AdDetailModal, ChannelMatrix, etc.)

---

## L2 — Schema Migrations (gates backend + page work)

Execute in order: DROP → MODIFY → NEW. Everything in [schema.md §1](docs/planning/schema.md#1-per-table-reference).

- [ ] **DROP migrations** — Drop: ai_summaries, recommendations, product_affinities, google_ads_keywords, creative_tags, creative_tag_categories, ad_creative_tags (covered in L1 migration)
- [ ] **MODIFY: workspaces** — Drop columns: has_store, has_ads, has_gsc, has_psi, country, region, timezone, target_roas, target_cpo, target_marketing_pct, utm_coverage_*
- [ ] **MODIFY: stores** — Drop: auth_key_encrypted, access_token_encrypted, refresh_token_encrypted, webhook_secret_encrypted, token_expires_at, cost_settings, historical_import_* cols, target_roas, target_cpo, target_marketing_pct, type
- [ ] **MODIFY: orders** — Add columns: per-source revenue (revenue_facebook_attributed, revenue_google_attributed, revenue_gsc_attributed, revenue_direct_attributed, revenue_organic_attributed, revenue_email_attributed, revenue_real_attributed). Add FK to customers. [schema.md §1 Orders](docs/planning/schema.md#1-per-table-reference)
- [ ] **MODIFY: daily_snapshots** — Add 7 per-source revenue + 6 profit-component columns. [schema.md §1 Snapshots](docs/planning/schema.md#1-per-table-reference)
- [ ] **NEW: customers** — Customer stitching table (workspace_id, email_hash, name, etc.). [schema.md §1 Orders/Customers/Products](docs/planning/schema.md#1-per-table-reference)
- [ ] **NEW: integration_runs** — Rename sync_logs → integration_runs. [schema.md §1 Integrations](docs/planning/schema.md#1-per-table-reference)
- [ ] **NEW: integration_events** — Replaces webhook_logs. Migrate webhook_logs rows (last 30d) + subsume. [schema.md §1 Integrations](docs/planning/schema.md#1-per-table-reference)
- [ ] **NEW: integration_credentials** — Polymorphic store/ad_account/search_console_property credentials. [schema.md §1 Integrations](docs/planning/schema.md#1-per-table-reference)
- [ ] **NEW: annotations** — Workspace-scoped events (daily_notes + workspace_events merged). [schema.md §1 UX primitives](docs/planning/schema.md#1-per-table-reference)
- [ ] **NEW: saved_views** — User-created filter + sort + column + date-range combos. [schema.md §1 UX primitives](docs/planning/schema.md#1-per-table-reference)
- [ ] **NEW: triage_inbox_items** — Rename inbox_items → triage_inbox_items. Migrate alerts + inbox_items. [schema.md §1 UX primitives](docs/planning/schema.md#1-per-table-reference)
- [ ] **NEW: workspace_targets** — Goals (ROAS, CAC, revenue targets) per workspace. [schema.md §1 UX primitives](docs/planning/schema.md#1-per-table-reference)
- [ ] **NEW: public_snapshot_tokens** — Shareable snapshot links (date-range frozen). [schema.md §1 UX primitives](docs/planning/schema.md#1-per-table-reference)
- [ ] **NEW: customer_rfm_scores** — RFM scoring materialized nightly. [schema.md §1 UX primitives](docs/planning/schema.md#1-per-table-reference)
- [ ] **NEW: metric_baselines** — Anomaly detection thresholds. [schema.md §1 UX primitives](docs/planning/schema.md#1-per-table-reference)
- [ ] **NEW: anomaly_rules** — User-defined alert rules. [schema.md §1 UX primitives](docs/planning/schema.md#1-per-table-reference)
- [ ] **NEW: digest_schedules** — Email digest configuration. [schema.md §1 UX primitives](docs/planning/schema.md#1-per-table-reference)
- [ ] **NEW: cost tables (6 total)** — store_cost_settings, shipping_rules, transaction_fee_rules, platform_fee_rules, tax_rules, opex_allocations. [schema.md §1 Costs](docs/planning/schema.md#1-per-table-reference)
- [ ] **NEW: product_variants** — SKU-level COGS + stock. [schema.md §1 Products](docs/planning/schema.md#1-per-table-reference)
- [ ] **Index audit** — Every hot query path from page specs. [schema.md §3](docs/planning/schema.md#3-index-audit)
- [ ] **JSONB + *_api_version pairing** — Audit all external-payload JSONB has paired api_version column. [schema.md §4](docs/planning/schema.md#4-jsonb--api_version-audit)

---

## L3 — Backend Foundation (parallel with L4 once L2 lands)

### New Value Objects
- [ ] Create AttributionWindow VO — window_days, click_days, view_days, etc. [backend.md §5](docs/planning/backend.md#5-value-objects-appvalueobjects)
- [ ] Create SourceDisagreement VO — Compares Store vs Real; delta sign. [backend.md §5](docs/planning/backend.md#5-value-objects-appvalueobjects)
- [ ] Create SignalType enum — Deterministic / Modeled / Mixed. [backend.md §5](docs/planning/backend.md#5-value-objects-appvalueobjects)
- [ ] Revise WorkspaceSettings VO — Add attribution defaults + cost config. [backend.md §5](docs/planning/backend.md#5-value-objects-appvalueobjects)

### New Services (14 items)
- [ ] SnapshotBuilderService — Builds daily + hourly snapshots from orders + ad_insights. [backend.md §1.3](docs/planning/backend.md#13-new-services)
- [ ] ChannelMappingResolver — UTM → channel lookup + validation. [backend.md §1.3](docs/planning/backend.md#13-new-services)
- [ ] ConfidenceThresholdService — n < threshold check + ConfidenceChip logic. [backend.md §1.3](docs/planning/backend.md#13-new-services)
- [ ] AnomalyDetectionService — Threshold breach detection for alerts. [backend.md §1.3](docs/planning/backend.md#13-new-services)
- [ ] SavedViewService — CRUD saved filters + views. [backend.md §1.3](docs/planning/backend.md#13-new-services)
- [ ] AnnotationService — CRUD annotations (events, notes, promotions). [backend.md §1.3](docs/planning/backend.md#13-new-services)
- [ ] TargetService — CRUD goals (ROAS, CAC, revenue). [backend.md §1.3](docs/planning/backend.md#13-new-services)
- [ ] ShareSnapshotTokenService — Generate + revoke public links. [backend.md §1.3](docs/planning/backend.md#13-new-services)
- [ ] RfmScoringService — Nightly RFM compute to customer_rfm_scores. [backend.md §1.3](docs/planning/backend.md#13-new-services)
- [ ] LtvModelingService — Predicted LTV + Predicted Next Order. [backend.md §1.3](docs/planning/backend.md#13-new-services)
- [ ] IntegrationHealthService — Sync freshness + error summaries. [backend.md §1.3](docs/planning/backend.md#13-new-services)
- [ ] HistoricalImportOrchestrator — Backfill orders/insights from platform APIs. [backend.md §1.3](docs/planning/backend.md#13-new-services)
- [ ] BillingUsageService — Compute 0.4% revenue-share usage per workspace. [backend.md §1.3](docs/planning/backend.md#13-new-services)
- [ ] SettingsAuditService — Log settings changes + revisions. [backend.md §1.3](docs/planning/backend.md#13-new-services)

### Modify Services (2 items)
- [ ] ProfitCalculator — Read new cost tables; output gross/net/COGS/profit. [backend.md §1.2](docs/planning/backend.md#12-modify-services)
- [ ] MonthlyReportService — Add per-source sections to report. [backend.md §1.2](docs/planning/backend.md#12-modify-services)

### New Actions (11 items)
- [ ] StartHistoricalImportAction (MODIFY) — Wire to new HistoricalImportOrchestrator. [backend.md §2](docs/planning/backend.md#2-actions-appactions)
- [ ] ConnectShopifyStoreAction (MODIFY) — Use new integration_credentials polymorphic. [backend.md §2](docs/planning/backend.md#2-actions-appactions)
- [ ] ConnectStoreAction (MODIFY) — Generic store connector. [backend.md §2](docs/planning/backend.md#2-actions-appactions)
- [ ] CreateAnnotationAction — User creates event/note/promotion. [backend.md §2](docs/planning/backend.md#2-actions-appactions)
- [ ] UpdateCostConfigAction — Dispatch RecomputeAttributionJob. [backend.md §2](docs/planning/backend.md#2-actions-appactions)
- [ ] UpdateAttributionDefaultsAction — Dispatch RecomputeAttributionJob. [backend.md §2](docs/planning/backend.md#2-actions-appactions)
- [ ] SaveFilterAsViewAction — Create saved_views row. [backend.md §2](docs/planning/backend.md#2-actions-appactions)
- [ ] SetWorkspaceTargetAction — Create workspace_targets row. [backend.md §2](docs/planning/backend.md#2-actions-appactions)
- [ ] ShareSnapshotAction — Call ShareSnapshotTokenService + generate link. [backend.md §2](docs/planning/backend.md#2-actions-appactions)
- [ ] UpdateChannelMappingAction — Dispatch RecomputeAttributionJob. [backend.md §2](docs/planning/backend.md#2-actions-appactions)
- [ ] CreateDigestScheduleAction — Create digest_schedules row. [backend.md §2](docs/planning/backend.md#2-actions-appactions)

### New Jobs (9 items)
- [ ] BuildDailySnapshotJob — Nightly snapshot compute. [backend.md §3.3](docs/planning/backend.md#3-jobs-appjobs)
- [ ] BuildHourlySnapshotJob — Hourly snapshot compute. [backend.md §3.3](docs/planning/backend.md#3-jobs-appjobs)
- [ ] ComputeRfmScoresJob — Nightly RFM recompute. [backend.md §3.3](docs/planning/backend.md#3-jobs-appjobs)
- [ ] DetectAnomaliesJob — Check metrics against baselines. [backend.md §3.3](docs/planning/backend.md#3-jobs-appjobs)
- [ ] DeliverDigestJob — Send scheduled email digests. [backend.md §3.3](docs/planning/backend.md#3-jobs-appjobs)
- [ ] RecomputeAttributionJob — Full re-run after config change. [backend.md §3.3](docs/planning/backend.md#3-jobs-appjobs)
- [ ] ExpirePublicSnapshotTokensJob — Clean up old tokens. [backend.md §3.3](docs/planning/backend.md#3-jobs-appjobs)
- [ ] CleanupOldIntegrationEventsJob — Retain only last 30d. [backend.md §3.3](docs/planning/backend.md#3-jobs-appjobs)
- [ ] IngestAlgorithmUpdatesJob — v2: RFM/LTV model updates. [backend.md §3.3](docs/planning/backend.md#3-jobs-appjobs)

### Integration Clients Test Coverage (5 items)
- [ ] FacebookAdsClient tests — 629 LoC, 0 tests. Rate limit + token expiry. [backend.md §4](docs/planning/backend.md#4-connectors-platform-api-clients)
- [ ] GoogleAdsClient tests — 466 LoC, 0 tests. Rate limit + token expiry. [backend.md §4](docs/planning/backend.md#4-connectors-platform-api-clients)
- [ ] SearchConsoleClient tests — 514 LoC, 0 tests. Rate limit + token expiry. [backend.md §4](docs/planning/backend.md#4-connectors-platform-api-clients)
- [ ] ShopifyConnector tests — 655 LoC, 0 tests. Webhook validation + pagination. [backend.md §4](docs/planning/backend.md#4-connectors-platform-api-clients)
- [ ] WooCommerceConnector tests — Webhook validation + pagination. [backend.md §4](docs/planning/backend.md#4-connectors-platform-api-clients)

### Controller Breakup (15 fat → page-aligned)
- [ ] Break up CampaignsController (1790 LoC) — Split into AdsController (campaigns + creatives view). [backend.md §6](docs/planning/backend.md#6-controllers--breakup-plan)
- [ ] Break up DashboardController — Dashboard view. [backend.md §6](docs/planning/backend.md#6-controllers--breakup-plan)
- [ ] Break up OrdersController — Orders view. [backend.md §6](docs/planning/backend.md#6-controllers--breakup-plan)
- [ ] Break up AttributionController — Attribution view. [backend.md §6](docs/planning/backend.md#6-controllers--breakup-plan)
- [ ] Break up SeoController — SEO view. [backend.md §6](docs/planning/backend.md#6-controllers--breakup-plan)
- [ ] Break up ProductsController — Products view. [backend.md §6](docs/planning/backend.md#6-controllers--breakup-plan)
- [ ] Break up ProfitController — Profit view. [backend.md §6](docs/planning/backend.md#6-controllers--breakup-plan)
- [ ] Break up CustomersController — Customers view. [backend.md §6](docs/planning/backend.md#6-controllers--breakup-plan)
- [ ] Break up IntegrationsController — Integrations view. [backend.md §6](docs/planning/backend.md#6-controllers--breakup-plan)
- [ ] Break up SettingsController (workspace/team/costs/billing/notifications/targets) — Settings sub-pages. [backend.md §6](docs/planning/backend.md#6-controllers--breakup-plan)
- [ ] Create OnboardingController — Onboarding flow (store connection → store setup → ad account setup → pixel setup). [backend.md §6](docs/planning/backend.md#6-controllers--breakup-plan)

### Routes (web.php rebuild)
- [ ] Rebuild web.php — Align to 10 pages + onboarding + settings subroutes + public snapshot. [backend.md §7](docs/planning/backend.md#7-routes)

### Feature Flags
- [ ] Create config/features.php — Phased unlock + v2 gates. [backend.md §10](docs/planning/backend.md#10-feature-flag-approach)

---

## L4 — Design System Primitives (parallel with L3)

Build phases are strict; later phases depend on earlier ones. [frontend.md §4](docs/planning/frontend.md#4-build-order-phased).

### Phase 1 (atoms)
- [ ] SourceBadge — 6 source colors (Store/Facebook/Google/GSC/Site/Real). [frontend.md P1](docs/planning/frontend.md#4-build-order-phased)
- [ ] StatusDot — Enum: success/warning/error/pending. [frontend.md P1](docs/planning/frontend.md#4-build-order-phased)
- [ ] Sparkline — Simple line sparkline. [frontend.md P1](docs/planning/frontend.md#4-build-order-phased)
- [ ] Skeleton — Shimmer placeholders. [frontend.md P1](docs/planning/frontend.md#4-build-order-phased)
- [ ] Entity — Icon + name + truncate for stores/campaigns/products. [frontend.md P1](docs/planning/frontend.md#4-build-order-phased)
- [ ] Tailwind tokens — 6 source colors, inter + JetBrains Mono, 4px grid. [frontend.md P1](docs/planning/frontend.md#4-build-order-phased)

### Phase 2 (molecules)
- [ ] MetricCard + Portfolio/MultiValue/Detail variants — [frontend.md P2](docs/planning/frontend.md#4-build-order-phased), [UX.md §5.1](docs/UX.md#51-metriccard--the-signature-primitive)
- [ ] KpiGrid — 4-column grid of MetricCards. [frontend.md P2](docs/planning/frontend.md#4-build-order-phased)
- [ ] DateRangePicker — [frontend.md P2](docs/planning/frontend.md#4-build-order-phased), [UX.md §5.2](docs/UX.md#52-daterangepicker)
- [ ] FilterChipSentence — [frontend.md P2](docs/planning/frontend.md#4-build-order-phased), [UX.md §5.4](docs/UX.md#54-filterchipsentence)
- [ ] ConfidenceChip — [frontend.md P2](docs/planning/frontend.md#4-build-order-phased), [UX.md §5.27](docs/UX.md#527-confidencechip)
- [ ] SignalTypeBadge — Deterministic/Modeled/Mixed. [frontend.md P2](docs/planning/frontend.md#4-build-order-phased), [UX.md §5.28](docs/UX.md#528-signaltypebadge)
- [ ] LetterGradeBadge — A/B/C/D/F for ads performance. [frontend.md P2](docs/planning/frontend.md#4-build-order-phased), [UX.md §5.33](docs/UX.md#533-lettergradebadge)
- [ ] StatStripe — Horizontal stat row (icon, metric, value, change). [frontend.md P2](docs/planning/frontend.md#4-build-order-phased), [UX.md §5.34](docs/UX.md#534-statstripe)
- [ ] TouchpointString — "Facebook → Google → Direct" compact string. [frontend.md P2](docs/planning/frontend.md#4-build-order-phased), [UX.md §5.35](docs/UX.md#535-touchpointstring)

### Phase 3 (organisms)
- [ ] DataTable + InlineEditableCell — Sticky header, column picker, sortable. [frontend.md P3](docs/planning/frontend.md#4-build-order-phased), [UX.md §5.5](docs/UX.md#55-datatable)
- [ ] LineChart — Recharts with dotted incomplete-period lines. [frontend.md P3](docs/planning/frontend.md#4-build-order-phased)
- [ ] BarChart — Horizontal + vertical variants. [frontend.md P3](docs/planning/frontend.md#4-build-order-phased)
- [ ] CohortHeatmap — Custom SVG (not Recharts). [frontend.md P3](docs/planning/frontend.md#4-build-order-phased)
- [ ] DaypartHeatmap — Hour × Day of week. [frontend.md P3](docs/planning/frontend.md#4-build-order-phased)
- [ ] FunnelChart — Sequential drop-off. [frontend.md P3](docs/planning/frontend.md#4-build-order-phased)
- [ ] QuadrantChart — X/Y scatter with 4 quadrants. [frontend.md P3](docs/planning/frontend.md#4-build-order-phased)
- [ ] LayerCakeChart — Daasity-style stacked cohort view. [frontend.md P3](docs/planning/frontend.md#4-build-order-phased)
- [ ] ProfitWaterfallChart — Custom SVG (not Recharts). [frontend.md P3](docs/planning/frontend.md#4-build-order-phased)
- [ ] EmptyState — Illustration + CTA. [frontend.md P3](docs/planning/frontend.md#4-build-order-phased), [UX.md §5.11](docs/UX.md#511-emptystate)
- [ ] LoadingState — Skeleton shimmer. [frontend.md P3](docs/planning/frontend.md#4-build-order-phased)
- [ ] AlertBanner + DemoBanner — Top-of-page alerts. [frontend.md P3](docs/planning/frontend.md#4-build-order-phased), [UX.md §5.11](docs/UX.md#511-emptystate)
- [ ] Toast — Toast notifications (success/error/info). [frontend.md P3](docs/planning/frontend.md#4-build-order-phased)
- [ ] DrawerSidePanel — Right-side 480px panel with close. [frontend.md P3](docs/planning/frontend.md#4-build-order-phased), [UX.md §5.10](docs/UX.md#510-drawersidepanel)
- [ ] SavedView — UI for saving + switching views. [frontend.md P3](docs/planning/frontend.md#4-build-order-phased), [UX.md §5.19](docs/UX.md#519-savedview)
- [ ] SubNavTabs — Sibling page sections (Segments/Retention/LTV/Audiences). [frontend.md P3](docs/planning/frontend.md#4-build-order-phased)
- [ ] ViewToggle — Switch table/chart view. [frontend.md P3](docs/planning/frontend.md#4-build-order-phased)

### Phase 4 (global chrome)
- [ ] TopBar filter stack (global selectors) — DateRangePicker, AttributionModelSelector, WindowSelector, AccountingModeSelector, SourceToggle, BreakdownSelector, ProfitModeToggle. [frontend.md P4](docs/planning/frontend.md#4-build-order-phased)
- [ ] Sidebar — 240px left nav. [frontend.md P4](docs/planning/frontend.md#4-build-order-phased), [UX.md §3](docs/UX.md#3-global-chrome)
- [ ] WorkspaceSwitcher — Dropdown + Cmd+K. [frontend.md P4](docs/planning/frontend.md#4-build-order-phased)
- [ ] CommandPalette (Cmd+K) — Page/saved-view/settings search. [frontend.md P4](docs/planning/frontend.md#4-build-order-phased)
- [ ] ContextMenu — Right-click filter/exclude/copy. [frontend.md P4](docs/planning/frontend.md#4-build-order-phased)
- [ ] FreshnessIndicator — Data refresh age badge. [frontend.md P4](docs/planning/frontend.md#4-build-order-phased)

### Phase 5 (thesis composites)
- [ ] TrustBar (signature) — Real Revenue + Orders; click to switch to Not Tracked. [frontend.md P5](docs/planning/frontend.md#4-build-order-phased), [UX.md §5.14](docs/UX.md#514-trustbar)
- [ ] TriageInbox — Needs-attention items (Not Tracked, Sync failures, etc.). [frontend.md P5](docs/planning/frontend.md#4-build-order-phased), [UX.md §5.26](docs/UX.md#526-triageinbox)
- [ ] Target (+ TargetLine/TargetProgress) — Goal display + line on charts. [frontend.md P5](docs/planning/frontend.md#4-build-order-phased)
- [ ] ActivityFeed — Real-time chronological events. [frontend.md P5](docs/planning/frontend.md#4-build-order-phased)
- [ ] TodaySoFar — Live revenue + orders YTD. [frontend.md P5](docs/planning/frontend.md#4-build-order-phased), [UX.md §5.25](docs/UX.md#525-todaysofar)
- [ ] ChartAnnotationLayer — Markers on chart for events. [frontend.md P5](docs/planning/frontend.md#4-build-order-phased), [UX.md §6.1](docs/UX.md#61-click-hierarchy-on-data-primitives)
- [ ] EntityHoverCard — Preview card on ID hover. [frontend.md P5](docs/planning/frontend.md#4-build-order-phased), [UX.md §5.18](docs/UX.md#518-entityhovercard)
- [ ] AudienceTraits — Segment audience composition (RFM, channel, etc.). [frontend.md P5](docs/planning/frontend.md#4-build-order-phased)
- [ ] ShareSnapshotButton — Generate + copy public link. [frontend.md P5](docs/planning/frontend.md#4-build-order-phased), [UX.md §5.29](docs/UX.md#529-sharesnapshotbutton)
- [ ] ExportMenu — CSV + format options. [frontend.md P5](docs/planning/frontend.md#4-build-order-phased), [UX.md §5.30](docs/UX.md#530-exportmenu)
- [ ] TabTitleStatus — Page title badge (loading/warning/error). [frontend.md P5](docs/planning/frontend.md#4-build-order-phased)

### Layouts (5 shells)
- [ ] AppLayout (MODIFY) — Global chrome (sidebar, top bar). [frontend.md §2](docs/planning/frontend.md#2-layout-shells)
- [ ] SettingsLayout (NEW) — Settings sub-pages with left nav. [frontend.md §2](docs/planning/frontend.md#2-layout-shells)
- [ ] AuthLayout (KEEP) — Login/signup/forgot-password. [frontend.md §2](docs/planning/frontend.md#2-layout-shells)
- [ ] OnboardingLayout (MODIFY) — Wizard steps + skip option. [frontend.md §2](docs/planning/frontend.md#2-layout-shells)
- [ ] PublicSnapshotLayout (NEW) — Public/snapshot/{token} viewer (no auth required). [frontend.md §2](docs/planning/frontend.md#2-layout-shells)

---

## L5 — Pages (require L3 + L4 primitives for that page)

Order by user value + unblocks. [PLANNING.md §3 L5](docs/PLANNING.md#l5--pages-require-l3--l4-primitives-for-that-page).

- [ ] 1. `/onboarding` — Store connection → Ad account setup → Pixel setup. [pages/onboarding.md](docs/pages/onboarding.md) (ref: UX §12)
- [ ] 2. `/integrations` — Connected stores/ad accounts; Tracking Health; Historical Imports; Channel Mapping. [pages/integrations.md](docs/pages/integrations.md)
- [ ] 3. `/dashboard` — Home page with TrustBar, KpiGrid, charts, triage inbox. [pages/dashboard.md](docs/pages/dashboard.md)
- [ ] 4. `/orders` — Order-by-order ground truth with source attribution. [pages/orders.md](docs/pages/orders.md)
- [ ] 5. `/attribution` — Attribution model comparison + Source Disagreement Matrix. [pages/attribution.md](docs/pages/attribution.md)
- [ ] 6. `/ads` — Campaign performance, creative gallery, triage. [pages/ads.md](docs/pages/ads.md)
- [ ] 7. `/profit` — P&L income statement with cost breakdown. [pages/profit.md](docs/pages/profit.md)
- [ ] 8. `/seo` — GSC keywords, rankings, opportunities. [pages/seo.md](docs/pages/seo.md)
- [ ] 9. `/products` — Product performance, COGS editor, lifecycle labels. [pages/products.md](docs/pages/products.md)
- [ ] 10. `/customers` — RFM segments, cohort retention, LTV curves. [pages/customers.md](docs/pages/customers.md)
- [ ] 11. `/settings/*` — Workspace / Team / Costs / Billing / Notifications / Targets. [pages/settings.md](docs/pages/settings.md)

### Public Snapshot View
- [ ] `/public/snapshot/{token}` — Frozen date-range copy of snapshot (no login). [pages/settings.md](docs/pages/settings.md) §Sharing

---

## L6 — Polish

- [ ] Component tests (Vitest + RTL) — All P1–P5 primitives. [frontend.md §10](docs/planning/frontend.md#10-testing-strategy)
- [ ] Playwright e2e tests — Onboarding flow, filter-on-click, source switch, inline COGS edit, Cmd+K, right-click annotation. [frontend.md §10](docs/planning/frontend.md#10-testing-strategy)
- [ ] A11y audit (WCAG AA) — Focus rings, chart table fallback, keyboard shortcuts. [UX.md §11](docs/UX.md#11-accessibility--keyboard)
- [ ] Performance tuning — Attribution recompute target <5 min / 100k orders. [backend.md §11](docs/planning/backend.md#11-attribution-pipeline)
- [ ] Pricing page — Marketing site (not in-app). [competitors/_crosscut_pricing_ux.md](docs/competitors/_crosscut_pricing_ux.md)

---

## Notes for Coding Agents

**Prompt for agents:**
```
Read CLAUDE.md, then docs/PLANNING.md, then PROGRESS.md.

Find the first [ ] checkbox in PROGRESS.md. Work that task.

When done: Change [ ] to [x] in PROGRESS.md.

If ambiguous, check docs/decisions/ first. If not there, ask.
```

- **Ambiguity:** Check [docs/decisions/](docs/decisions/) for precedent. If not found, ask before starting.
- **Next task:** An agent reading this finds the first [ ] checkbox and works it. After you mark [x], the next agent sees [x] and moves to the next [ ].
