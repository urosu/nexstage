# Metric dictionary — competitor-sourced naming

Cross-competitor reference for how the same metric is named, defined, and formatted across tools. When picking our naming, default to whichever name appears most often across competitors — users have learned it already.

Source profiles: every tool in `/home/uros/projects/nexstage/docs/competitors/*.md`. Abbreviations used in tables: **TW** = Triple Whale, **NB** = Northbeam, **Pol** = Polar Analytics, **LT** = Lifetimely (AMP), **Peel** = Peel Insights, **Shop** = Shopify Native, **Klav** = Klaviyo, **Wk** = Wicked Reports, **Hyr** = Hyros, **Rkr** = Rockerbox, **Mot** = Motion, **Put** = Putler, **Mtk** = Metorik, **Mlc** = Mailchimp, **Omni** = Omnisend, **GSC** = Google Search Console, **GA4** = Google Analytics 4, **Meta** = Meta Ads Manager, **GAds** = Google Ads. N/A = the tool does not expose this concept. `—` = not verified in research corpus.

## Revenue / sales metrics

| Metric concept | TW | NB | Pol | LT | Peel | Shop | Klav | Mlc | Put | Mtk | Our pick |
|---|---|---|---|---|---|---|---|---|---|---|---|
| Top-line sales incl. tax/shipping/discounts | Gross Sales | Revenue | Gross Sales | Revenue (Gross) | Gross Revenue | **Gross sales** | Placed Order Value | Revenue (gross) | Gross Sales | Gross Sales | **Gross Sales** |
| Sales after refunds, discounts, tax, shipping | Net Sales | Net Revenue | Net Sales | Net Revenue | Net Revenue | **Net sales** | — | Revenue (net) | Net Sales | Net Sales | **Net Sales** |
| Revenue from orders that platform X claims credit for | Attributed Revenue | Attributed Rev (1d)/(7d)/LTV | Attributed Revenue | Attributed Revenue | Attributed Revenue | Sales attributed to marketing | Attributed Revenue | Revenue attributed to Mailchimp | Attributed Sales | Attributed Revenue | **Attributed Revenue** |
| Revenue platform reports vs revenue store recorded | "Store / Platform" (implicit) | De-duplicated vs Platform-reported | Side-by-side: Meta/Google/GA4/Pixel | N/A | N/A | N/A | N/A | N/A | Source-badged rows | N/A | **Store Revenue / Platform Revenue / Real Revenue** |
| Revenue from first-time customers only | NC Revenue (new-customer) | Revenue (1st Time) | New Customer Revenue | New Customer Revenue | New Customer Revenue | First-time customer sales | New subscribers revenue | — | New Customer Revenue | New Customer Sales | **New Customer Revenue** (suffix when contrasted) |
| Revenue from returning customers | Returning Revenue | Revenue (Returning) | Repeat Revenue | Returning Revenue | Returning Revenue | Returning customer sales | Repeat sales | — | Returning Revenue | Repeat Revenue | **Returning Revenue** |
| Revenue total (platform-agnostic, before attribution) | Total Revenue | Revenue (All) | Total Revenue | Total Revenue | Total Revenue | Total sales | Total Revenue | Total Revenue | Total Revenue | Total Revenue | **Total Revenue** |
| Average order size | AOV (mean/median/mode) | AOV | AOV | AOV | AOV | **Average order value** | Avg order revenue | Average order revenue | AOV | AOV | **AOV** (show mean primary, median in tooltip) |
| Revenue per visitor | RPV | RPV | RPV | RPV | — | — | Avg revenue per recipient | Avg revenue per recipient | ARPU / ARPPU | — | **RPV** (Revenue per Visitor) |
| Refund total | Refunds | Refunds | Refunds | Refunds | Refunds | Refunds / Returns | — | Refunds | Refunds | Refunds | **Refunds** |
| Refunded revenue as % of gross | Refund Rate | Refund Rate | Refund Rate | Refund Rate | — | Return rate | — | — | Refund Rate | Refund Rate | **Refund Rate** |
| Subscription recurring revenue | Subscription Revenue | — | Subscription Revenue | Subscription Revenue | MRR / Subscription Revenue | Recurring sales | — | — | MRR | MRR / Subscription Revenue | **MRR** (subscription), **Subscription Revenue** (total incl. renewals) |
| Total predicted recurring annual | ARR | — | ARR | — | ARR | — | — | — | ARR | — | **ARR** |
| Total store revenue across all sources | MER-referenced "Total Revenue" | Blended Revenue | Total Revenue | Total Revenue | Blended Revenue | Gross sales | — | Omnichannel Revenue | Net Sales | Revenue | **Total Revenue** |

