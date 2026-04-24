# Amplitude

**URL:** https://amplitude.com
**Target customer:** Mid-market to enterprise product teams; digital products with PM-led analytics culture. The "more enterprise Mixpanel" — heavier sales motion, bigger contracts, multi-product suite (Analytics + Experiment + Session Replay + CDP + Feature Flags + Guides & Surveys). In ecommerce it's used by sophisticated brands with in-house product/growth teams — think app-first retailers, subscription commerce, D2C brands over $20M GMV. 11,000+ digital products; 217% 3-year ROI cited.
**Pricing:**
- **Starter** — $0. 10K MTUs, up to 2M events/mo. Analytics templates, unlimited Session Replay, feature flags, Web Experimentation, AI Feedback.
- **Plus** — **$49/mo (annual)**. Up to 300K MTUs or 25M events. Unlimited product analytics, behavioral cohorts, feature tagging, custom audiences.
- **Growth** — custom (mid-market; typically $2–30k/mo). Feature Experimentation (proper A/B testing), Web Experimentation code editor, predictive audiences, Accounts add-on.
- **Enterprise** — custom (~$40k–$200k+/yr). Cross-product analysis, advanced permissions, multi-armed bandit experiments, dedicated account manager, SSO, SOC 2 / HIPAA, governance.
- **Startup Scholarship** — 1 year of Growth free for companies <$10M funding, <20 employees.
- Guided setup + tracking-plan design: $499 add-on.
- MTU = monthly tracked user = one unique user with ≥1 event in the org per calendar month.
**Positioning one-liner:** "AI-powered digital analytics" — product analytics as the backbone of a broader behavioral-experience platform, with experimentation and session replay integrated natively.

## What they do well
- **Charts + Dashboards + Notebooks split is distinctive.**
  - **Charts** = a single saved visualization (one funnel, one retention, one segmentation).
  - **Dashboards** = grid of Charts, stakeholder-facing roll-ups.
  - **Notebooks** = long-form narrative with charts, cohorts, text, images interleaved. Used for launch post-mortems, experiment analyses, strategy docs that reference live data.
  The Notebook concept is rare in the category and uniquely suited to sharing findings rather than just dashboards.
- **Chart vocabulary is the broadest in product analytics.** Event Segmentation, Funnel Analysis, Retention Analysis, Journeys (Pathfinder), Revenue, Stickiness, Lifecycle, Impact Analysis, Experiment Results, Compass (correlation), User Composition. Each is a distinct chart "type" with its own builder.
- **Journeys / Pathfinder** beats Mixpanel's Flows on polish. Shows paths leading to or from an event with weighted widths, "Journey Map" view for comparing all paths, analyze by frequency / similarity / average time.
- **Behavioral cohorts are powerful.** Compound "users who did X ≥ N times in the last D days AND have property P AND didn't do Y" definitions; live-updating; exportable to ad platforms and lifecycle tools.
- **Predictive Cohorts (ML-driven).** Powered by Nova AutoML: "users most likely to churn in next 30 days", "users most likely to purchase", "users most likely to reach $500 LTV". Set the outcome, Amplitude trains and generates the cohort. Predictive cohorts can be used as targeting rules in Experiment or as audiences in reverse-ETL.
- **Accounts (B2B/B2C add-on).** Toggles the unit of analysis from "user" to "organization" or any group key. Retention becomes account-retention; funnels become account-conversion. Critical for subscription commerce, wholesale ecommerce, B2B marketplaces.
- **Amplitude Experiment — proper A/B testing.** Not just analysis of external experiments: Amplitude issues variants, randomizes, tracks exposure, computes stat-sig, handles feature flags. Target experiments to **behavioral cohorts or predictive cohorts** — e.g., "show this new checkout only to users predicted to churn". Multi-armed bandits on Enterprise.
- **Session Replay integrated with every chart.** Any user in a funnel drop-off or a retention cohort → their session replay, one click.
- **Data Governance features on Growth+.** Taxonomy (enforce event naming conventions), Schema (block unexpected events), event blocking, PII redaction, audit logs. Enterprises respect this.
- **AI Agents, AI Visibility, AI Feedback (2025 push).** "AI Visibility" tracks brand mentions in AI search results (novel, ecommerce-adjacent). AI Feedback processes qualitative customer input at scale. Amplitude MCP integrates with Claude/Cursor.
- **Speed and scale.** "Dashboards load fast, insights correct, systems run with minimal hiccups" — 2025 reviews emphasize reliability at scale. Runs on petabyte-scale event stores.
- **Free Session Replay on every tier** — distinct from Mixpanel (capped) and Hotjar/FullStory (separate products).

