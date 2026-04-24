# Pricing page UX — cross-competitor deep dive

Cross-cut of 13 ecommerce analytics / adjacent pricing pages, focused on what Nexstage should copy and what to avoid. Profiles in `/home/uros/projects/nexstage/docs/competitors/*.md` are the baseline; this doc lives above them.

## Structure patterns we see across tools

- **3-tier is the default shape, 4-tier is common, 5+ is ad-spend or GMV segmentation dressed as "tiers".** Triple Whale, Peel, Lifetimely, Atria all land on 3–4 visible tiers with a sales-gated "Custom" at the top.
- **GMV/order-volume slider is the dominant pricing driver for analytics tools.** Triple Whale, Putler, Peel, Metorik, Lifetimely, RoasMonster all put a slider above the fold. Ad-spend slider is the equivalent for ad-centric tools (Northbeam, Motion, Atria). Seat-based pricing is conspicuously absent — most tools include unlimited users on every tier.
- **Revenue-share is never exposed on a pricing page.** Zero tools in this set price on a % of revenue line item. Everything is either flat tiers or metered by volume. This is a gap Nexstage can actually own.
- **"Starting at $X/mo" is a near-universal framing** to anchor low. The real price lives in a slider or a tier-selector one interaction away.
- **Monthly/annual toggle = "save 2 months"** (Triple Whale, Atria explicitly; most others imply it). Discount is usually 15–20%.
- **Above-the-fold CTA is "Book a demo" once a tool crosses ~$500/mo.** Self-serve is table stakes below ~$300; above that, a sales gate is the norm.
- **Feature comparison matrix is the second viewport.** Typically 50–80 rows, grouped into 4–6 categories. Triple Whale's is 75+ rows; Atria's 79 rows; Peel uses checkmarks vs "N/A" for a subtle "you are missing out" signal.
- **FAQs live at page bottom** and almost always answer the same 6 questions: trial mechanics, cancellation, plan switches, which plan fits me, what drives the price, what's included on free.
- **Social proof clusters in three zones:** logo strip mid-page, testimonials between tiers and FAQ, G2/Shopify badges near footer.

## Tool-by-tool breakdown

### Triple Whale
- **URL:** https://www.triplewhale.com/pricing
- **Tiers:** Free $0 / Starter from $179/mo / Advanced from $259/mo / Custom $539+/mo for $20M+ GMV. GMV slider 0→$30M+ drives real price; $6M GMV brand actually pays ~$1,129/mo.
- **Above the fold:** Headline "Faster, more profitable growth starts here." Interactive GMV slider auto-recommends a tier. 3–4 tier cards visible. CTAs are "Get started" / "Talk to sales" / "Book demo" depending on tier.
- **Social proof placement:** Four unnamed enterprise logos mid-page under "Trusted by brands $20M–$1B+". No testimonials on the pricing page itself — pushed to homepage.
- **Friction signals:** "Talk to Sales" on Advanced and Custom. Custom tier has no self-serve path. No explicit free-trial duration — free plan is indefinite but deliberately crippled. Annual = 2 months free. Asterisks disclose "prices based on 12-month commitment".
- **Clever bits:** GMV slider with real-time tier recommendation is the best pattern on the page — it converts "which tier am I?" into one drag. 75+ row feature matrix with category grouping (Core Platform / Integrations / AI / Measurement / BI). Moby AI is shown as "Add-On" in the matrix — transparent about the second paywall.
- **Anti-patterns:** Four tiers on the main view but a parallel "Annual Commitment Plans" section adds four *more* tiers (Growth, Pro, Premium, Premium+, Enterprise+) — double the decision load. Add-on prices are displayed as "$X.XX/month" with the number redacted. "Weekly Product Training Sessions" listed redundantly across rows to inflate tier differentiation. Users report hitting upgrade walls constantly post-signup (Moby credits are a second paywall on top of the plan).