## Spend / cost metrics

| Metric concept | TW | NB | Pol | LT | Shop | Meta | GAds | Mtk | Our pick |
|---|---|---|---|---|---|---|---|---|---|
| Total amount spent on ads on a platform | Ad Spend | Spend | Ad Spend | Ad Spend | N/A | **Amount spent** | **Cost** | Ad Spend | **Ad Spend** |
| Total ad spend across all platforms | Total Ad Spend / Blended Spend | Total Spend | Blended Ad Spend | Total Ad Spend | N/A | — | — | Blended Ad Spend | **Total Ad Spend** (or Blended Ad Spend when contrasted with single-platform) |
| Cost of goods sold | COGS | COGS | COGS | COGS | COGS (Advanced+) | N/A | N/A | COGS | **COGS** |
| Shipping cost paid by the store | Shipping Cost | Shipping | Shipping | Shipping Costs | Shipping | N/A | N/A | Shipping | **Shipping Costs** |
| Payment processor fees | Transaction Fees | Transaction Fees | Payment Fees | Transaction Fees | Payments fees | N/A | N/A | Transaction Fees | **Transaction Fees** |
| Fixed costs not tied to orders | Operating Expenses | OpEx | Fixed Costs | Operating Expenses | — | N/A | N/A | Custom Costs | **Operating Expenses** |
| Arbitrary user-defined cost | Custom Costs | — | Custom Costs | Custom Costs | — | N/A | N/A | Custom Costs | **Custom Costs** |
| Spend + refund + COGS + ops (all outflows) | — | — | Total Costs | Total Costs | — | N/A | N/A | — | **Total Costs** |
| Cost per thousand impressions | CPM | CPM | CPM | CPM | N/A | **CPM (cost per 1,000 impressions)** | — | CPM | **CPM** |
| Cost per click | CPC | CPC | CPC | CPC | N/A | **CPC (all)** / **CPC (cost per link click)** | CPC | CPC | **CPC** (prefer link-CPC on Meta; clarify in tooltip) |
| Cost per visitor | CPV | CPV | — | — | N/A | — | — | — | **CPV** (Cost per Visitor) |

## Profit metrics

| Metric concept | TW | NB | Pol | LT | Shop | Put | Mtk | Our pick |
|---|---|---|---|---|---|---|---|---|
| Revenue − COGS | Gross Profit | Gross Profit | Gross Profit | Gross Profit | **Gross profit** | Gross Profit | Gross Profit | **Gross Profit** |
| Gross Profit / Net Sales | Gross Margin | Gross Margin | Margin | Gross Margin | Margin | Margin | Margin | **Gross Margin** |
| Revenue − COGS − Shipping − Fees − Spend − OpEx | Net Profit | Contribution Margin / Net Profit | Net Profit | Net Profit | — (gated Advanced+) | Net Profit | Net Profit | **Net Profit** |
| Net Profit / Revenue | Profit Margin | Net Margin | Net Margin | Net Margin | — | Profit Margin | — | **Profit Margin** |
| Profit attributable to an ad (before OpEx) | Ad-Profit | Contribution | — | Ad Contribution | — | — | — | **Ad Contribution** |

## Attribution / marketing-efficiency metrics

| Metric concept | TW | NB | Pol | LT | Peel | Wk | Hyr | Mtk | Our pick |
|---|---|---|---|---|---|---|---|---|---|
| Attributed revenue / platform spend (single-platform) | ROAS | ROAS (d) | Platform ROAS | ROAS | Platform ROAS | Platform ROAS | Hyros ROAS | ROAS | **ROAS** (with source badge: `ROAS · Facebook`) |
| Total revenue / total ad spend (cross-platform) | **Blended ROAS** | ROAS (Blended) | **Blended ROAS** | ROAS (Blended) | Blended ROAS | True ROAS | "Real" ROAS | Blended ROAS | **Blended ROAS** |
| Total revenue / total ad spend (Lifetimely framing) | MER | Marketing Efficiency Ratio | MER | **MER** | MER | — | — | MER | **MER** (label as "MER — Marketing Efficiency Ratio" first time shown) |
| ROAS restricted to new customers | **NC-ROAS** | ROAS (1st Time) | nROAS | New-Customer ROAS | NC-ROAS | New-Customer ROAS | nROAS | — | **NC-ROAS** (expand in tooltip: "ROAS on first-time customers only") |
| ROAS using lifetime customer value | LTV ROAS | LTV ROAS | Lifetime ROAS | Lifetime ROAS | Lifetime ROAS | LTV ROAS (Revenue lookforward) | LTV ROAS | — | **LTV ROAS** |
| Cost per acquired new customer | CAC | CAC (1st Time) | CAC | CAC | CAC | nCAC | CAC | CAC | **CAC** |
| CAC on a specific window | — | CAC (d) / CAC (LTV) | — | — | — | — | — | — | **CAC (d)** (when window differs from default) |
| Cost per purchase (may include repeat buyers) | CPA | Cost per Purchase | CPA | CPA | CPA | CPA | CPA | CPA | **CPA** |
| LTV / CAC | LTV:CAC | LTV/CAC | LTV:CAC | LTV:CAC | LTV:CAC | LTV/CAC | — | LTV:CAC | **LTV:CAC Ratio** |
| Days to recover CAC from a cohort | CAC Payback | Payback Period | CAC Payback | **CAC Payback Period** | Payback | CAC Payback | CAC Payback | — | **CAC Payback Period** (in days) |
| Revenue platforms can't claim + store recorded | "Unattributed" / "Direct" | Unattributed | Not matched | Unattributed | Other | "Non-attributed" | — | Direct | **Not Tracked** (our term; can go negative — see PLANNING §6) |

