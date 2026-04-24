# Triple Whale

**URL:** https://www.triplewhale.com
**Target customer:** Shopify-only DTC brands, $250k–$40M GMV. Sweet spot is founder-operators and small-to-medium Shopify stores running paid ads on Meta/Google/TikTok. Agencies are a significant segment. 50,000+ claimed users, ~2,500 Shopify merchants cited in older reviews.
**Pricing:** GMV-based tiers. Billed monthly or annually (annual = 2 months free).
- **Free / Founders Dash** — $0. Basic integrations, 10 users, 12-month lookback, first/last-click only. No Triple Pixel.
- **Starter** — $179–$299/mo (GMV-scaled). Multi-touch attribution via Triple Pixel, post-purchase surveys, Sonar Send, unlimited lookback/users.
- **Advanced** — $259–$389/mo. Adds subscription tracking, Total Impact Attribution, creative/product/cohort analytics, no-code dashboard builder, SQL editor, Sonar Optimize.
- **Professional** — $749/mo. Adds MMM, full SQL, unlimited custom dashboards.
- **Enterprise+** — custom. Bi-directional warehouse sync, dedicated CSM, implementation specialist.
- GMV scaling is real: a $6M GMV brand pays ~$1,129/mo. Moby AI is a credit-based add-on on top.
**Positioning one-liner:** "AI that actually works for ecommerce" — a unified measurement + BI + AI platform that tells Shopify brands what to do next.

## What they do well
- **Blended metrics are the headline.** Blended ROAS and MER (Marketing Efficiency Ratio) are front-and-center, not buried. This is the single concept DTC founders most want and Triple Whale centered the whole product around it.
- **Triple Pixel is the product moat.** First-party server-side pixel with identity graph; heavily marketed as the iOS-14 workaround. Post-purchase surveys layer on top ("How did you hear about us?") and feed into the Total Impact attribution model.
- **Summary Dashboard is built for the morning coffee check.** Sessions, revenue, spend, ROAS, MER, orders, AOV, CAC, NC-ROAS (new customer ROAS), LTV — all in one grid with real-time (~15-min) refresh. Mobile app exists specifically so founders can check on the go.
- **Creative Cockpit** is a standout feature: ad creative gallery with thumb-stop ratio, CTR, CPM, CPC, spend, attributed revenue, all segmentable by naming convention/type/ID so you can compare creative clusters vs. individual ads.
- **Benchmarks dashboard** shows your CPA/CPC/CPM/CVR/CTR/MER/ROAS/AOV vs. cohorts of other Triple Whale customers (filtered by industry and AOV <$100/>$100 or by revenue bracket). Data from 20k+ customers.
- **Customizable widget-grid dashboards.** Drag-drop widgets, preset metrics, SQL editor for power users. 75+ ready-made dashboards ship by default.
- **Moby AI / Willy / Lighthouse** — chat interface, anomaly detection, budget reallocation agents, creative brief generation, image/video generation. Heavily invested; most aggressive AI positioning in the category.
- **LTV cohort analysis** by acquisition source with "golden path" identification for repeat-customer journeys. Shows time-between-orders as a histogram.
- Tight Shopify integration including **inventory** (flag ads driving out-of-stock SKUs).

## Weaknesses / common complaints
- **Shopify-only.** WooCommerce/BigCommerce/Magento stores are unsupported. This is repeatedly cited as the #1 disqualifier.
- **Attribution over-counts.** If a customer touched FB and Google, both get full credit — channel totals can sum to >100% of actual orders. This is by design (mirrors platform behavior) but confuses users trying to tie the numbers together.
- **Support is the loudest complaint.** Trustpilot reviews: "no customer support despite paying $600+/mo", "waited 3 months for issue resolution", "support is friendly but absolutely ineffective". A reviewer reported being charged a $7k annual fee after cancelling.
- **Buggy mobile app.** iOS/Android apps "feel like a clunky web app", crash on open, layering issues with dropdowns, slow data loads.
- **~80% of platform is paywalled.** Free tier is deliberately crippled; users report constantly hitting upgrade walls. Moby AI credits are a second paywall on top of the plan.
- **Attribution numbers frequently diverge from ad platforms in unpredictable ways.** Top G2/Reddit complaint — users can't explain the discrepancy to their boss.
- **Amazon/PayPal direct purchases are invisible** (cohorts never see those customers).
- **UI complexity.** "Modifying reports or navigating menus is problematic" — especially for SMBs who don't have an analyst.
- Requires "medium to advanced skill" to actually trust the attribution output.
- 3.9/5 on Shopify App Store (87 reviews) — mediocre for a category leader.

## Key screens

