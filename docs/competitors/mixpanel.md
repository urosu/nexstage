# Mixpanel

**URL:** https://mixpanel.com
**Target customer:** Product-led SaaS (B2B and B2C apps) and digital-first consumer products. In ecommerce it's the "we care about behavior before purchase" crowd — DTC brands running complex checkout flows, subscription commerce, app-first retailers. Less common for commodity Shopify stores; more common for brands with a custom frontend, Shop app users, or app-shopping flows. Claims 12,000+ community, used by Netflix, Uber, Canva, Notion.
**Pricing:** Switched from MTU (Monthly Tracked Users) to **event-based pricing in February 2026**. Existing MTU contracts can stay on MTU or migrate.
- **Free** — $0. Up to 1M monthly events (new event-based model) or 20M events for new signups. 5 saved reports. 10K session replays/mo. Core reports (Insights, Funnels, Retention, Flows). No credit card.
- **Growth** — $0 floor + $0.28 per 1,000 events beyond 1M. Unlimited saved reports, behavioral cohorts, 20K replays (scalable to 500K), advanced reports (Impact, Signal, Experiments). Pricing calculator on site.
- **Enterprise** — custom, starting ~$20k/yr. Unlimited events, HIPAA, data governance controls, premium support, Group Analytics add-on, Data Pipelines add-on, custom terms.
- **Startup** — first year free for companies <5 years old with ≤$8M funding.
- Add-ons stack: base + Group Analytics + Data Pipelines + extra Session Replays can 2–3× the bill.
**Positioning one-liner:** "Product analytics for the AI era" — event-first behavioral analytics with fast aggregate queries (sub-second on billions of events) and a recent pivot toward Metric Trees, AI assistant, and session replay.

## What they do well
- **Report taxonomy is industry-defining.** Insights, Funnels, Flows, Retention, Impact, Experiments, Signal (2025 rename), Session Replay. The chart-type-as-report-template model has been copied by Amplitude, PostHog, June, everyone.
- **Funnel builder is the cleanest in the category.** Drag events into a sequence, set a conversion window, add filters and breakdowns, done. Every competitor compares themselves to it. For ecommerce: Product Viewed → Product Added → Checkout Started → Purchase Completed, with revenue at each step and drop-off %.
- **Retention report does both the curve and the heatmap.** Line chart with one line per cohort + tabular heatmap showing % retained at each period, color-graded. The single most-imitated viz in the category.
- **Flows (Sankey) shows what users did before or after an event.** Click into a funnel step, "View as Flow", and see every event that happened pre/post the step, width-proportional to users. Nothing else in the ecommerce category has this.
- **"View as Flow" drill-through from Funnels** — turns "where did users drop off" into "what did they do instead" in one click.
- **Breakdowns are universal.** Every report supports breaking a metric by any property (UTM medium, device, country, plan tier, custom property). Charts get per-breakdown series automatically.
- **Segment comparison is first-class.** "Show me Funnel A for logged-in users vs. guests" — two series on the same chart, formatted identically. No manual filtering of two separate reports.
- **Speed.** Sub-second query times even at billions of events. The UX is fluid enough that users explore freely — the "go make a sandwich while this loads" moment never happens.
- **Session Replay (2024).** Integrated with reports — click a user in a funnel drop-off and watch their session. Heatmaps for page-level engagement.
- **Metric Trees (2025).** Define a top-level metric (Revenue) and its dependencies (Orders × AOV); Mixpanel auto-decomposes and surfaces which leaf moved when the root changed. Maps onto ecommerce revenue attribution.
- **Boards (their dashboard).** Drag-resize cards on a grid; supports text, images, and YouTube/Loom video cards alongside reports. Good for narrative dashboards with context.
- **AI assistant (MCP, 2025).** Ask product questions via Claude, Cursor, or ChatGPT. Not a UI surface — it's an MCP server the user brings their own LLM to.
- **Alerts and Subscriptions on every report.** Threshold-based alerts on any saved Insight; scheduled deliveries of Boards to Slack or email.

