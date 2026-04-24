# Instagram Shopping Insights (Meta Commerce Manager)

**URL:** https://www.facebook.com/business/help/928462240946943 (Instagram Insights in Commerce Manager) | https://help.instagram.com/825941707897287 (help) | https://business.facebook.com/commerce (Commerce Manager app)
**Target customer:** Any Instagram merchant with a Product Catalog tagging products in feed posts, Reels, Stories, or Live. Since Meta sunset native checkout on Facebook/Instagram Shops on September 4, 2025, virtually all shops now redirect to the merchant's website, so the insights primarily measure **pre-click discovery/intent funnel** on Instagram itself, not post-click conversion (which happens on the merchant's site and is attributed via the Meta Pixel / Conversions API, not Commerce Manager). Skews toward DTC brands $100k–$10M GMV running Shopify/WooCommerce/BigCommerce as the backend commerce platform and using Instagram as a product-discovery surface.
**Pricing:** Free. Included with any Meta Business account that has a Commerce Manager-connected Catalog.
**Positioning one-liner:** "Here's how many eyeballs and taps your product catalog got on Instagram this week." A pre-click discovery-funnel view that intentionally stops at the handoff to the merchant's website — Meta Ads Manager measures everything post-click.

## What they do well
- **Tagged-content attribution is native and granular.** Every product tag on every post / Reel / Story / Live is individually tracked — views, clicks, adds-to-cart (if Pixel fires), purchases (if Pixel + Conversions API fire). Per-piece-of-content, per-product granularity.
- **Real-time app-level insights on mobile.** Tap a shoppable post → "View Insights" → swipe up → see Product views and Product button clicks for that specific post. Near-instant feedback loop for a creator who just posted.
- **Tabbed structure in Commerce Manager is clean.**
  - **Overview** — at-a-glance snapshot.
  - **Performance** — Sales / Behavior / Traffic sub-sections.
  - **Discovery** — where visitors came from (Feed / Explore / Notifications / Facebook Shop / Search).
  - **Product-tagged Content** — per-tag performance segmented by creator, post type (Feed / Reels / Stories / Live), product, variant.
  - **Catalog** — performance by product and by collection.
  - **Audience** — demographics (age, gender, region, language).
- **Per-tagged-product and per-creator drill-down.** You can see "this exact product, tagged in this exact Reel, by this exact creator" — useful for creator-brand collabs where the merchant wants to attribute a specific creator's post.
- **Content-format breakdown.** Performance split by Feed / Reels / Stories / Live. Matches the content taxonomy creators actually think in.
- **Audience demographics without a separate setup.** Age, gender, region, language automatic once a Catalog is connected.
- **Free and already-there** for any merchant running a Meta Business account. Bundled with the tools they're already logged into.
- **Data retention is generous** — historical insights go back 24 months for most metrics in Commerce Manager, longer than TikTok Shop's 90d default.

## Weaknesses / common complaints
- **Massive scope shrinkage after native-checkout deprecation.** With Meta shutting down Facebook/Instagram checkout on September 4, 2025, "purchases" and "revenue" inside Commerce Manager are now a function of Pixel/CAPI quality on the merchant's site — not a direct Meta-owned transaction. Many merchants report their Insights "Sales" tab showing partial or zero data post-migration because their CAPI coverage is incomplete.
- **Shop tab removed from Instagram's home navigation** (2023–2024 rollout) — the Reels tab replaced it. Discovery traffic to shops collapsed; Discovery-tab insights show the shift but can't reverse it. Many merchants saw their Instagram Shop sessions drop 60–80%.
- **No ad spend.** Commerce Manager insights do not include Meta Ads spend / ROAS. You flip to Ads Manager for that, and the two views use different attribution logic.
- **Attribution model is opaque.** Meta doesn't clearly document whether product-tag attribution is last-touch, last-click, or modeled. Merchants on forums describe numbers that don't reconcile with either the Pixel view in Ads Manager or their Shopify / Woo order log.
- **Data delay is 24–72 hours for anything server-side.** Conversions processed by CAPI, some tagged-content attribution, and audience metrics lag behind real-time. No explicit freshness badge on cards.
- **Thin visualization.** Tables with single numbers, occasionally a line chart. No heatmaps, no funnels, no cohort views.
- **Export is limited.** Screen-level export to CSV for some tables; no API for shopping insights (the Marketing API covers ads, not commerce insights). Most merchants who want their Instagram shopping data in a warehouse build a scraper.
- **"Purchases" metric depends on Pixel + CAPI being configured correctly.** If a merchant hasn't set up CAPI (or their integration broke — a frequent BigCommerce-post-Feedonomics scenario), the Sales tab goes blank or undercounts by 30–60%.
- **Per-variant performance has gaps.** Variant-level tagging exists, but variant-level attribution is inconsistent — often the parent SKU gets credit.
- **Creator attribution stops at the collaborator tag.** If a creator posts your product without a formal "collab" tag (via organic product-tagging permission), their contribution may land in "Other" rather than attributed to the creator.
- **No comparison view / no benchmarks.** Meta shows your numbers; they don't show peer cohort comparison or "your brand vs category average" despite owning the data.
- **No segmentation by UTM / campaign.** Campaign-level analytics live in Ads Manager; Commerce Manager is organic-first and doesn't join campaign dimensions.
- **Mobile Commerce Manager is half-baked.** The mobile app version of Commerce Manager is much less feature-rich than desktop — most merchants check mobile for the lightweight in-post insights only.
- **Frequent UI churn.** Meta reorganizes Commerce Manager navigation roughly yearly; Reddit and agency blogs describe merchants re-learning the tool every 12–18 months.
- **Help documentation is thin and often outdated.** Multiple Meta Business Help Center pages still reference the pre-2024 navigation. Forums fill the gap with screenshots that are themselves months out of date.