## Attribution model names

| Concept | TW | NB | Rkr | Pol | Wk | Hyr | Klav | Our pick |
|---|---|---|---|---|---|---|---|---|
| Credit first touchpoint 100% | First Click | First Touch | First Touch | First-Touch | First-Touch | — | — | **First Touch** |
| Credit last touchpoint 100% | Last Click | Last Touch | Last Touch | Last-Touch | Last-Touch | — | Last Touch | **Last Touch** |
| Last non-direct touchpoint | — | Last Non-Direct Touch | — | Last Non-Direct | — | — | — | **Last Non-Direct Touch** |
| Equal credit across touches | Linear | Linear | Even Weight | Linear | — | — | — | **Linear** |
| Fractional clicks-only | — | **Clicks-Only** | Modeled Multi-Touch | — | — | — | — | **Clicks-Only** |
| Clicks + modeled views | — | Clicks + Modeled Views | — | — | — | — | — | **Clicks + Modeled Views** |
| Clicks + platform-verified views | — | **Clicks + Deterministic Views** | — | — | — | — | — | **Clicks + Deterministic Views** |
| Proprietary MTA | Triple Attribution | Modeled Multi-Touch | Modeled Multi-Touch | Polar MTA | Wicked Measure | (singular) | — | **Multi-Touch (Nexstage)** (never "true" or "real" attribution) |
| Pixel + survey blend | Total Impact | — | — | — | — | — | — | — (not shipping v1) |
| What the ad platform itself reports | Last Platform Click | Platform-reported | Platform-reported | Platform Attribution | Facebook Attribution | Pixel | — | **Platform-Reported** |

## Customer / LTV metrics

| Metric concept | TW | NB | Pol | LT | Peel | Klav | Put | Our pick |
|---|---|---|---|---|---|---|---|---|
| Cumulative revenue per customer | LTV | LTV | LTV / CLV | LTV | LTV | **Historic CLV** / **Predicted CLV** | LTV | **LTV** |
| ML-predicted future revenue | Predicted LTV | Predicted LTV | Predicted CLV | **Predicted LTV** (Amp AI) | Predicted LTV | **Predicted CLV** | — | **Predicted LTV** |
| 30/60/90/180/365d LTV | LTV 30d / LTV 90d | LTV (Window) | LTV 90d etc. | **3/6/9/12-month LTV** | LTV at Month N | 30/60/90 CLV | — | **LTV (90d)** — window in parens |
| Customer lifecycle segments | — | — | — | — | Champions / Loyal / At Risk / About to Sleep / Hibernating / Needs Attention | **Champions / Loyal / Potential Loyalists / At Risk / About to Sleep / Needs Attention** | New / Returning / Recurring / VIP / Churned | **RFM Segments** (with named buckets: Champions, Loyal, At Risk, Hibernating — copy Peel/Klaviyo) |
| % of customers who purchased again | Repeat Rate | Repeat Purchase Rate | Repeat Rate | Repeat Rate | Repurchase Rate | Repeat Rate | Repeat Rate | **Repeat Rate** |
| % of ordering customers who were repeat | Returning Customer Rate | — | Returning Customer Rate | Returning Customer % | — | — | — | **Returning Customer Rate** |
| Predicted days to next order | — | — | Predicted next order | Predicted next order date | Predicted next order | **Predicted next-order date** | — | **Predicted Next Order** |
| Average days between orders | Time Between Orders | — | Avg Days Between Orders | Avg Days Between Orders | Avg Order Frequency | **Avg days between orders** | — | **Avg Days Between Orders** |
| % of customers likely to churn | — | — | Churn Risk | — | Churn Rate | **Churn Risk Prediction** | Churn Rate | **Churn Risk** (predicted) / **Churn Rate** (observed) |
| Subscription cancellations / period | — | — | Churn | Churn | Churn % | — | Churn | **Churn Rate** |
| New customer count | New Customers | New Customers | New Customers | New Customers | New Customers | New Subscribers | New Customers | **New Customers** |

