# Attribution

Route: `/attribution`

## Purpose

The page where source disagreement is the product — show, per channel, how much revenue Store recorded vs what each platform claims, compute a Real number, and make "Not Tracked" a first-class, drillable bucket that can go negative.

## User questions this page answers

- Why doesn't Facebook's revenue number match my Shopify admin?
- Under which attribution model do my ad platforms look best — and does that model still hold up against store truth?
- Which orders is each platform missing, over-claiming, or splitting credit on?
- For a given order, what touchpoints actually led to it, and how does each model divide that credit?
- How has the attribution gap moved over the last 30 / 90 days — am I getting better at capturing signal or worse?
- Where did the "Not Tracked" revenue come from and where did the over-reporting go?

## Data sources

| Source (UX §4 color) | Required? | Provenance | Freshness |
|---|---|---|---|
| Store (slate-500) | Yes — baseline | `orders` + `attribution_*` columns (see PLANNING §6) | <2 min (webhook) |
| Facebook (indigo-500) | Yes | Platform-reported conversions from `ad_insights` joined to orders via `fbclid` / Meta click ID + windowed attribution | ~15 min |
| Google Ads (amber-500) | Yes | Platform-reported conversions via `gclid` / GCLID enhanced conversions + windowed attribution | ~30 min |
| GSC (emerald-500) | Optional | `gsc_daily` clicks as an organic-attribution signal; never a conversion source in v1 | 24–48h (Google delay) |
| Site (violet-500) | Optional | First-party session pixel (future) | v2 |
| Real (yellow-400) | Yes — computed | `RevenueAttributionService` reconciling the above via workspace model + window | Derivative of inputs |

"Not Tracked" is derived: `Total Revenue − Σ(Attributed Revenue across sources after de-duplication)` — can go negative (see `_crosscut_metric_dictionary.md` Trust group).

## Above the fold (1440×900)

- TrustBar (§5.14) — **primary**, full-width, pinned directly beneath FilterChipSentence. ToggleGroup `Orders / Revenue` on the bar itself (canonical per UX §5.14). Cell format: `Store: 1,249 orders | Facebook: 1,198 (−51) | Google: 1,221 (−28) | Real: 1,249 | Not Tracked: +0`. Click any non-Store cell → "Which orders are missing from Facebook?" drill (DataTable with side-by-side order-level attribution).
- **Toolbar row** (right-aligned above the matrix): `ShareSnapshotButton` (§5.29) · `ExportMenu` (§5.30 — CSV includes source-disagreement matrix as long-form rows)
- AlertBanner (warning) — inline when attribution gap crosses ±15% or Modeled signals are calibrating (copy: "Facebook over-reports by 18% this period — inspect")
- KpiGrid (4 cols) — each `MetricCardDetail` variant (§5.1 — includes prev-period + prev-year deltas + source-disagreement gap chip)
  - MetricCard "Real Revenue (28d)" · sources=[Real, Store, Facebook, Google] · gold-lightbulb on Real
  - MetricCard "Attributed Revenue" · sources=[Facebook, Google] — summed cross-platform, header carries `Σ per source · may exceed Total` microcopy per §5.1
  - MetricCard "Not Tracked" · sources=[computed] · signed value; negative styling in rose, positive in slate. Click body → Not Tracked drill (see below)
  - MetricCard "Attribution Gap" · sources=[computed] · headline is signed currency `Σ(Attributed Revenue across sources after de-dup) − Total Revenue` per `_crosscut_metric_dictionary.md` glossary (absolute difference, not ratio). Delta tracks movement vs previous period; positive = over-claimed, negative = under-claimed. Tooltip derivation cites the formula verbatim.
