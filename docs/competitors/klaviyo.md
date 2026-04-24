# Klaviyo

**URL:** https://www.klaviyo.com
**Target customer:** Shopify DTC brands from $100k–$100M+ GMV; the default email/SMS platform for mid-market ecommerce. Also serves WooCommerce, BigCommerce, Magento, and custom stacks. 157k+ customers claimed, with heaviest penetration in Shopify mid-market. Enterprise arm ("Klaviyo One") serves multi-brand portfolios.
**Pricing:** Tiered by active profile count. Marketing (email/SMS) and Data+Analytics (Marketing Analytics) are billed separately.
- **Free** — $0. 250 active profiles, 500 emails/mo, 150 SMS credits/mo. Built-in reporting, Overview Dashboard, RFM not included.
- **Email** — from $20/mo (251–500 profiles, 5,000 sends, 150 SMS credits). Example rungs: 5k contacts = $100/mo, 10k = $150/mo, 50k = $720/mo, 200k = $2,315/mo.
- **Email + SMS** — from $35/mo (adds 1,250 SMS/MMS credits + push).
- **Marketing Analytics** (paid add-on) — from $100/mo up to 13,500 active profiles. Unlocks Custom CLV, RFM, product analysis, multi-touch attribution. **Not included in the base Email/SMS plan.**
- **Advanced KDP / Klaviyo One** — enterprise, 6-figure annual contracts. +20% surcharge on $10k+/mo accounts.
- SMS billed via credits ($15/mo for 1,250 credits). International SMS costs 3–12 credits/message.
- Billing model changed Feb 2025: all active profiles count toward pricing, not just emailed profiles. Prices went up meaningfully for dormant-list brands.
**Positioning one-liner:** "The customer platform for your brand." — unified email + SMS + push + review + profile database with built-in attribution and predictive analytics, priced as the premium option in the category.

## What they do well
- **Reporting is a first-class surface, not an afterthought.** `Analytics` is a top-level nav item with four sub-tabs: Dashboards, Custom Reports, Benchmarks, Trends. Pre-built dashboards ship out of the box: **Overview, SMS, Business Review, Email Deliverability**.
- **Home dashboard is the landing page, and it's good.** Business Performance Summary (total vs attributed revenue), Top Performing Flows (top 6, ranked by conversions), Recent Campaigns table, alerts feed, "pick up where you left off." Conversion metric is a dropdown — default is Placed Order but any custom event works.
- **Benchmarks are peer-cohort based, not just industry averages.** Peer group = ~100 companies matched by industry, AOV, revenue, YoY growth, send cadence, and email revenue %. Four benchmark tabs: Overview, Business performance, Campaign performance, Flow performance. Status badges ("Excellent / Good / Fair / Poor") on every metric.
- **Flow analytics are embedded in the flow builder itself.** Toggle "Show Analytics" on the visual canvas and every message card expands to show Delivered / Open rate / Click rate / CVR / Revenue over your chosen range. You see drop-off visually between steps of a 5-email welcome flow.
- **Funnel summary card** aggregates delivery → open → click → conversion as a bar chart, per-channel (email, SMS, push).
- **CLV dashboard is a distinct surface.** Historic + predicted CLV, predicted next-order date, predicted number of orders, avg days between orders. Auto-retrains the model at least weekly. Includes "Current model" card showing 5 example customer profiles with predictions.
- **RFM dashboard buckets customers into 6 mutually exclusive cohorts** (Champions, Loyal, Potential Loyalists, At Risk, About to Sleep, Needs Attention). Click any cohort → view profiles → create a segment in one step.
- **Attribution window is per-channel and adjustable retroactively.** Email clicks/opens, SMS clicks/opens, push, WhatsApp each have independent lookback windows. When you change the window, historical reports recalculate (up to 36 hours).
- **Predictive analytics on every profile:** churn risk, expected next-order date, predicted CLV, expected AOV. Usable as segment filters directly.
- **Send-time optimization, subject line A/B, product recommendations** are all ML-driven and ship as defaults on the Standard/Pro tiers.
- **Direct Shopify order-ID match.** When a Shopify order fires the `Placed Order` event, Klaviyo receives the full cart + order ID and can attribute without UTM gymnastics. Same for WooCommerce via their official plugin.

