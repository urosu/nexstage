# Target backend architecture — v1 MVP

Designed against [_codebase_audit_backend.md](../_codebase_audit_backend.md) + [_codebase_audit_patterns.md](../_codebase_audit_patterns.md) + [schema.md](./schema.md). Every item tagged **KEEP / MODIFY / NEW / DROP**.

Connectors in v1: WooCommerce · Shopify · Facebook Ads · Google Ads · GSC. No GA4, no Site pixel. Pricing: €39/mo base + 0.4% revenue share. Multi-tenant: 39 models already use `#[ScopedBy(WorkspaceScope::class)]`.

---

## 0. Guiding principles

Ten rules every backend module obeys. Deviations are flagged per-class.

1. **Thin controllers, fat services.** Controllers ≤ ~250 LoC. No SQL or FX math in controllers. Page controllers pre-join server-side and hand flat `BreakdownRow[]` down to `BreakdownView` (CLAUDE.md gotcha).
2. **Every job filters `workspace_id` explicitly.** `WorkspaceScope` is request-bound and throws if `WorkspaceContext::id()` is null. Jobs take `$workspaceId` in constructors and call `app(WorkspaceContext::class)->set(...)` at the top of `handle()` — or use `withoutGlobalScopes()` and filter on `workspace_id` manually.
3. **Every external API has retry + rate-limit + typed exception.** `FacebookRateLimitException`, `GoogleRateLimitException`, `WooCommerceRateLimitException`, `PsiQuotaExceededException`, `FxRateNotFoundException` — each translates to a non-throwing fallback path upstream.
4. **Actions are single-purpose and idempotent.** Each Action does one DB side-effect family. Re-running with the same input must not double-write (upsert keys, not inserts).
5. **Cost / attribution config changes trigger retroactive recalc.** `UpdateCostConfigAction`, `UpdateAttributionDefaultsAction`, channel mapping CRUD — all dispatch a recomputation job (RecomputeAttributionJob, ComputeDailySnapshotJob fan-out, ReclassifyOrdersForMappingJob).
6. **Snapshots are never aggregated at request time.** Page requests hit `daily_snapshots` / `hourly_snapshots` / `daily_snapshot_products`. Building the snapshot is a job. Aggregating `orders` at request time is banned.
7. **FX is DB-first.** `FxRateService` reads `fx_rates` only. `UpdateFxRatesJob` is the single Frankfurter caller. Historical imports prefetch; `RetryMissingConversionJob` cleans NULLs nightly.
8. **Never `SUM` across `ad_insights` levels.** All `ad_insights` queries filter `level IN ('campaign' | 'adset' | 'ad')` to exactly one value. No implicit GROUP BY across levels.
9. **Divide-by-zero discipline.** Ratios (CPM, CPC, CPA, ROAS, CTR, CVR, AOV, MER, LTV:CAC) compute on the fly. SQL uses `NULLIF`. PHP null-checks. UI renders "N/A".
10. **Platform JSONB pairs with `*_api_version`.** `raw_meta`, `raw_insights`, `creative_data`, `lighthouse_snapshots.raw_response`, `integration_events.payload`. Nexstage-owned JSONB (`attribution_*`, `workspace_settings`, `parsed_convention`, `url_state`) does not pair.
11. **One queue per external provider.** 11 queues in `config/horizon.php`. Historical imports isolated on `imports-{store,ads,gsc}` to prevent starvation. Webhooks on `critical-webhooks` with 30s timeout.
12. **Feature flags live in `config/features.php`.** No package. Env-backed, read at call-time. Drives phased unlock (cohort day 7, LTV day 30, predictive day 90) and v2-toggles (benchmarking, white-label).

---

## 1. Services (`app/Services/`)

### 1.1 KEEP services (no shape change)

| Service | Path | Status | Purpose | Public methods | Reads | Writes | Called by |
|---|---|---|---|---|---|---|---|
| RevenueAttributionService | `app/Services/RevenueAttributionService.php` | KEEP | Attribution-based revenue breakdowns by channel/campaign (the "Real" number computer). | `getAttributedRevenue`, `getCampaignAttributedRevenue`, `batchGetCampaignAttributedRevenues`, `getUnrecognizedSources`, `getUnattributedRevenue` | `orders.attribution_*` | — | DashboardController, CampaignsController, AcquisitionController, SeoController, DiscrepancyController, ComputeUtmCoverageJob |
| FxRateService | `app/Services/Fx/FxRateService.php` | KEEP | DB-first EUR-based FX conversion. | `getRate`, `convert` | `fx_rates` | — | Upsert{WC,Shopify}OrderAction, SyncsAdInsights trait, RecomputeReportingCurrencyJob, RetryMissingConversionJob, AdHistoricalImportJob |
| WorkspaceContext | `app/Services/WorkspaceContext.php` | KEEP | Request-scoped singleton for WorkspaceScope. | `set`, `id`, `slug` | — | — | SetActiveWorkspace middleware; every job that touches tenant tables |
| StoreConnectorFactory | `app/Services/StoreConnectorFactory.php` | KEEP | Resolves `StoreConnector` per store platform. | `make` | `stores.platform` | — | RemoveStoreAction, IntegrationsController |
| CampaignNameParserService | `app/Services/CampaignNameParserService.php` | KEEP | Parses `{country}|{campaign}|{target}` convention into `campaigns.parsed_convention`. | `parse`, `parseAndSave` | `products`, `product_categories` | `campaigns.parsed_convention` | SyncsAdInsights trait |
| CommercialEventCalendar | `app/Services/CommercialEventCalendar.php` | KEEP | Curated commercial events for 40+ markets. | `resolve(year)`, `supportedCountries` | hardcoded consts | — | SeedCommercialEventsJob, HolidaysController |
| GeoDetectionService | `app/Services/GeoDetectionService.php` | KEEP | First-login country detection. | `detect(Request)` | CF headers, ip-api fallback | session | OnboardingController, SetActiveWorkspace |
| NarrativeTemplateService | `app/Services/NarrativeTemplateService.php` | KEEP | One-sentence narrative headers per page. | `forDashboard`, `forCampaigns`, `forSeo`, … | — | — | 9 page controllers |
| AttributionParserService | `app/Services/Attribution/AttributionParserService.php` | KEEP | Runs registered `AttributionSource`s in priority order, first-hit-wins. | `parse(Order)`, `debug(Order)` | `orders` via sources | — | Upsert{WC,Shopify}OrderAction, BackfillAttributionDataJob |
| ChannelClassifierService | `app/Services/Attribution/ChannelClassifierService.php` | KEEP | `(utm_source, utm_medium)` → `channel_name + channel_type` with 4-tier workspace→global + literal→regex lookup, Redis-cached 60 min. | `classify`, `cacheKey` | `channel_mappings`, Redis | Redis | AttributionParserService, ManageController (invalidation) |
| AttributionSource × 5 | `app/Services/Attribution/Sources/*.php` | KEEP | PixelYourSite, WooCommerceNative, ReferrerHeuristic, ShopifyCustomerJourney, ShopifyLandingPage. | `tryParse(Order)` | order fields | — | AttributionParserService |
| CogsReaderService | `app/Services/Cogs/CogsReaderService.php` | KEEP | Extract COGS from WC line-item meta across 4 built-in plugin keys + custom keys. | `readFromLineItem` | — | — | UpsertWooCommerceOrderAction |
| PerformanceRevenueService | `app/Services/PerformanceMonitoring/PerformanceRevenueService.php` | KEEP | Lighthouse-joined revenue-at-risk queries. | `monthlyOrdersPerUrl`, `revenueAtRisk` | `orders`, `gsc_pages`, `search_console_properties` | — | PerformanceController |
| PsiClient | `app/Services/PerformanceMonitoring/PsiClient.php` | KEEP | PSI v5 + CrUX + quota tracking in Redis. | `check(url, strategy)` | PSI API | Redis | RunLighthouseCheckJob |

### 1.2 MODIFY services