## Key screens

### In-App Post Insights (mobile, quick view)
- **Entry:** From any shoppable post in Instagram → tap "View Insights" → swipe up on the drawer.
- **Metrics shown:** **Product views** (taps on tagged product → product-page view inside Instagram) and **Product button clicks** (tap of the "View on website" / "Add to bag" CTA on the product page).
- **Scope:** Per-post only. No roll-up in-app.
- **Data freshness:** Near-real-time (minutes).
- **Limitations:** This is the entire mobile surface; no sessions/purchases/revenue on mobile.

### Commerce Manager → Overview
- **Layout:** At-a-glance dashboard. Cards for headline metrics: total product views, total outbound clicks / product-button clicks, top-tagged products, top-performing posts.
- **Date range control:** Top-right picker; default "Last 7 days", with last-28-day and custom options.
- **Data density:** Medium-low. ~6–8 cards.
- **Interactions:** Each card links to the relevant detailed tab.

### Commerce Manager → Performance
- **Layout:** Three sub-tabs — **Sales**, **Behavior**, **Traffic**.
  - **Sales** (only populated if shop had checkout, or if Pixel/CAPI is attributing purchases): purchases, revenue, AOV. Post-Sept-2025 most merchants see thin data here.
  - **Behavior:** product page views, adds to cart, adds to wishlist, checkouts initiated. This is the meaningful middle-funnel view for website-redirect shops.
  - **Traffic:** sessions, views, unique visitors to the shop.
- **Chart types:** Mostly line charts over the selected date range.
- **Screenshot refs:** https://www.shoprunnerbusiness.com/post/instagram-insights-commerce-manager

### Commerce Manager → Discovery
- **Layout:** Breakdown of where shop visitors came from inside Meta surfaces.
- **Sources tracked:** Feed, Stories, Reels, Explore, Notifications, Search, Facebook Shop, Other.
- **Per-source metrics:** Sessions, product page views, conversion through to add-to-cart or outbound click.
- **Critical context:** Post-Shop-tab-removal, the "Shop on homepage" source is gone; Feed + Reels + Explore dominate. This tab is the honest read on how badly shop traffic has collapsed.

### Commerce Manager → Product-tagged Content
- **Layout:** Ranked table of every post (Feed / Reels / Stories / Live) that tagged a product. Columns: post thumbnail, post type, creator (if collab), product tagged, variant (if applicable), product page views, purchases, adds to cart, adds to wishlist.
- **Filters:** Date range, content format (Feed / Reels / Stories / Live), creator, product, product category.
- **Drill-down:** Click a post → post-detail page with every tag on that post and per-tag performance.
- **Collaborator attribution:** Posts flagged as "Collab" or "Partnership" get per-creator attribution; organic creator tags land as "Other" or the posting account.

### Commerce Manager → Catalog
- **Layout:** Per-product and per-collection performance table. Metrics: product page views, CTR from impressions, adds to cart, purchases (if attributed).
- **Filters:** Date range, category, availability status.
- **Use case:** Merchandising — which catalog items are getting views but not converting? Answers the "rockstar vs. cold product" question Ecommerce Insights on BigCommerce gives you pre-chewed.

