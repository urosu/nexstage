# MonsterInsights

**URL:** https://www.monsterinsights.com
**Target customer:** WordPress site owners who want Google Analytics 4 data inside the WP admin without touching code. Four stated personas: publishers/bloggers, ecommerce store owners (WooCommerce / EDD / MemberPress), business/marketing sites, and agencies managing client sites. Distribution is huge: 3M+ active installs on WordPress.org, 4.5/5 from 3,133 reviews. Not a serious competitor to dedicated ecommerce analytics (data is raw GA4, no store-DB reconciliation) — but sets the UX expectation for any WooCommerce merchant evaluating analytics tools. Owned by Awesome Motive (same team as WPForms, OptinMonster, AIOSEO).
**Pricing (annual, 50%-off promotional pricing shown permanently; regular prices in parens):**
- **Plus** — $99.50/yr (reg. $199) — 1 site. Standard analytics, no ecommerce report.
- **Pro** — $199.50/yr (reg. $399) — 5 sites. Adds **eCommerce Report**, Form Conversion Report, Custom Dimensions, Coupons Report, advanced tracking (Enhanced Ecommerce, authors, categories, SEO score), PPC tracking, WooCommerce + EDD + WPForms integrations. Marked "Most Popular."
- **Elite** — $299.50/yr (reg. $599) — 5 sites. Pro + UserFeedback Elite (surveys, NPS, heatmaps).
- **Agency** — $399.50/yr (reg. $799) — 25 sites, 1 year of updates.
- A **Lite** (free) tier exists on WordPress.org but is not on the pricing page; reviewers repeatedly call it "practically useless."

14-day money-back guarantee.
**Positioning one-liner:** "The best Google Analytics plugin for WordPress" — shows GA4 data as native-feeling WP admin reports without GA's UI, aimed at non-technical site owners.

## What they do well
- **Zero-code install.** Insert GA4 tracking with "a few clicks." For non-technical WP users this is the entire value proposition and they deliver.
- **Native WP admin feel.** Reports live under `Insights » Reports » *` in the WP sidebar. Everything looks like WP admin — same typography, same card patterns, same "screen options." Users don't feel like they've left their site.
- **Useful pre-built reports.** Overview, Publishers, Traffic (Overview, Landing Pages, Source/Medium, Campaigns, Technology), Search Console, eCommerce (Overview, Funnel, Coupons, Cart Abandonment, User Journey), Forms, Media, Realtime, Site Speed, Dimensions, Country/Region.
- **User Journey report (ecommerce).** Timeline per converted user — how they arrived (campaign), which pages they viewed, time-to-purchase (even across multiple days). One of the genuinely differentiated screens.
- **Cart abandonment reports are split into two useful views** (by product and by date).
- **Custom Dimensions report.** Lets users analyze sessions by author, category, tag, focus keyword, SEO score — properly leveraging WP's content model.
- **Realtime widget in the WP dashboard.** Small but sticky — active users, pages/min for last 30 min, top pages/countries/cities.
- **GDPR-compliant tracking mode** out of the box.
- **Integration surface inside WP.** WooCommerce, Easy Digital Downloads, MemberPress, WPForms, Gravity Forms, Formidable, AffiliateWP, Yoast SEO — each contributes tracked events that appear in MonsterInsights reports.
- **Email summaries + PDF export.** Weekly digest and PDF export for client reporting (agency tier).
- **PPC ad tracking (Pro+).** Google, Microsoft, Meta, TikTok, Pinterest, Snapchat, LinkedIn click-in tracking.