## Weaknesses / common complaints
- **Attribution over-counts by design.** Default 5-day-from-open + 5-day-from-click windows mean any Apple MPP auto-open credits Klaviyo for anything that customer buys in 5 days — regardless of whether they engaged. Widely cited as the #1 reason Klaviyo and Shopify numbers never match. Klaviyo reports typically show 40-70% of identifiable email-driven orders; the rest leak to "unattributed" in Shopify or double-counted elsewhere. **This is exactly the "Klaviyo says $X, Shopify says $Y" problem Nexstage has to help users reconcile.**
- **Pricing scales punishingly.** 5k → 50k profiles is $100 → $720/mo for email alone. Reddit and G2: "We're paying 10x what we paid two years ago without a feature step-up." Feb 2025 billing change made this worse.
- **Marketing Analytics is a $100+/mo add-on on top of the base plan.** Multi-touch attribution, RFM, product analysis, Custom CLV all live here. Smaller brands hit a feature cliff at around 5–10k contacts where the advanced analytics become essential but the add-on eats margin.
- **Customer support is slow.** Days to first response, generic first-level responses. Trustpilot and G2 complaint pattern matches Triple Whale's — "friendly but ineffective." Klaviyo One clients get dedicated CSMs; everyone else fends off the bot.
- **Segment loading is slow** on large lists (200k+ profiles). "Painfully slow" is the consistent phrase on G2.
- **Dashboard customization capped at 10 custom dashboards.** Power users and agencies hit this quickly.
- **Overview/Analytics dashboard vs. Home dashboard is confusing.** Two different surfaces showing overlapping data — new users often don't realize both exist.
- **No native cross-channel attribution outside Klaviyo's own channels.** You see email vs. SMS vs. push fine. You don't see email vs. Meta vs. Google without a BI tool.
- **Landing pages and deeper segmentation are "missing" per many reviews** — you build landing pages in Shopify or a third-party tool and pipe traffic back.

## Key screens

### Home dashboard (lands on login)
- **Layout:** Customized per brand. Top strip = account alerts. Center canvas = four main cards stacked: Business Performance Summary, Top Performing Flows, Recent Campaigns, Personalized Recommendations ("Pick up where you left off").
- **Business Performance Summary:** Conversion metric dropdown (default "Placed Order"), date range control up to 180 days. Shows Total Revenue, Attributed Revenue, Attributed %, breakdown by channel (email / SMS / push) and by type (flows vs campaigns).
- **Top Performing Flows card:** Table of up to 6 flows ranked by conversions. Columns: flow name, trigger, live/draft status, message-type icons, delivered count, conversions, % change vs prior period.
- **Recent Campaigns card:** Chronological list of sent campaigns. Columns: name, send date, type icons, open rate, click rate, conversion data.
- **Alerts section:** Flow anomalies, billing, delivery issues.
- Screenshot refs: https://help.klaviyo.com/hc/en-us/articles/9974064152347, https://www.klaviyo.com/blog/redesigned-homepage-dashboard

### Analytics > Dashboards (Overview Dashboard)
- **Layout:** Card grid. Seven default cards; user can add from a library of 10+ extra cards; max 10 dashboards per account.
- **Default cards:** Conversion Summary (stacked bar, flows vs campaigns over time), Campaign Performance (opens/clicks/CVR with peer benchmarks), Campaign Performance Detail (flat table), Flows Performance (by channel with benchmarks), Flows Performance Details (alphabetized table), Performance Highlights (top/bottom metrics, monthly), Email Deliverability (bounce/spam/unsubscribe trend).
- **Extra cards:** Conversion by channel, Email/SMS/Mobile push funnel summaries, SMS deliverability, Flow volumes, Forms performance (detail + summary), Email deliverability by domain, Flows conversion, Subscriber growth.
- **Date-range behavior:** Auto-scales aggregation (daily ≤30d, weekly 30–90d, monthly >90d).
- **Interactions:** Hover tooltips on every chart, sortable paginated tables, multichannel tabs (email / SMS / push), green/red % deltas.

### Flow analytics (per-flow drill-down)
- **Overview tab:** Metric cards at top (opens/clicks/CVR/revenue) + "Engagement over time" line graph.
- **Visual canvas with embedded analytics:** Toggle shows per-message performance cards inside the flow builder — the killer UX move. You see flow drop-off spatially, not in a separate report.
- **Recipient Activity tab:** Sortable table per profile showing opens/clicks/skip reason/conversion status.
- **Link Activity tab:** Unique clicks, total clicks, clicks/recipient per link.
- **Deliverability tab:** Breakdown by inbox provider (Gmail/Yahoo/Outlook), email domain, country, client type.
- **Per-message sidebar snapshot:** Click any step → 30-day mini-report (Delivered, Open rate, Click rate, CVR, Revenue).

### Campaign comparison (Custom Reports)
- **Builder:** Pick dimensions (campaign, flow, segment, date), pick metrics, pick chart type. Saved to Custom Reports library.
- **Comparative reports:** Side-by-side campaign performance — sort by any metric. Benchmarks overlay shows "how this campaign ranks vs your peer group."
- **Retroactive recalculation** when attribution windows change is a first-class feature.

