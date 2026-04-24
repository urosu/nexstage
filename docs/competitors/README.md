# Competitor research — index

**52 files total.** 30 direct competitors + 5 UX inspiration refs + 1 UI patterns catalog + 6 cross-cut deep dives + 5 UI teardowns + 1 screens folder + this README.

## How to use this folder

- **Start with [`_patterns_catalog.md`](_patterns_catalog.md)** — 189 named UI patterns (155 adopt, 34 avoid) distilled from every other file. This is the canonical input for designing each Nexstage page.
- Individual competitor files are deep references. Read only when you need specifics.
- `_inspiration_*.md` = non-ecommerce UX references (different template — no pricing, pure UX).
- `_crosscut_*.md` = cross-competitor deep dives on specific surfaces (pricing pages, onboarding flows).
- `_screens/` = reserved for screenshot files. Populate when referenced from a page spec.

## Ecommerce analytics — direct competitors (17)

| # | Tool | Positioning | Price floor | Platform | Why it matters |
|---|---|---|---|---|---|
| 1 | [Triple Whale](triple-whale.md) | Shopify DTC attribution + creative + profit | ~$129 → $389/mo realistic | Shopify only | Category-defining. 80% paywalled. Pixel + last-click + DDA; Moby AI. |
| 2 | [Northbeam](northbeam.md) | Mid-market MTA attribution | $1,500/mo | Shopify, Woo, BigC, Magento | Global model/window selector drives whole app. Fractional credit. |
| 3 | [Polar Analytics](polar-analytics.md) | Unified reporting + BI | ~$720/mo | Shopify-first | Side-by-side attribution compare validates our trust thesis. |
| 4 | [Lifetimely](lifetimely.md) | LTV + P&L for DTC | $0 free → $999/mo | Shopify | Classic income-statement P&L. LTV Drivers table. Role templates. |
| 5 | [Peel Insights](peel-insights.md) | Cohort/LTV deep dive | $179 → $899+/mo | Shopify | Three cohort views (table + curves + pacing). Magic Dash AI. |
| 6 | [Metorik](metorik.md) | WooCommerce analytics + segmentation | $25/mo | Woo-first | Segment builder with live count. Saved-segment-as-primitive. |
| 7 | [Glew](glew.md) | Multi-brand / multi-store | Opaque | Multi-platform | Persistent aggregated-vs-per-store toggle in top chrome. |
| 8 | [Putler](putler.md) | Multi-source merchant dashboard | $20 → $2,250/mo | Woo, Shopify, Stripe, PayPal | Pulse + Overview dual-timeframe. 80/20 Pareto. 7×24 heatmap. |
| 9 | [MonsterInsights](monsterinsights.md) | GA-in-WordPress-admin | $99.50 → $399.50/yr | Woo / WordPress | User Journey timeline. Trusts GA4. Paywall bloat. |
| 10 | [Segments Analytics](segments-analytics.md) | RFM + lifecycle segmentation | $79 → $199/mo | Shopify | FilterGPT natural-language segment builder. |
| 11 | [Fairing](fairing.md) | Post-purchase survey attribution | Volatile | Shopify | Side-by-side "survey vs pixel" delta — closest to our six-source pattern. |
| 12 | [Daasity](daasity.md) | Managed warehouse + Looker | ~$1,899/mo | Shopify-first | Layer Cake cohort viz. 120+ Looker templates. |
| 13 | [Shopify Native](shopify-native.md) | Free, bundled with Shopify | $0 (on Shopify) | Shopify only | **Day-1 comparison.** Live View globe. No ad spend. Last-click only. |
| 14 | [Shopify Plus Reporting](shopify-plus-reporting.md) | Custom Reports + ShopifyQL Notebooks | $2,300/mo (Plus plan) | Shopify Plus | Commerce-aware SQL macros: `DURING bfcm`, `COMPARE TO`, `VISUALIZE`. Sidekick AI (cautionary). |
| 15 | [BigCommerce Analytics](bigcommerce-analytics.md) | Native (BigCommerce) | Standard included; add-on $49–$249/mo | BigC only | Purchase Funnel is best-in-class. "Rockstar/Hot/Cold/At-Risk" labels. Meta CAPI bugs. |
| 16 | [Motion](motion.md) | Creative analytics for ads | $250/mo | Shopify + Meta/TT/Google | Thumbnail-first grid with stat strip. Creative-only scope. |
| 17 | [RoasMonster](roasmonster.md) | Real-ROAS vs pixel-ROAS | ~€500+/mo, €5k ad floor | Shopify, Woo | **Closest thesis** but collapses disagreement. We out-execute by making it first-class. |