- **Source Disagreement Matrix** (signature viz, page-local, novel — extends the Rockerbox / Fairing side-by-side pattern to N sources):
  - Rows = channels (`Facebook Ads · Google Ads · Email · SMS · Organic · Direct · Referral · Not Tracked`). Rows come from `ChannelMappings` seeder + a pinned `Not Tracked` synthetic row at the bottom.
  - Columns = sources (`Store · Facebook · Google · GSC · Real`). Site hidden in v1.
  - Cells show attributed revenue for that channel × source cell, plus a small delta chip `Δ vs Store` (green / rose / neutral per sign).
  - Cell coloring: red-to-green gradient on `|Δ|` magnitude (Peel cohort-heatmap pattern).
  - Click any cell → DataTable drill filtered to orders in that channel × source intersection.
  - Click a row label → opens channel-detail DrawerSidePanel with all five source values side-by-side and a time-series of disagreement.
  - Column header shows total; row footer shows total; bottom-right corner cell is the Attribution Gap.

## Below the fold

- **Model Comparison Table** (Fairing side-by-side delta pattern, extended; Northbeam Model Comparison promoted out of hamburger menu per anti-pattern):
  - Rows = channels (same list as the matrix)
  - Columns = attribution models: `First Touch · Last Touch · Last Non-Direct · Linear · Clicks-Only · Clicks + Modeled Views`
  - Each column shows attributed revenue + `Δ vs Store` superscript.
  - Below each cell: a 20-point horizontal `Sparkline` (attributed revenue under that model over the active date range) — lets the eye spot where models diverge over time.
  - Header row ToggleGroup: `Revenue | Orders | CAC | ROAS` — swaps the metric the table is showing.
  - "Pin a model as primary" button per column → that model becomes the TopBar AttributionModelSelector default for the page (URL updates).
- LineChart "Attribution gap over time" — stacked-area per-source contribution to revenue with the `Not Tracked` layer on top (can render below zero when Σ Attributed > Store). GranularitySelector defaults Weekly for ≥14d ranges; TargetLine at 0 emphasises the crossover.
- **Attribution Time Machine** (Wicked Reports signature, page-local component):
  - Horizontal timeline scrubber spanning the current date range.
  - Scrub-head shows the state of the Source Disagreement Matrix *on that date* — reconstructed from `daily_snapshots` without rerunning `RevenueAttributionService`.
  - Play/pause button runs through the range at 2s / day (ChartAnnotationLayer events become "pops" as they pass).
  - Use case: audit "when did Facebook start over-reporting by more than 10%?" — the scrubber surfaces the exact week.
  - Desktop-only by design — the scrub affordance doesn't survive on touch.
- **Customer Journey Timeline** (Triple Whale Customer Journeys + Northbeam Orders):
  - Infinite-scroll list of recent orders (DataTable in journey-card mode — 5 rows per screen, not 50). Default sort: revenue desc over active range.
  - Each card: masked email · order value · order date · horizontal touchpoint row (platform SourceBadges connected by arrows, ends at a slate cart icon for purchase) · touchpoint count · days-to-convert.
  - Click card → DrawerSidePanel with per-order detail: vertical timeline of every touchpoint (platform icon, campaign name, UTM, landing page, timestamp), each touchpoint showing fractional credit under the active model. Top of drawer has a local AttributionModelSelector override so the user can see credit re-split live without touching the global selector.
  - The drawer is the per-order accountability surface — the "why was this order credited to X?" spot-check.

### "Not Tracked" drill (DataTable, opened from MetricCard body click or TrustBar cell click)

- FilterChipSentence pre-filled: `Showing orders where no connected source claimed credit` (or `where Facebook didn't claim credit` when coming from a source cell).
- Columns: `Order · Customer (masked) · Revenue · Status · Store Channel · FB Click ID? · GAds Click ID? · UTM Source · UTM Medium · Touchpoints · Gap Reason`
- `Gap Reason` is a computed chip: `No click ID · Outside attribution window · Over-reported elsewhere · Consent denied · Unmatched UTM` — derived from PLANNING §6 `attribution_*` columns. Hover opens an explainer popover citing the derivation.
- Each row click → Customer Journey Timeline drawer for that order (same component as above, so the behaviour is uniform).
- When "Not Tracked" goes **negative** (platforms over-report), the drill flips semantically: FilterChipSentence reads `Showing 124 claimed purchases with no matching Store order`. Columns replace `Order` with `Claimed Purchase (platform)` and include `Platform · Click ID · Claimed at · Closest Store order (fuzzy match)`. This is the surface that answers "Facebook says I sold $61k, Shopify says $48k — where are the extra $13k coming from?".