## Conversion / funnel metrics

| Metric concept | TW | NB | Pol | LT | Shop | GA4 | Meta | Klav | Our pick |
|---|---|---|---|---|---|---|---|---|---|
| Sessions that resulted in purchase / sessions | CVR | CVR | Conversion Rate | CVR | **Online store conversion rate** | Conversion rate | — | Conversion Rate | **Conversion Rate** |
| Session count | Sessions | Visitors | Sessions | Sessions | **Online store sessions** | **Sessions** | — | — | **Sessions** |
| Unique users | Visitors | Visitors | Visitors | Visitors | — | **Users** | Reach | — | **Visitors** (display), **Users** (internal/GA4 parity) |
| Page views | Pageviews | Pageviews | Pageviews | Pageviews | Page views | **Views** | — | — | **Pageviews** |
| % that added to cart | Add-to-Cart Rate | — | ATC Rate | ATC Rate | — | Add to cart rate | — | — | **Add-to-Cart Rate** |
| % that initiated checkout | Checkout Rate | — | Checkout Rate | Checkout Rate | Checkout rate | — | — | — | **Checkout Rate** |
| Orders placed | Orders / Transactions | Transactions | Orders | Orders | **Orders** | Purchases | — | Placed Orders | **Orders** (use Transactions only when GA4-sourced) |
| Checkout started but not completed | Abandoned Checkout | — | Abandoned Checkout | Abandoned Cart | Abandoned checkout | — | — | — | **Abandoned Checkout** |
| Cart created but no checkout | Abandoned Cart | — | Abandoned Cart | Abandoned Cart | Cart analysis | — | — | — | **Abandoned Cart** |
| % who bounce | Bounce Rate | — | — | — | — | Bounce rate | — | — | **Bounce Rate** (GA4 carryover) |
| Engaged session count | — | — | — | — | — | **Engaged sessions** | — | — | **Engaged Sessions** (GA4 carryover; if used) |

## Ads / creative metrics

| Metric concept | Meta | GAds | TW | NB | Mot | Wk | Hyr | Our pick |
|---|---|---|---|---|---|---|---|---|
| Times ad was shown | Impressions | Impressions | Impressions | Impressions | Impressions | Impressions | Impressions | **Impressions** |
| Unique users who saw ad | Reach | — | Reach | Reach | Reach | — | — | **Reach** |
| Impressions per person | Frequency | — | Frequency | Frequency | Frequency | — | — | **Frequency** |
| Clicks / Impressions | **CTR (link)** / **CTR (all)** | CTR | CTR | CTR | CTR | CTR | CTR | **CTR** (default link-CTR; "CTR (all)" when contrasted) |
| Link clicks | Link clicks | Clicks | Clicks | Clicks | Clicks | Clicks | Clicks | **Link Clicks** (Meta), **Clicks** (Google) |
| Outbound clicks (Meta-specific) | Outbound clicks | — | Outbound Clicks | — | — | — | — | **Outbound Clicks** (Meta only) |
| Landing page loaded post-click | Landing page views | — | LP Views | — | — | — | — | **Landing Page Views** (Meta only) |
| Video 3s-plays / Impressions | — | — | **Thumb-Stop Ratio** | — | **Thumb-stop rate** | — | — | **Thumb-Stop Rate** (computed, not stored) |
| Video avg watch % completed | ThruPlay | — | Hold Rate | — | Hold rate | — | — | **Hold Rate** (+ ThruPlay as raw) |
| Purchases attributed by platform | Purchases | Conversions | Purchases | Transactions | Purchases | Orders | Sales | **Purchases** (when platform-attributed) / **Orders** (when store-sourced) |
| Platform-reported ROAS | **Purchase ROAS** | **Conv. value / cost** | Platform ROAS | — | ROAS | Facebook ROAS | Hyros ROAS | **Purchase ROAS** (Meta), **Conv. Value / Cost** (Google), prefixed with source |
| Cost per purchase | **Cost per purchase** | Cost / conv. | CPA | Cost per Purchase | CPA | CPA | CPA | **Cost per Purchase** (Meta), **CPA** (Nexstage generic) |
| Action quality / engagement composite | — | Quality Score | — | — | Leaderboard Score | — | — | **Quality Score** (Google Ads only) |
| Moment-by-moment video performance | — | — | — | — | **Frame-by-frame scroll curve** | — | — | Reserved for v2 |