## Weaknesses / common complaints
- **Aggressive paywall / upsells.** The single most common complaint. "Almost everything beyond the first screen is hidden behind a paywall." "No real information unless you upgrade." "Wildly expensive — to do what you can DIY for free." "Free version is practically useless."
- **Bloatware install behavior.** "Installs bloatware plugins / activates them sitewide without asking in Multisite." Opt-out rather than opt-in defaults draw 1-star reviews repeatedly. Tries to install OptinMonster/WPForms/AIOSEO banners.
- **"Didn't install it, don't want it, don't need it, but keeps showing up."** Bundled into other Awesome Motive plugins so users who didn't choose it still see it.
- **Upsell nag pattern inside WP admin.** Even paying users see upsells to higher tiers and sister products.
- **It's just GA.** You're reading Google Analytics through MonsterInsights' lens. Data accuracy issues (ad-blockers, GA4 sampling, attribution) are Google's; MonsterInsights can't fix them but gets blamed for them.
- **Customer support complaints on WordPress.org.** At least one high-visibility review: "costed us thousands of dollars in wrong ads and wrong ads optimization, with non-existent support and Google audiences not populating for four months."
- **Ecommerce report is derivative.** Mirrors GA4 Enhanced Ecommerce — doesn't reconcile with the WooCommerce database (can't say "GA says 142 conversions but WC has 148 orders"). No source disagreement UI.
- **Constrained by WP admin width.** Reports work around the collapsible WP sidebar; on narrow viewports or with many menu items, layouts get cramped.
- **"Lite is practically useless"** keeps appearing — the free tier exists mostly as a funnel to Plus.

## Key screens

### Overview report (inside WP admin)
- **Layout:** `Insights » Reports » Overview`. Single scrollable page. Top: date range selector (7 days / 30 days / custom). Below: time-series graph (sessions + pageviews). Below graph: 4-card KPI strip (sessions, pageviews, avg session duration, total users — each with Δ% vs previous period). Below strip: two-column grid — **New vs Returning** donut chart, **Device Breakdown** donut, **Top Countries** table, **Top Referrals** table, **Top Posts/Pages** list with view counts.
- **Key metrics shown:** Sessions, pageviews, avg session duration, total users, new vs returning, device split, top countries, top referrals, top content.
- **Data density:** Medium. Not overwhelming — fits the "at-a-glance" promise.
- **Date range control:** Top of page — 7 days / 30 days / custom.
- **Interactions:** Date selector recalculates whole page. Top Posts list items link to the page content (WP post edit). No drill-down from cards — this is read-only reporting.
- **Screenshot refs:** `mi-overview-top-nav-1.png` on monsterinsights.com.

### eCommerce report
- **Layout:** `Insights » Reports » eCommerce`. Top card strip: **conversion rate**, **transactions**, **revenue**, **AOV**. Below: **Top Products** list (rank, name, quantity, revenue), **Top Conversion Sources**, **Total Add to Carts**, **Total Removed from Cart**, **Time to Purchase**, **Sessions to Purchase**. Sub-tabs within eCommerce: Funnel, Coupons, Cart Abandonment (by product + by date), User Journey.
- **Key metrics shown:** Conversion rate, transactions, revenue, AOV, top products, top conversion sources, cart-add/remove counts, time/sessions to purchase.
- **Data density:** Medium-high — 4-card strip + ~6 secondary widgets.
- **Date range control:** Top of page.
- **Interactions:** Sub-tab navigation. Cart abandonment splits into two charts (products in abandoned carts + abandonment % by day).
- **Screenshot refs:** `mi-ecommerce-report-1024x836.jpeg`.

### Funnel report (ecommerce sub-tab)
- **Layout:** Horizontal funnel chart showing customer progression "from viewing an item → add to cart → purchase" with drop-off counts and % between stages.
- **Screenshot refs:** `mi-funnel-report-1.png`.

### User Journey report (ecommerce)
- **Layout:** Timeline per purchased order. Shows how long the user took (across sessions/days), which campaign brought them in, which pages they viewed, the products they viewed before converting.
- **Data density:** High per-row, but scoped to one user at a time.
- **Screenshot refs:** `monsterinsights-user-journey-1.png`, `user-journey-easy-digital-downloads-1.png`.

