# Omnisend

**URL:** https://www.omnisend.com
**Target customer:** Shopify and WooCommerce SMBs — the "Klaviyo for smaller budgets" positioning. Sweet spot is stores with 500–10,000 contacts: too small for Klaviyo's pricing, too growth-focused for Mailchimp. 100k+ customers claimed; heavy presence in the Shopify App Store (#1 email marketing app by install count for several years, 5,800+ reviews, 4.7/5).
**Pricing:** Contact-tier scaling, billed monthly. Analytics features are mostly bundled; Advanced Reporting is the only gated report.
- **Free** — $0. 250 contacts, 500 emails/mo, 500 web pushes, $1 free SMS credit, 50 reviews published (Shopify). All core analytics included.
- **Standard** — from $16/mo (30% starter discount: $11.20/mo for 3 months). Contact-scaled. 500 contacts → 6,000 emails/mo (12× contact count). Unlimited push. Standard reports.
- **Pro** — from $59/mo. 2,500 contacts starter rung. Unlimited emails. **SMS credits equal to monthly plan cost** bundled (e.g., $59/mo = $59 SMS credits). Unlocks Advanced Reporting, personalized content recommendations, priority support.
- **Custom** — quote-based for >2,500 contacts. Unlimited everything, dedicated onboarding, free migration, account expert (thresholds above $400/mo).
- Billable contacts include subscribers AND non-subscribers (cart abandoners, purchasers). Pricing adjusts monthly based on contact count.
**Positioning one-liner:** "The #1 email & SMS marketing platform purpose-built for ecommerce." — most ecommerce-native of the three major players, pitched against both Klaviyo (cheaper, simpler) and Mailchimp (actually ecommerce-first).

## What they do well
- **Ecommerce-first defaults.** Out of the box the platform knows what a product, order, cart, abandoned checkout, and browse-abandoner are — no setup. Dashboard overview shows "revenue generated from Omnisend, total store revenue, and list growth" on first login.
- **Customer Lifecycle Map is the signature analytics feature.** Two-axis grid (vertical = order count, horizontal = order value) where every customer lands in an RFM-derived cell: Recent, Needs Nurturing, Loyalists, Champions, At Risk, About to be Lost. Hover any cell → customer count + "View Contacts" / "Retention Tactics" actions.
- **Lifecycle stages auto-calculate and link to prebuilt retention tactics.** Each stage ships with recommended workflow templates ("At Risk → send reactivation flow"). One-click from diagnosis to remediation.
- **Customer Breakdown report** aggregates AOV, returning-customer rate, avg days between orders, total customers over trailing 365 days. Outliers auto-excluded. Daily refresh.
- **Sales attribution is user-configurable.** Per-channel attribution windows; recalculated on change. Attribution triggers are explicit (open vs click) — you pick which counts.
- **Auto-UTM tagging on every email link** with preset campaign name + campaign ID. Makes downstream GA/Nexstage attribution trivially correct.
- **Live View** — real-time activity feed of product views, add-to-carts, orders. Similar pattern to Triple Whale's Live Orders widget.
- **Advanced Reporting (Pro tier)** unlocks campaign sales segmented by audience segments, channel trends over time, device/platform breakdowns, exportable CSV/Excel. Indefinite historic data retention.
- **Click maps** show email engagement visually — heatmap of where subscribers clicked in the email body.
- **24/7 live chat support even on free tier** is a consistent review highlight ("more responsive than Klaviyo or Mailchimp combined").
- **Forms reports** track signup form performance as a first-class report, not an afterthought.
- **One dashboard, truly multi-channel.** Email, SMS, push, Facebook Messenger on the same screen with same filters. Real "channels side by side" that Klaviyo and Mailchimp both claim but neither fully executes.
- **4.7/5 on Shopify App Store** with 5,800+ reviews — the strongest distribution moat in the category.