| Service | Path | Status | Change | Why |
|---|---|---|---|---|
| ProfitCalculator | `app/Services/ProfitCalculator.php` | MODIFY → rename `ProfitCalculatorService` | Read from new cost tables (`store_cost_settings`, `shipping_rules`, `transaction_fee_rules`, `tax_rules`, `opex_allocations`, `platform_fee_rules`) instead of the `StoreCostSettings` VO. Add `completeness(workspaceId)` returning 0-100 score that drives degrade-gracefully ProfitMode. | schema.md §1.6 promotes jsonb VO to dedicated tables |
| MonthlyReportService | `app/Services/MonthlyReportService.php` | MODIFY | Add per-source revenue sections using the 7 new `daily_snapshots` columns. | schema.md §1.5 |
| AiSummaryService | `app/Services/Ai/AiSummaryService.php` | DROP (v1) | UX §2 excludes AI assistant in v1. | — |
| AiCreativeTaggerService | `app/Services/Ai/AiCreativeTaggerService.php` | DROP (v1) | Same. Creative tagging stays rule-based via `creative_tags` taxonomy. | — |

### 1.3 NEW services

| Service | Path | Status | Purpose | Public methods | Reads | Writes | Called by |
|---|---|---|---|---|---|---|---|
| SnapshotBuilderService | `app/Services/Snapshots/SnapshotBuilderService.php` | NEW | Single authoritative builder for `daily_snapshots` + `daily_snapshot_products` + `hourly_snapshots` (currently logic inlined in 3 jobs). Emits the 13 new profit/per-source columns. | `buildDaily(storeId, date)`, `buildHourly(storeId, date)`, `buildProducts(storeId, date, limit=100)` | `orders`, `order_items`, `ad_insights`, `refunds`, `product_variants`, cost tables | `daily_snapshots`, `hourly_snapshots`, `daily_snapshot_products` | ComputeDailySnapshotJob, ComputeHourlySnapshotsJob, BuildDailySnapshotJob (new fan-out) |
| ChannelMappingResolver | `app/Services/Attribution/ChannelMappingResolver.php` | NEW (extract from ChannelClassifierService) | Priority-ordered resolver: workspace literal → workspace regex → global literal → global regex. Reads new `channel_mappings.priority` column. | `resolve(source, medium, workspaceId)` | `channel_mappings`, Redis | Redis | ChannelClassifierService (thin wrapper for back-compat) |
| ConfidenceThresholdService | `app/Services/Trust/ConfidenceThresholdService.php` | NEW | Evaluates `orders_count`, `sessions`, `impressions` against `workspace_settings.confidence_threshold_*`. Returns `SignalType` VO. Drives `SignalTypeBadge` UI. | `evaluate(metric, sampleSize, workspaceId)` | `workspace_settings` jsonb | — | DashboardController, CampaignsController, AcquisitionController, SeoController, StorePageController |
| AnomalyDetectionService | `app/Services/Trust/AnomalyDetectionService.php` | NEW | Evaluates the 4 seeded anomaly rules from `anomaly_rules` table against rolling baselines in `metric_baselines`. Emits rows to `triage_inbox_items`. | `evaluate(workspaceId)`, `ruleTypes()` | `anomaly_rules`, `metric_baselines`, `daily_snapshots`, `ad_insights`, `integration_events` | `triage_inbox_items` | DetectAnomaliesJob |
| SavedViewService | `app/Services/Workspace/SavedViewService.php` | NEW | CRUD + pin ordering for `saved_views`. Shared vs personal semantics. | `create`, `update`, `delete`, `pin`, `forPage`, `forUser` | `saved_views` | `saved_views` | SavedViewController (new), sidebar Inertia shared props |
| AnnotationService | `app/Services/Workspace/AnnotationService.php` | NEW | CRUD for user annotations; appends system annotations (migration, algorithm update, token expiry, cogs update). Hide-per-user for system-authored. | `create`, `update`, `delete`, `hideForUser`, `forChart(scope, range)`, `appendSystem(type, payload)` | `annotations` | `annotations` | AnnotationController (new), system event hooks (OAuth reconnect, cost update, algorithm update ingest) |
| TargetService | `app/Services/Workspace/TargetService.php` | NEW | CRUD for `workspace_targets`; computes progress against `daily_snapshots` / `ad_insights`. | `create`, `update`, `archive`, `progressFor(target)` | `workspace_targets`, `daily_snapshots`, `ad_insights` | `workspace_targets` | TargetsController (new) |
| ShareSnapshotTokenService | `app/Services/Workspace/ShareSnapshotTokenService.php` | NEW | Generate / revoke / validate public-snapshot tokens. Optionally materialises `snapshot_data` jsonb. | `generate(page, urlState, ttl)`, `revoke`, `resolve(token)` | `public_snapshot_tokens` | `public_snapshot_tokens` | PublicSnapshotController (new), GenerateSnapshotTokenAction |
| RfmScoringService | `app/Services/Customers/RfmScoringService.php` | NEW | Computes R/F/M quintile scores + segment assignment over a rolling 365d window; persists to `customer_rfm_scores` per `computed_for` date. | `scoreWorkspace(workspaceId, date)` | `orders`, `customers` | `customer_rfm_scores` | ComputeRfmScoresJob |
| LtvModelingService | `app/Services/Customers/LtvModelingService.php` | NEW (v1 stub, v2 real) | v1: naive LTV = `orders_count × AOV × expected_future_orders_heuristic`. v2: BG/NBD + Gamma-Gamma. Version-tagged via `customer_rfm_scores.model_version`. | `predict(customer)`, `version()` | `orders`, `customers` | `customer_rfm_scores.predicted_ltv_*` | RfmScoringService |
| IntegrationHealthService | `app/Services/Integrations/IntegrationHealthService.php` | NEW | Elevar-style per-destination accuracy over rolling 7d. Synthesized from `integration_events`. Surfaces to `/integrations` Tracking Health tab. | `accuracyPct(workspaceId, destination, 7d)`, `deliveryRate`, `matchQualityDistribution`, `errorCodeBreakdown` | `integration_events` | — | IntegrationsController, DashboardController (Trust-health widget) |
| HistoricalImportOrchestrator | `app/Services/Integrations/HistoricalImportOrchestrator.php` | NEW | Standardises the 4 import jobs. Writes `historical_import_jobs` row, dispatches correct job, tracks progress % via chunk events, broadcasts to frontend via Inertia partial reload. | `start(integration, from, to)`, `progressFor(jobId)`, `resume(jobId)`, `cancel(jobId)` | `historical_import_jobs` | `historical_import_jobs` | StartHistoricalImportAction, IntegrationsController retry flows, TriggerReactivationBackfillJob |
| BillingUsageService | `app/Services/Billing/BillingUsageService.php` | NEW | Computes monthly GMV and 0.4% revenue-share; persists to `billing_revenue_share_usage`; reports to Stripe metered billing. | `computeForPeriod(workspaceId, month)`, `reportToStripe(usageId)` | `daily_snapshots.revenue`, `ad_insights.spend` (fallback) | `billing_revenue_share_usage` | ReportMonthlyRevenueToStripeJob |
| SettingsAuditService | `app/Services/Workspace/SettingsAuditService.php` | NEW | Writes `settings_audit_log` on every Settings mutation. Exposes "Revert" on reversible changes. | `record(subPage, entity, field, from, to, reversible)`, `revert(logId)` | `settings_audit_log` | `settings_audit_log` | Settings controllers + WorkspaceObserver hooks |

### 1.4 DROP (services no longer called)

| Service | Reason |
|---|---|
| AiSummaryService | v1 drops AI assistant (UX §2) |
| AiCreativeTaggerService | v1 drops AI prescriptions; taxonomy is rule-based |

---

## 2. Actions (`app/Actions/`)

### 2.1 KEEP