### Cart Abandonment report
- **Layout:** Two separate views. **By product:** table of products in abandoned carts with lost revenue and cart-abandonment %. **By date:** time-series of cart abandonment rate by day.
- **Screenshot refs:** `mi-reports-ecommerce-cart-abandonment-products.png`, `mi-reports-ecommerce-cart-abandonment-date-1024x568.png`.

### Coupons report
- **Layout:** Table with columns: coupon code, times used, total revenue from those orders, AOV per coupon.
- **Screenshot refs:** `mi-coupons-report-1.png`.

### Traffic Overview + sub-reports
- **Layout:** Tables of channels, landing pages, source/medium, technology (browser/device), campaigns (UTM).
- **Data density:** High per table, sortable columns.
- **Screenshot refs:** `monsterinsights-traffic-overview-1.png`, `mi-landing-page-details-zoomed-1.png`, `source-medium-zoomed.png`, `mi-tech-report-1.png`, `mi-campaigns-zoomed.png`.

### Publishers report
- **Layout:** Aggregates Landing Pages, Outbound Links, Affiliate Links, Download Links, Scroll Depth, Demographics, Interest Categories into one dashboard sensibly organized for content publishers.
- **Screenshot refs:** `mi-reports-publisher-outbound.png`.

### Realtime report + dashboard widget
- **Layout:** Active users counter, pageviews/minute for last 30 minutes (line chart), top pages / countries / cities tables.
- **Interactions:** Auto-refreshing. Also exposed as a WP dashboard home widget.
- **Screenshot refs:** `mi-realtime-report.jpeg`.

### Search Console report
- **Layout:** Table of top 50 Google search terms with clicks, impressions, CTR, avg position.
- **Screenshot refs:** `search-console-report.png`.

### Forms report
- **Layout:** Overview card strip (sessions, pageviews, form impressions, completions) + time-series graph. Below: per-form breakdown, segmentable by traffic source / campaign.
- **Screenshot refs:** `forms-overview-report-mi-1024x796.png`, `forms-report-breakdown-1024x638.png`.

### Site Speed report
- **Layout:** Card-style performance metrics. Page speed, server response time, key web vitals.
- **Screenshot refs:** `mi-site-speed-new-ga4-1.png`.

### Custom Dimensions report
- **Layout:** Configurable drill by author, publish date, category, tag, focus keyword, SEO score. Tables.
- **Screenshot refs:** `author-tracking-dimensions-report.png`, `most-popular-categories-report-1.png`.

### Country / Region report
- **Layout:** Expandable country list with drill-down into regions within each country.
- **Screenshot refs:** `country-report-mi-e1729191740678.png`.

### Media report
- **Layout:** Table of tracked videos with plays, avg watch time, avg % watched, completion rate.
- **Screenshot refs:** `mi-media-report-new-2.png`.

### WP Dashboard Widget (home screen)
- **Layout:** WordPress home dashboard gets a MonsterInsights widget — recent sessions/pageviews, top posts, device split. Designed to greet you every time you log into WP admin.

## Integrations
- **Ecommerce:** WooCommerce, Easy Digital Downloads, MemberPress.
- **Forms:** WPForms, Gravity Forms, Formidable Forms.
- **Ads:** Google Ads, Microsoft Ads, Meta, TikTok, Pinterest, Snapchat, LinkedIn (click-in/pixel tracking).
- **SEO:** Yoast SEO, AIOSEO.
- **Affiliate:** AffiliateWP, ThirstyAffiliates.
- **Content:** YouTube, Vimeo, HTML5 video.
- **Monetization:** Google AdSense.
- **Data source:** Google Analytics 4 (the only one — everything else is event enrichment).