## SEO / search metrics (Google Search Console)

| Metric concept | GSC | SEMrush | Ahrefs | GA4 | Our pick |
|---|---|---|---|---|---|
| Impressions in Google results | **Impressions** | Estimated Impressions | — | — | **Impressions** |
| Clicks from Google results | **Clicks** | Est. Clicks | Organic Traffic | — | **Clicks** |
| Clicks / Impressions | **CTR** | CTR | — | — | **CTR** (GSC flavour) |
| Average SERP position | **Average position** | Position | Position | — | **Average Position** |
| Number of queries ranked | — | Keywords | Organic Keywords | — | **Ranking Queries** |
| Pages indexed | Indexed pages | — | — | — | **Indexed Pages** |
| Query dimension | **Queries** | Keywords | Keywords | — | **Queries** |
| Page dimension | **Pages** | URLs | Pages | Landing page | **Pages** |
| Device breakdown | **Devices** | — | — | Device | **Devices** |
| Country breakdown | **Countries** | — | — | Country | **Countries** |
| Rich-result / appearance types | **Search appearance** | SERP features | SERP features | — | **Search Appearance** |

## Email / SMS metrics

| Metric concept | Klav | Mlc | Omni | Our pick |
|---|---|---|---|---|
| Messages delivered to inbox | Delivered | Delivered | Delivered | **Delivered** |
| Unique opens / delivered | Open Rate (unique) | Open rate | Open rate | **Open Rate** |
| Unique clicks / delivered | Click Rate (CTR) | Click rate | CTR | **Click Rate** (avoid CTR — collides with ads) |
| Conversions / delivered | Placed Order Rate | Conversion rate | Conversion rate | **Conversion Rate** (email) |
| Revenue attributed to message | Attributed Revenue | Revenue attributed | Sales | **Attributed Revenue** |
| Avg revenue per recipient | Revenue per Recipient | Avg revenue per recipient | RPR | **Revenue per Recipient** |
| Emails bounced | Bounce Rate | Bounce rate | Bounce rate | **Bounce Rate** |
| Unsubscribe % | Unsubscribe Rate | Unsubscribe rate | Unsubscribe rate | **Unsubscribe Rate** |
| Spam complaint % | Spam Complaints | Abuse rate | Spam rate | **Spam Rate** |
| % of list that opened in last 90d | Engaged Profiles | Engaged contacts | Active subscribers | **Engaged Profiles** (Klaviyo term is clearest) |
| SMS credits consumed | SMS Credits | Segments sent | SMS sends | **SMS Credits** |
| List growth rate | Subscriber Growth | Audience growth | Growth rate | **Subscriber Growth** |
| % clicking through to purchase | CVR (flow/campaign) | Conversion rate | Conversion rate | **Conversion Rate** (per flow) |
| Funnel completion through automation | Funnel completion | — | Flow completion | **Flow Completion Rate** |

## Trust / data-quality metrics (Nexstage-relevant)

| Metric concept | Where seen | Example label | Our pick |
|---|---|---|---|
| Difference between what a platform reports and what the store recorded | Rockerbox (De-dup vs Platform), Polar (side-by-side), Wicked (Wicked vs Facebook), Fairing (survey vs pixel) | "Platform over-reports by 12%" | **Attribution Gap** (display as ±%; never "error") |
| Peer-cohort comparison status | Klaviyo (Excellent / Good / Fair / Poor), Varos (percentile bar) | "Excellent" badge on metric | **Benchmark Status** (Excellent / Good / Fair / Poor; copy Klaviyo) |
| Freshness of a data source | Vercel, Stripe | "Updated 3 min ago" / "Live" | **Last Synced** (relative time) |
| Sync progress during backfill | Lifetimely (explicit bar), Linear (ring), Motion | "Importing 18,420 of 24,100 orders (76%)" | **Sync Progress** |
| Integration health | Rockerbox (Data Status Reporting), Wicked (Advanced Signal quality) | "Connection healthy" / "Needs reauth" | **Connection Status** |
| Sample-size confidence | Varos (chip) | "Based on 12 orders — low confidence" | **Sample Size Note** |
| Platform-verified vs modeled signal | Northbeam (Deterministic vs Modeled Views) | "Deterministic" / "Modeled" | **Signal Type** |
| Whether a metric crossed an anomaly threshold | Polar alerts, Peel Magic Insights | "Conversion rate dropped 18% vs 7d avg" | **Anomaly Flag** |
| Pixel match rate (Meta CAPI / Google EC) | Wicked Advanced Signal | "Match quality: 7.4/10" | **Match Quality** |
| Amount the six sources disagree by | Polar, Rockerbox, Nexstage thesis | "Store says $42k / Facebook says $61k / Real: $48k" | **Source Disagreement** (our term; visualise, never hide) |