| Action | Path | Status | Purpose | Input | Side effects |
|---|---|---|---|---|---|
| UpsertShopifyOrderAction | `app/Actions/UpsertShopifyOrderAction.php` | KEEP (add `customer_id`/`shipping_cost_snapshot` writes) | Shopify GraphQL order → `orders` + items + coupons + attribution. | Store, reportingCurrency, raw order node | Upsert `orders`, `order_items`, `order_coupons`; FX; attribution |
| UpsertWooCommerceOrderAction | `app/Actions/UpsertWooCommerceOrderAction.php` | KEEP (same additions) | WC REST order → schema. | Store, reportingCurrency, raw WC order | Upsert `orders`, `order_items`, `order_coupons`; FX; COGS; attribution (flag-gated) |
| UpsertWooCommerceProductAction | `app/Actions/UpsertWooCommerceProductAction.php` | KEEP (add `product_variants` writes) | WC product.updated → products/categories. | Store, raw WC product | Upsert `products`, `product_variants`, `product_categories` |
| StartHistoricalImportAction | `app/Actions/StartHistoricalImportAction.php` | MODIFY | Writes new `historical_import_jobs` row instead of scattered `historical_import_*` cols; dispatches platform job. | Store \| AdAccount \| SearchConsoleProperty, Carbon $fromDate | Insert `historical_import_jobs`; dispatch platform import job |
| ConnectShopifyStoreAction | `app/Actions/ConnectShopifyStoreAction.php` | MODIFY | Credentials → `integration_credentials` (polymorphic) instead of inline cols. | shopDomain, accessToken, Workspace | Insert/update `stores`, `integration_credentials`, `store_webhooks`, `store_urls`; dispatch Lighthouse + UTM-coverage |
| ConnectStoreAction | `app/Actions/ConnectStoreAction.php` | MODIFY | Same credential extraction for WC. | Workspace, {domain, consumer_key, consumer_secret} | Same as above |
| AcceptWorkspaceInvitationAction | `app/Actions/AcceptWorkspaceInvitationAction.php` | KEEP | — | WorkspaceInvitation, User | Insert `workspace_users`; update invitation |
| CreateWorkspaceAction | `app/Actions/CreateWorkspaceAction.php` | KEEP | — | User, domain | Insert `workspaces`, `workspace_users` (14-day trial) |
| DeleteWorkspaceAction | `app/Actions/DeleteWorkspaceAction.php` | KEEP | Soft-delete after Stripe check. | Workspace | Set `workspaces.deleted_at` |
| InviteWorkspaceMemberAction | `app/Actions/InviteWorkspaceMemberAction.php` | KEEP | — | Workspace, email, role | Upsert `workspace_invitations`; queue mail |
| RemoveWorkspaceMemberAction | `app/Actions/RemoveWorkspaceMemberAction.php` | KEEP | — | WorkspaceUser | Delete row |
| RevokeWorkspaceInvitationAction | `app/Actions/RevokeWorkspaceInvitationAction.php` | KEEP | — | WorkspaceInvitation | Delete row |
| TransferWorkspaceOwnershipAction | `app/Actions/TransferWorkspaceOwnershipAction.php` | KEEP | — | Workspace, newOwner | Update roles + owner_id |
| UpdateStoreAction | `app/Actions/UpdateStoreAction.php` | KEEP | — | Store, {name, slug, timezone} | Update `stores` |
| UpdateWorkspaceAction | `app/Actions/UpdateWorkspaceAction.php` | KEEP (audit hook) | Update workspace fields; dispatches `RecomputeReportingCurrencyJob` when currency changes; writes `settings_audit_log`. | Workspace, array | Update `workspaces`; write audit |
| ProductCostImportAction | `app/Actions/ProductCostImportAction.php` | MODIFY | Writes to `product_variants.cogs_amount` (not deprecated `product_costs`). | UploadedFile, Workspace | Bulk upsert `product_variants.cogs_amount` |
| RemoveAdAccountAction | `app/Actions/RemoveAdAccountAction.php` | KEEP | — | AdAccount | Cascade delete |
| RemoveGscPropertyAction | `app/Actions/RemoveGscPropertyAction.php` | KEEP | — | SearchConsoleProperty | Cascade delete |
| RemoveStoreAction | `app/Actions/RemoveStoreAction.php` | KEEP | Platform webhooks removed via connector. | Store | Delete webhooks + store |

### 2.2 NEW actions

| Action | Path | Status | Purpose | Input | Side effects |
|---|---|---|---|---|---|
| ChangeWorkspaceMemberRoleAction | `app/Actions/ChangeWorkspaceMemberRoleAction.php` | NEW | Role change (admin ↔ member); owner change goes through Transfer action. | WorkspaceUser, newRole | Update `workspace_users.role`; audit log |
| UpdateCostConfigAction | `app/Actions/UpdateCostConfigAction.php` | NEW | Settings → Costs mutations across 6 tables. Dispatches `ComputeDailySnapshotJob` fan-out (retroactive). | Workspace, CostConfigDiff | Upsert `store_cost_settings` / `shipping_rules` / `transaction_fee_rules` / `tax_rules` / `opex_allocations` / `platform_fee_rules`; audit log; dispatch snapshot rebuild |
| SetWorkspaceTargetAction | `app/Actions/SetWorkspaceTargetAction.php` | NEW | Create/update a target row. | Workspace, metric, period, target_value, visible_on_pages | Upsert `workspace_targets`; audit log |
| CreateSavedViewAction | `app/Actions/CreateSavedViewAction.php` | NEW | Persist page filter+sort combo. | Workspace, User \| null, page, name, url_state | Insert `saved_views` |
| CreateAnnotationAction | `app/Actions/CreateAnnotationAction.php` | NEW | User-authored chart annotation. | Workspace, User, payload | Insert `annotations` |
| GenerateSnapshotTokenAction | `app/Actions/GenerateSnapshotTokenAction.php` | NEW | `ShareSnapshotButton` backing action. | Workspace, User, page, url_state, ttl | Insert `public_snapshot_tokens` |
| RevokeSnapshotTokenAction | `app/Actions/RevokeSnapshotTokenAction.php` | NEW | Owner revokes a share. | PublicSnapshotToken | Set `revoked_at` |
| ReconnectIntegrationAction | `app/Actions/ReconnectIntegrationAction.php` | NEW | Rotate credentials without deleting history. | integrationable (polymorphic), new credentials | Update `integration_credentials`; clear `consecutive_sync_failures`; dispatch catch-up sync |
| DisconnectIntegrationAction | `app/Actions/DisconnectIntegrationAction.php` | NEW | Soft-disconnect (keep history, stop syncs). | integrationable | Set `status='disconnected'`; drop tokens from `integration_credentials` |
| UpsertCustomerAction | `app/Actions/UpsertCustomerAction.php` | NEW | Stitches order into `customers` (by `workspace_id + email_hash`). Called inside order upserts. | Workspace, Store, emailHash, name, country | Upsert `customers`; increment `orders_count`, `lifetime_value_*` |
| ApplyLtvOverrideAction | `app/Actions/ApplyLtvOverrideAction.php` | NEW | `/customers` inline-edit exclude-corporate. | Customer, User, amount, reason | Insert/update `customer_ltv_overrides`; recompute customer metrics |

---

## 3. Jobs (`app/Jobs/`)

### 3.1 Queue map (11 queues from `config/horizon.php`)

| Queue | Wait threshold | Timeout | Tries | Provider |
|---|---:|---:|---:|---|
| `critical-webhooks` | 30 s | 30 s | 5 | WC + Shopify webhooks |
| `sync-facebook` | 300 s | 300 s | 3 | Facebook Ads |
| `sync-google-ads` | 300 s | 300 s | 3 | Google Ads |
| `sync-google-search` | 300 s | 300 s | 3 | GSC |
| `sync-store` | 300 s | 300 s | 3 | WC + Shopify sync |
| `sync-psi` | 300 s | 300 s | 3 | PageSpeed Insights |
| `imports-store` | 600 s | 7200 s | 3 | WC + Shopify historical |
| `imports-ads` | 600 s | 7200 s | 3 | FB + Google historical |
| `imports-gsc` | 600 s | 7200 s | 3 | GSC historical |
| `default` | 120 s | 300 s | 3 | Token refresh, digest send |
| `low` | 600 s | 7200 s | 3 | Snapshots, FX, cleanup, backfills |