## Weaknesses / common complaints
- **Deliverability averages ~64%** — noticeably below Klaviyo and Mailchimp benchmarks (70–80%). Degrades on lists above 50k. New-domain warmup splits sends between your domain and Omnisend's shared domain only for the first daily campaign; later sends go entirely shared.
- **Cohort analysis and predictive insights are NOT built-in.** Reviewers explicitly call this out: "Cohort analysis or predictive insights aren't built-in — you'll need to export via API or third-party tools." This is the single biggest analytics gap vs. Klaviyo.
- **No native predicted CLV, no churn risk score, no predicted next-order-date per profile.** Lifecycle stages are RFM-derived, not ML-derived. Good enough for segmentation, weak for forecasting.
- **Standard plan has reduced segment-level reporting.** Segment analytics and historical-depth features are gated behind Pro. Analytics feature-cliff at the Standard→Pro jump.
- **Template customization is limited.** Drag-drop builder feels restrictive for creative-heavy brands; image sizing in pop-up builder is clunky.
- **Two-step pop-up limit** — three-step opt-in flows (e.g., teaser → email capture → SMS opt-in) require workarounds.
- **SMS back-in-stock alerts are generic** — no deep-link to the specific requested product, unlike the email equivalent.
- **No Google Analytics direct integration** beyond auto-UTM. No Looker Studio connector. No native BI integration. To get Omnisend data into a warehouse you hit the API.
- **English-only UI.** Non-English-speaking teams struggle compared to Mailchimp's multilingual interface.
- **Audit-permission role can't view flow designs** — inconsistent with how Klaviyo/Mailchimp handle read-only access.
- **"Ecommerce focus" is occasionally a limitation** — most templates are product-promo-centric; content/newsletter brands find them constraining.
- **Lifecycle Map needs ≥100 returning customers** to compute store-specific thresholds. Below that, default (arbitrary) thresholds apply — less useful for small stores.

## Key screens

### Dashboard Overview (landing page)
- **Layout:** Single scrollable screen. Top row: three big-number tiles — Revenue generated from Omnisend, Total store revenue, List growth. Secondary row: Active subscribers, Campaigns sent, Workflows active.
- **Date range:** Preset (7 / 30 / 90 days / custom). No period comparison on free tier.
- **Live View sidebar/widget:** Real-time ticker of product views → add-to-carts → orders.
- **Quick links:** "Create campaign" / "Build workflow" / "Open Lifecycle Map" CTAs top-right.
- **No card customization on this page** — it's a fixed surface, unlike Klaviyo's grid.

### Reports tab (top-level nav)
- **Sub-tabs:** Campaign Reports, Automation Reports, Form Reports, Sales Report, Customer Breakdown Report, Advanced Reporting (Pro only).
- **Sales Report:** Revenue by channel (email / SMS / push) with filter by time period and campaign. Line chart + table. Filter drops tie into attribution settings.
- **Campaign Reports:** Per-campaign drill-down. Columns: sent, opens, clicks, bounces, unsubs, spam reports, revenue, AOV. Click map embedded inline.
- **Automation Reports:** Per-step metrics inside each workflow. Delivery → open → click → conversion. Revenue per step.
- **Form Reports:** Views, submissions, conversion rate, contacts added.
- **Advanced Reporting (Pro):** Segment-level campaign analysis, channel trend comparisons, cohort over time, audience-segment overlays.

### Customer Lifecycle Map (the showcase screen)
- **Layout:** 2D grid. X-axis = order value bucket, Y-axis = order count bucket. Each cell represents a lifecycle stage.
- **Stages:** Recent, Needs Nurturing, Loyalists, Champions, At Risk, About to be Lost. Each cell shows customer count + % of base.
- **Hover:** Tooltip with avg order value, avg days since last order, customer count.
- **Click:** Opens side panel with customer list + "Retention Tactics" recommended workflows.
- **Refresh:** Daily. Thresholds are store-specific (derived from your purchase patterns) once you have ≥100 returning customers.
- **Visual style:** Heatmap coloring — darker cells = higher customer count or more valuable.
- Screenshot ref: https://www.omnisend.com/features/customer-lifecycle-stages/