## Weaknesses / common complaints
- **Pricing is opaque and climbs fast.** Growth and Enterprise are both custom. MTU overage + event overage + add-ons (Accounts, Recommend, Guides, Data Governance) compound. Reviewers say "difficult to predict growing usage costs" and "expensive as adoption increases".
- **"More enterprise than Mixpanel"** also means slower onboarding. Sales-led; implementation typically involves a Solutions Architect. SMBs bounce.
- **Implementation is a project.** Tracking plan design, event taxonomy, property schema — Amplitude actively sells a $499 "Guided Setup" because they know teams struggle.
- **Accounts add-on costs extra.** B2B ecommerce users who need the org-level view pay a premium for it.
- **UI is dense.** "Complex features, unclear pricing, user permissions" show up in negative reviews. The product's breadth is also its discoverability problem.
- **No native ecommerce connector.** Same as Mixpanel — Shopify/WooCommerce data comes via Segment, Rudderstack, or custom pipelines. Order-level properties need flattening.
- **No ad-spend / ROAS story.** Amplitude doesn't reconcile ad spend against revenue. Ecommerce customers pair it with another tool or build the attribution in a warehouse.
- **Predictive Cohorts only reliable with sufficient data.** Needs ~1000+ conversions of the target outcome to train usefully. Small stores can't meaningfully use it.
- **"Legacy charts" vs. new charts split is confusing.** Pathfinder and Journeys coexist; Revenue chart is legacy; users find docs referring to deprecated reports.
- **Experiment is a separate license.** Even on Growth, "Feature Experimentation" is the proper A/B product — and on Enterprise "multi-armed bandit" is another line item.
- **Event bloat incentive.** Same MTU/event pricing criticism as Mixpanel. Users prune tracking to manage costs.
- **Trial/free ceiling reachable quickly.** 10K MTUs on Starter is tight for any consumer app; once exceeded, Plus at $49/mo is a hard jump.

## Key screens

### Event Segmentation (Insights-equivalent)
- **Layout:** Left panel builder (Events + Filters + Breakdowns + Measurement); right panel chart.
- **Event logic:** Add one or more events with AND/OR; apply per-event filters; segment by user or event properties.
- **Measurement:** Uniques / totals / event property aggregates (sum/avg/distinct/percentiles); formulas across events (A - B, A/B).
- **Chart modes:** Line, area, bar, stacked, metric, pie, table, world map, US map, scatter.
- **Comparisons:** Previous period overlay, multiple segment lines on the same chart.

### Funnel Analysis
- **Layout:** Ordered event picker at top (with "conversion within N [minutes/hours/days]" window); filters, breakdowns, attribution window on the right.
- **Drop-off visualization:** Horizontal bar funnel with conversion % and absolute counts at each step, step-to-step drop-off %.
- **Modes:**
  1. **Conversion over time** — line chart of the overall funnel CVR trend.
  2. **Time to convert** — histogram of convert durations.
  3. **Frequency** — how often users complete the funnel.
- **Advanced:** Count in any order vs. specific order, include/exclude intermediate events, track unique users vs. unique sessions.
- **Drill:** Any cohort (converters, drop-offs) → save as cohort → used in other reports.

### Retention Analysis
- **Layout:** "Starting event" (the trigger) + "Return event" (engagement) + period (day/week/month) + retention type.
- **Retention types:** N-Day (precise), Unbounded (≥N days), Rolling (any N-day window), Range (between dates).
- **Visualizations:**
  1. **Retention Curve** — line chart, one line per cohort, Y = % retained.
  2. **Retention Heatmap** — table, rows = cohort, cols = period since, color-graded cells.
  3. **Retention Metric** — single number (e.g., "Day 30 retention").
- **Behavioral adjustments:** exclude initial-day retention, consider any return event or specific return event.

### Journeys (Pathfinder)
- **Layout:** "Start event" or "End event" picker → path visualization centered on that event.
- **Path rendering:** Sankey-like nodes and edges, widths proportional to user counts; branching depth configurable.
- **Two analysis modes:**
  - **By unique users:** how many distinct users took each path.
  - **By total path frequency:** total opt-in count (same user can count multiple times).