## Benchmarking / peer data (1)

| Tool | Why it matters |
|---|---|
| [Varos](varos.md) | Percentile bars as "you vs peer median" canonical visual. P25–P75 shaded band on time series. Shopify-only (gap for us). |

## Creative AI workflow (1)

| Tool | Why it matters |
|---|---|
| [Atria](atria.md) | Raya agent. Letter-grade badges. Three-column Winners/Iteration/Candidates triage. |

## Attribution plumbing (4)

| Tool | Why it matters |
|---|---|
| [Wicked Reports](wicked-reports.md) | FunnelVision TOF/MOF/BOF. Attribution Time Machine (touchpoint replay). Shopify + Woo. Public pricing. |
| [Hyros](hyros.md) | Cautionary tale: "we are the truth" narrative backfires when numbers don't match CRM. Dark-pattern cancellation. |
| [RockerBox](rockerbox.md) | **Purest analog to our thesis** — dedup vs platform-reported toggle. Three-methodology narrative (MTA + MMM + Incrementality). |
| [Elevar](elevar.md) | **Ready-made data-quality UI** — per-destination accuracy %, conversion health feed, error code directory. |

## Email / SMS marketing analytics (3)

| Tool | Why it matters |
|---|---|
| [Klaviyo](klaviyo.md) | 5d/5d attribution windows (inflates numbers). Per-channel window control. Docs admit 40–70% capture rate. |
| [Omnisend](omnisend.md) | Shorter windows, auto-UTM on every link. 2D Customer Lifecycle Map. |
| [Mailchimp](mailchimp.md) | 5d/30d windows (longest). Net revenue (vs Klaviyo's gross). Industry-benchmark overlay on every report. |

## Social commerce native (2)

| Tool | Why it matters |
|---|---|
| [TikTok Shop Analytics](tiktok-shop-analytics.md) | Content-as-attribution-dimension. Creator is a top-level tab. Surface-specific windows (30-min LIVE). |
| [Instagram Shopping Insights](instagram-shopping-insights.md) | Pre-click discovery only; post-click lives in Ads Manager. Native checkout deprecated Sept 2025. |

## Generalist BI / product analytics (4)

| Tool | Why it matters |
|---|---|
| [Looker Studio](looker-studio.md) | "Free" but needs $60–$250/mo in connectors (Supermetrics). Maintenance nightmare. The DIY alternative. |
| [Metabase](metabase.md) | X-ray auto-exploration on any table. "Alert on question, Subscribe to dashboard" split is right mental model. |
| [Mixpanel](mixpanel.md) | **Category vocabulary** — Insights / Funnels / Retention / Flows / Cohorts. Cohort → ad audience sync. |
| [Amplitude](amplitude.md) | Charts / Dashboards / Notebooks artifact split. Predictive Cohorts. Accounts unit-of-analysis toggle. |

## UX inspiration — non-ecommerce dashboards (5)

Different template (UX-focused, no pricing).

| Tool | Steal this |
|---|---|
| [Stripe](_inspiration_stripe.md) | KPI-card-with-sparkline. Skeleton shimmer. Click-to-filtered-table. Cmd+K. "Today so far" widget. |
| [Linear](_inspiration_linear.md) | Command palette. Optimistic UI + toast undo. View toolbar. Hover card previews. |
| [Vercel](_inspiration_vercel.md) | Geist primitives: Status Dot, Entity, Context Card, Gauge. Streaming logs. Optimistic SWR. |
| [Plausible](_inspiration_plausible.md) | 6-KPIs-above-shared-chart + click-to-switch-metric. Filter-as-chip-sentence. Dotted line for incomplete periods. URL state. |
| [GA4](_inspiration_ga4.md) | 3-panel Explore canvas; segment builder's user/session/event abstraction. **Avoid:** silent sampling, 24h latency. |

## Synthesis documents

**Start here when designing pages:**

- **[`_patterns_catalog.md`](_patterns_catalog.md)** — 189 named UI patterns with adopt/avoid recommendations. Every page spec should reference patterns by name from this catalog.
- **[`_crosscut_metric_dictionary.md`](_crosscut_metric_dictionary.md)** — 85 metric concepts with competitor-sourced naming across 18 tools. Names we'll use + formulas + glossary.
- **[`_crosscut_ux_copy.md`](_crosscut_ux_copy.md)** — 60 UX copy contexts (buttons, tooltips, empty states, errors, status badges). Nexstage tone guide.

**Cross-cut deep dives on specific surfaces:**

- **[`_crosscut_pricing_ux.md`](_crosscut_pricing_ux.md)** — 13-tool pricing-page breakdown + 10 steal, 10 avoid, + our positioning statement.
- **[`_crosscut_onboarding_ux.md`](_crosscut_onboarding_ux.md)** — 11-tool onboarding breakdown + 10 steal, 10 avoid, + proposed Nexstage onboarding path.
- **[`_crosscut_multistore_ux.md`](_crosscut_multistore_ux.md)** — multi-store / agency UX: switcher, aggregation, permissions, agency tier, white-label. Includes concrete v1/v2 proposals.
- **[`_crosscut_mobile_ux.md`](_crosscut_mobile_ux.md)** — mobile/responsive UX matrix + native app analysis + Nexstage mobile stance (responsive-only v1).
- **[`_crosscut_export_sharing_ux.md`](_crosscut_export_sharing_ux.md)** — export formats, scheduled delivery, shareable links, custom report builders, white-label.

**Deep UI teardowns (screen-by-screen, element-by-element):**

- **[`_teardown_triple-whale.md`](_teardown_triple-whale.md)** — Summary, Pixel/Attribution, Customer Journeys, Creative Cockpit, Moby Chat, 12 screens total.
- **[`_teardown_northbeam.md`](_teardown_northbeam.md)** — Global attribution-model + window + accounting toggles, Metrics Explorer, Sales, Creative, 12 screens.
- **[`_teardown_polar.md`](_teardown_polar.md)** — BI-tool lineage, folders + blocks + workspaces, 10 attribution models, floating chat, 14 screens.
- **[`_teardown_peel.md`](_teardown_peel.md)** — Metrics → Reports → Dashboards hierarchy, Tickers vs Legends, RFM 10-segment, Magic Dashboards, 18 screens.
- **[`_teardown_fairing.md`](_teardown_fairing.md)** — Question editor with live preview, AUTO recategorisation, extrapolated-vs-observed columns, 11 screens.

## Cross-cutting observations

Patterns that appear in 3+ competitors — treat as table stakes:

- Cmd+K command palette · Skeleton loaders over spinners · Optimistic UI with toast rollback · Filter-as-chip-sentence · Tabular/monospace numbers · Status Dot primitive · Deep-linkable URL filter state · Per-card date range + sparklines · Persistent aggregated-vs-per-store toggle (multi-store)

## Gaps nobody occupies (our wedge)

1. **Source disagreement as first-class UI.** Everyone picks one source of truth or hides the math. Even RoasMonster collapses it into one "real" number. Our six-source MetricCard with the disagreement as first-class info is unoccupied.
2. **Shopify + WooCommerce with transparent SMB pricing.** Only Northbeam, Putler, Glew, RoasMonster, Wicked Reports span both. All are either expensive (NB, RoasMonster, Wicked), opaque (Glew), or generalist (Putler).
3. **Pricing gap $200–$1,500/mo.** Triple Whale Advanced ($389) → Northbeam Starter ($1,500) is a no-man's-land. Our 39€ + 0.4% lands exactly here.
4. **Revenue-share pricing.** Literally nobody does it. Universal volume sliders (GMV/orders/ad spend) or metered tiers. Uncontested positioning.
5. **"What to do next" mostly absent.** Atria's Raya and Peel's Magic Dash are early attempts. Not yet table stakes — differentiator if done right.
6. **Benchmarking is shallow.** Only Varos does it meaningfully, Shopify-only. Design schema from day 1, ship once we have stores.
7. **Data-quality / tracking-health UI is rare.** Only Elevar does it well, and they're infrastructure not analytics. We can fold it into the main dashboard — "here's why the numbers don't match."

## The specific tools our users WILL compare us against on day 1

In descending priority:

1. **Shopify Native** — every merchant already has it, zero marginal cost. Must clearly beat it on ad reconciliation, cross-platform view, and attribution transparency.
2. **Klaviyo / Omnisend / Mailchimp** — every merchant compares our "email revenue" to theirs. Critical to show source + window + gross/net labels so they understand why they don't match.
3. **Triple Whale** (if Shopify) — the aspirational reference. Must match their metric vocabulary without their paywall wall.
4. **Metorik** (if WooCommerce) — the existing incumbent for Woo stores. Must match their segmentation depth.
5. **Meta Ads Manager + Google Ads** — the platforms themselves are what ROAS claims get compared to. Our "store vs platform" delta view addresses this directly.