### Customer Breakdown Report
- **Overview section:** Three primary tiles — Average Order Value, Returning Customer Rate, Total Customers (trailing 365 days).
- **Embedded Lifecycle Map** below.
- **Per-segment drill:** Click Returning Customer Rate → see average days between orders histogram.
- Screenshot ref: https://support.omnisend.com/en/articles/5560449-customer-breakdown

### Campaign comparison
- Table view with sortable columns. No matrix/leaderboard visualization.
- Columns: campaign name, type, send date, recipients, open rate, click rate, conversion rate, revenue, AOV.
- **Side-by-side compare:** Select up to ~5 campaigns → comparison view with bar charts per metric.
- A/B test results appear inline with winner highlighted.

### Workflow (automation) analytics
- **Per-step metrics inline on the workflow canvas** — similar pattern to Klaviyo, though less polished.
- Per-step: sent, delivered, open rate, click rate, conversion rate, revenue.
- No true funnel visualization; just step-by-step numbers.
- Drop-off between steps is implied by delta rather than drawn as a funnel chart.

### Live View
- Ticker widget/page showing real-time customer actions.
- Entries: "Customer X viewed Product Y", "Added to cart", "Placed order — $85".
- Filterable by event type.

### Advanced Reporting (Pro-only)
- **Channel Trends:** Line chart comparing email / SMS / push revenue over time, stacked or overlaid.
- **Segment Analysis:** Which segments generate the most revenue — table sorted by revenue contribution.
- **Device/Platform Breakdown:** Desktop vs mobile, iOS vs Android vs web email client.
- **Historic Comparison:** YoY / MoM period overlays on any chart.
- **Exports:** CSV / Excel on any view.

## Attribution model
**Configurable last-touch per channel, with user-defined attribution triggers.**