## Naming principles (what we learned from this exercise)

1. **Suffix, don't rename.** Attribution variants belong as parenthetical suffixes: `ROAS (7d)`, `ROAS (1st Time)`, `CAC (30d)`, `LTV ROAS`. Northbeam's typography-as-documentation pattern is the gold standard; coined a new metric like "nROAS" only where industry adopted it widely (NC-ROAS).
2. **"True" / "Real" / "Ground-Truth" prefixes are a red flag.** Hyros' "truth" positioning is the cautionary tale — the moment one number disagrees with Stripe, the narrative collapses. The only exception in our vocabulary is **Real Revenue** (gold lightbulb), and even that is labelled as *our* computation, not objective truth.
3. **Commerce-aware terms beat generic ones.** `Gross Sales` / `Net Sales` are accounting-grade and every CFO already knows them. Use them over "Revenue" when the distinction matters; use "Revenue" as the umbrella when it doesn't.
4. **Use the term users already learned.** If 8 of 10 competitors call it `ROAS`, don't invent `ADROI`. Learn cost > feature naming advantage.
5. **Source badges before the metric name, not after.** `ROAS · Facebook` (source is context, not suffix) is clearer than `Facebook ROAS`. Scales to six sources without name explosion.
6. **Computed ratios are never stored.** CPM / CPC / CPA / ROAS / CTR / CVR / AOV are all divisions — compute on the fly, NULLIF, show "N/A" on zero. Avoids cascading staleness.
7. **Expose the window, don't hide it.** `ROAS` alone is meaningless; `ROAS (7d click)` is honest. Klaviyo's retroactive-recalculation-on-window-change is the best implementation.
8. **"Not Tracked" beats "Unattributed".** Unattributed sounds like we failed; Not Tracked sounds like we're honest. Both describe store orders no platform claimed. Plus "Not Tracked" can go negative when platforms over-report, which "Unattributed" semantically can't.
9. **Prefer plain words over acronyms on the card; keep acronyms in drill-downs.** `Marketing Efficiency Ratio` (expanded once) → `MER` (in tables). Klaviyo's "Placed Order Value" is too verbose for a card header; we'd label it `Revenue` with Klaviyo as the source.
10. **Named RFM segments (Champions, At Risk) beat raw scores (4-5-3).** Peel and Klaviyo both moved to named buckets; copy the 6-bucket Klaviyo taxonomy (Champions / Loyal / Potential Loyalists / At Risk / About to Sleep / Needs Attention).
11. **Three-state recommendations beat free-form prescriptions.** Wicked's **Scale / Chill / Kill** and Atria's three-column triage are both tighter than "here's what to consider." Constrain to actionable outcomes.
12. **When in doubt, follow Northbeam for ads and Klaviyo for email.** They're the canonical naming in their respective domains; diverging costs users 5-10 years of learned vocabulary.

## Glossary (longhand) definitions for our help docs

### Revenue group

- **Gross Sales** — Product revenue before any deductions. Includes taxes, shipping, and applied discounts as line items. Matches Shopify's `gross_sales` in the Finance report. Does *not* subtract refunds. Shown with currency symbol per workspace.
- **Net Sales** — Gross Sales minus refunds, minus discounts, minus taxes, minus shipping. The closest thing to "cash you earned." Matches Shopify's `net_sales`. Use this as the headline on P&L surfaces; use Gross Sales on the Revenue surface.
- **Total Revenue** — Top-level revenue for a workspace across all connected stores, currency-converted to workspace currency (see `FxRateService`, DB-first). Default: Net Sales semantics.
- **Attributed Revenue** — Revenue that a specific source (Facebook, Google, Klaviyo, etc.) claims credit for under that source's own attribution window. Always displayed with its source badge. Sum of attributed revenue across sources can exceed Total Revenue because platforms over-count; the **Attribution Gap** surfaces this by design.
- **Real Revenue** — Nexstage-computed revenue after reconciling all six sources. Gold-lightbulb badge. This is our proprietary number, not a ground-truth claim. Methodology is linked from every Real Revenue surface (`?` icon opens explainer).
- **New Customer Revenue** — Revenue from orders where the customer's `customer_id` had zero prior orders in the workspace. Matches what Northbeam calls "Revenue (1st Time)." Used to separate acquisition from retention.
- **Returning Revenue** — Total Revenue − New Customer Revenue.
- **AOV (Average Order Value)** — Net Sales ÷ Orders. We show mean on the card; median is available in the tooltip. Why: mean is the universal default; median protects against whale-skew.
- **RPV (Revenue per Visitor)** — Net Sales ÷ Unique Visitors over the same window. Meaningful only when sessions are tracked; "—" otherwise.
- **Refunds** — Sum of refunded order amounts. Displayed as a positive number on the Refunds card; subtracted from Gross Sales to produce Net Sales.