### Benchmarks dashboard
- **Tabs:** Overview, Business performance, Email campaigns, SMS campaigns, Flows, Signup forms.
- **Per-metric cards:** Your value + peer median + industry median, status badge (Excellent/Good/Fair/Poor), bar or line chart. Hover reveals full peer distribution.
- **Peer group definition visible** — users can see which ~100 companies their benchmarks are drawn from (anonymized). Rebuilt weekly.
- Screenshot ref: https://www.klaviyo.com/features/benchmarks

### CLV dashboard (Marketing Analytics add-on)
- **Current Model card:** Historic + predicted date ranges, last update timestamp, 5 example customer profiles with their CLV numbers.
- **Segments using CLV card:** Table of segments that reference CLV attributes.
- **Upcoming campaigns card:** Campaigns targeting CLV-filtered segments.
- **Flows using CLV card / Forms using CLV card.**
- Per-profile metrics: Predicted CLV, Historic CLV, Total CLV, Predicted # orders, Historic # orders, AOV, Avg days between orders.

### RFM dashboard (Marketing Analytics add-on)
- **Six cohorts** rendered as a grid of cards: Champions, Loyal, Potential Loyalists, At Risk, About to Sleep, Needs Attention.
- Each card: customer count, % of base, avg CLV, avg days since last order. Click → view profiles → "Create segment."

### Product analytics (Marketing Analytics add-on)
- Repeat-purchase timing (avg days between orders for a SKU).
- Cross-sell graph: "Customers who bought X also bought Y" — basket-affinity analysis.
- Product-sequence analysis for next-best-product recommendations.

### Trends (industry report)
- Live benchmark page showing industry-wide trends: open rate, CVR, revenue per recipient, by industry and AOV band. Gated behind login but partly public at https://www.klaviyo.com/marketing-resources/email-benchmarks-by-industry-2024.

## Attribution model
**Last-touch, per-channel, configurable windows.** This is the load-bearing mechanism the entire reporting surface sits on.

Defaults:
- **Email:** 5 days from click, 5 days from open
- **SMS:** 5 days from click, 1 day from open (SMS click window was 24h pre-2024, now 5 days to match email)
- **Push:** 24 hours from open
- **WhatsApp:** 5 days from click

**Multi-channel resolution:** If a customer interacts with both an email and an SMS, the most recent interaction **within its own channel's window** gets credit — not simply "most recent message." This means a customer could see an email 4 days ago, an SMS 2 days ago, and if the SMS is outside its own shortened window, the email still wins.

**Order matching:** Direct integration with Shopify/WooCommerce fires a `Placed Order` event with order ID + line items. No UTM match needed. This is cleaner than ad-platform attribution but comes at the cost of only seeing Klaviyo's own channels.

**Known over-attribution paths:**
1. Apple MPP auto-opens count as opens → any purchase in the next 5 days gets "email attributed" even though the customer never saw the email.
2. Default 5-day click window is long relative to DTC cycles — many "attributed" orders would have happened anyway.
3. Klaviyo credits itself regardless of what Meta/Google/TikTok also claimed for the same order; sum-to-100% not enforced.

Klaviyo added a bot-click filter and Apple Privacy filter in 2023–2024 — optional toggles. Enabling them reduces reported attributed revenue, which is why many brands leave them off.

## Integrations
- **Stores:** Shopify (flagship), Shopify Plus, WooCommerce, BigCommerce, Magento, Wix, Squarespace, Ecwid, Salesforce Commerce Cloud, custom via API.
- **Reviews:** Klaviyo Reviews (native), Okendo, Yotpo, Judge.me, Stamped.
- **Subscriptions:** Recharge, Bold, Stay, Skio, Loop, Seal, Awtomic.
- **Loyalty:** Smile.io, LoyaltyLion, Yotpo Loyalty, Friendbuy.
- **Helpdesk:** Gorgias (tight), Zendesk, Kustomer, Re:amaze.
- **Ads:** Meta Custom Audiences (push audiences from segments), Google Ads Customer Match, TikTok, Pinterest.
- **BI:** Klaviyo Data Platform (KDP) supports reverse-ETL to Snowflake/BigQuery/Redshift (Advanced KDP tier).
- 350+ official integrations claimed.