### Northbeam
- **URL:** https://www.northbeam.io/pricing
- **Tiers:** Starter from $1,500/mo (brands <$1.5M annual ad spend) / Professional custom (>$250k/mo spend) / Enterprise custom (>$500k/mo spend). Price driven by ad-spend tier and data processing volume.
- **Above the fold:** Three tier cards simultaneously visible. Subhead "Based on your annual marketing spend, choose the option that's best for you." Primary CTA is `Book a demo` on every tier — zero self-serve.
- **Social proof placement:** "Trusted by 800+ companies" mid-page. Result stats ("37% increase in ROAS") from enterprise case studies inline with the tiers.
- **Friction signals:** Starter is month-to-month but still demo-gated; Pro/Enterprise are annual only and fully custom. No free trial. Credit card never enters the flow — everything is contract-signed.
- **Clever bits:** Eligibility line on each tier ("Lower than $1.5M/yr in media spend", "Greater than $250k/mo") — a form of self-segmentation that reduces sales call waste. Nine-question FAQ addresses the obvious objections (why is this expensive? how is data volume calculated?).
- **Anti-patterns:** $1,500/mo minimum with zero self-serve is an aggressive sales gate; a small brand touching this page bounces. No comparison table — bullet lists inside cards force users to scan side-by-side manually. Demo is the only action — there's literally no way to see the product without a call.

### Polar Analytics
- **URL:** https://www.polaranalytics.com/pricing
- **Tiers:** Core ("the essential data stack") and Custom ("a customizable product stack"). Prices hidden — "Select your annual GMV" selector gates the number. "Saves 20% on individual product costs" implies the Core plan is a bundle.
- **Above the fold:** "All plans include" framing leads — dedicated Snowflake DB, unlimited users, unlimited historical data, CSM with Slack channel. Value props before prices.
- **Social proof placement:** 4,000+ brands claimed; 30+ logos (Allbirds, Volcom, Joseph & Joseph) mid-page; multiple linked case studies.
- **Friction signals:** Both plans require "Book a demo". No free trial mentioned. Advertising Signals add-on lists "$ Contact us" instead of a number.
- **Clever bits:** Leading with "unlimited users, unlimited data, dedicated CSM" undercuts Triple Whale's paywall-heavy image — positions Polar as "more included, less nickel-and-diming".
- **Anti-patterns:** Price hidden behind demo — same sin as Northbeam but without the ad-spend threshold that justifies it. No FAQ. No comparison table. Custom plan is just "Core + add-ons you have to ask about".

### Lifetimely (Amp)
- **URL:** https://useamp.com/pricing (Lifetimely is one of four products on a shared tab)
- **Tiers:** Free ($0, 50 orders/mo) / M $149 (3k orders) / L $299 (7k) / **XL $499 (15k) — "Most Popular"** / XXL $749 (25k) / Unlimited $999. Amazon add-on +$75/mo. Clean order-volume ladder.
- **Above the fold:** "Simple pricing for every stage of growth". Tabbed product switcher (Lifetimely / Back in Stock / Slide Cart / Bundles) with install counts and star ratings baked into each tab. All tier cards visible.
- **Social proof placement:** "60,000+ installs", "★ 4.8" shown on the Lifetimely tab header — install count and rating as headline trust signals. "Wall of Love" footer link. "45,000+ brands finding hidden profit" in bottom CTA.
- **Friction signals:** No "Contact Sales" anywhere. "No credit card required" stated explicitly. 14-day free trial on paid tiers. Free plan is indefinite at 50 orders/mo.
- **Clever bits:** **Six tier cards visible at once is less painful than it sounds** because they're a monotonic volume ladder — you find your row in <5 seconds. Feature lists are identical across tiers — all differentiation is volume. Removes "which features do I need?" decision entirely. FAQ explicitly addresses "what if my volume fluctuates?" (2-month grace period) — this answers the #1 anxiety on any metered plan.
- **Anti-patterns:** Tabbed product switcher means the Lifetimely pricing is buried; you have to know you want Lifetimely. No yearly toggle visible.

