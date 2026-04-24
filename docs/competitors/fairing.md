# Fairing (formerly Enquire)

**URL:** https://fairing.co / https://apps.shopify.com/fairing
**Target customer:** Shopify / Shopify Plus DTC brands doing $500k-$100M+ (they claim 2,000+ Shopify Plus brands). Marketing teams who feel their Meta/Google pixel attribution is lying to them after iOS 14 / Consent Mode.
**Pricing (Shopify App Store, 2025-2026):**
- Free: up to 100 responses/mo
- Standard: $15/mo — 200 responses
- Professional: $49/mo — 500 responses
- Enterprise: $149/mo — 5,000 responses
- Direct-sale plans on fairing.co scale well beyond this; one review reported a long-term customer being moved from ~$49 to ~$680/mo (347% increase) then double that in year two.
- 14-day trial

**Positioning one-liner:** "In-moment attribution surveys, purpose-built to improve your marketing measurement" — the category-defining "How did you hear about us?" post-purchase survey tool. They bill themselves as the fix for pixel-based attribution by asking customers directly.

## What they do well
- **Category-leading response rates.** They claim 40-80% response rates on post-purchase surveys; the high end is genuinely above anything platform attribution can match.
- **Intelligent branching / Question Stream.** Answer "Podcast" → next question asks *which* podcast. Answer "Influencer" → asks *who*. The follow-up is what makes the data actionable beyond "Social (47%)".
- **One-click Shopify thank-you page integration.** Zero engineering. Survey renders on order status page natively — no redirect, no iframe.
- **AI response classification.** Free-text answers get auto-classified into channels/vendors, keeping tag taxonomies clean at volume.
- **LTV by acquisition-channel analysis.** Joins survey response to 90/180/365-day LTV — lets you see that "TikTok-self-reported" customers have 2x LTV of "Meta-self-reported" even when CAC looks identical.
- **Integration to BI/data tools.** Snowflake, BigQuery, Segment, GA4, Klaviyo, Meta CAPI, Triple Whale, Elevar, Daasity all consume the survey data.
- **5.0 stars on Shopify App Store** (193+ reviews, 98% five-star). "Built for Shopify" badge.

## Weaknesses / common complaints
- **Pricing volatility.** Most-cited complaint in reviews: one merchant was told they had to pay 347% more in year one and 1,381% more in year two, with access cut off shortly after being charged. Fairing acknowledged the transition was mishandled.
- **Only surveys the buyers.** Non-converters are invisible — so the attribution picture skews toward what drove the *purchase*, not what drove the *consideration*.
- **Response bias.** Influencer-driven customers overselect "Instagram"; older customers default to "Google". The data is directionally useful but never exact.
- **The "aha moment" requires volume.** Stores doing <100 orders/week get thin sample sizes per channel, and weekly charts look jagged.
- **No ad-spend layer.** Fairing tells you what channel is sending customers, but not the cost — you still need Triple Whale / Northbeam / a warehouse to compute CAC on survey-based attribution.
- **UI is intentionally narrow.** It's a survey tool with attribution reporting bolted on — not a full marketing dashboard. Users drift to Triple Whale or Daasity when they want a single pane.

## Key screens

### Summary / Dashboard
- **Layout:** Top strip: response count, response rate %, active survey. Main card: horizontal bar chart of top channels by share of respondents ("Facebook, Instagram, Google, Snapchat, TikTok, Pinterest, Other"). Secondary panel: trend line of response volume over time.
- **Key metrics shown:** Response count, response rate (responses/orders), top channels by % share, "Other" bucket clickthrough to Pixel attribution dashboard for reconciliation.
- **Data density:** Deliberately low — this is a dashboard a CMO can read in 10 seconds.
- **Date range control:** Global picker (7d, 30d, 90d, custom).
- **Interactions:** Click any channel bar to drill to the individual responses; click "Other" to see free-text answers queued for classification.
- **Screenshot refs:** Shopify App Store listing carousel — https://apps.shopify.com/fairing

### Attribution Deep Dive
- **Layout:** Two-column compare view. Left: **survey-based attribution** (what customers say). Right: **last-click / UTM attribution** (what the pixel says). Same channel rows, different % share. A delta column in the middle.
- **Key metrics shown:** Survey %, Last-click %, delta (over/under reported), order count, revenue attributed under each model.
- **Interactions:** Click a delta to pivot into orders where the two disagree (e.g. pixel says "Direct" but survey says "Podcast"). Segment by new vs. returning customer.
- **This is the single screen most worth studying.** It's the UI embodiment of "platforms disagree with customers" — the same trust thesis Nexstage is built on, just at a single-source level.