### Spend group

- **Ad Spend** — Amount spent on ads, sourced from `ad_insights` for a single platform. Always badged with the source.
- **Total Ad Spend** (synonym: **Blended Ad Spend** in ROAS context) — Sum of Ad Spend across all connected ad platforms. Currency-converted to workspace currency.
- **COGS** — Cost of Goods Sold. Per-variant cost entered by the user (or imported). Applied to each order line at order-capture time, stored in `orders.cogs_amount`. Does not retroactively change historical orders if COGS is updated.
- **Shipping Costs** — What the store pays the carrier (not what the customer pays for shipping). Entered per-order or calculated from connected shipping app (ShipStation/ShipBob).
- **Transaction Fees** — Payment processor fees. Shopify Payments auto-syncs; PayPal/Stripe entered as % of transaction.
- **Operating Expenses** — Fixed periodic costs not tied to orders (rent, salaries, subscriptions). Workspace-level.

### Profit group

- **Gross Profit** — Net Sales − COGS. Product profitability before marketing or operations.
- **Gross Margin** — Gross Profit ÷ Net Sales, shown as percentage.
- **Net Profit** — Net Sales − COGS − Shipping Costs − Transaction Fees − Total Ad Spend − Operating Expenses. The bottom line.
- **Profit Margin** — Net Profit ÷ Net Sales, shown as percentage.
- **Ad Contribution** — Attributed Revenue − (COGS on attributed orders) − Ad Spend. How much profit an ad generated before OpEx. Rolls up to campaign / adset / ad level.

### Efficiency / attribution group

- **ROAS (Return on Ad Spend)** — Attributed Revenue ÷ Ad Spend, per source. Window shown in parens (`ROAS (7d)`). Display "N/A" when Ad Spend is 0.
- **Blended ROAS** — Total Revenue ÷ Total Ad Spend. Cross-platform sanity check; sidesteps attribution debates. Synonym *MER* for contexts where the finance/DTC audience expects that name.
- **MER (Marketing Efficiency Ratio)** — Same math as Blended ROAS; surfaced under this name on finance/CFO-oriented surfaces. First time shown in a session, label is "MER — Marketing Efficiency Ratio."
- **NC-ROAS** — New Customer Revenue ÷ Ad Spend. Answers "how efficiently is this ad acquiring new customers?" Distinct from ROAS because retargeting can have high ROAS and zero NC-ROAS.
- **LTV ROAS** — Predicted Lifetime Revenue of acquired customers ÷ Ad Spend. Uses `Predicted LTV` model output, not realised revenue.
- **CAC (Customer Acquisition Cost)** — Ad Spend ÷ New Customers. Per platform when badged; blended across all spend otherwise.
- **CPA (Cost per Acquisition / Purchase)** — Ad Spend ÷ Purchases (may include repeat buyers). Use **CAC** when restricted to new customers.
- **LTV:CAC Ratio** — Predicted LTV ÷ CAC. Healthy DTC benchmark is >3:1 over 12 months.
- **CAC Payback Period** — Days of cumulative gross margin required for a cohort to recover its CAC. Shorter = better; 3-6 months is strong.
- **CPM** — Ad Spend ÷ (Impressions / 1000).
- **CPC** — Ad Spend ÷ Link Clicks (Meta) or ÷ Clicks (Google). Clarify source in tooltip.
- **Not Tracked** — Total Revenue − Σ(Attributed Revenue across all sources after de-duplication). Can go negative when platforms over-report. Does not mean "unattributed orders" — it means "store revenue our reconciliation couldn't pin on a source." See PLANNING §6.

### Customer group