### Tracking Health strip (below Customer Journey Timeline)

Small per-source row, patterned on Elevar's Channel Accuracy Report: per connected source show `Match Quality (0–10)` · `Events sent (period)` · `Events matched` · `Consent-denied %` · link to `/integrations` for any source where match quality drops below the workspace threshold. This strip makes the accuracy claim auditable — we're not asking users to take the Real number on faith.

## Interactions specific to this page

- **Global AttributionModelSelector + WindowSelector are authoritative** (§7.0.1). Changing the model retroactively recomputes the matrix, model comparison table, line chart, and every MetricCard (Klaviyo-style recalc with brief `Recomputing…` banner). URL state is `?model=last-non-direct&window=7d`.
- **AccountingModeSelector** flips revenue attribution between click-date (Accrual Performance) and order-date (Cash Snapshot). On Attribution specifically, Cash Snapshot is the default because it matches Shopify admin — which is the disagreement users are investigating.
- **SourceToggle** (multi-select of the 6 sources in TopBar) propagates to the matrix column visibility. Defaults `[Real]` but the matrix forces a minimum of Store + Real columns visible (collapsing to one source defeats the page's purpose — Store stays as baseline).
- **Click a cell in the Source Disagreement Matrix** → DataTable drill filtered to that channel × source. Click a column header → swap the highlighted source across the page.
- **Right-click any matrix cell** → ContextMenu: `Filter to this · Exclude this · Copy value · Open in Orders · Add annotation here` (the add-annotation option only lights up on the LineChart).
- **Attribution Time Machine scrub** also updates the KpiGrid + TrustBar to that date's state — so the entire page becomes a replay surface. Release the scrub → everything snaps back to live data.
- **Model Comparison Table "Pin a model"** writes to `?model=` and triggers the global recalc once — so the choice flows into every other page in the session.
- **ConfidenceChip (§5.27) is aggressive here** — any cell below threshold greys out the number and suppresses the Δ. Trust depends on not letting small samples drive conclusions.
- **SignalTypeBadge (§5.28) on every Facebook cell** computed from Clicks+Modeled Views. Click the badge for the methodology + sample size; hover for one-line summary.
- **No per-card override on this page.** Attribution is the global thesis; letting one card override the global model would undermine the story. Exception: the Customer Journey drawer's local model selector, which is read-only for the drawer and doesn't touch page state.
- **ProfitMode behavior (replace-semantic).** When global `ProfitMode` is ON, every revenue-flavored metric flips to its profit equivalent (Revenue → Profit, ROAS → Profit ROAS, CAC → Profit-CAC), source-attributed consistently. The Source Disagreement Matrix cells show profit instead of revenue; the Model Comparison Table columns flip; `Not Tracked` becomes signed profit gap. If COGS is missing, affected cells degrade with amber StatusDot + "Add COGS" tooltip per UX §7 cost inputs. No paired-column variant here (unlike `/orders`); flip replaces, not adds.
- **EntityHoverCard (§5.18) applies** on every entity ID rendered in the Customer Journey Timeline cards and the drill-downs — order IDs, masked customer email, campaign names, ad IDs all trigger the 400ms-dwell preview per the global convention.

## Competitor references

- [RockerBox — Cross-Channel Attribution Report](../competitors/rockerbox.md#cross-channel-attribution-report-mta) — the purest enterprise analog to our thesis: de-duplicated vs platform-reported toggle. We extend it from 2 sources to 6 and surface it as a matrix, not a toggle.
- [Fairing — Attribution Deep Dive](../competitors/fairing.md#attribution-deep-dive) — side-by-side compare view with delta column between survey-based and last-click attribution. We adopt the delta column pattern and extend it to N columns via the Model Comparison Table.
- [Polar Analytics — Paid Marketing Dashboard](../competitors/_teardown_polar.md#screen-paid-marketing--acquisition-dashboard) — 10 attribution models shipped as a model picker. We surface them in the comparison table so users see the disagreement in one glance instead of switching and remembering.
- [Northbeam — Attribution Home](../competitors/_teardown_northbeam.md#screen-attribution-home-model-comparison) — channel-mix donuts under multiple models + channel-breakdown table with a column per model. Anti-pattern we're fixing: Northbeam buries Model Comparison in a hamburger menu.
- [Triple Whale — Customer Journeys](../competitors/_teardown_triple-whale.md#screen-pixel--customer-journeys) — horizontal touchpoint timeline per order. We keep this shape for the journey drawer.
- [Wicked Reports — Attribution Time Machine](../competitors/wicked-reports.md#attribution-time-machine) — touchpoint replay for any order ID. We generalise from per-order to per-workspace via the date scrubber on the matrix.
- [RoasMonster](../competitors/roasmonster.md) — pixel-vs-shop delta anomaly surfacing. We do it as the default, not an anomaly — the matrix IS the diagnostic.
- [Elevar — Channel Accuracy Report](../competitors/elevar.md#channel-accuracy-report) — per-destination accuracy % as a headline KPI. We borrow the SignalTypeBadge + ConfidenceChip combination for the same "earn trust before showing numbers" purpose.
- [Hyros — cautionary](../competitors/hyros.md) — "we are the truth" narrative backfires when numbers disagree with CRM. We never prefix Real Revenue with "True"; the page labels Real as Nexstage-computed reconciliation, linked to a `How is this computed?` explainer.

## Mobile tier

**Desktop-only** (≥1280px). The Source Disagreement Matrix, Model Comparison Table, and Attribution Time Machine scrubber do not survive on mobile. At `<lg` the page renders a banner ("Attribution requires desktop — matrix comparisons don't fit small screens. Mobile users get the TrustBar + Not Tracked drilldown only.") with the TrustBar + a simplified orders list. Customer Journey Timeline is the only below-the-fold section that stays usable on tablet.

## Out of scope v1

- **Nexstage Multi-Touch Attribution model** (branded "Multi-Touch (Nexstage)" in `_crosscut_metric_dictionary.md`) — exposed as a disabled option with tooltip "Calibrating · requires 30d of data". Ships once the calibration pipeline is live (avoiding the Northbeam 30-day-warmup anti-pattern for other models).
- **Post-purchase survey source** (Fairing-style) — schema-ready (`orders.attribution_source_survey`), not wired in v1.
- **Site pixel (first-party)** — deferred; Site column hidden in the matrix.
- **Sankey diagram of top customer paths** — Northbeam Attribution Home bottom panel — replaced in v1 by the Customer Journey Timeline cards, which are more actionable.
- **Incrementality / holdout testing (Causal)** — Polar's Causal module, RockerBox's Incrementality — v2.
- **Media Mix Modeling (MMM)** — enterprise-tier differentiator we are deliberately not competing on.
- **Editable per-channel TOF/MOF/BOF tagging** (Wicked FunnelVision pattern) — considered for v2 if we add a funnel stage dimension to `ChannelMappings`.
- **Scheduled "attribution anomaly" email digest** — the daily digest (§5.30 ExportMenu → Schedule email) will include the TrustBar snapshot in v1; full anomaly-tuned alerts arrive with the Alerts triage surface.
- **Per-order attribution override / manual re-credit** — audit-trail implications; deferred.
- **Public snapshot URLs (§5.29) of the attribution matrix** — sharing a live-updating trust view with non-logged-in stakeholders is the intended v1 capability; gated behind the ShareSnapshotButton, not a page-specific affordance.