## Weaknesses / common complaints
- **Pricing cliff is brutal.** Jump from free (1M events) to Growth pricing is real; once a store does any reasonable traffic, the event count compounds. Users report 2–3× billed costs from add-ons (Group Analytics, Data Pipelines, extra Session Replay). "No middle-tier between Growth and Enterprise" is the loudest mid-market complaint.
- **Event bloat is a constant tax.** Every client-side event counts; every page view, every button click. Teams end up pruning their tracking plan just to stay under quota — the opposite of what analytics should encourage.
- **Not an ecommerce tool.** No native Shopify or WooCommerce connector. Users pipe orders via Segment / RudderStack / custom webhook → Mixpanel events. Order-level fields (line items, shipping, discounts) have to be flattened into event properties by hand.
- **Attribution is weak.** Mixpanel doesn't do MTA; there's no "blended ROAS", no ad-spend joins, no multi-touch model. If you want channel attribution you're feeding in UTM or source property and doing first-touch / last-touch manually.
- **Implementation is heavy.** Users consistently say "harder to implement and master than other tools". Tracking plan design, event schema, property naming — this is an engineering project, not a plug-and-play install.
- **Funnels with complex logic require saving "behaviors"** — a 2024 addition. Before that, complex funnel definitions had to be recreated in every report. Still not ideal.
- **Flows (Sankey) can't be computed on arbitrary breakdowns cheaply.** At high event volume, flows report times out on deep paths.
- **Group Analytics (accounts/companies) is a paid add-on, not default.** B2B-ecommerce (wholesale, subscriptions with org accounts) users have to pay extra for the "account roll-up" view.
- **Session Replay is capped tightly** — free = 10K/mo, Growth default = 20K/mo. Scaling up is an add-on line item that surprises users at billing time.
- **UI is dense.** Powerful but overwhelming. SMB users describe it as "intimidating" — the learning curve is real and documented.
- **No real dashboard-scoped filter propagation.** Each card queries independently; Boards have a concept of filters but they don't always apply cleanly across every card.
- **Data governance without Enterprise is limited.** No SSO, no granular RBAC, no audit logs — all Enterprise features.

## Key screens

### Insights (trend/segmentation report)
- **Layout:** Left panel — event + filter + breakdown builder. Right panel — chart.
- **Event picker:** Add event → apply filters (property equals/contains/regex) → add breakdowns (one or more properties) → select measurement (total events, unique users, sessions, aggregate property).
- **Chart types:** Line (trend), Bar, Stacked Bar, Pie, Metric (scalar), Table, Pivot Table.
- **Comparison:** "Compare to previous period" toggle overlays a ghost series.
- **Save:** As a saved Insight, add to a Board, set an alert.

### Funnels (Conversion report)
- **Layout:** Step picker at top — add events in sequence. Optional "within X hours/days" conversion window. Filters and breakdowns on the right.
- **Visualizations:**
  1. **Funnel Steps** — the classic horizontal-bar funnel, percentage + count at each step + drop-off % between steps.
  2. **Trends** — line chart of overall conversion rate over time.
  3. **Time to Convert** — distribution histogram of how long users took to complete the funnel.
  4. **Frequency** — histogram of how many times users completed the funnel.
- **Ecommerce example the docs use:** Product Viewed → Product Added → Checkout Started → Purchase Completed. Revenue-at-each-step is computable via aggregate property measurement.
- **Drop-off drill:** Click a step's drop-off bar → "View as Flow" → see what events the drop-off cohort did instead.
- **Screenshot refs:**
  - https://docs.mixpanel.com/docs/reports/funnels/funnels-quickstart
  - https://docs.mixpanel.com/docs/reports/funnels/funnels-advanced
  - https://docs.mixpanel.com/changelogs/2024-02-13-new-funnels-retention

