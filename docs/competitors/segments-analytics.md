# Segments Analytics (by Tresl)

**URL:** https://www.tresl.co (product) / https://apps.shopify.com/segments (Shopify App Store)
**Target customer:** Shopify DTC brands, pre-$1M up to $10M+ annual revenue, marketing / CRM / lifecycle owners who need customer segmentation but do not have a data analyst on staff
**Pricing:**
- Starter: Free (FilterGPT segment builder + Shopify Flow automation only)
- Core: $199/mo (was $79 on older tiers — pricing rose) — 50k audience syncs, 2-year lookback, unlimited AI segments, 50+ prebuilt segments
- Pro: $479/mo — 150k syncs, unlimited lookback, product journey analysis, custom AI reports, 2 onboarding calls
- Enterprise: $1,549/mo+ (custom) — unlimited syncs, dashboards built by their data scientists, dedicated Slack, monthly reviews
- 14-day free trial on paid tiers

**Positioning one-liner:** "Maximize LTV with shopper insights and AI RFM segmentation" — built by ex-LinkedIn data scientists; the "data scientist in a box" for Shopify merchants who want Klaviyo-quality segments without building them by hand.

## What they do well
- **RFM segmentation is the default, not a feature.** On install they auto-generate 9 lifecycle segments (Active Loyals, At Risk Repeats, Churned High Value, New Customers, etc) with CLV, AOV, and frequency already computed per segment.
- **FilterGPT** — a natural-language segment builder. You type "Find first-time customers from May 2023" and it generates the filter set. Supports non-English prompts (they demo Spanish).
- **Bi-directional Shopify sync** — segments push back to Shopify as customer tags, so Flow automations and Shopify Email can target them without re-building the logic.
- **Audience sync to ad platforms** — Meta, Google Ads, TikTok, Postscript, Klaviyo. Segments are the activation layer, not just a reporting layer.
- **Perfect 5.0 on Shopify App Store (58 reviews, 100% five-star)** and a 5.0 on the newer listing (193+ reviews). Support is their moat — custom solutions for enterprise accounts get called out in nearly every review.
- **Product journey / affinity analysis** (Pro tier) — shows what customers buy in what order and at what interval, which feeds into welcome-series and cross-sell timing.

## Weaknesses / common complaints
- **Pricing climbed aggressively.** The Core tier was $79/mo historically and is now $199/mo — small brands who benchmarked the old price feel squeezed.
- **Customer segmentation only.** No ad-spend view, no GSC, no attribution across platforms. They own one slice.
- **Shopify-only.** No WooCommerce integration.
- **Data sync caps matter.** On Core, only 50k customers sync to destinations — growing brands hit the wall and upsell to Pro ($479).
- **"Results in 48 hours"** — initial data processing isn't instant, which makes demo day underwhelming.
- **Natural language is still a thin veneer.** FilterGPT is great for "new customers from May" but complex multi-condition segments still require the traditional filter UI underneath.

## Key screens

### Dashboard / home (Segments Overview)
- **Layout:** Tile grid of pre-built segments. Each tile is one lifecycle segment (e.g. "Active Loyals") with customer count, % of base, AOV, CLV (they call it ACLV), and growth-percentage chip.
- **Key metrics shown:** Customer count per segment, CLV/ACLV, AOV, frequency, percent growth vs prior period.
- **Data density:** Medium — roughly 9-12 segment tiles on the landing view plus a KPI strip at the top.
- **Date range control:** Global date range picker; analysis range depends on plan (2 years Core, unlimited Pro).
- **Interactions:** Click any segment tile to drill into the customer list; from the customer list you sync to ad platform / Klaviyo / Shopify tags with a single action.
- **Screenshot refs:** Seven screenshots on the Shopify App Store listing — AI capabilities, retargeting, FilterGPT natural language interface, product intelligence. URL: https://apps.shopify.com/segments