### 3.2 KEEP jobs

| Job | Queue | Schedule | Status | Purpose | `ws_id` filter? |
|---|---|---|---|---|---|
| WooCommerceHistoricalImportJob | `imports-store` | — | KEEP (MODIFY: write `historical_import_jobs`) | 30-day reverse chunks; FX prefetch; checkpointed. | yes |
| ShopifyHistoricalImportJob | `imports-store` | — | KEEP (same MODIFY) | 30-day reverse chunks via ShopifyConnector. | yes |
| AdHistoricalImportJob | `imports-ads` | — | KEEP (same MODIFY) | FB async reports; Google 30-day chunks; uses SyncsAdInsights trait. | yes |
| GscHistoricalImportJob | `imports-gsc` | — | KEEP (same MODIFY) | 5-day `Http::pool` concurrent; 16-month clamp. | yes |
| SyncAdInsightsJob | `sync-facebook` \| `sync-google-ads` | hourly per account + hourly catchup | KEEP | 3-day rolling insights; structure sync gated 23h. | yes |
| SyncSearchConsoleJob | `sync-google-search` | every 6h per property + hourly catchup | KEEP | Last-5-days via `Http::pool`. | yes |
| SyncShopifyOrdersJob | `sync-store` | — | KEEP | Orders updated in last 2h. | yes |
| SyncShopifyProductsJob | `sync-store` | daily 02:00 | KEEP | Full product re-upsert. | yes |
| SyncShopifyRefundsJob | `sync-store` | daily 03:30 | KEEP | 7-day refund backfill. | yes |
| SyncShopifyInventorySnapshotJob | `sync-store` | daily 03:00 | KEEP (MODIFY: write `product_variants.cogs_amount`) | Variant unit-cost sync. | yes |
| SyncStoreOrdersJob | `sync-store` | — | KEEP | On-demand WC order sync. | yes |
| SyncProductsJob | `sync-store` | daily 02:00 | KEEP | Full WC product re-upsert. | yes |
| SyncRecentRefundsJob | `sync-store` | daily 03:30 | KEEP | 7-day WC refund backfill. | yes |
| PollStoreOrdersJob / PollShopifyOrdersJob | `sync-store` | hourly | KEEP | Fallback when webhooks stale >90 min. | yes |
| ReconcileStoreOrdersJob | `low` | daily 01:30 | KEEP | WC API vs local drift check. | yes |
| ProcessWebhookJob | `critical-webhooks` | — | KEEP | WC webhook router. | yes (sets ctx) |
| ProcessShopifyWebhookJob | `critical-webhooks` | — | KEEP | Shopify webhook router; re-fetches full order for journey data. | yes (sets ctx) |
| UpdateFxRatesJob | `low` | daily 06:00 | KEEP | **Sole Frankfurter caller**. | global |
| RetryMissingConversionJob | `low` | daily 07:00 | KEEP | Retry NULL `total_in_reporting_currency` with fresh FX. | yes (iterates) |
| RecomputeReportingCurrencyJob | `low` | — | KEEP | On workspace currency change. | yes |
| RefreshOAuthTokenJob | `default` | daily 05:00 | KEEP | Proactive refresh 24h-expiring Google tokens. | yes (iterates) |
| RunLighthouseCheckJob | `sync-psi` | daily 04:00 mobile + 04:00+35s desktop | KEEP | PSI check per `store_urls`. | yes |
| ReportMonthlyRevenueToStripeJob | `low` | monthly 1st 06:00 | MODIFY | Delegate to `BillingUsageService`; write `billing_revenue_share_usage`. | yes (iterates) |
| GenerateMonthlyReportJob | `low` | monthly 1st 08:00 | KEEP | Monthly PDF via MonthlyReportService. | yes |
| SendDailyDigestJob | `default` | via DispatchDailyDigestsJob | KEEP | Daily/weekly digest email. | yes |
| DispatchDailyDigestsJob | invokable | hourly | KEEP | Finds workspaces at local 05:00. | yes |
| SendHolidayNotificationsJob | `low` | daily 09:00 | KEEP | Holiday lead-day email. | yes |
| RefreshHolidaysJob | `low` | yearly Jan 1 00:15 | KEEP | yasumi populate per country+year. | global |
| SeedCommercialEventsJob | `low` | monthly 1st 00:20 | KEEP | Seeds current + next year. | global |
| DetectStockTransitionsJob | `low` | daily 01:15 | KEEP (MODIFY: write `triage_inbox_items`) | Yesterday vs day-before stock diff → inbox. | yes |
| BackfillAttributionDataJob | `low` | — | KEEP | Re-process orders through attribution pipeline. | yes |
| ReclassifyOrdersForMappingJob | `low` | — | KEEP | Merge new channel mapping into historical orders. | yes |
| ComputeUtmCoverageJob | `low` | daily 03:45 per `has_store+has_ads` | KEEP (MODIFY: read from `stores`/`ad_accounts` existence, not dropped `workspaces.has_*` flags) | UTM coverage %. | yes |
| TriggerReactivationBackfillJob | `low` | — | KEEP | Dispatches import jobs on workspace reactivation. | yes |
| CleanupOldSyncLogsJob | `low` | weekly Sun 03:00 | MODIFY → `CleanupOldIntegrationRunsJob` | Rename to match `integration_runs`; 90d retention. | global |
| CleanupOldWebhookLogsJob | `low` | weekly Sun 03:15 | DROP | `integration_events` replaces `webhook_logs`. Retention handled by new `CleanupOldIntegrationEventsJob`. | — |
| PurgeDeletedWorkspaceJob | `low` | weekly Sun 05:00 | KEEP | Hard-delete soft-deleted >30d. | global |
| ComputeProductAffinitiesJob | — | — | DROP (v1) | `product_affinities` table dropped; drawer uses co-occurrence on the fly. | — |
| GenerateAiSummaryJob | — | — | DROP (v1) | `ai_summaries` dropped. | — |
| TagCreativesWithAiJob | — | — | DROP (v1) | AI tagging dropped. | — |

### 3.3 NEW jobs

| Job | Queue | Schedule | Purpose | Dispatched by | `ws_id` filter? |
|---|---|---|---|---|---|
| BuildDailySnapshotJob | `low` | daily 00:30 per active store | Thin wrapper over `SnapshotBuilderService::buildDaily`. Replaces `ComputeDailySnapshotJob` ownership of the snapshot logic (job shrinks to a caller). | DispatchDailySnapshots invokable; UpdateCostConfigAction fan-out | yes |
| BuildHourlySnapshotJob | `low` | daily 00:45 per active store | Wrapper over `buildHourly`. | DispatchHourlySnapshots | yes |
| ComputeRfmScoresJob | `low` | nightly 02:15 per workspace | Computes `customer_rfm_scores` for prior day; depends on snapshots being built. | schedule | yes |
| DetectAnomaliesJob | `low` | hourly per active workspace | Evaluates 4 `anomaly_rules` → writes `triage_inbox_items`. | schedule | yes |
| DeliverDigestJob | `default` | via DigestScheduleDispatcher (hourly) | Honors `digest_schedules` (daily/weekly/monthly cadence, recipients jsonb). Replaces old hard-coded 05:00 logic. | DigestScheduleDispatcher | yes |
| RecomputeAttributionJob | `low` | — | On attribution model/window/cost change: re-derive `orders.attribution_*` + rebuild snapshots. Target: 100k orders in <5 min via chunked UPDATE. | UpdateCostConfigAction, SetAttributionDefaultsAction | yes |
| ExpirePublicSnapshotTokensJob | `low` | daily 04:15 | Revokes `public_snapshot_tokens` past `expires_at`. | schedule | global |
| CleanupOldIntegrationEventsJob | `low` | weekly Sun 03:15 | 30-day PII retention on `integration_events.payload`. | schedule | global |
| IngestAlgorithmUpdatesJob | `low` | weekly Mon 04:00 | Refresh seeded `google_algorithm_updates` from manual list (v1) / published feed (v2). On new row, append `annotations` with `annotation_type='algorithm_update'`. | schedule | global |