### Summary Dashboard (home)
- **Layout:** Full-width widget grid on the main canvas; persistent left sidebar. Widgets are drag-droppable tiles. User can pick individual-store or multi-store view from a store switcher.
- **Key metrics shown (standard widgets):** Net Sales, Gross Sales, Orders, Sessions, CVR, AOV (shown as mean/median/mode, three separate values), Total Ad Spend, Blended ROAS, MER, Blended CPA, NC-ROAS (new customer ROAS), LTV (60d/90d), Profit, Refunds, COGS, Shipping costs. Channel tiles for Facebook / Google / TikTok / Snapchat each show spend + purchases + ROAS.
- **Data density:** High. Easily 15–25 metrics above the fold on the default layout.
- **Date range control:** Preset ranges (Today / Yesterday / Last 7 / Last 30 / MTD / QTD / YTD / Custom). Comparison period toggle. Stats refresh every ~15 minutes.
- **Interactions:** Every tile has drill-down; most tiles expose MoM / YoY deltas and a compact sparkline. Data-platform-level filters segment Shopify + ad data by country, channel, product, etc.
- **AI layer:** Moby chat sidebar is present on every dashboard — "ask a question about this data" with generated insights.
- **Screenshot refs:**
  - https://www.triplewhale.com (hero)
  - https://www.triplewhale.com/free (Founders Dash preview)
  - https://kb.triplewhale.com/en/articles/6127778-summary-dashboard-metrics-library

### Attribution page ("Pixel" section, with "Attribution All" table)
- **Layout:** Flat table of channels/campaigns/adsets/ads with swappable attribution model at the top. User customizes visible columns via a settings modal.
- **Attribution models exposed as a dropdown:** Triple Attribution (default MTA), Last Click, First Click, Linear, Last Platform Click, Total Impact (pixel + post-purchase survey blend), Clicks & Deterministic Views (beta).
- **Key columns:** Spend, Purchases, Revenue, ROAS, NC-Purchases, NC-ROAS, CPA, CPM, CPC, CTR. Split by attribution window (1d / 7d / 28d) where applicable.
- **Drill path:** Channel → Campaign → Adset → Ad. Breadcrumb at top; click any row to zoom.
- **Comparison:** Model Comparison view shows the same row under multiple attribution models side-by-side — the closest thing in the category to Nexstage's "disagreement" view, but less visually opinionated.
- **Pending data handling:** Shows "processing" state next to recent days while the pixel backfills.

### Creative Cockpit
- **Layout:** Gallery of creative "cards" with ad thumbnail/video preview on the left and metrics on the right. Toggleable to a flat table. Group-by dropdown: individual ad / naming convention / creative type / ad ID / segment.
- **Metrics per creative:** Spend, Impressions, CPM, CTR, CPC, Thumb-Stop Ratio (3s-view / impressions — their signature metric), Hold Rate, Purchases, Revenue, ROAS, AOV, CAC. With Triple Pixel enrichment overlaid.
- **Creative Highlights:** A curated "top creative by 10 metrics" summary module at the top — quick-look trophy cards.
- **Segments:** User-defined clusters (e.g., "UGC videos", "static product shots") that roll up aggregate performance.
- **Cross-platform:** Facebook, Google, TikTok, X aggregated into one creative surface.

### LTV / Customer Analytics
- **Cohort table** with rows = acquisition month, columns = LTV at 30/60/90/180/365 days, colored heatmap gradient.
- **Acquisition-source split:** Cohorts broken out by first-touch channel.
- **Time-between-orders histogram** for repeat customers.
- **"Golden path"** — the most common product sequence for repeat customers.

### Product Journeys / Cart Analysis
- Product bundling analysis (market-basket style) — which SKUs are bought together.
- Product-to-product journey graph for repeat buyers.
- Per-product attribution: spend attributed to driving a specific SKU's sales.

### Benchmarks / Trends
- **Layout:** Chart-per-metric comparing "your store" vs. "peer cohort median" over time. Line chart with two lines, shaded peer band.
- **Peer selection controls:** Industry dropdown, AOV bucket (<$100 / >$100), revenue bracket.
- **Metrics:** CPA, CPC, CPM, CVR, CTR, MER, ROAS, AOV.
- Published at https://app.triplewhale.com/trends (gated behind login).

### Moby AI / Willy chat
- Persistent chat sidebar or full-page "Agents library" view.
- Natural-language queries run against the warehouse; returns tables and generated charts inline.
- Moby Agents: saved automations (anomaly detection, daily digest, budget reallocation suggestions) with configurable thresholds and delivery (Slack, email).
- Lighthouse = anomaly feed module that surfaces "ROAS dropped 30% on this campaign vs. 7d avg"-style alerts.

### Live Orders / Activity Feed
- Chronological real-time feed of new orders with each order's attributed channel/campaign/ad.
- Runs as a ticker-style widget; useful for promo-launch war rooms.

## Attribution model
Seven models exposed:
1. **Triple Attribution (default MTA)** — Triple Pixel-driven multi-touch, linear-ish distribution across touchpoints.
2. **First Click**
3. **Last Click**
4. **Linear** (equal credit across touches)
5. **Last Platform Click** (mimics platform reporting)
6. **Total Impact** — blends pixel data + post-purchase survey responses via ML. Their flagship/differentiated model.
7. **Clicks & Deterministic Views (beta)** — uses platform-verified view-through data, not modeled.

The philosophy is **intentionally permissive**: each channel gets credit under its own model, so Meta + Google + TikTok ROAS can each show "full credit" for the same conversion. Sum-to-100% is explicitly not enforced. Post-purchase surveys ("How did you hear about us?") are the closest thing to ground truth and feed the Total Impact model.