### Retention
- **Layout:** First-event picker ("did X"), return-event picker ("then did Y"), retention type (classic / unbounded / rolling), period (day/week/month), filters, breakdowns.
- **Visualizations:**
  1. **Retention Curve** — line chart, one line per cohort, Y = % retained, X = period since day 0.
  2. **Retention Heatmap** — table rows = cohort (signup week / month), columns = periods since, cell color = % retained. Row 0 is always 100%, gradient from red (low) to green (high). The canonical cohort heatmap.
  3. **Line** and **Metric** simplified variants.
- **Interaction:** Click any cohort row to create a Mixpanel cohort of those users and pivot to Insights / Flows / Session Replay.
- **Screenshot refs:**
  - https://docs.mixpanel.com/docs/reports/retention
  - https://community.mixpanel.com/product-updates/retention-report-average-retention-line-table-4529

### Flows (Sankey Pathfinder)
- **Layout:** Start event ("start from") or end event ("end at") at the center; funnel of paths leading in / leading out. Nodes are events, widths proportional to user counts.
- **Settings:** Max path depth, session-gap window, include/exclude events, breakdowns.
- **Use:** "What do users do after Purchase Completed?" or "How do users get to the Checkout page?". Critical for ecommerce journey understanding.
- **Limits:** Deep paths (10+ steps) get slow and noisy.

### Boards (dashboard)
- **Layout:** Grid of cards. Drag to resize; insert rows; cards can be reports, text (rich text), images, or embedded video (YouTube/Vimeo/Loom).
- **Dashboard filters:** Top bar; date picker, property filters that propagate to cards (when cards accept the property).
- **Collaboration:** Comment threads per card, @mentions, share links, public snapshot view.
- **Refresh:** Auto-refresh intervals, manual refresh.
- **Screenshot refs:**
  - https://docs.mixpanel.com/docs/boards
  - https://mixpanel.com/blog/boards-collaborate-cards-mixpanel-feature-update/

### Cohorts (user segments)
- **Layout:** "Users who did X in the last Y days" → list of users matching. Live-updating.
- **Compositions:** AND/OR of event-based and property-based criteria. Nested groups.
- **Export/use:** Cohorts sync to ad platforms (Meta, Google) for re-targeting, to email tools (Klaviyo, Mailchimp), as comparison filters in any report.
- **Ecommerce: "Users who viewed a product 3x in 7 days but didn't buy"** is a cohort; re-targeting audience pops out the other end.

### Session Replay
- **Layout:** Chronological list of sessions; filters by event, cohort, date. Player with DOM playback and heatmap overlay.
- **Integration with reports:** Any user row in a report can launch their session replay.
- **Cost:** 10K replays/mo free, 20K on Growth, paid scale up to 500K+.

### Signal (formerly Impact) — causal analysis
- **Layout:** Define an "event of interest" (e.g., "Signed up for newsletter"), a conversion goal ("Purchased"), a time horizon. Mixpanel computes whether the event correlates with the goal above baseline.
- **Output:** Lift % with confidence interval.
- **Status:** Advanced report; behind Growth tier.

### Experiments
- **Layout:** Variant assignment events → conversion goal → statistical confidence output.
- **Analysis-only:** Mixpanel reads experiment data; assignment is still done by your own feature-flag tool (LaunchDarkly, Optimizely, Statsig, custom).

### Alerts & Subscriptions
- **Alerts (per-report):**
  - Static threshold: "when revenue < $10k/day".
  - Relative: "when week-over-week drop > 20%".
  - Anomaly (Enterprise-ish): ML-driven "unusual vs. historical" — behind newer tiers.
  - Delivery: Slack, email, webhook.
- **Subscriptions (Board-level):**
  - Scheduled delivery of a Board snapshot to Slack, email.
  - PDF or image; filter context preserved.