### 3.4 Catchup / self-healing (KEEP scheduler closures)

`ad-insights-catchup`, `search-console-catchup`, `lighthouse-catchup-{mobile,desktop}`, `detect-stuck-imports` (every 10 min), `close-stuck-sync-logs` (hourly — rename to `close-stuck-integration-runs`). All kept.

---

## 4. Connectors (platform API clients)

| Connector | Path | Current LoC | Test coverage | API version pinned | Rate-limit strategy | Retry | Error → `integration_events` |
|---|---|---:|---|---|---|---|---|
| FacebookAdsClient | `app/Services/Integrations/Facebook/FacebookAdsClient.php` | 629 | 0 | `v25.0` (hardcoded) | Proactive backoff on `X-Business-Use-Case-Usage` + `X-FB-Ads-Insights-Throttle`; dev-tier 57%, standard 88%; `Retry-After` on error; jitter ±20%. | 3 tries, exponential backoff | `FacebookRateLimitException` / `FacebookTokenExpiredException` / `FacebookApiException` → `integration_events.status='failed'` + `error_code` from Meta + `error_category` (one of `rate_limit` / `token_expired` / `permission` / `temporary`) |
| GoogleAdsClient | `app/Services/Integrations/Google/GoogleAdsClient.php` | 466 | 0 | `v23` | Token refresh 5 min pre-expiry; 429/RESOURCE_EXHAUSTED → `GoogleRateLimitException` + `Retry-After`. | 3 tries | `GoogleAccountDisabledException` → `error_category='account_disabled'`; generic → `error_category='temporary'` |
| SearchConsoleClient | `app/Services/Integrations/SearchConsole/SearchConsoleClient.php` | 514 | 0 | `v3` | `Http::pool` HTTP/2; rowLimit 25k; 429 → `gsc_throttled_until`. | 3 tries | Same exception mapping |
| ShopifyConnector (+ GraphQlClient) | `app/Services/Integrations/Shopify/ShopifyConnector.php` | 655 | 0 | `2026-04` (`config/shopify.php`) | Leaky-bucket cost tracking; sleeps when remaining <100. | job `tries` | HTTP error → `integration_events` inbound row on webhook path; sync errors → `integration_runs.error_message` |
| WooCommerceConnector (+ WooCommerceClient) | `app/Services/Integrations/WooCommerce/WooCommerceConnector.php` | 525 | 1 (`WooCommerceClientTest`) | `v3` | 500ms between pages; 429 → `WooCommerceRateLimitException`; SSRF-hardened base URL (HTTPS prod, reject IPs/private TLDs). | 3 tries | Same |
| PsiClient | `app/Services/PerformanceMonitoring/PsiClient.php` | — | 0 | `v5` | Hard quota → pause to midnight UTC; soft 5 min. | 2 tries | `PsiQuotaExceededException` → `integration_events.error_category='quota_exceeded'` |

**Test coverage is load-bearing work.** All six connectors need unit tests for: rate-limit backoff path, token refresh path, error-to-exception mapping, pagination cursor handling. Currently 1/6 has any tests.

**Contract:** `app/Contracts/StoreConnector.php` — `testConnection`, `syncOrders`, `syncProducts`, `syncRefunds`, `registerWebhooks`, `removeWebhooks`, `getStoreInfo`, `supportsHistoricalCogs`, `supportedAttributionFeatures`, `supportsMultiTouch`. Resolved via `StoreConnectorFactory::make(Store)`.

---

## 5. Value Objects (`app/ValueObjects/`)

| VO | Path | Status | Purpose | Fields |
|---|---|---|---|---|
| ParsedAttribution | `app/ValueObjects/ParsedAttribution.php` | KEEP | Normalised attribution result written to `orders.attribution_*`. Immutable. | `source_type`, `first_touch?`, `last_touch?`, `click_ids?`, `channel?`, `channel_type?`, `raw_data?` |
| StoreCostSettings | `app/ValueObjects/StoreCostSettings.php` | DEPRECATE (VO → dedicated tables) | Cast for `stores.cost_settings` jsonb. Replaced by `store_cost_settings` + 5 sibling tables per schema.md §1.6. VO kept one migration cycle as a read-through shim. | — |
| WorkspaceSettings | `app/ValueObjects/WorkspaceSettings.php` | MODIFY | Add 7 new fields for confidence thresholds + attribution defaults + anomaly thresholds. | + `default_attribution_model`, `default_window`, `default_accounting_mode`, `default_profit_mode`, `default_breakdown`, `confidence_threshold_orders/sessions/impressions`, `anomaly_threshold_platform_overreport_pct`, `anomaly_threshold_real_vs_store_pct`, `anomaly_threshold_spend_dod_pct`, `anomaly_threshold_integration_down_hours` |
| AttributionWindow | `app/ValueObjects/AttributionWindow.php` | NEW | `{days: 1\|7\|28\|ltv, model: first_touch\|last_touch\|last_non_direct\|linear\|data_driven}`. Passed to `RevenueAttributionService` query methods. | `days`, `model` |
| SourceDisagreement | `app/ValueObjects/SourceDisagreement.php` | NEW | Output of Source Disagreement Matrix computation. | `store`, `facebook`, `google`, `gsc`, `site`, `real`, `delta_pct`, `winner` |
| SignalType | `app/ValueObjects/SignalType.php` | NEW | UI badge data: one of `measured` / `mixed` / `modeled` / `insufficient`. | `type`, `sample_size`, `threshold`, `reason?` |
| CostConfigDiff | `app/ValueObjects/CostConfigDiff.php` | NEW | Payload for `UpdateCostConfigAction`. | `shipping?`, `transaction_fees?`, `tax?`, `opex?`, `platform_fees?`, `affects_dates?` (for selective snapshot rebuild) |
| IntegrationHealth | `app/ValueObjects/IntegrationHealth.php` | NEW | Elevar-style health card payload. | `destination`, `accuracy_pct`, `delivery_rate`, `match_quality_p50`, `match_quality_p99`, `top_errors[]`, `window_days` |

---

## 6. Controllers — breakup plan

Target: every controller ≤ ~250 LoC; controllers map 1:1 (or 1:n when tabs split) to `docs/pages/*.md`.

| Current controller | LoC | Proposed split | Maps to pages |
|---|---:|---|---|
| CampaignsController | 1790 | → `CampaignsController` (index/table), `CampaignLevelController` (level=campaign/adset/ad toggle handling), `CreativeGalleryController` (grid + drawer), `AdPacingController` (pacing widget) | `pages/ads.md` |
| StorePageController | 1436 | → `StoreProductsController`, `StoreCustomersController` (v1 stub), `StoreCohortsController`, `StoreCountriesController`, `StoreOrdersController` (5 tabs, one controller each) | `pages/store.md` (5 tabs) |
| AcquisitionController | 1323 | → `AcquisitionChannelsController`, `AcquisitionDisagreementController` (Platform-vs-Real), `AcquisitionJourneysController` | `pages/attribution.md` |
| DashboardController | 1222 | → `DashboardController` (hero + sections), `DashboardTriageController` (TriageInbox compact), `DashboardBannerController` (NotTracked banner dismiss) | `pages/dashboard.md` |
| AdminController | 1202 | → `AdminOverviewController`, `AdminLogsController`, `AdminQueueController`, `AdminWorkspacesController`, `AdminUsersController`, `AdminHealthController`, `AdminAlertsController`, `AdminDevController`, `AdminAttributionDebugController`, `AdminChannelMappingsController` | Admin (internal) |
| AnalyticsController | 993 | → `ProductDetailController` (drawer + product show); `/analytics/*` list pages are 301 redirects | `pages/products.md` |
| StoreController | 908 | → `StoresIndexController`, `StoreOverviewController`, `StoreSettingsController` (splits overview vs settings) | Settings · store identity |
| GoogleOAuthController | 883 | → `GoogleOAuthController` (redirect+callback), `GoogleAdsSelectController`, `GscSelectController` | OAuth flows |
| ManageController | 663 | → `ManageTagGeneratorController`, `ManageNamingConventionController`, `ManageChannelMappingsController`, `ManageProductCostsController` | `/manage/*` |
| PerformanceController | 610 | → `PerformanceController` (single page) — within-file extract services, not controllers | `pages/performance.md` (future `/site`) |
| IntegrationsController | 595 | → `IntegrationsController` (show), `IntegrationSyncController` (retry/reimport/sync verbs), `IntegrationHealthController` (Tracking Health tab) | `pages/integrations.md` |
| SeoController | 585 | → `SeoController` (hero + tabs), inline services for Pages/Queries sub-queries | `pages/seo.md` |
| BillingController | 536 | → `BillingController` (show/subscribe/cancel/resume), `BillingPaymentMethodsController`, `BillingInvoicesController` | Settings · billing |
| FacebookOAuthController | 518 | → `FacebookOAuthController` (redirect+callback), `FacebookAdAccountSelectController` | OAuth |
| AdsController / AdSetsController | 512 / 484 | DROP (legacy 301 redirects to `/campaigns?level=ad`) | — |