### Peel
- **URL:** https://www.peelinsights.com/pricing
- **Tiers:** Core $179 annual / $199 mo (>6k orders) / Essentials $449/$499 (>16k) / **Accelerate $809/$899 (>29k) — "Popular"** / Tailored custom (>62k). Driver is monthly store orders.
- **Above the fold:** "Peel scales with your business needs". Interactive order-count input recommends a plan. Tier cards below.
- **Social proof placement:** Mid-page logos (Jones Road, Cora). Tagline "loved by Shopify Plus and Amazon businesses". G2 badges + 5-star Shopify reviews near bottom.
- **Friction signals:** 7-day free trial, no credit card. Tailored tier → Contact Us. All other tiers "Try now" self-serve.
- **Clever bits:** CSM credits are a real differentiator called out per tier (0 → 1 → 2 → 5/mo). Turns "human help" into a metered line item that scales with the tier. Checkmarks vs "N/A" (not greyed out) on the comparison matrix — sharper visual contrast than the usual lock icons.
- **Anti-patterns:** 7-day trial is short for an analytics tool; most stores won't have a month of data sync'd by then. Pricing targeted at Shopify Plus brands (6k orders/mo floor) means the SMB segment self-disqualifies on the first card.

### Metorik
- **URL:** https://metorik.com/pricing
- **Tiers:** **Single pricing model, order-volume slider 0→150k+.** Starts at $25/mo. All features unlocked on every plan. One subscription covers multiple stores.
- **Above the fold:** "Unlimited Users. Simple Pricing." 4.9★ from 150+ reviews inline with headline. Free 30-day trial CTA with "No credit card needed".
- **Social proof placement:** Customer logos (Universal Yums, La Marzocco, Sennheiser, Scratch, Tupperware) with one headline testimonial: "Value for money is off the charts" — Mike Halligan, Scratch Pet Food.
- **Friction signals:** Contact us for custom only; otherwise fully self-serve. 30-day trial is long. No CC required.
- **Clever bits:** **Horizontal slider with quick-select buttons (100, 500, 2k, 5k, 10k, 25k, 50k, 100k, 150k) is the cleanest slider UX in the category** — scrubbing is slow, jumping is fast. FAQ explicitly addresses "how is order count calculated?", "what happens if I exceed?" (automatic adjustment, no manual downgrade). Historical data cap = 120× monthly order volume — published as a rule, not a hidden limit.
- **Anti-patterns:** None serious. Starts at $25 anchors very low — some users may feel "too cheap to be trusted" for brands that want enterprise positioning.

### Putler
- **URL:** https://www.putler.com/pricing/
- **Tiers:** Revenue-based metered billing, 10 bands from "Up to $10K/mo revenue → $20/mo" through "$3M–$5M → $2,250/mo". Custom tier above. One driver: monthly revenue.
- **Above the fold:** "Looking for the pricing table?" self-deprecating headline. Primary CTA "Start my 14-day free trial". Interactive slider shows all 10 revenue bands.
- **Social proof placement:** Six named testimonials with business names (Fuzzy and Birch, SuperFastBusiness etc.). "94% got better control" stat. "Paid for itself in 10 minutes" pull-quote.
- **Friction signals:** 14-day trial, no credit card. Custom tier requires contact. Trial limited to previous 90 days of data — honest cap rather than silent limit.
- **Clever bits:** **"Cost of manual reporting" table shows $600–$1,500/mo in lost time at 3 hrs/mo** — converts the pricing decision into a savings calculation before the user sees the price. Revenue-based pricing in raw dollar bands (not percentage) is transparent and predictable.
- **Anti-patterns:** No yearly toggle. Revenue-band pricing can feel punitive as a store grows — passing $10k → $30k revenue = 2.5× price jump.

