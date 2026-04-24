# Wicked Reports

**URL:** https://www.wickedreports.com
**Target customer:** DTC ecommerce brands in the $5M–$50M revenue range, digital agencies managing 5–50 clients, and media buyers optimizing paid ad spend. Strongest fit: Shopify + Klaviyo + Meta/Google stacks doing cold-traffic customer acquisition with long consideration windows (months, not days). Info-product/course sellers and subscription/membership sites (ReCharge, Keap, HighLevel, Infusionsoft customers) are a secondary but explicit segment.
**Pricing:** Revenue-banded, self-serve up to $2.5M ARR. No free trial published; downgrade/upgrade takes effect next cycle.
- **Measure** — $499/mo. FunnelVision, Cohort & LTV Reports, lifetime lookback/lookforward, major ad platform + standard cart + standard CRM integrations.
- **Scale** — $699/mo. Adds API integrations (CRM/cart) + Outbound API, currency conversion, international time-zone data loading.
- **Maximize** — $999/mo. Adds **5 Forces AI**, **Advanced Signal / Meta CAPI**, Custom Conversions, Amazon Revenue Integration.
- **Enterprise** — from $4,999/mo. Adds priority support, dedicated servers, custom SLAs, enterprise security reviews.
- Add-ons (on lower tiers): Advanced Signal +$199/mo, 5 Forces AI +$199/mo.
**Positioning one-liner:** "First-Party Attribution That Finds New Customers" — strip out retargeting bias so you can see which ads genuinely acquire *new* customers, then train Meta/Google with that signal.

## What they do well
- **New-customer vs. repeat-customer split is the headline.** Every report auto-separates first-time from repeat buyers, and the company openly markets the claim that 60%+ of your retargeting budget is recycled revenue platforms are falsely claiming credit for (Bullseye Sellers case study: nCAC $116 → $69). This is sharper positioning than Triple Whale's generic "MER".
- **FunnelVision is the flagship report — 90%+ of user time lives there.** A single visual representation of common conversion paths with TOF / MOF / BOF (top/middle/bottom of funnel) segmentation. User picks a strategy ("new customer acquisition", "ROAS reporting", "performance by initiative") and FunnelVision assembles the relevant view. Heavy use of side-by-side "Wicked vs. Facebook Attribution" comparison charts as proof.
- **Attribution Time Machine** — their signature marketing/technical concept. Takes a customer's eventual click and stitches backward through pixel history to identify the cold-traffic touchpoint that *started* the journey, rather than only crediting whoever closed. Especially valuable for long consideration cycles (30d+ between first touch and conversion).
- **Advanced Signal / Meta CAPI pipe.** Sends "New Purchase" vs "Repeat Purchase" server-side events back to Meta as custom conversion signals, so Meta optimizes for acquisitions instead of retargeting — effectively using Wicked's attribution data to re-train the ad platform. Closest thing in the category to a closed-loop learning system.
- **5 Forces AI** — weekly Scale / Chill / Kill budget recommendations with plain-English reasoning per campaign. Heavily emphasized as the "action" layer on top of measurement.
- **Supports Shopify AND WooCommerce AND BigCommerce AND Amazon** — notable because Triple Whale, Northbeam, Polar don't. Plus CRM-heavy stacks: Keap, HighLevel, ActiveCampaign, Klaviyo, Salesforce, ReCharge.
- **Long attribution windows by default.** Pitched as "lifetime lookback/lookforward" vs. the industry-standard 7-day click / 1-day view. Customized windows per business per cohort.
- **Order-level + customer-ID reconciliation.** Revenue ties to actual `order_id` and CRM contact IDs, not pixel estimates — so auditing "did this attributed order actually ship?" is possible.

## Weaknesses / common complaints
- **Expensive floor.** $499/mo minimum is 2–3x Triple Whale Starter; the Enterprise jump to $4,999/mo is steep. Pricing is a repeated objection in competitor comparison posts (SegMetrics, Cometly, HeadWest Guide).
- **No free trial published.** Multiple comparison posts call out the absence of a trial. Demo-gated.
- **UI feels dated relative to Triple Whale / Northbeam.** Review sites describe it as "functional, not beautiful" — tables over visualizations, lots of controls. This is less of a deal-breaker for agency/analyst personas but is a real SMB objection.
- **FunnelVision is powerful but has a learning curve.** The TOF/MOF/BOF mental model plus customizable lookback windows means every knob has a reason — but users without marketing-ops backgrounds get lost in the configuration.
- **Competitors' support criticism is public.** Linx Digital (an agency that uses both) wrote "With Hyros, the customer service team is far more knowledgeable, helpful, and proactive" — implying Wicked's support is weaker.
- **Pinterest, TikTok, Snap support is thinner than competitors** — Meta + Google + Microsoft are first-class, but the long tail of ad platforms is less covered than Rockerbox's 100+ or Triple Whale's 9-platform creative surface.
- **No MMM or incrementality testing.** Wicked does MTA only; no marketing-mix modeling, no lift/holdout tests. For brands scaling past $50M this becomes a ceiling.
- **Not a dashboard tool in the Triple Whale sense.** You don't log in for a morning coffee check — you log in to run specific reports. This is a persona mismatch for founder-operators who want glance-and-go.