**New controllers** (NEW):

| Controller | Maps to |
|---|---|
| ProfitController | `pages/profit.md` |
| CustomersController (v1 skeleton; full in v2 per phased unlock) | `pages/customers.md` |
| AlertsController | `pages/alerts.md` (full-page triage) |
| AttributionController | `pages/attribution.md` (Time Machine drill) |
| SavedViewController | CRUD for `saved_views` (shared partial across pages) |
| AnnotationController | CRUD for `annotations` |
| TargetsController | Settings · Targets |
| PublicSnapshotController | `/public/snapshot/{token}` unauthenticated render |
| NotificationsController | Settings · Notifications (digests, Slack, anomaly rules) |
| CostsController | Settings · Costs |
| SlackOAuthController | Slack install flow for `slack_webhooks` |

---

## 7. Routes (target `routes/web.php`)

Middleware: `auth`, `verified`, `onboarded`, `super_admin` where applicable. All data pages under `Route::prefix('{workspace:slug}')`. `web.php` keeps the `/webhooks/{wc,shopify}/{id}` pair in `api.php`. **No public API in v1.**

### 7.1 Data pages (workspace-scoped)

| HTTP | Path | Controller@action | Middleware |
|---|---|---|---|
| GET | `/{workspace:slug}/` | DashboardController@__invoke | auth, verified, onboarded |
| POST | `/{workspace:slug}/dashboard/dismiss-banner/{banner}` | DashboardBannerController@dismiss | same |
| GET | `/{workspace:slug}/store` | StorePageController@__invoke | same (+ `?tab=` routes to one of 5 sub-controllers) |
| GET | `/{workspace:slug}/ads` | CampaignsController@__invoke | same (rename `/campaigns` → `/ads`) |
| GET | `/{workspace:slug}/ads/creatives` | CreativeGalleryController@__invoke | same |
| GET | `/{workspace:slug}/attribution` | AttributionController@__invoke | same |
| GET | `/{workspace:slug}/acquisition` | AcquisitionChannelsController@__invoke | same (kept as alias of `/attribution?tab=channels`) |
| GET | `/{workspace:slug}/seo` | SeoController@__invoke | same |
| GET | `/{workspace:slug}/products` | StoreProductsController@__invoke | same |
| GET | `/{workspace:slug}/products/{product}` | ProductDetailController@show | same |
| GET | `/{workspace:slug}/profit` | ProfitController@__invoke | same |
| GET | `/{workspace:slug}/customers` | CustomersController@__invoke | same |
| GET | `/{workspace:slug}/orders` | StoreOrdersController@__invoke | same |
| GET | `/{workspace:slug}/orders/{order}` | OrdersController@show | same |
| GET | `/{workspace:slug}/alerts` | AlertsController@__invoke | same |
| GET | `/{workspace:slug}/inbox` | redirect → `/alerts` | same |
| GET | `/{workspace:slug}/performance` | PerformanceController@__invoke | same (future `/site`) |
| GET | `/{workspace:slug}/holidays` | HolidaysController@index | same |
| GET | `/{workspace:slug}/help/data-accuracy` | closure → `Help/DataAccuracy` | same |

### 7.2 Workspace primitives

| HTTP | Path | Controller@action |
|---|---|---|
| POST | `/{workspace:slug}/annotations` | AnnotationController@store |
| PATCH | `/{workspace:slug}/annotations/{id}` | AnnotationController@update |
| DELETE | `/{workspace:slug}/annotations/{id}` | AnnotationController@destroy |
| POST | `/{workspace:slug}/annotations/{id}/hide` | AnnotationController@hide (per-user hide of system annotations) |
| POST | `/{workspace:slug}/saved-views` | SavedViewController@store |
| PATCH | `/{workspace:slug}/saved-views/{id}` | SavedViewController@update |
| DELETE | `/{workspace:slug}/saved-views/{id}` | SavedViewController@destroy |
| POST | `/{workspace:slug}/share-snapshots` | PublicSnapshotController@generate |
| DELETE | `/{workspace:slug}/share-snapshots/{id}` | PublicSnapshotController@revoke |
| GET | `/public/snapshot/{token}` | PublicSnapshotController@render (unauthenticated) |

### 7.3 Settings

| HTTP | Path | Controller@action |
|---|---|---|
| GET/PATCH/DELETE | `/{workspace:slug}/settings/workspace` | WorkspaceSettingsController |
| GET | `/{workspace:slug}/settings/team` | WorkspaceTeamController@index |
| POST | `/{workspace:slug}/settings/team/invite` | WorkspaceInvitationController@store |
| PATCH/DELETE | `/{workspace:slug}/settings/team/members/{id}` | WorkspaceMemberController |
| POST | `/{workspace:slug}/settings/team/transfer` | WorkspaceMemberController@transfer |
| GET | `/{workspace:slug}/settings/costs` | CostsController@show |
| PATCH | `/{workspace:slug}/settings/costs/shipping` | CostsController@updateShipping |
| PATCH | `/{workspace:slug}/settings/costs/fees` | CostsController@updateFees |
| PATCH | `/{workspace:slug}/settings/costs/tax` | CostsController@updateTax |
| PATCH | `/{workspace:slug}/settings/costs/opex` | CostsController@updateOpex |
| PATCH | `/{workspace:slug}/settings/costs/platform` | CostsController@updatePlatformFees |
| GET / POST | `/{workspace:slug}/settings/costs/product-variants/{id}` | CostsController@{editCogs, updateCogs} |
| GET | `/{workspace:slug}/settings/targets` | TargetsController@index |
| POST / PATCH / DELETE | `/{workspace:slug}/settings/targets/{id?}` | TargetsController |
| GET | `/{workspace:slug}/settings/notifications` | NotificationsController@show |
| POST | `/{workspace:slug}/settings/notifications/digests` | NotificationsController@updateDigest |
| POST | `/{workspace:slug}/settings/notifications/anomaly-rules` | NotificationsController@updateRules |
| POST | `/{workspace:slug}/settings/notifications/slack/connect` | SlackOAuthController@redirect |
| GET | `/{workspace:slug}/settings/integrations` | IntegrationsController@show |
| POST | `/{workspace:slug}/settings/integrations/{morphable}/{id}/sync` | IntegrationSyncController@sync |
| POST | `/{workspace:slug}/settings/integrations/{morphable}/{id}/retry-import` | IntegrationSyncController@retry |
| POST | `/{workspace:slug}/settings/integrations/{morphable}/{id}/reimport` | IntegrationSyncController@reimport |
| POST | `/{workspace:slug}/settings/integrations/{morphable}/{id}/reconnect` | ReconnectIntegrationController@update |
| DELETE | `/{workspace:slug}/settings/integrations/{morphable}/{id}` | DisconnectIntegrationController@destroy |
| GET | `/{workspace:slug}/settings/billing` | BillingController@show |
| … billing subroutes … | (KEEP existing 10 routes) | |