- **Journey Map view:** All paths laid out together; sort by frequency, similarity, or average time to complete.
- **Filters:** Include/exclude events, set session-gap window, breakdown by property.
- **Screenshot refs:**
  - https://amplitude.com/docs/analytics/charts/journeys/journeys-understand-visualizations
  - https://amplitude.com/docs/analytics/charts/journeys/journeys-understand-paths
  - https://amplitude.com/docs/analytics/charts/legacy-charts/legacy-charts-pathfinder

### Revenue / Revenue LTV
- **Layout:** Picker for revenue event + property (price, quantity, currency) + breakdowns.
- **Visualizations:** Cumulative revenue by cohort, ARPU trend, revenue distribution histogram, LTV curve (revenue accumulated per cohort over time).
- **Ecommerce relevance:** This is Amplitude's closest thing to an "ecommerce report" — but it's still an event aggregation over `Purchase Completed` events, not a reconciliation with store DB or ad spend.

### Dashboards
- **Layout:** Drag-resize grid of Chart cards. Drag-drop widgets to tailor display. Color-coded bar and line graphs side-by-side with KPIs.
- **Filters:** Top-bar dashboard filters that propagate to cards (when the chart accepts the property).
- **Real-time:** Interface is fast, updates in real-time.
- **Cross-project:** Charts from different Amplitude projects can coexist on the same dashboard.
- **Screenshot refs:**
  - https://amplitude.com/docs/analytics/dashboard-create
  - https://amplitude.com/templates/ecommerce-dashboard
  - https://amplitude.com/blog/amplitude-dashboards-charts

### Notebooks
- **Layout:** Long-form document interleaving text (rich text / markdown), images, charts, cohorts, funnels. Each chart is live.
- **Use cases:** Experiment post-mortems ("here's what we shipped, here's the impact chart, here's the cohort that responded"), launch analyses, OKR reviews, exec updates.
- **Differentiator:** Charts stay live — readers see current data, not snapshots. "Tell a media-rich story with your data".
- **Share:** Public link, team link, permissioned.

### Cohorts (behavioral + predictive)
- **Behavioral cohorts:**
  - Builder: compound event criteria + property criteria + user property criteria + group criteria; nested AND/OR.
  - Updates: live-refresh; scheduled refresh.
- **Predictive cohorts:**
  - Define outcome event (e.g., "Purchased in next 30 days").
  - Nova AutoML trains on historical behavior, outputs cohort of top-N% most likely users.
  - Rebuilds daily; confidence score shown.
  - **Ecommerce use:** "Users most likely to churn" → re-engagement campaign. "Users most likely to reach $500 LTV" → VIP treatment.
- **Sync targets:** Meta, Google Ads, TikTok, Braze, Iterable, Klaviyo, Segment, warehouse.

### Amplitude Experiment
- **Flow:**
  1. Define experiment — variants, traffic allocation, target cohort (incl. predictive), exposure event.
  2. Run — Amplitude serves variants via SDK or feature flag.
  3. Analyze — stat-sig results chart, per-variant conversion, revenue impact, confidence intervals.
- **Target by predictive cohort:** "show new onboarding only to users predicted to churn" — a unique capability.
- **Multi-armed bandit** on Enterprise — adaptive traffic allocation as the experiment runs.
- **Integrates with flags:** Feature Flags + Experiments + Guides (in-app) + Analytics unify.

### Accounts view (B2B add-on)
- **Layout:** Same chart types, but the unit of analysis is "account" (organization) not "user".
- **Retention:** Does an account come back? Which accounts churned?
- **Funnels:** Account-level funnel — did anyone on the account complete X?
- **Cohorts:** Account-level criteria (total users, plan tier).
- **Ecommerce relevance:** Subscription commerce (ReCharge), wholesale B2B ecommerce, multi-user shopping carts.

### Search
- **Layout:** Global search across charts, dashboards, cohorts, notebooks. Filter by owner, type, last-modified, starred.
- **Why it matters:** Enterprises end up with thousands of charts; discoverability is the problem.

### Alerts (Monitoring)
- **Alerts:** per-chart thresholds (absolute, % change, anomaly). Delivery: email, Slack, webhook, PagerDuty.
- **Dashboard subscriptions:** scheduled delivery of a dashboard snapshot to Slack/email.
- **Anomaly detection:** ML-driven (Enterprise) — "unusual vs. historical baseline" without user-set thresholds.