Defaults (inferred from docs — Omnisend doesn't publicize exact default windows as prominently as Klaviyo):
- Email: orders placed within the set window after a click or open (default ~5 days, user-adjustable).
- SMS: shorter window by default (~1–3 days).
- Push: shorter still.

**User control:** In settings, merchant picks (a) attribution window per channel, (b) which events trigger attribution (open, click, or both), (c) whether to include subscribers-only or all contacts.

**Order matching:** Shopify/WooCommerce native integration fires order events with order ID. No UTM-match reliance (though auto-UTM is applied for downstream tools).

**Channel resolution:** When multiple channels qualify, last-touch within channel windows wins — same logic as Klaviyo but less explicitly documented.

**Compared to Klaviyo:** Shorter defaults, more control exposed in the UI. Less opinionated ("pick your own window") rather than Klaviyo's "5 days is the answer." Good for sophisticated users, confusing for novices.

**Compared to Shopify/Nexstage:** Same fundamental over-attribution risk as Klaviyo but somewhat muted because default windows are shorter.

## Integrations
- **Stores:** Shopify (flagship, deep), WooCommerce (deep), BigCommerce, PrestaShop, Wix, Squarespace, Shoplo, Opencart, Magento, custom API.
- **Reviews:** Native Omnisend Reviews (Shopify), Yotpo, Judge.me, Stamped, Okendo.
- **Loyalty:** Smile.io, LoyaltyLion, Gameball, Stamped Loyalty.
- **Subscriptions:** Recharge, Appstle, Bold.
- **Helpdesk:** Gorgias, Zendesk, Re:amaze.
- **Popups/Forms:** Native; integrations with Justuno, Privy.
- **Ads audiences:** Meta Custom Audiences, Google Ads Customer Match.
- **Zapier** and **Make** for long tail.
- **Notably missing:** Google Analytics direct connector, Looker Studio connector, warehouse destinations. API-only for BI.
- ~90 total integrations claimed.

## Notable UI patterns worth stealing
- **Customer Lifecycle Map as a 2D grid instead of a table of cohorts.** Axes = count × value; cells = stages. Spatially obvious, no legend needed, clickable. Massively better than Klaviyo's 6-card RFM grid for seeing "where is my customer base concentrated."
- **Retention Tactics linked directly to each lifecycle stage.** Click "At Risk" cell → offered prebuilt workflow template. Diagnosis → treatment in one click. Rare pattern in analytics tools; most stop at showing the problem.
- **Attribution settings exposed as simple UI controls, not buried in a developer-only config.** Window sliders per channel; trigger checkboxes (open / click). Users can experiment without reading docs.
- **Auto-UTM every outbound link** with preset tags. Zero-config downstream attribution for anyone using Nexstage or GA. Good precedent: the email tool shouldn't rely on itself to be the only source of truth; it should emit clean UTM signals too.
- **Live View ticker** as a persistent widget — visceral energy during promo launches, same UX payoff as Triple Whale.
- **Customer Breakdown's three-tile header** (AOV / Return Rate / Total Customers over 365d) is the cleanest distillation of "what's my customer base doing" I've seen. Three numbers > twelve.
- **Per-automation step metrics embedded on the canvas** — same pattern Klaviyo uses, slightly less visually polished but conceptually identical.
- **Plain-language stage names** (Champions, At Risk, About to be Lost) over jargon (Cluster A, Decile 1). RFM segments become instantly usable by non-analyst users.
- **Outlier exclusion in AOV/Returning Rate calculations by default.** Small UX touch — acknowledges that a single $50k order shouldn't skew the store's reported average. Good pattern for any mean-based metric.

## What to avoid
- **Don't ship predictive analytics as a "coming soon" placeholder** — Omnisend's lack of built-in CLV prediction, churn scoring, and next-order-date is the #1 reason brands eventually graduate to Klaviyo. Either ship meaningful predictions or don't claim the category.
- **Don't gate historical data depth behind the next tier up.** Standard users losing trailing-365-day segment data at a contact-count upgrade boundary is a consistent complaint.
- **Don't let the default attribution window be invisible.** Users on Omnisend frequently don't realize they can configure it — the default is just applied silently. Surface the setting on report screens, not only in admin.
- **Don't ship a multi-channel dashboard that doesn't actually reconcile channels.** Email + SMS + push totals can overlap (same customer, multiple touches, different channels each crediting themselves). Without an explicit "deduplicated across channels" number, users can't trust the top-line.
- **Don't skip a cohort view.** Every other analytics tool in adjacent categories has one; absence is felt quickly by graduating users.
- **Don't let default tiers have wildly different analytics depth.** Omnisend's Standard→Pro cliff on segment analytics teaches users to mistrust the reports they have. Better to ship one reporting surface and gate only the advanced stuff (exports, retention, custom metrics).
- **Don't under-invest in deliverability infrastructure.** 64% deliverability is a brand-damaging number; every missed inbox is a user complaint.
- **Don't ship an "audit" role that can't see flow designs.** Permission inconsistencies train users to avoid inviting teammates.

## Sources
- https://www.omnisend.com
- https://www.omnisend.com/pricing/
- https://www.omnisend.com/features/reports/
- https://www.omnisend.com/features/customer-lifecycle-stages/
- https://www.omnisend.com/blog/customer-lifecycle-stages/
- https://support.omnisend.com/en/articles/3533018-omnisend-pricing-plans-2026
- https://support.omnisend.com/en/articles/5569006-understand-your-customer-lifecycle-map
- https://support.omnisend.com/en/articles/5574998-analyze-your-customer-lifecycle-stages
- https://support.omnisend.com/en/articles/5560449-customer-breakdown
- https://support.omnisend.com/en/articles/5553046-customer-lifecycle-marketing-for-ecommerce
- https://apps.shopify.com/omnisend (5,800+ reviews, 4.7/5)
- https://apps.shopify.com/omnisend/reviews
- https://www.g2.com/products/omnisend/reviews
- https://www.capterra.com/p/153508/Omnisend/reviews/
- https://www.trustpilot.com/review/omnisend.com
- https://www.emailvendorselection.com/omnisend-review/
- https://www.stylefactoryproductions.com/blog/omnisend-review
- https://www.emailtooltester.com/en/reviews/omnisend/
- https://www.sender.net/reviews/omnisend/
- https://flowium.com/blog/omnisend-pricing/
- https://www.inboxarmy.com/blog/omnisend-pricing/
- https://xgentech.net/blogs/resources/omnisend-shopify-app-review
- https://www.digismoothie.com/app/omnisend