## Notable UI patterns worth stealing
- **Attribution-window control that recalculates historical reports retroactively.** Huge trust signal — users can change the window and immediately see what their numbers would look like. Very few analytics tools let you do this without a re-sync wait.
- **Per-channel attribution windows, not one global window.** Email and SMS behave differently; one window is wrong for both.
- **Status badges on every benchmark metric** (Excellent / Good / Fair / Poor). Not just a number next to peers — an opinionated rating. Low cognitive load, high decision value.
- **Peer group transparency** — showing users the ~100 companies they're benchmarked against (by composition, not names) builds trust in the comparison.
- **Embedded analytics inside the flow builder** — spatial drop-off at the exact step where a user is editing. A ~5-email welcome flow shows its funnel right there on the canvas.
- **Conversion-metric dropdown at the top of every dashboard.** Any tracked event becomes the dashboard's conversion metric. Default is Placed Order; easily switched to Signed Up, Started Checkout, etc. Good precedent for Nexstage's multi-event support.
- **CLV "Current Model" card showing 5 example profiles** — anchors the abstract concept in real, clickable customer rows. Makes ML predictions feel less like magic.
- **Six-bucket RFM grid** as a visual — cheap to implement, instantly grokkable. Anchor for customer-segmentation UX.
- **"Performance Highlights" top/bottom metrics card** — surfaces "your best flow this month" and "your worst" without the user having to sort a table. Triple Whale does the same thing with Creative Highlights.

## What to avoid
- **Don't ship a default attribution window that looks wildly inflated.** Klaviyo's 5-day-from-open window is the single biggest reason users don't trust their own reports. If Nexstage shows email-attributed revenue, default to a conservative window (e.g., 2 days from click) and let users expand, not contract.
- **Don't hide bot-click/MPP filtering as an opt-in toggle.** When filters cost users their vanity metrics, they'll leave them off. Default-on + clear disclosure of what was filtered.
- **Don't gate multi-touch attribution behind a $100/mo add-on.** Klaviyo's Marketing Analytics upsell is the #2 complaint after price-scaling. If attribution is the trust story, it can't be paywalled.
- **Don't have two dashboards that overlap (Home vs Overview).** Pick one landing surface and commit. Navigation confusion is a user-research red flag Klaviyo keeps tripping over.
- **Don't cap custom dashboards at 10.** Agencies and power users burn through that in a week.
- **Don't let segment loading be noticeably slow on large lists.** If a 50k-contact segment takes 20 seconds, users stop using segments.
- **Don't ship customer support that takes >48 hours to respond.** Klaviyo's paid-tier support is a sore spot; Nexstage has an opportunity here simply by responding same-day during launch.

## Sources
- https://www.klaviyo.com
- https://www.klaviyo.com/pricing
- https://www.klaviyo.com/solutions/analytics
- https://www.klaviyo.com/products/marketing-analytics
- https://www.klaviyo.com/features/benchmarks
- https://www.klaviyo.com/blog/redesigned-homepage-dashboard
- https://www.klaviyo.com/blog/ai-benchmarks
- https://help.klaviyo.com/hc/en-us/articles/1260804504250 (Understanding message attribution)
- https://help.klaviyo.com/hc/en-us/articles/4708299478427 (Getting started with analytics / overview dashboards)
- https://help.klaviyo.com/hc/en-us/articles/13267004984859 (How to build an analytics dashboard)
- https://help.klaviyo.com/hc/en-us/articles/9974064152347 (Understanding the home dashboard)
- https://help.klaviyo.com/hc/en-us/articles/16427152766619 (Business review dashboard)
- https://help.klaviyo.com/hc/en-us/articles/115002779351 (Flow analytics)
- https://help.klaviyo.com/hc/en-us/articles/17797865070235 (CLV dashboard)
- https://help.klaviyo.com/hc/en-us/articles/360020919731 (Predictive analytics)
- https://help.klaviyo.com/hc/en-us/articles/18193920339483 (RFM segments)
- https://help.klaviyo.com/hc/en-us/articles/360050110072 (Benchmarks reports)
- https://help.klaviyo.com/hc/en-us/articles/360050180151 (Peer groups)
- https://help.klaviyo.com/hc/en-us/articles/33789259613595 (Marketing Analytics tier)
- https://klaviyopricing.com/
- https://www.retainful.com/blog/klaviyo-pricing
- https://yocto.agency/the-truth-about-klaviyos-attribution-window/
- https://www.polarisgrowth.com/en/blog/the-new-klaviyo-attribution-models-and-tracking-explained
- https://www.letterbox.media/post/klaviyo-attribution-vs-shopify-why-the-numbers-never-match-and-which-to-trust
- https://mhdigitalgroup.com/email-marketing/the-klaviyo-performance-dashboard-guide/
- https://wholedesignstudios.com/klaviyo-analytics/
- https://www.g2.com/products/klaviyo/reviews
- https://www.trustpilot.com/review/klaviyo.com
- https://klaviyo.tech/the-research-behind-our-new-rfm-feature-4c38be17b184
- https://www.titanmarketingagency.com/articles/klaviyo-rfm-segments