Triple Pixel is server-side, deployed via Shopify app install; claims to resolve identity across devices/sessions and mitigate iOS 14 signal loss.

## Integrations (ad platforms + stores)
- **Stores:** Shopify only (strict).
- **Ad platforms:** Facebook/Instagram, Google Ads, TikTok, Snapchat, Pinterest, X/Twitter, Microsoft/Bing, Amazon Ads, YouTube.
- **Email/SMS:** Klaviyo, Postscript, Attentive, Omnisend.
- **Other:** Slack, Google Sheets, Zapier, Triple Whale warehouse sync (Enterprise+), 60+ total claimed.
- Shopify subscription apps (Recharge etc.) for recurring-revenue tracking.

## Notable UI patterns worth stealing
- **Blended ROAS + MER + platform ROAS shown side-by-side** — three numbers, three labels, clear hierarchy. Our six-source `MetricCard` should be the spiritual successor of this.
- **Three AOV values (mean/median/mode) displayed simultaneously.** Tiny UX detail that acknowledges the average is misleading. Good pattern for any distribution-skewed metric.
- **"Creative Highlights" trophy module** — top creative per metric, surfaced above the full table. Low cognitive load, drives drill-down.
- **Attribution model dropdown at the table header**, not buried in settings. Switching models is a 1-click action, which reinforces that multiple models exist.
- **Thumb-Stop Ratio as a first-class creative metric** — not a raw "3s views" but a ratio. Good precedent for computed-on-the-fly metrics being promoted to card status.
- **Peer-cohort benchmark shading** on trend charts (your line + shaded peer band) — cleaner than a single comparison line.
- **Pods View** — multi-store roll-up for agency users. Agencies are a big segment; a read-only multi-workspace view is table-stakes for that persona.
- **Live Orders feed** as a widget you can park on the dashboard — visceral, sales-floor energy.
- **Post-purchase survey → attribution model pipe.** Surveys are a DTC cliché; feeding them into an attribution model is not.
- **15-minute refresh cadence** as a marketing message ("near real-time") — avoids the "is this live?" question entirely.

## What to avoid
- **Don't let channel totals sum to more than 100% without calling it out.** Users hate the "my totals don't reconcile" moment. Nexstage's "Not Tracked" concept directly addresses this; don't regress.
- **Don't paywall 80% of the app.** User resentment is proportional to the ratio of locked icons to usable features. Either make a tier genuinely useful or don't ship it.
- **Don't ship a mobile app that's a web-view wrapper.** If the native app is worse than the desktop site it damages trust across the entire brand.
- **Don't bury attribution-model switching.** It should be a top-level control on every attribution surface.
- **Don't over-index on AI chat as a replacement for good surfaces.** Users prefer purpose-built pages for common questions; chat is for the long-tail.
- **Don't require SQL to build a useful custom metric.** The no-code custom metric builder is a table-stakes answer; SQL editor is a power-user escape hatch.
- **Don't let the Summary grow to 25+ widgets by default.** Density at that level overwhelms SMBs — make the default layout ~8–10 widgets with easy add-widget flow.
- **Don't ship a UI with visible layering/dropdown bugs.** Multiple reviews specifically call out z-index problems; this is a trust-killer.

## Sources
- https://www.triplewhale.com
- https://www.triplewhale.com/pricing
- https://www.triplewhale.com/attribution
- https://www.triplewhale.com/creative-cockpit
- https://www.triplewhale.com/pixel
- https://www.triplewhale.com/moby-ai
- https://www.triplewhale.com/blog/new-look
- https://www.triplewhale.com/blog/creative-cockpit
- https://www.triplewhale.com/blog/trends-benchmarking
- https://kb.triplewhale.com/en/articles/6127778-summary-dashboard-metrics-library
- https://kb.triplewhale.com/en/articles/12117524-a-guide-to-triple-whale-s-navigation
- https://kb.triplewhale.com/en/articles/6476726-benchmarks-dashboard
- https://kb.triplewhale.com/en/articles/7128379-the-total-impact-attribution-model
- https://kb.triplewhale.com/en/articles/6362638-analyze-creative-performance-with-creative-cockpit
- https://kb.triplewhale.com/en/articles/5960333-understanding-and-utilizing-attribution-models
- https://www.g2.com/products/triple-whale/reviews
- https://www.trustradius.com/products/triple-whale/reviews
- https://apps.shopify.com/triplewhale-1/reviews (3.9/5, 87 reviews)
- https://www.upcounting.com/blog/triplewhale-review (agency perspective)
- https://tripleareview.com/triple-whale-review/
- https://www.headwestguide.com/triple-whale-vs-northbeam
- https://www.smbguide.com/northbeam-vs-triple-whale/
- https://www.putler.com/triple-whale-review/
- https://www.youtube.com/watch?v=4vWp6TfnXh0 ("UPDATED 2025: How To Use Triple Whale (Tutorial/Walkthrough)")
- https://www.youtube.com/watch?v=9VbEuXoh3wI ("Triple Whale Creative Dashboard Tutorial")