### Segment Builder (FilterGPT)
- **Layout:** Split UI — natural language prompt box at top, filter chips rendered below after parsing, preview of matching customers on the right.
- **Key metrics shown:** Matching customer count updates live; preview table shows name, last order date, total spend.
- **Interactions:** Type prompt in any language → AI generates filter chips → user can edit/add filters manually → save segment → pick activation destinations (Shopify tag, Klaviyo list, Meta audience, etc).
- **Segment criteria surfaced:** Customer tenure (first-time / returning), purchase timing (date ranges), product purchased, total spend, order frequency, last-order recency, geography, tag, UTM, channel. RFM buckets are pre-computed.
- **Screenshot refs:** GIF demos on the FilterGPT launch post — https://www.tresl.co/blog/introducing-filtergpt-ai-driven-customer-segmentation

### Customer / Shopper Insights (Pro tier)
- **Layout:** Cohort-style analysis with horizontal band charts showing time-to-second-purchase, product repurchase intervals, and category affinity.
- **Key metrics shown:** Days between 1st and 2nd purchase (median, P25/P75), next-best-product probability per segment, category affinity matrix.
- **Data density:** High — intentionally dense because this tier targets analysts.
- **Interactions:** Hover for exact day counts; click a product to see which segments repurchase it and on what cadence.

### Reports / ReportGPT
- **Layout:** Chat-style prompt + generated chart. User asks a question, the LLM returns a visualization.
- **Interactions:** Prompt a question → receive chart + summary text → can export to Google Sheets or schedule via Shopify Flow.

## The "angle" — what makes them different
Segments is the **"no data scientist required"** thesis for customer LTV work. They don't try to own the whole dashboard — they own the customer object. RFM and lifecycle segmentation are *the* primary view, not a nested report. Then they close the loop by pushing those segments into the tools merchants already pay for (Klaviyo, Meta, Shopify tags). The natural-language layer (FilterGPT / ReportGPT) is a bet that marketing managers will build their own segments if the interface stops pretending they can write SQL. The per-segment CLV number is the headline metric the whole product orbits around.

## Integrations
- **Ad platforms:** Meta, Google Ads, TikTok
- **Email/SMS:** Klaviyo, Postscript, Attentive (via native sync)
- **Commerce:** Shopify (write-back as customer tags), Shopify Flow, Shopify Checkout
- **Export:** Google Sheets
- 20+ total destinations per marketing page

## Notable UI patterns worth stealing
- **Segments-as-tiles on the landing page** — each tile already has the key metric pre-computed. No empty-state "build your first segment" wall.
- **Growth-percentage chips next to every count.** Small detail, big signal density.
- **Natural-language prompt that renders as filter chips** — you can still edit mechanically after the AI parses. Best of both worlds.
- **One-click activation from a customer list** — "sync to Klaviyo / Meta / Shopify tag" lives as a button on the segment view, not in a separate export flow.
- **Pre-computed CLV per segment** — shown as a first-class column, not hidden behind a toggle.
- **Lifecycle naming is opinionated** ("At Risk Repeats" vs "Customers whose last purchase was 60-90 days ago") — the label does the interpretive work.

## What to avoid
- **Over-reliance on AI chat.** ReportGPT is flashy but users revert to the filter UI for anything non-trivial. The chat shouldn't be the *only* path in.
- **Data-sync caps as a pricing lever.** Customers feel penalized for growth (50k → 150k → unlimited is a big upsell jump at $199 → $479).
- **48-hour initial processing.** Demo loses momentum. Nexstage should show synthetic-but-realistic sample data during backfill.
- **Shopify-tag write-back is one-way friction** — if a segment definition changes, untagging is messy. Don't build the same trap.
- **Too many lifecycle segments out of the box (9+).** Review confusion about overlap is common. Start with 4-5 that clearly don't overlap.

## Sources
- https://apps.shopify.com/segments
- https://www.tresl.co/
- https://www.tresl.co/pricing
- https://www.tresl.co/blog/introducing-filtergpt-ai-driven-customer-segmentation
- https://www.digismoothie.com/app/segments
- https://skywork.ai/skypage/en/Unlocking-AI-Potential-A-Deep-Dive-into-Tresl-Segments-Analytics/1976830998380343296
- https://www.putler.com/shopify-customer-segments/