### LTV Analytics
- **Layout:** Cohort-style table. Rows = channels from survey responses. Columns = 30/60/90/180/365-day LTV and repeat rate.
- **Key metrics shown:** Cohort size, avg LTV at each horizon, repeat order rate, AOV by channel.
- **Interactions:** Toggle by first-purchase date cohort; filter by product purchased.

### Question Stream / Survey Builder
- **Layout:** Drag-and-drop question list on the left, live preview of the thank-you-page survey on the right. Branch logic rendered as an indented tree.
- **Interactions:** Add question → pick type (single-select, multi-select, free-text, NPS) → add branching ("If answer = Podcast, show question X") → publish. Multi-language toggle. Conditional logic on customer tags / first-purchase status.

### Responses (raw)
- **Layout:** Infinite-scroll list of individual responses with customer name, order ID, question, answer, classified channel, timestamp. AI-classification chip next to each response with confidence indicator.
- **Interactions:** Bulk re-categorize, override classification, export CSV, sync to Klaviyo/Meta.

## The "angle" — what makes them different
Fairing's thesis is narrow and sharp: **pixel attribution is a corrupted signal, and the cheapest honest measurement is to ask the customer on the thank-you page.** They don't try to own the whole analytics stack — they own the zero-party-data moment. Everything else (LTV, AI classification, integrations) is downstream of the survey response.

The killer UI pattern is the **side-by-side compare with last-click attribution** — they visually prove their value by showing you the disagreement. It's the only analytics product that leads with "here is where we disagree with Meta, and the customer sided with us." Nexstage's six-source badge system (Store / Facebook / Google / GSC / Site / Real) is the same idea at much broader scope.

## Integrations
- **Marketing:** Meta (CAPI), Google Ads, TikTok, Snapchat, Pinterest
- **Email/SMS:** Klaviyo, Attentive, Postscript
- **Analytics/BI:** GA4, Segment, Triple Whale, Northbeam, Elevar, Daasity, Podscribe
- **Warehouses:** Snowflake, BigQuery
- **Productivity:** Google Sheets
- **Shopify:** Orders enrichment (writes attribution back to order metafields)

## Notable UI patterns worth stealing
- **Side-by-side "what survey says vs what pixel says" view with a delta column.** This is the gold. Nexstage should consider the same pattern per channel (Store says X, Meta says Y, Real says Z).
- **Horizontal bar chart of channel share as the home screen.** One chart, brutally simple. The CMO test passes.
- **Branching surveys where the follow-up is the actual value.** Generic "Social" is useless; "Instagram → @creator_x" is a purchase order for more influencer spend.
- **AI classification with a confidence chip and override affordance.** Users trust the AI *because* they can correct it cheaply.
- **"Other" bucket is clickable.** Dark data surfaced instead of hidden.
- **LTV table with channel on the row axis** — answers "is this channel sending junk customers?" in one view.
- **Response-rate % as a headline metric next to response count.** Context prevents misreading volume.

## What to avoid
- **Opaque direct-sales pricing.** Customers discovering a 10x price hike at renewal tanks NPS. Publish the ladder or keep it to 4 public tiers.
- **Letting survey bias be the whole story.** Fairing is great, but without pairing it with ad-spend / cost-per-order data, "TikTok has the most responses" can still be a false positive. Always show alongside platform-reported attribution, never instead of.
- **Low-volume store dead zone.** Stores under 100 orders/week see noisy data. Either show directional chips ("sample too small") or aggregate to broader time windows.
- **Responses queue as the second-class citizen.** Because classification needs human oversight, the inbox UX should be first-class — Fairing's is fine but not great.
- **Attribution-only framing.** Fairing keeps drifting into NPS and CRO surveys because pure attribution hits a ceiling. If you build survey-based attribution in Nexstage, pair it with something else from day one.

## Sources
- https://fairing.co/
- https://apps.shopify.com/fairing
- https://apps.shopify.com/fairing/reviews
- https://fairing.co/products/attribution-surveys
- https://fairing.co/blog/attribution-surveys-measure-what-dashboards-miss
- https://fairing.co/blog/the-complete-guide-to-attribution-surveys
- https://www.g2.com/products/fairing/reviews
- https://www.1800d2c.com/tool/fairing
- https://help.daasity.com/advanced/marketing-attribution/survey-based-attribution.md
- https://podscribe.com/blog/announcing-fairing-integration