## Chart vocabulary
- **Signature:** Journeys (Pathfinder), Retention Curve + Heatmap, Funnel (Steps / Trends / Time-to-Convert / Frequency), Lifecycle, Stickiness (DAU/MAU ratio), Compass (correlation), Revenue LTV curve, Experiment Results.
- **Standard:** line, bar, stacked, area, scatter, pie, pivot table, metric, world/US map.
- **Gaps:** sankey-as-standalone-chart (Journeys covers it), treemap, radial, violin/distribution (time-to-convert is one).
- **Ecommerce-relevant:** Revenue LTV curve and Lifecycle are the closest-to-ecommerce reports; still event-centric, not order-centric.

## Query builder / abstraction level
- **Primary:** event + filter + breakdown + measurement picker (same grammar as Mixpanel/PostHog).
- **Formulas:** inline formula input supports arithmetic across events (A + B, A / B, (A - B) / A × 100).
- **Saved computed properties and events:** reusable across charts.
- **SQL access:** via Data Warehouse integration (Snowflake, BigQuery, Redshift, Databricks) — bidirectional. Warehouse users can query Amplitude event data from SQL; Amplitude can chart warehouse-materialized tables.
- **No block-based / visual query composer beyond the event builder.**

## Alerting & subscriptions
- **Alerts:** per-chart, threshold / % change / anomaly (Enterprise). Email, Slack, webhook, PagerDuty.
- **Subscriptions:** dashboard snapshots via email/Slack on a schedule.
- **Anomaly detection:** ML-driven on Enterprise — better than Mixpanel's threshold-only on lower tiers.

## Integrations (ecommerce angle)
- **SDKs:** JS, mobile (iOS/Android/RN/Flutter), server SDKs, HTTP API.
- **CDPs:** Segment, RudderStack, mParticle native.
- **Data warehouses:** BigQuery, Snowflake, Redshift, Databricks (bi-directional).
- **No native Shopify/WooCommerce.** ETL via Segment or custom integration.
- **Audience sync:** Meta, Google Ads, TikTok, Braze, Iterable, Klaviyo, reverse ETL.
- **Slack, Jira, PagerDuty, webhook** for alerts and notebook collab.

## Notable UI patterns worth stealing
- **Notebooks as a distinct artifact from Dashboards.** Long-form narrative + live charts. Ecommerce teams writing weekly updates currently copy screenshots into Notion — Notebooks solve that. We should consider a version scoped to attribution / experiment / launch post-mortems.
- **Charts / Dashboards / Notebooks three-way split.** Each has a different use: ad-hoc exploration (Chart), stakeholder roll-up (Dashboard), narrative / explanation (Notebook). Clearer information architecture than "everything is a dashboard".
- **Predictive Cohorts.** Outcome-defined ML cohorts with sync targets. Nexstage's natural analogue: "customers most likely to reorder in 30 days" → Klaviyo / Meta audience.
- **Accounts abstraction** — the unit-of-analysis toggle from user → organization (or store, or brand, or client). For agencies using Nexstage, the unit should switch between store / brand / client seamlessly.
- **Target A/B experiments to behavioral or predictive cohorts** — experimentation gated by audience quality. Compelling pattern: "show new checkout only to users likely to churn" maps to "show free-shipping promo only to customers likely to churn".
- **Journey Map compare-all-paths view** — not just one path at a time but a matrix of paths sorted by frequency/similarity. Better than Mixpanel's Flows for the "how do my users move through my site" question.
- **Feature Tagging** — tag events with "feature A / feature B / feature C" metadata, then automatically compute retention + engagement per feature. Nexstage analogue: tag orders with "promo", "first-time", "reorder" and auto-compute cohort metrics.
- **Search as a first-class nav** — global search across charts/dashboards/cohorts/notebooks. At 500+ saved objects, this is the actual navigation.
- **Anomaly detection without user thresholds** (Enterprise) — sidesteps the "I don't know where to set the alert" problem. This is the right default for ecommerce alerts.
- **AI Visibility (2025)** tracking brand mentions in AI search — prescient for a world where ChatGPT / Perplexity replace some Google queries. Ecommerce SEO-adjacent; interesting thread to pull.