## Chart vocabulary
- **Signature:** funnel (with steps, trends, time-to-convert, frequency variants), retention curve, retention heatmap, flow/Sankey.
- **Standard:** line, bar (stacked, grouped), pie, metric (scalar), pivot table, area.
- **Missing:** geo map (rudimentary), treemap, distribution (partial — time-to-convert is one), radial/chord.
- **Ecommerce relevance:** strong on behavioral analysis (funnel/retention/flow), weak on revenue/spend/attribution (no built-in ROAS, MER, blended metrics).

## Query builder / abstraction level
- **Primary:** event-property builder. Pick event → filter → breakdown. No SQL.
- **Formulas:** JQL (legacy), Custom Events, Custom Properties — JavaScript-like expressions that define derived events/properties. Power user feature.
- **SQL mode:** via Data Warehouse Connectors (Snowflake, BigQuery, Redshift, Databricks) — a 2023+ feature. Lets advanced users query warehouse tables with Mixpanel's viz layer.
- **Saved behaviors (2024):** named sequences of events ("Completed checkout = Cart Updated → Shipping Entered → Payment Entered → Purchase Completed") reusable across funnels/retention/cohorts.

## Alerting & subscriptions
- **Alerts:** per-saved-report, threshold / relative change / anomaly. Slack, email, webhook. Good surface — better than Looker Studio (none) or Metabase (threshold only).
- **Subscriptions:** Board-level scheduled delivery to Slack/email, filter context preserved.
- **Segment-based audiences as "alerts":** a cohort filling past N users can trigger notifications.

## Integrations (ecommerce angle)
- **Ingestion:** JS SDK, mobile SDKs (iOS/Android/React Native/Flutter), server SDKs (Node/Python/Ruby/Go/Java), HTTP API, Segment/RudderStack/mParticle, data-warehouse reverse ETL.
- **Data warehouses:** BigQuery, Snowflake, Redshift, Databricks (bidirectional — pull from + mirror to).
- **No native ecommerce connector.** Shopify/WooCommerce order events go through Segment or custom webhook.
- **Audience sync (reverse ETL):** Meta, Google Ads, TikTok, Klaviyo, Iterable, Braze. Cohorts become ad audiences.
- **Output:** Slack, email, webhooks, CSV, data-warehouse mirror (pipeline add-on).

## Notable UI patterns worth stealing
- **One report = one chart type, with multiple visualizations.** Funnels can be Steps / Trends / Time-to-Convert / Frequency — all derived from the same query. Low cognitive load: "I want funnel data" → then pick how to see it.
- **"View as Flow" cross-report drill-through.** Click a step's drop-off → switch to Flows with that cohort pre-loaded. This is the pattern ecommerce attribution tools need: "Click the 'Not Tracked' bucket → switch to Flows of those users".
- **Retention report ships both the curve and the heatmap.** Users disagree on which is better; show both. Good precedent — don't pick one.
- **Event + breakdown + filter = universal query grammar.** Every report uses the same three primitives. Users learn it once and apply it everywhere.
- **Saved behaviors** as reusable query fragments — "our checkout funnel" is a named object, not a definition copy-pasted into 12 reports.
- **Cohort → sync to ad platform** — a cohort defined in analytics becomes a re-targeting audience without ETL. Nexstage's audience story could mirror this.
- **Session Replay launched from a report row** — the "who is this user?" question gets answered with the session itself, not a join to a CRM.
- **Metric Trees** — decomposition of a top-level metric into its drivers, auto-diagnosed when the root moves. Ecommerce revenue naturally decomposes this way (Orders × AOV → Orders × Average Basket Size × Units-per-Basket), and our MetricCard family could grow into this.
- **Boards allow text/video cards inline with reports.** Narrative dashboards — "here's the context, here's the chart" — outperform raw chart grids for stakeholder sharing.
- **Free tier is legitimately useful.** 1M events is enough for a small SaaS to actually adopt. Not a 14-day trial pretending to be a free tier.