### Commerce Manager → Audience
- **Layout:** Demographic breakdowns — age, gender, region/country, top languages. Pie/bar charts.
- **Limitations:** Aggregated demographics only. No per-customer detail (Meta doesn't expose customer identity through Commerce Manager insights).

## The "angle" — what makes them different
Instagram Shopping Insights is **pre-click discovery-funnel analytics by design**. The mental model is: Meta owns the impression-through-tap funnel (product impressions → product-page views → product-button clicks); the merchant's website owns the tap-through-purchase funnel (sessions → add-to-cart → checkout → purchase). Commerce Manager stops at the handoff boundary. Post-click conversion gets attributed in Ads Manager via the Pixel and Conversions API, not in Commerce Manager.

This makes Commerce Manager insights a very specific-purpose tool: "am I getting eyeballs and taps on my catalog through organic Instagram content?" It is **not** the tool to answer "what's my Meta ROAS" (that's Ads Manager) or "how does my Instagram revenue compare to my Shopify revenue" (that's a third-party tool like Nexstage).

The second angle is **creator-collab attribution as a native concept.** Instagram has spent years formalizing the "Partnership" / "Collab" tagging, and those signals flow through Commerce Manager's Product-tagged Content tab. Per-creator product-page-view attribution is built in, without the merchant having to manually reconcile creator-driven campaigns.

What Instagram Shopping Insights **doesn't** do:
- **Post-click conversion** on website-redirect shops (that's the Pixel/CAPI's job, reported in Ads Manager).
- **Ad spend / ROAS** (Ads Manager).
- **Cross-channel comparison** with Facebook Shop, TikTok Shop, or the merchant's website.
- **Real-time purchase data** — lags 24–72h server-side.
- **Custom metrics / query layer** — fixed tabs, fixed columns, no API.
- **Peer benchmarks** despite Meta owning the population data.

Nexstage's opening: a Shopify/Woo/BigCommerce merchant who tags products on Instagram sees their **pre-click funnel** in Commerce Manager and their **post-click/order** funnel in their store. The two worlds are stitched together via UTM parameters on the outbound link (messy, ~60% direct on Instagram) or via Pixel/CAPI (opaque). Nexstage can do the stitch the right way — match Instagram-tagged content to the resulting store orders via UTM + attribution model — and present it as a single unified view.

## Integrations
- **Stores:** Catalog source can be a Shopify / BigCommerce / WooCommerce / Salesforce Commerce Cloud product feed (often via Feedonomics for non-Shopify). The Catalog is the source of truth; tagged-content insights flow from the Catalog.
- **Ad platforms:** Meta Ads Manager is a sibling (not parent) — Commerce Manager insights and Ads Manager insights are separate with separate attribution logic.
- **Pixel + Conversions API:** Required for meaningful "Sales" / "Purchases" metrics in Commerce Manager post-Sept-2025. CAPI configuration quality directly determines Commerce Manager data quality.
- **Creators / Collabs:** In-product "Partnership" / "Branded Content" tooling is the official attribution path.
- **Export/API:** No public API for Commerce Manager shopping insights. CSV export from most tables. The Marketing API covers ads; the Commerce API covers catalog management but not insights.
- **Warehouse/BI:** No first-party integration. Tools like Supermetrics, Fivetran, Improvado scrape what they can through Graph API endpoints; coverage is partial.

## Notable UI patterns worth stealing
- **Pre-click funnel scoped explicitly.** Instagram stops at the handoff to the merchant's site — the tool knows what it's responsible for. Nexstage should be equally explicit about which attribution stage each metric belongs to (did Meta *serve* the impression? did the *store* convert? which source "owns" the number?).
- **Content-format split (Feed / Reels / Stories / Live) on every tag report.** Treats content format as a first-class dimension. Nexstage's ad/creative surfaces should allow "group by content format" as a single-click filter if we ever ingest Instagram/TikTok creative data.
- **Mobile in-app post-level insights.** Tap a post → swipe up → see its numbers. Near-instant feedback. Nexstage should consider a lightweight mobile equivalent for "how's this post/ad/campaign doing" without full dashboard.
- **Discovery tab — where did visitors come from inside the platform.** Instagram breaks shop traffic by Feed / Explore / Reels / Notifications / Search. Nexstage's equivalent is "traffic source" on the store, but we could push further: within Meta, which *surface* drove the click? Within TikTok, which *surface*? The finer-grained source model is useful.
- **Collaborator (creator) attribution as a first-class concept.** If Nexstage ever ingests influencer / affiliate data, model the collab/partnership tag as the attribution carrier, not a free-form text field on the ad.
- **Per-variant analytics on tagged products.** Variants often behave very differently; surfacing variant-level data (even imperfectly) respects merchandising reality.
- **Audience demographics free-out-of-the-box from the Catalog.** Nexstage could compute age/gender region demographics from Shopify customer data + enrichment and show them wherever a merchant would expect "who buys this" — a low-effort, high-trust surface.

## What to avoid
- **Don't be opaque about attribution model.** "Purchases" in Commerce Manager is attributed via some blend of last-touch and Pixel/CAPI signal that Meta doesn't clearly document. Users lose trust when the number can't be explained. Nexstage must name the attribution model on every ROAS/revenue card.
- **Don't let CAPI misconfiguration silently zero out sales data.** Many merchants saw their Commerce Manager Sales tab go blank after the Feedonomics migration on BigCommerce or after their Shopify Pixel broke. The tool should detect the outage and surface a diagnostic ("CAPI coverage was 80% last week, 0% today — check your Pixel"), not just show 0.
- **Don't reshuffle the primary navigation every year.** Meta's yearly Commerce Manager redesigns are widely hated. Nexstage should commit to stable IA; changes behind feature flags or versioned URLs.
- **Don't ship a mobile tool that's 20% of the desktop tool.** Mobile Commerce Manager has been "coming soon" for years. Either invest to parity on the top-used surfaces or don't market mobile at all.
- **Don't bury freshness state.** Cards that could be 72 hours stale need a freshness indicator — ideally with timestamp of last refresh. Instagram's silence on freshness is one reason merchants don't trust the numbers.
- **Don't pretend the shop tab still exists.** After the removal of Shop from Instagram's home tab, many third-party docs (and some Meta docs) still describe the old layout. Nexstage's integrations docs / help content must be current and dated.
- **Don't silo sales data between Commerce Manager and Ads Manager.** Two separate apps with different attribution logic for the same underlying events is confusing. Nexstage's single-source truth is the point — one number per event, one model per surface, consistent across surfaces.
- **Don't build insight surfaces that can't export.** Commerce Manager's "screen-level CSV" is enough for casual analysis but blocks warehouse workflows. Every surface must have a machine-readable export path.
- **Don't omit peer benchmarks when you have the data.** Meta has demographic and category scale comparable to Shopify's and doesn't surface benchmarks. Nexstage can't match Meta's data scale, but we shouldn't hide benchmarks where we do have them (e.g., anonymized aggregate across Nexstage customers).

## Sources
- https://www.facebook.com/business/help/928462240946943 (Instagram Insights in Commerce Manager)
- https://help.instagram.com/825941707897287 (Instagram Help Center: Commerce Manager insights)
- https://en-gb.facebook.com/business/help/713927982072352 (Get insights in Commerce Manager)
- https://www.facebook.com/business/help/1013149685852226 (Specifications for Discovery in Commerce Manager Insights)
- https://www.facebook.com/business/help/538769503450341 (Commerce Manager Insights)
- https://www.facebook.com/business/help/459488681526952 (Get Facebook Page Shop Insights)
- https://www.facebook.com/business/shopping/blog/Learn-more-about-insights-for-your-shop/
- https://www.facebook.com/business/help/1314349509894768 (About Changes to Shops and Checkout)
- https://www.valueaddedresource.net/meta-phases-out-native-checkout-facebook-instagram-shops/
- https://feedonomics.com/blog/meta-removing-native-checkout/
- https://www.godatafeed.com/blog/meta-is-dropping-native-checkout-on-facebook-and-instagram
- https://ppc.land/meta-phases-out-facebook-and-instagram-shops-checkout-by-august-2025/
- https://econsultancy.com/instagram-removes-shop-tab-homepage-impact-social-commerce/
- https://www.plannthat.com/how-to-measure-your-instagram-shopping-posts/
- https://www.topbubbleindex.com/blog/instagram-shopping-insights/
- https://www.topbubbleindex.com/blog/instagram-shopping-commerce-manager/
- https://www.outfy.com/blog/instagram-analytics/
- https://www.shoprunnerbusiness.com/post/instagram-insights-commerce-manager
- https://www.agorapulse.com/blog/facebook/facebook-commerce-manager-setup/
- https://madgicx.com/blog/instagram-analytics
- https://cropink.com/instagram-shopping-statistics
- https://www.loungelizard.com/blog/the-essential-guide-to-instagram-shopping/
- https://getkoro.app/blog/instagram-product-tagging
- https://getkoro.app/blog/shopping-tags-in-instagram-ads
- https://www.linkedin.com/learning/getting-started-with-facebook-shop-for-creators/overview-of-commerce-manager-insights