## Key screens

### FunnelVision (the flagship, 90% of user time)
- **Layout:** Central visual "funnel" representation with TOF / MOF / BOF bands as the primary vertical axis. Channels and campaigns appear as rows within each funnel tier; revenue + click data sit in side tables.
- **Attribution model dropdown** at the top (first-touch / last-touch / Wicked's proprietary multi-touch / custom).
- **Strategy picker:** user chooses one of ~8 pre-built strategies — "Improve new customer acquisition", "Report more Facebook ROAS", "Strategically optimize TOF ad budget", "Clarity on paid media conversion time lag", "Adjust CPAs based on product SKU LTV". Each pre-configures filters/columns.
- **Side-by-side platform comparison:** persistent "Wicked ROAS vs. Facebook ROAS vs. Google ROAS" strip. Used as a marketing screenshot frequently — closest analog to Nexstage's six-source MetricCard.
- **Customizable lookback window** per report. Most tools offer 7/28-day; Wicked exposes multi-month windows explicitly, which is necessary for cold-traffic stories.
- **TOF/MOF/BOF tagging** is editable at the channel level (user assigns "Facebook Prospecting" as TOF, "Facebook Retargeting" as MOF, "Branded Search" as BOF).
- **Screenshot refs:** https://www.wickedreports.com/funnel-vision, https://www.wickedreports.com/blog/funnel-vision-first-look, https://www.wickedreports.com/fv-short-demo

### Cohort & LTV Reports
- Cohort table with acquisition month as rows, LTV at 30/60/90/180/365d as columns. Similar in shape to Triple Whale's LTV grid.
- **Segment by acquisition source** (first-touch channel / campaign).
- **Revenue lookforward** — project cohort LTV forward using historical cohorts' curves.
- **Predicted-future-revenue** is a stated accounting model option: "count the future LTV of a cohort, not just day-0 revenue." Distinctive.

### Attribution Time Machine
- Conceptual UI: pick a conversion, see the chronological list of every tracked touchpoint leading to it, color-coded by channel, with gaps shown (days between touches).
- Works retrospectively — you can "replay" what happened for any order ID.
- **Practical use:** auditing disputed attribution. "Why did Wicked credit this $1,200 sale to a Facebook TOF ad when last-click says it was branded Google?"

### 5 Forces AI (Maximize tier)
- Weekly email + in-app cards labeled **Scale / Chill / Kill** per campaign.
- Each card includes: current spend, recommended spend delta, the metric driving the recommendation, and a plain-English explanation ("this campaign's new-customer ROAS dropped 40% over 14 days while retargeting ROAS held — shift $500/day to TOF-A").
- User can accept / reject / snooze each recommendation.

### Advanced Signal dashboard
- Channel health panel: for each connected platform (Meta CAPI, Google Enhanced Conversions), shows events sent, match rate, and recent errors.
- **Custom Conversions** builder — define "New Purchase $>200" and pipe it server-side to Meta as an optimization event.
- **Quality score** per event stream (pattern similar to Meta's native EMQ).

## Attribution model
Wicked's public positioning avoids listing classic model names (linear, time-decay, position-based) and instead sells **one proprietary first-party MTA model** branded as "Wicked Measure", tuned by:
- **Customizable lookback windows** per cohort / per strategy (days to 12+ months).
- **TOF/MOF/BOF credit weighting** — the user explicitly tags channels by funnel stage, and Wicked uses that taxonomy in credit assignment.
- **New-customer vs. repeat-customer split** — every credit assignment is dual-counted across the two cohorts, so a report can show "Facebook acquired 40 new customers AND drove 120 repeat orders" side-by-side.
- **Click-level + order-ID matching** — not pixel-fire estimates. Revenue ties to Shopify/WooCommerce/Amazon orders after the fact.
- **Patent-pending** (their language) — they market the model as proprietary IP, not a configurable model selector.

**Philosophy:** single source of truth, opinionated model, but with TOF/MOF/BOF tagging as the user-controlled knob. Contrasts with Triple Whale's "here are 7 models, you pick" and Rockerbox's "MTA + MMM + incrementality triangulated". Wicked's bet: the model is less important than the lookback window, the new/repeat split, and the data reconciliation to order IDs.

## Integrations
- **Ecommerce:** Shopify, WooCommerce, BigCommerce, Amazon (Maximize tier), ReCharge (subscriptions).
- **CRM / email:** Klaviyo, Keap (Infusionsoft), HighLevel, ActiveCampaign, Salesforce, ClickFunnels/Actionetics, Ontraport.
- **Ad platforms:** Meta, Google Ads, Microsoft/Bing, Pinterest, TikTok. No Snapchat, weaker Amazon Ads coverage than Triple Whale.
- **SMS / email trackers:** Attentive, Postscript, Klaviyo SMS — tracked as touchpoints in the journey.
- **Outbound API** (Scale tier +) — pipe attribution data out to warehouses or BI tools.
- **Meta CAPI / Google Enhanced Conversions** as outbound destinations (Advanced Signal).

## Notable UI patterns worth stealing
- **Strategy picker as a top-level filter.** Instead of "here's a report, configure it", the user picks *what question they're asking* ("improve new customer acquisition") and the report self-configures. Lower-cognitive-load entry point than a blank grid.
- **Persistent "Wicked vs. Facebook" comparison strip** on every attribution surface. The entire product is built around "the platforms lie and we show you by how much". Nexstage's six-source MetricCard should go harder in this direction.
- **TOF/MOF/BOF tagging at the channel level.** User explicitly assigns funnel stage; reports then respect that taxonomy. Much clearer than "Prospecting vs. Retargeting" naming hacks.
- **New-customer vs. repeat-customer split as default.** Not a filter toggle; the default report always shows both columns. Reduces the "are we comparing apples to apples?" moment.
- **Attribution Time Machine / order replay.** Audit-grade: given an `order_id`, show the touchpoint sequence that produced it, as a timeline. This is a natural extension of Nexstage's order-level `attribution_*` columns.
- **Scale / Chill / Kill three-state recommendations.** Tighter than "optimize" hand-waving; constrains the action space to three outcomes a media buyer can actually execute.
- **Patent-pending as marketing.** Whether or not the IP is real, marketing attribution as "our algorithm, tuned to your business" is a defensible narrative vs. "we expose 7 industry-standard models".
- **Lookback window as a first-class control.** Most tools hide this in settings; Wicked treats it as a primary filter because DTC consideration cycles don't fit a 7-day mold.

## What to avoid
- **Don't gate the primary attribution view behind $499/mo.** Even agencies balk — Triple Whale's free tier gave them a huge onboarding moat that Wicked is actively losing to. A free read-only tier with one connected store would be table-stakes.
- **Don't market "patent-pending" as a feature.** Users read this as "black box"; pair it with an explainer that shows the math. Triple Whale ran into the same "why don't these numbers reconcile" Reddit threads.
- **Don't skip MMM and incrementality** if you want to sell above $50M. The market is maturing; pure MTA is increasingly seen as table-stakes, and Rockerbox owns the "all three methodologies" narrative at the enterprise tier.
- **Don't ship a product where 90% of time is in one report.** FunnelVision is powerful but the rest of the app atrophies — users complain they don't know what the other tabs are for. Either promote the secondary reports or consolidate.
- **Don't under-invest in UI polish.** The demo videos look like Windows 7 — competitors (Triple Whale, Northbeam) look like Linear. In 2026 this loses the evaluation even when the data is better.
- **Don't require demo for pricing at the top tier.** Publishing "Enterprise from $4,999" is honest and useful; hiding behind demo gates annoys analyst personas.

## Sources
- https://www.wickedreports.com
- https://www.wickedreports.com/pricing
- https://www.wickedreports.com/funnel-vision
- https://www.wickedreports.com/demos
- https://www.wickedreports.com/fv-short-demo
- https://www.wickedreports.com/wicked-shopify-integration
- https://www.wickedreports.com/klaviyo
- https://www.wickedreports.com/wicked-google-integration
- https://www.wickedreports.com/wicked-facebook-integration
- https://www.wickedreports.com/wicked-recharge
- https://www.wickedreports.com/blog/funnel-vision-first-look
- https://www.wickedreports.com/blog/save-20-of-ad-spend-with-funnelvision-a-game-changing-solution
- https://help.wickedreports.com/how-to-use-funnel-vision
- https://docs.wickedreports.com/
- https://nerdisa.com/wickedreports/
- https://www.adleaks.com/marketing-accuracy-mid-funnel-visibility/
- https://cbweb.net/wicked-reports-review/
- https://segmetrics.io/articles/hyros-pricing-compared/ (comparative context)
- https://linxdigital.com/blog/the-truth-behind-alex-beckers-hyros-our-honest-opinion (agency comparative review)