## What to avoid
- **Don't price by event.** Event-count pricing punishes the instrumentation depth that makes analytics valuable. Our MTU-free model is the right answer.
- **Don't paywall mid-market features into Enterprise-only.** SSO, audit logs, role-based permissions — these are security basics, not enterprise upsell.
- **Don't ship a free tier that's actually a trial.** Mixpanel's generous free tier is a feature; the cliff from free to paid is the problem. We should keep the cliff smooth.
- **Don't let report complexity explode.** Each of Mixpanel's 7+ report types has dozens of knobs. Users get lost. Nexstage's advantage is opinionated pre-built views — don't let "customization" become unbounded.
- **Don't ship product analytics without attribution.** Mixpanel has no ad-spend side of the story. Ecommerce users live in both worlds and toggling is painful.
- **Don't let the learning curve kill SMBs.** The "powerful but intimidating" reputation is real. Default dashboards that "just work" matter more than 10 configurable report types.
- **Don't stack Group Analytics / Session Replay / Data Pipelines as hidden add-ons.** Users expect the base price to cover the base product.

## Why ecommerce brands pick it
- Behavioral analysis is the job. Funnels, retention, flows are the best in the category.
- Non-Shopify / custom-frontend / app-first retailers can't use Shopify-only tools; Mixpanel is event-based and works with anything.
- Subscription commerce (recurring billing, churn analysis, upgrade/downgrade funnels) fits naturally.
- Cohort → Meta audience sync is a growth-team staple.

## Why they leave it
- Cost. Event-based pricing compounds faster than expected; the cliff from free to Growth is visible.
- No ad spend, no ROAS, no attribution. Ecommerce needs a second tool to fill the revenue-per-channel gap — and at that point you're paying for two tools.
- Shopify/WooCommerce integration is DIY via Segment; data model mismatches between "orders" and "events" burn engineering hours.
- Implementation is a project. SMB operators want a tool that works in a week; Mixpanel realistically needs a quarter.
- UI intimidation — the power-user surface alienates non-analysts.

## Sources
- https://mixpanel.com
- https://mixpanel.com/pricing/
- https://mixpanel.com/pricing/plan-builder/
- https://docs.mixpanel.com/docs/reports/funnels/funnels-quickstart
- https://docs.mixpanel.com/docs/reports/funnels/funnels-advanced
- https://docs.mixpanel.com/docs/reports/retention
- https://docs.mixpanel.com/docs/boards
- https://docs.mixpanel.com/docs/admin/pricing-and-plans/pricing
- https://docs.mixpanel.com/changelogs/2024-02-13-new-funnels-retention
- https://docs.mixpanel.com/changelogs/2024-04-03-save-funnel-retention-behaviors
- https://mixpanel.com/blog/boards-collaborate-cards-mixpanel-feature-update/
- https://community.mixpanel.com/product-updates/retention-report-average-retention-line-table-4529
- https://community.mixpanel.com/x/questions/n6g45vpyynbl/managing-event-ingestion-and-mtu-costs-in-mixpanel
- https://contentsquare.com/guides/mixpanel-glossary/funnels/
- https://userpilot.com/blog/mixpanel-funnel-analysis/
- https://userpilot.com/blog/mixpanel-retention-analytics/
- https://userpilot.com/blog/mixpanel-cohorts/
- https://userpilot.com/blog/mixpanel-reviews/
- https://userpilot.com/blog/mixpanel-boards/
- https://marketingtoolpro.com/2025/07/mixpanel-review/
- https://hackceleration.com/mixpanel-review/
- https://openpanel.dev/articles/mixpanel-pricing
- https://costbench.com/software/developer-tools/mixpanel/
- https://www.optimizely.com/insights/blog/mixpanel-pricing-and-better-alternatives/
- https://www.trustradius.com/products/mixpanel/pricing
- https://www.crazyegg.com/blog/mixpanel-review/
- https://marketlytics.com/blog/case-study-using-mixpanel-to-measure-conversion-funnel-and-user-retention/