### 7.4 OAuth + webhooks (KEEP)

OAuth flows unchanged: `/oauth/facebook*`, `/oauth/google/{ads,gsc}*`, `/shopify/{install,callback}`. Webhooks in `api.php`: `POST /webhooks/woocommerce/{id}`, `POST /webhooks/shopify/{id}`.

### 7.5 Admin (KEEP; split controllers per §6)

All `/admin/*` routes stay; handlers route to split controllers. `super_admin` middleware.

---

## 8. Multi-tenancy

- **`#[ScopedBy([WorkspaceScope::class])]` on all tenant models** — 39 models already; new tables (customers, customer_rfm_scores, customer_ltv_overrides, product_variants, store_cost_settings, shipping_rules, transaction_fee_rules, tax_rules (workspace rows), opex_allocations, platform_fee_rules, integration_credentials, integration_runs, integration_events, historical_import_jobs, annotations, saved_views, workspace_targets, public_snapshot_tokens, digest_schedules, slack_webhooks, anomaly_rules, triage_inbox_items, settings_audit_log, billing_revenue_share_usage) apply the same attribute. Total target: ~62 scoped models.
- **`WorkspaceContext` throws** when `id()` is null — no silent fallback. Jobs that operate on global tables (`fx_rates`, `holidays`, `google_algorithm_updates`, `channel_mappings` global rows, `tax_rules` global rows) explicitly use `withoutGlobalScopes()`.
- **Job rule:** every job filters `workspace_id` explicitly or sets `WorkspaceContext` at the top of `handle()`. Scheduler closures use `Model::query()->withoutGlobalScope(WorkspaceScope::class)` before per-tenant dispatch.
- **Portfolio view** (3+ workspaces in an agency) is enforced at controller layer via `?view=portfolio` query param. Controller iterates `workspaces WHERE billing_workspace_id = :parentId OR id = :parentId` and fans queries out; no new tables required.
- **Agency billing consolidation** via `workspaces.billing_workspace_id` self-FK. `BillingUsageService::computeForPeriod` rolls children up to parent on report.

---

## 9. Queue strategy

### 9.1 Existing 11-queue topology (KEEP)

Per `config/horizon.php` — unchanged shape. Process counts per environment (prod / local):

| Queue | Processes (prod) | Processes (local) |
|---|---:|---:|
| `critical-webhooks` | 10 | 2 |
| `sync-facebook` | 2 | 1 |
| `sync-google-ads` | 2 | 1 |
| `sync-google-search` | 2 | 1 |
| `sync-store` | 10 | 3 |
| `sync-psi` | 2 | 1 |
| `imports-store` | 10 | 3 |
| `imports-ads` | 2 | 1 |
| `imports-gsc` | 2 | 1 |
| `default` | 5 | 2 |
| `low` | 3 | 1 |

Memory limit 64 MB per worker; `fast_termination = false`; `trim` failed retention 7 days.

### 9.2 New job → queue mapping

| Job | Queue | Reason |
|---|---|---|
| BuildDailySnapshotJob / BuildHourlySnapshotJob | `low` | non-user-blocking, tolerant of delay |
| ComputeRfmScoresJob | `low` | nightly |
| DetectAnomaliesJob | `low` | hourly, cheap queries |
| DeliverDigestJob | `default` | blocking on email latency; modest parallelism |
| RecomputeAttributionJob | `low` | chunked 10k rows per run; SLA 5 min for 100k |
| ExpirePublicSnapshotTokensJob | `low` | cleanup |
| CleanupOldIntegrationEventsJob | `low` | cleanup |
| IngestAlgorithmUpdatesJob | `low` | weekly |

### 9.3 Horizon config target (MODIFY)

- Add `waits.redis:low = 600` (already present).
- Raise `sync-store` processes prod 10 → 15 if Shopify webhook backlog exceeds 30s during BFCM (observation-based, post-launch).
- Add `supervisor-digests` for `default` queue separation if digest send blocks token refresh. Defer.

---

## 10. Feature flags

**Current (3):** `config('attribution.parser_enabled')`, `config('services.facebook.api_tier')`, `config('geo.lookup_enabled')`.

**Target:** new `config/features.php` with env-backed keys, no package.

```php
// config/features.php (NEW)
return [
    // Trust / attribution
    'attribution_parser_enabled' => env('ATTRIBUTION_PARSER_ENABLED', true),
    'multi_touch_enabled' => env('FEATURE_MULTI_TOUCH', false), // v1.5

    // Phased unlock (days since workspace created)
    'cohort_analysis_unlock_days' => env('FEATURE_COHORT_UNLOCK_DAYS', 7),
    'ltv_basic_unlock_days' => env('FEATURE_LTV_BASIC_UNLOCK_DAYS', 30),
    'ltv_predictive_unlock_days' => env('FEATURE_LTV_PREDICTIVE_UNLOCK_DAYS', 90),

    // v2 toggles (schema-ready, UI-disabled)
    'benchmarking_enabled' => env('FEATURE_BENCHMARKING', false),
    'white_label_enabled' => env('FEATURE_WHITE_LABEL', false),
    'subscription_commerce_enabled' => env('FEATURE_SUBSCRIPTION_COMMERCE', false),
    'ai_assistant_enabled' => env('FEATURE_AI_ASSISTANT', false),

    // External API tiers
    'facebook_api_tier' => env('FB_API_TIER', 'dev'), // dev | standard
    'geo_lookup_enabled' => env('GEO_LOOKUP_ENABLED', true),
];
```

**Consumed by:**
- `CustomersController`: gates Retention tab by `cohort_analysis_unlock_days` + workspace age; LTV columns by `ltv_*_unlock_days`.
- `AttributionController`: `multi_touch_enabled` toggles the Time Machine multi-touch scrubber.
- `IntegrationHealthService`: `benchmarking_enabled` gates cross-workspace benchmark overlays.
- `AgencySettingsController`: `white_label_enabled` gates agency logo/color surface.

**Per-workspace overrides** (where needed) ride on `workspace_settings` jsonb — not feature flags (flags are environment-level; workspace toggles are settings).

---

## 11. Attribution pipeline

### Input

- `ad_insights` (spend + platform_conversions + platform_conversions_value per campaign/adset/ad × date × hour).
- `orders.attribution_first_touch` / `attribution_last_touch` / `attribution_last_non_direct` / `attribution_click_ids` — populated by `AttributionParserService` on ingest.
- `channel_mappings` — `ChannelMappingResolver` cache (Redis 60 min).

### Output

- Query-time read: `RevenueAttributionService` returns attributed-revenue-by-source (no write).
- Materialised: `daily_snapshots.revenue_{facebook,google,gsc,direct,organic,email,real}_attributed` columns (7 per-source + real). Built by `SnapshotBuilderService`.

### Triggers → `RecomputeAttributionJob`

1. Workspace changes `default_attribution_model` or `default_window` → re-derive `orders.attribution_real_touch` (the computed winner column) + rebuild `daily_snapshots` for the affected date range.
2. Channel mapping CRUD → `ReclassifyOrdersForMappingJob` merges new `channel`/`channel_type` into `orders.attribution_last_touch` for matching orders + dispatches snapshot rebuild.
3. Cost config changes → no attribution impact; only profit columns on `daily_snapshots` rebuild.

### Performance target

- **Recompute 100k orders in <5 min.** Achieved via chunked UPDATE (10k rows/batch), PostgreSQL JSONB `jsonb_set` in-place updates, partial indexes on `attribution_last_touch->>'source'` functional expressions (existing), parallel chunk processing where chunks are disjoint date ranges.

### Write path

Order ingest → `AttributionParserService::parse(Order)` → iterates 5 sources priority-ordered, first-hit-wins → `ChannelClassifierService::withChannel()` enriches channel/type → Action writes `orders.attribution_*` jsonb + scalar `attribution_source`.

---

## 12. Snapshot refresh strategy

### Cadence