- **LTV (Lifetime Value)** — Cumulative Net Sales per customer, realised. Windowed: `LTV (30d)`, `LTV (90d)`, `LTV (365d)`, `LTV` (unbounded).
- **Predicted LTV** — ML-modelled forward-looking LTV per customer or cohort. Shown with confidence range, not a point estimate. Retrained weekly minimum.
- **Repeat Rate** — % of customers who have placed ≥2 orders (cumulative, not per period).
- **Returning Customer Rate** — Within a period, % of orders where the buyer was a returning customer.
- **Churn Rate** (subscription) — % of active subscribers who cancelled in a period. Distinct from **Churn Risk** (predicted).
- **Avg Days Between Orders** — Per-customer median delta between consecutive orders. Useful for setting campaign cadence.
- **RFM Segments** — Named customer buckets: **Champions** (recent, frequent, high-value), **Loyal**, **Potential Loyalists**, **At Risk**, **About to Sleep**, **Needs Attention**. Copied from Klaviyo's 6-bucket taxonomy to avoid divergent naming.

### Funnel group

- **Sessions** — Session count, sourced from store Shopify analytics or Nexstage Site pixel depending on source badge.
- **Visitors** — Unique sessionised users. GA4 users might recognise this as "Users."
- **Conversion Rate** — Orders ÷ Sessions, for the same window and source. "N/A" when Sessions = 0.
- **Add-to-Cart Rate** / **Checkout Rate** — Derived from Shopify customer-events pixel where available.
- **Abandoned Cart / Abandoned Checkout** — Cart created without a checkout started / Checkout started without purchase. Distinct; don't conflate.

### Ads group

- **Impressions** — Times an ad was displayed.
- **Reach** — Unique users who saw an ad.
- **Frequency** — Impressions ÷ Reach.
- **CTR** — Link Clicks ÷ Impressions. Default link-CTR; "CTR (all)" exposed only when contrasted with link-CTR on the same screen.
- **Thumb-Stop Rate** — 3-second video views ÷ Impressions. Signal of whether the ad stopped scroll. Computed, not stored.
- **Hold Rate** — Avg % of video duration watched. Signal of whether viewers kept watching.
- **Purchases** (platform-attributed) vs **Orders** (store-recorded) — Always distinct. Never averaged together.
- **Purchase ROAS** — Meta-specific. When Meta is the source badge, the ROAS card is labelled Purchase ROAS to mirror Ads Manager.

### SEO group

- **Impressions** (GSC) — Times site appeared in Google results. Same word as ads; distinguished by source badge.
- **Clicks** (GSC) — Times user clicked through to site from Google results.
- **CTR** (GSC) — Clicks ÷ Impressions in Google results context. Source badge disambiguates from ad CTR.
- **Average Position** — Site's average SERP rank across all ranking queries. Lower is better.
- **Queries** — Search terms users entered to reach the site.
- **Search Appearance** — Rich-result / feature types (Featured Snippet, Site Links, FAQ, etc.).

### Email group

- **Delivered** — Messages accepted by recipient ISP.
- **Open Rate** — Unique opens ÷ Delivered. Bot-filtering toggle should default on (unlike Klaviyo).
- **Click Rate** — Unique clicks ÷ Delivered. We avoid "CTR" here because it collides with ad CTR.
- **Conversion Rate** (email) — Conversions ÷ Delivered, within the per-channel attribution window.
- **Attributed Revenue** (email) — Revenue credited to an email/SMS within the attribution window. Disclose the window on the card (`Attributed Revenue (5d click)`).
- **Revenue per Recipient** — Attributed Revenue ÷ Delivered. Pricing-per-contact makes this the efficiency metric that matters.
- **Bounce Rate**, **Unsubscribe Rate**, **Spam Rate** — Deliverability basics; red flags when > industry benchmark.
- **Flow Completion Rate** — % of flow entries that reached the final step. Mailchimp's "Customer Journey completion %" is the clearest implementation.

### Trust group (Nexstage-specific)

- **Attribution Gap** — Σ(Attributed Revenue across sources) − Total Revenue. Positive = over-reporting; negative = under-reporting. Always shown as a signed percentage on the six-source card.
- **Benchmark Status** — Your value relative to peer cohort, rendered as `Excellent / Good / Fair / Poor`. Copied from Klaviyo's status-badge pattern. Peer cohort methodology linked from `?` icon.
- **Match Quality** — For pixel/CAPI events, percentage of events successfully matched to a user identity on the destination platform. Scale 0–10 mirroring Meta's EMQ.
- **Sample Size Note** — Below a threshold (e.g., <30 orders), metrics render with a "Based on N orders — low confidence" chip, grey-tinted. Prevents chasing noise.
- **Anomaly Flag** — Automated highlight when a metric crosses a workspace-configured threshold or a computed statistical anomaly. Surfaced on Home alerts and dashboard cards.
- **Signal Type** — For attribution models that mix deterministic and modelled signals, a `Deterministic · Modelled` split label tells users where the number came from.