### Varos
- **URL:** https://www.varos.com/pricing
- **Tiers:** Freemium with a "Brands plan from $99/mo". Core benchmarks (Shopify, Meta, Google, TikTok) are free; paid unlocks real-time trends, more integrations, Agency features. **"Give data to get data"** — free access conditional on sharing anonymized data.
- **Above the fold:** Value prop leads with benchmark stats: 4,500+ companies, $4B+ tracked ad spend. Free CTA dominant.
- **Social proof placement:** Stats serve as social proof — the value of the tool *is* network density, so "4,500 companies" is literally the pitch.
- **Friction signals:** Data-contribution requirement is the hidden cost. No CC for free. Paid plan is self-serve at $99.
- **Clever bits:** **Free plan is a genuine marketing channel, not a paywall teaser** — you get real benchmarks. The product's data network grows with every free signup, so free is a growth loop not a loss leader. Nexstage can't copy this model (we're not a benchmarks tool) but the principle (free tier that makes the paid product better) is worth noting.
- **Anti-patterns:** "FREE (for now)" signaling in marketing copy implies the freemium won't last — undercuts trust.

### Motion
- **URL:** https://motionapp.com/pricing
- **Tiers:** Starter $250/mo (up to $50k ad spend) / Pro custom ($50k+) / Growth custom ($250k+). Core features (AI tags, analytics, unlimited seats/accounts) identical across tiers — upper tiers unlock integrations, CSM, dedicated support.
- **Above the fold:** "Plans that scale with your creative needs". Three cards. CTAs are "Get started" (Starter) and "Book a demo" (higher).
- **Social proof placement:** Six agency/brand logos (VaynerCommerce, Huel, Jones Road, Wpromote, Caraway, Foxwell Digital). Three testimonials with headshots and titles.
- **Friction signals:** Pro/Growth require "Let's chat". Separate "Motion AI Studio" requires its own demo — second product sale.
- **Clever bits:** **Feature parity across tiers reduces buyer confusion** — all differentiation is spend-volume, not capability. "Not sure where to start?" CTA offers guided selection — acknowledges slider/tier choice is hard.
- **Anti-patterns:** No FAQ on pricing page. No comparison table — bullet lists only. $250 floor is high for an SMB analytics tool.

### Atria
- **URL:** https://www.tryatria.com/pricing
- **Tiers:** Core $129/mo annual ($159 monthly) / **Plus $269/mo annual ($329 monthly) — "Most Popular"** / Business custom / Enterprise custom. Drivers: ad spend cap, seats, AI credits, storage, brand slots.
- **Above the fold:** "Time is money. Save both with Atria." ROI framing. Monthly/annual toggle with "Save 20%". Core plan with 7-day free trial CTA immediately visible.
- **Social proof placement:** Six testimonials with company (Ipsy, Inspire Brands Group, SelfMade, Performance Purple, Blenders Eyewear, Rubix). Winning quote: "I almost never logged into Motion. Now I'm in Atria daily" — direct anti-Motion pitch.
- **Friction signals:** Business/Enterprise sales-gated. 7-day trial is short. "Soon" tags on unshipped features are honest but create FOMO.
- **Clever bits:** **ROI Calculator below the tiers** takes hours-saved × hourly rate and outputs monthly savings — personalised value story under the price. 79-row feature matrix across six categories (Access / Creation / Analytics / Assets / Research / Inspiration / Brand Profiles) — exhaustive. Multi-axis pricing drivers (ad spend + seats + credits + storage) so bigger users pay for the dimension that actually scales on them.
- **Anti-patterns:** Four pricing drivers at once means the "which tier am I?" question is harder, not easier. Competes with Motion visually by putting Motion in testimonial quotes — strong but could age badly.

### RoasMonster
- **URL:** https://roasmonster.com/pricing
- **Tiers:** **One plan, full feature list.** Interactive calculator with two variables: number of shops + monthly ad spend. Custom above $1M/mo spend.
- **Above the fold:** "Calculate your fee" with sliders. Value prop "One plan, full feature list". Free demo CTA in header.
- **Social proof placement:** Case studies in nav; inline testimonials implied but not detailed.
- **Friction signals:** Free trial requires scheduling an intro call (not instant). Annual discounts require email inquiry. No CC for trial.
- **Clever bits:** Two-variable slider (shops + spend) is a cleaner mental model than GMV alone — matches how agencies actually think about pricing. Single plan eliminates tier paralysis.
- **Anti-patterns:** "Schedule an intro call to start trial" is a hard friction gate — most SaaS users expect instant signup.

### Klaviyo (adjacent — email + analytics, not direct competitor but benchmark for ecommerce SMB onboarding)
- **URL:** https://www.klaviyo.com/pricing
- **Tiers:** Free (250 profiles, 500 emails/mo, 150 SMS credits) / Marketing (price hidden, sliding scale) / Data + Analytics (hidden) / Service (30% off promo) / Professional Services custom / Enterprise custom. Drivers: active profiles, emails sent, mobile message credits.
- **Above the fold:** "Start with a free plan". "Start free" + "Build a plan" as dual CTAs. All tiers visible in the nav. Limits explicit: "Up to 250 profiles, 500 emails/month."
- **Social proof placement:** None on pricing page — unusual. Klaviyo relies on brand awareness.
- **Friction signals:** No CC for free plan. No free trial — free tier is permanent. Enterprise tier has no visible contact button but exists.
- **Clever bits:** **"Build a plan" is a separate flow** — acknowledges pricing for metered products can't fit in a card. "Once you exceed either threshold, your ability to send may be restricted" — blunt honesty about what happens at the cap.
- **Anti-patterns:** Paid tier prices completely hidden — you must use the calculator. No side-by-side tier comparison. "30% off" discount flag on Service tier is an urgency pattern that erodes trust if it's always visible.

### Shopify native analytics (adjacent — the zero-price baseline we compete against)
- **URL:** Bundled with every Shopify plan (Basic $39 / Shopify $105 / Advanced $399 / Plus $2,300+)
- **Tiers:** Analytics depth scales with Shopify plan tier. No separate analytics SKU.
- **Above the fold:** N/A — not a pricing page per se.
- **Friction signals:** Advanced reports gated to $105+ plan. Custom reports gated to Advanced ($399). Not marketed as "analytics pricing" — merchants don't realise they're paying a plan premium for analytics.
- **Clever bits:** Baking analytics into the platform plan means there's no separate buy decision — every Shopify merchant gets something. This is the "good enough" competitive floor every third-party tool must beat.
- **Anti-patterns:** Shopify reports can't integrate ad platform data, which is exactly the opening every tool in this list exploits.

## Patterns to steal for Nexstage

1. **Slider/calculator above the fold.** Metorik's order-volume slider with quick-select buttons (100, 500, 2k, 5k, 10k, 25k) is the cleanest in the category. We should run a similar slider but anchor it on monthly store revenue — that matches our 0.4% revenue-share framing exactly.
2. **Lead with "what's included on every plan".** Polar's "dedicated Snowflake DB, unlimited users, unlimited historical data" header reframes the pricing conversation. Ours can be "unlimited users, unlimited history, every integration, 15-minute refresh — on every plan".
3. **"Most popular" badge on the middle tier** (every tool uses this). If we land on 3 tiers, mark the middle one.
4. **Cost-justification table.** Putler's "$600–$1,500/mo in lost time from manual reporting" pre-empts the price objection. We can do "3 dashboards × $200/mo each vs one Nexstage".
5. **FAQ at the bottom with the six standard questions** — trial mechanics, cancellation, plan switches, which plan, what drives the price, what's included on free.
6. **"No credit card required" stated twice** — once near trial CTA, once in FAQ. Metorik and Lifetimely both do this; it measurably reduces trial friction.
7. **Feature comparison matrix sorted by category** (Store / Ads / Search / Site / AI / Team), checkmarks vs N/A — Peel's pattern. Keep it under 50 rows.
8. **Revenue-share disclosed on the pricing page, not in the ToS.** Zero competitors do transparent revenue-share pricing — this is the pattern we can invent. Single price (39€/mo + 0.4% of store revenue processed through Nexstage) with a slider that shows "at €50k MRR you pay €39 + €200 = €239".
9. **Yearly = 2 months free** (near universal; we should match it).
10. **Self-segment the visitor.** Northbeam's "lower than $1.5M/yr" eligibility line is surprisingly effective at avoiding sales-call waste. We should add "fits stores doing €10k–€5M monthly revenue; over that → talk to us" prominently.

## Anti-patterns to avoid

1. **Never hide pricing behind demo** (Northbeam, Polar). An SMB bounces in 8 seconds; we can't afford a sales-call gate at €39/mo.
2. **Never publish add-on prices as "$X.XX redacted"** (Triple Whale). Either it's priced or it's not.
3. **Never publish parallel annual/monthly tier tables** that double the visible tier count (Triple Whale's free tier + Starter + Advanced + Custom + the separate Growth / Pro / Premium / Premium+ / Enterprise+ annual-commitment ladder). Decision load kills conversion.
4. **Never gate a trial behind a sales call** (RoasMonster). If the trial isn't instant, it's not a trial.
5. **Never make the trial shorter than it takes to sync data** (Peel's 7 days is too short when historical sync runs 30 min → 24 hours and ad platforms need 7 days to show meaningful data). Nexstage trial must be ≥14 days.
6. **Never stack a second paywall inside the plan** (Triple Whale's Moby AI credits on top of the plan price). If AI is included, it's included; if it's metered, be transparent.
7. **Never price features users can't observe.** Lifetimely includes identical features on every volume tier — only the cap differs. This is easier to communicate than Triple Whale's 75-row matrix where features appear and disappear across tiers.
8. **Never require credit card for trial** — universal; only RoasMonster breaks this and it shows in their conversion framing.
9. **Never write tier names that are meaningless** ("Advanced", "Plus", "Pro"). Prefer descriptive ("Starter" / "Growth" / "Scale") or just a size letter (M/L/XL like Lifetimely — surprisingly readable).
10. **Never make the "most popular" tier be the top non-custom tier.** That's a tell that you want the user to pay more. Mark the middle tier.

## Our positioning

At 39€/mo + 0.4% revenue share, we land in a **gap nobody is occupying**. Triple Whale starts at $179 with GMV-scaled upgrades hitting $1,100 by $6M GMV; Peel starts at $199 with a 6k-orders/mo floor (Shopify Plus segment); Lifetimely starts at $149 but caps at 3k orders on its cheapest paid tier. Metorik starts at $25 but is pure reporting, not attribution. Northbeam starts at $1,500 and demo-gates everything. **There is no competitor serving a €10k–€500k/mo revenue WooCommerce/Shopify SMB with transparent, revenue-indexed pricing under $150.**

Our pricing page should pitch this directly: a single card (not three tiers — just one, with a slider), "€39/mo + 0.4% of revenue processed, no cap, no seat limit, no feature gates". Show the slider computing "You'd pay €X based on your last month". Put "€39 minimum, €500 soft-ceiling — bigger than that, talk to us" as the only sales gate. Below the card, a comparison table that shows our competitors' monthly cost at €100k, €500k, €1M, €3M MRR vs ours — this is the moment where our model wins. The page should read as "one plan, priced honestly, scales with you" — Metorik's clarity + Putler's revenue-transparency + something none of them have (a single revenue-share number). Competitors we visually position next to: Metorik and Lifetimely (SMB-friendly pricing pages) rather than Triple Whale or Northbeam (enterprise sales gates we can't and shouldn't match).