- **`BuildDailySnapshotJob`** — daily 00:30 UTC per active store. Runs in workspace's `reporting_timezone` by dispatching at workspace-local 00:30 (dispatcher closure iterates workspaces, computes UTC offset, schedules delayed dispatch within `DispatchDailySnapshots` invokable). Writes yesterday in workspace-local time.
- **`BuildHourlySnapshotJob`** — every hour within the current workspace-local day. Writes only the most recent completed hour.
- **`daily_snapshot_products`** — rebuilt when `UpdateCostConfigAction` fires (retroactive COGS backfill flag on the action). Fans out `BuildDailySnapshotJob` for the affected date range with `rebuildProducts=true`.

### Locking

- **Redis lock** per `(workspace_id, snapshot_type, store_id, date)` via `Cache::lock("snapshot:{$ws}:{$type}:{$store}:{$date}", 600)`.
- Prevents concurrent builds when manual retry + scheduled run collide.
- Lock acquired in `SnapshotBuilderService::buildDaily`; released on success/failure.

### Input tables

`orders` + `order_items` + `refunds` (revenue, profit lines) + `ad_insights` (spend, platform_conversions_value) + `product_variants` (COGS) + `store_cost_settings` / `shipping_rules` / `transaction_fee_rules` / `tax_rules` / `opex_allocations` / `platform_fee_rules` (for profit columns).

### Output

13 new columns on `daily_snapshots` (7 per-source revenue + 6 profit components + sessions), 3 new on `hourly_snapshots` (FB/Google/Real), `daily_snapshot_products` top-100 per day.

---

## 13. Integration health

`IntegrationHealthService` — Elevar-style "Tracking Health" per destination.

### Metrics

| Metric | Window | Source | Formula |
|---|---|---|---|
| **Accuracy %** | rolling 7d | `integration_events` | `delivered / (delivered + failed)` where `direction='outbound' AND destination=:p` |
| **Delivery rate** | rolling 7d | `integration_events` | `delivered / total` |
| **Match quality p50 / p99** | rolling 7d | `integration_events.match_quality` | percentile on 0-10 scale (Elevar parity) |
| **Top error codes** | rolling 7d | `integration_events.error_code + error_category` | `COUNT GROUP BY error_code ORDER BY count DESC LIMIT 10` |
| **Last successful delivery** | latest | `integration_events` | `MAX(received_at) WHERE status='delivered'` |

### Fallback

When a destination has no `integration_events` rows (pre-launch baseline): synthesize accuracy from `integration_runs` completed-vs-failed ratio for that integrationable. Clearly marked "Estimated" in UI.

### Surface

- `/integrations` Tracking Health tab: full per-destination table.
- Dashboard Trust Health widget: top 3 destinations by traffic + worst accuracy.
- `/attribution` Tracking Health strip: Match Quality column per order source.

---

## 14. Historical import

### `HistoricalImportOrchestrator` standardises the 4 sibling jobs

| Job | Integration | Chunk shape | Source of truth |
|---|---|---|---|
| WooCommerceHistoricalImportJob | stores (WC) | 30-day reverse chunks via WC REST | `historical_import_jobs.checkpoint.date_cursor` |
| ShopifyHistoricalImportJob | stores (Shopify) | 30-day reverse chunks via ShopifyConnector | same |
| AdHistoricalImportJob | ad_accounts | FB async reports 90-day / Google 30-day | same |
| GscHistoricalImportJob | search_console_properties | 5-day `Http::pool` batches, 16-month clamp | same |

### Shared columns on `historical_import_jobs`

`workspace_id`, `integrationable_type`, `integrationable_id`, `job_type`, `status`, `from_date`, `to_date`, `total_rows_estimated`, `total_rows_imported`, `progress_pct`, `checkpoint` jsonb, `started_at`, `completed_at`, `duration_seconds`, `error_message`.

### Progress per chunk via events

Each chunk completion UPDATEs `progress_pct` + `total_rows_imported` + `checkpoint`. Frontend polls `/integrations/imports/{id}/status` (JSON) at 5s cadence via Inertia partial reload; `TabTitleStatus` (browser tab title) updates parenthetical progress.

### Self-healing

- `detect-stuck-imports` (every 10 min) marks jobs stuck in `pending >15 min` or `running >60 min` as failed.
- Retry endpoints (`IntegrationSyncController@retry`) resume from `checkpoint` — never restart from scratch.
- Reactivation: `TriggerReactivationBackfillJob` computes trial-gap window and dispatches new rows for all 4 job types.

---

## 15. Billing

### Stripe integration (Cashier)

- `billing_subscriptions` + `billing_subscription_items` (renamed from `subscriptions` / `subscription_items`).
- `workspaces.stripe_id` = Cashier customer.
- `BillingController@subscribe` uses Cashier `newSubscription` with base plan `€39/mo` + metered item for revenue share.

### Revenue-share calculation

`BillingUsageService::computeForPeriod(workspaceId, month)`:

1. `has_store=true` → GMV from `daily_snapshots.revenue` summed over month.
2. `has_store=false AND has_ads=true` → ad spend from `ad_insights WHERE level='campaign'` (fallback billing basis).
3. Enterprise workspaces (`billing_plan='enterprise'`) → skip; invoiced manually.
4. Write `billing_revenue_share_usage` row with `gross_revenue_reporting`, `billable_revenue_reporting` (after any exclusions), `rate_bps=40`, `computed_amount_reporting`.
5. Report to Stripe via `Stripe\SubscriptionItem::createUsageRecord`.

### `ReportMonthlyRevenueToStripeJob`

Monthly 1st 06:00 UTC. Iterates active workspaces; delegates to `BillingUsageService`; tolerant of partial failure (one workspace fails doesn't abort the run).

### Agency hierarchy

- `workspaces.billing_workspace_id` self-FK.
- When set, billing roll-up happens at parent: parent workspace's `ReportMonthlyRevenueToStripeJob` sums children's `billing_revenue_share_usage` rows and reports a single Stripe usage record against parent's subscription.
- Children have `billing_plan='child'` (schema CHECK enforced) and their own subscriptions are skipped.
- Consolidated invoice rendered by Stripe; Nexstage UI shows per-child breakdown on Settings → Billing.

---

## 16. Open questions (punt to PLANNING.md)

1. **Sync vs async `RecomputeAttributionJob` UX.** When user changes attribution model: (A) synchronous with progress banner, block UI for <5 min; (B) async with toast "Recomputing attribution — refresh in 5 min". Schema supports both. Recommend B (async + toast) for model/window changes; sync for cost changes (usually affects one date range and finishes in seconds).

2. **Split campaign-level controllers.** Keep `/ads` as one page with `?level=campaign|adset|ad` (current pattern) OR split into three pages `/ads`, `/ads/adsets`, `/ads/ads`? Current pattern keeps SavedViews tidy; split might reduce controller complexity. Recommend KEEP single page with level toggle.

3. **Worker count for `sync-store` during BFCM.** Current 10 prod workers OK for baseline; Shopify + WC simultaneous BFCM traffic may queue. Decide: static bump to 20 or autoscaling trigger via Horizon ENV?

4. **`SnapshotBuilderService` locking granularity.** Lock per `(workspace_id, snapshot_type, store_id, date)` may produce 1000s of Redis keys. Alternative: single lock per `(workspace_id, date)` — coarser, safer against multi-store races. Recommend the coarser lock; revisit if build contention appears.

5. **Public snapshot materialisation.** `public_snapshot_tokens.snapshot_data` jsonb optional. Materialise at token-create (faster reads, stale when underlying data shifts) or lazy re-read (fresh, slower)? Recommend lazy with 5-min Redis cache layer.

6. **Webhook dedupe.** Current path: WC/Shopify webhook → `WebhookLog` row (pending) → `ProcessWebhookJob`. After `webhook_logs` drop, dedupe moves to `integration_events` with unique constraint on `(integrationable_id, external_ref, event_type)`. Decide: hard DB-unique or app-layer `Cache::lock()` check? Recommend unique constraint; idempotent re-delivery is a Shopify guarantee.