## Notable UI patterns worth stealing
- **Reports live inside the primary admin nav.** Not a floating embed; not an iframe to an external dashboard. Analytics feels native to where the user already works. For Nexstage, the parallel is: keep data presentation integrated with the store context, not tucked into a separate surface.
- **At-a-glance Overview → drilldown sub-reports.** The overview is deliberately shallow (4 cards, 6 secondary widgets); depth lives in sub-reports. Prevents the "wall of numbers" problem Glew suffers.
- **Funnel report with drop-off counts between stages.** Classic, but well-executed — shows both absolute counts and conversion % between steps.
- **User Journey timeline.** One purchase = one scrollable row of events (campaign → page views → add-to-cart → purchase) with time deltas. Beautifully simple narrative for "what happened before this conversion." Nexstage could do this per order, joining ad clicks + UTM + store events.
- **Two complementary views for cart abandonment (by product + by date).** Same data, two lenses. Pattern applies anywhere — Nexstage could do "revenue by channel" × "revenue by date" as split views.
- **Realtime widget as a dashboard home citizen.** Tiny surface area, high emotional stickiness. Gets users to log back in.
- **PDF export + email summaries.** Agencies live on these; underrated retention feature.
- **Drill-down by WP content model (author, category, tag, focus keyword).** Using the platform's native taxonomy as dimensions is smart. Nexstage analog: drill by store-native tags (product type, collection, vendor, country).

## What to avoid
- **Paywall walls that hide core numbers.** Users are viscerally angry: "no real information unless you upgrade." Nexstage should gate *features*, not baseline metrics.
- **Auto-installing sister plugins / opt-out bloatware.** The #1 reason for 1-star reviews. Do not ship cross-promos that auto-activate.
- **Nag banners for other products inside a paid tool.** Paying users should never see "upgrade to X" banners.
- **Constantly-renewed 50% "promotional" pricing.** Transparent fake urgency. Either price at the real number or run time-bounded sales honestly.
- **A free tier that's deliberately broken to funnel upgrades.** "Lite is practically useless" is actually the user-facing rendering of a growth strategy — and it erodes trust.
- **Lite install defaults that enable tracking without a consent prompt.** GDPR is compliance-only — the default consent flow should be explicit.
- **Being "just GA with a prettier wrapper."** MonsterInsights inherits every GA limitation (sampling, attribution gaps, ad-blocker loss) without adding store-DB reconciliation. That's exactly the gap Nexstage's trust thesis exploits — data should be reconciled from multiple sources, not single-sourced from one.
- **Depending on a single upstream data source (GA4).** When Google changes GA4, MonsterInsights scrambles. Multi-source ingestion is safer.
- **Weekly/monthly percentage deltas without confidence intervals.** A 50% week-over-week swing on a small site is noise. Deltas need sample-size context (graying out when N is too small).

## Sources
- https://www.monsterinsights.com
- https://www.monsterinsights.com/pricing/
- https://www.monsterinsights.com/features/
- https://www.monsterinsights.com/feature/ecommerce/
- https://www.monsterinsights.com/your-ultimate-guide-to-monsterinsights-dashboard-reports/
- https://www.monsterinsights.com/docs/how-to-find-your-google-analytics-reports-in-monsterinsights/
- https://www.monsterinsights.com/docs/how-to-enable-enhanced-ecommerce-in-wordpress/
- https://wordpress.org/plugins/google-analytics-for-wordpress/
- https://wordpress.org/support/plugin/google-analytics-for-wordpress/reviews/
- https://wordpress.org/support/topic/avoid-costed-us-thousands/
- https://wordpress.org/support/topic/what-a-monstrous-waste-of-time/
- https://wordpress.org/support/topic/free-version-is-practically-useless/
- https://wordpress.org/support/topic/wildly-expensive-to-do-what-you-can-diy-for-free/
- https://wordpress.org/support/topic/installs-bloatware-plugins-activates-them-sitewide-without-asking-in-multisite/
- https://thinkmaverick.com/monsterinsights-google-analytics/
- https://wp101.com/monsterinsights-review/