## What to avoid
- **Don't gate the B2B account view behind an add-on.** For ecommerce with subscriptions or agencies managing multiple stores, the "org-level view" is core, not add-on.
- **Don't make pricing opaque.** Amplitude's "talk to sales for Growth" is hostile to SMBs who just want to know what they'll pay.
- **Don't let predictive features fail silently on small datasets.** Predictive Cohorts need volume; when there isn't enough data, the UI should say so, not produce a misleading cohort.
- **Don't let "legacy charts" and "new charts" coexist indefinitely.** The Pathfinder vs. Journeys split confuses users and pollutes docs.
- **Don't require $499 for a tracking plan.** If setup is this hard, the product is too hard.
- **Don't ship enterprise permissions as table stakes only at Enterprise tier.** SSO, RBAC, audit logs are security requirements for any SaaS business, not enterprise luxuries.
- **Don't let the product feel like "ten products in one".** Amplitude's breadth (Analytics + Experiment + Flags + Session Replay + Guides + Feedback + Data + CDP) is also its burden. Each new surface dilutes focus.
- **Don't default to event-centric views for order-centric users.** Ecommerce users think in orders, not events — our UI must preserve that shape even if the underlying store is event-like.

## Why ecommerce brands pick it
- Behavioral depth — funnel/retention/journeys are best-in-class, especially the Journeys path analysis.
- Predictive cohorts for re-engagement and VIP identification.
- Amplitude Experiment if you need proper A/B testing alongside analytics.
- Notebooks for execs/stakeholders; Dashboards for operators; Charts for PMs.
- Enterprise-grade governance, SSO, audit logs, SOC 2 / HIPAA.
- Cross-product: behavior → experiment → feature flag → user guide, all in one data model.

## Why they leave it
- Cost. Enterprise contracts are $50k–$200k+. Growth is custom and climbs with MTU.
- No ad-spend / ROAS / store-DB reconciliation — can't be the only analytics tool for an ecommerce brand.
- Implementation is a quarter-long project; Shopify/WooCommerce integration is DIY.
- SMB tier (Starter/Plus) is too narrow; the mid-market jump to Growth is a wall.
- "Too product-analytics" for founders who need blended ROAS and order-level views.
- Tracking plans rot — event schemas drift, governance tax is real.

## Sources
- https://amplitude.com
- https://amplitude.com/pricing
- https://amplitude.com/docs/analytics/charts/journeys/journeys-understand-visualizations
- https://amplitude.com/docs/analytics/charts/journeys/journeys-understand-paths
- https://amplitude.com/docs/analytics/charts/legacy-charts/legacy-charts-pathfinder
- https://amplitude.com/docs/analytics/charts/legacy-charts/legacy-charts-journeys
- https://amplitude.com/docs/analytics/dashboard-create
- https://amplitude.com/docs/analytics/search
- https://amplitude.com/docs/analytics/behavioral-cohorts
- https://amplitude.com/docs/data/audiences/predictions-use
- https://amplitude.com/blog/introducing-predictive-cohorts
- https://amplitude.com/blog/experiment-feature-management
- https://amplitude.com/blog/ab-testing
- https://amplitude.com/blog/cohorts-to-improve-your-retention
- https://amplitude.com/blog/ecommerce-product-benchmarks
- https://amplitude.com/blog/amplitude-dashboards-charts
- https://amplitude.com/templates/ecommerce-dashboard
- https://amplitude.com/templates/e-commerce-dashboard
- https://amplitude.com/amplitude-experiment
- https://help.amplitude.com/hc/en-us/articles/115001816407-Amplitude-s-charts-find-the-right-one-for-your-analysis
- https://help.amplitude.com/hc/en-us/articles/115001351507-Get-the-most-out-of-Amplitude-s-Funnel-Analysis-chart
- https://help.amplitude.com/hc/en-us/articles/360061270232-Amplitude-Experiment-overview-Optimize-your-product-experience-through-A-B-testing
- https://academy.amplitude.com/create-and-analyze-predictive-cohorts
- https://e-cens.com/blog/amplitude-101-advanced-analysis-with-pathfinder-cohorts/
- https://e-cens.com/blog/amplitude-101-building-your-first-essential-analyses-part-3-of-getting-started-series/
- https://userpilot.com/blog/amplitude-analytics-features-alternatives/
- https://userpilot.com/blog/amplitude-pathfinder/
- https://userpilot.com/blog/amplitude-tracking/
- https://userpilot.com/blog/amplitude-pricing/
- https://usermaven.com/blog/amplitude-pricing
- https://marketingtoolpro.com/2025/07/amplitude-review/
- https://www.simpleanalytics.com/resources/analytics-review/amplitude-review-and-a-better-alternative
- https://www.itqlick.com/amplitude/pricing
- https://www.spendflo.com/blog/amplitude-pricing-guide
- https://livesession.io/blog/amplitude-pricing-features-costs-and-a-better-alternative
- https://www.optimizely.com/insights/blog/amplitude-pricing-and-a-better-alternative/
