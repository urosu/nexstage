# Daasity

**URL:** https://www.daasity.com / https://apps.shopify.com/daasity
**Target customer:** Mid-market and enterprise consumer brands ($5M-$500M+) running omnichannel (DTC + wholesale + retail). Named logos: Tweezerman, SC Johnson, Poppi, Manscaped, Rothy's, ILIA. Brands who have outgrown Triple Whale and are about to hire a data engineer — Daasity is the "pay us instead of a headcount" pitch.
**Pricing:**
- Shopify App Store starts at $1,899/mo (14-day free trial). Usage-based on rolling 3-month revenue average.
- Direct-sale: ~$349/mo for stores under $2M revenue, scaling to $999/mo for >$10M brands. Destination-count pricing is documented as $350/mo for 2 destinations, $800/mo for 4, custom beyond that.
- Implementation fees (for custom data modeling) commonly run into thousands of dollars on top of subscription.

**Positioning one-liner:** "Enterprise Level Analytics, No Data Engineering" — a fully-managed data stack (ELT + warehouse schema + Looker dashboards) for consumer brands who want Snowflake/BigQuery without hiring to run it.

## What they do well
- **Full managed data stack, not a dashboard product.** They extract (300+ connectors), load into the brand's own warehouse (Snowflake / BigQuery / Redshift), model the data into standardized schemas, and ship Looker dashboards on top. The brand owns the warehouse — Daasity is the plumbing + modeling layer.
- **120+ prebuilt Looker dashboards/reports** organized into Omnichannel, Retail Analytics, Ecommerce, Acquisition Marketing, Retention Marketing, Utility, and Data Source categories. Includes LTV & RFM, Cohort Analysis ("Layer Cake" visualization), Site Funnels, Attribution Deep Dive, Inventory, Promotional Efficiency, Vendor-Reported Marketing Performance.
- **Genuine omnichannel.** Retail/wholesale data (Nielsen, SPINS, Whole Foods, Walmart Marketplace, Amazon Vendor Central, NewStore, RetailNext) sits alongside DTC data in the same warehouse — most competitors can't do this.
- **Explores (semantic layer over Looker).** Non-technical users build reports via drag-and-drop on pre-defined Orders / Customer / Marketing / Product models without writing LookML or SQL. Analysts can still drop into SQL / LookML for custom work.
- **Reverse-ETL destinations** (Audiences) — push warehouse-computed segments back to Meta, Google Ads, Klaviyo, Attentive.
- **Strong support / CSM attention on enterprise accounts.** Reviews repeatedly call out implementation help building custom reports.

## Weaknesses / common complaints
- **Steep learning curve.** Because it's Looker underneath, non-technical marketers hit a wall when they leave the Explores and try to customize dashboards. "Data modeling is coding-extensive" shows up in G2 reviews.
- **Expensive, and the hidden cost is implementation.** Subscription is only part of it — custom schema / warehouse work often adds thousands in services fees.
- **Project-management horror stories.** At least one detailed public review described six months of incomplete custom implementation, unexpected overruns, and staff turnover including the cofounder departing mid-project.
- **Time-to-value is slow.** Unlike Triple Whale / Fairing / Segments which are live within hours, Daasity is a weeks-to-months onboarding.
- **Destination-based pricing surprises SMBs.** Most consumer brands need 4+ destinations, which puts them at $800+/mo before the Shopify App Store sticker price even applies.
- **Not positioned for WooCommerce SMBs.** Their target is Shopify Plus / Shopify B2B / BigCommerce / Magento at the top end.
- **Looker dependency.** If Google deprecates or reprices Looker, Daasity's UX is tied to a platform they don't own. Shopify Sidekick + ShopifyQL is a credible medium-term threat.

## Key screens

### Home Dashboard (Flash / Company Overview)
- **Layout:** Top strip of real-time KPI tiles (revenue, orders, AOV, traffic). Below: sales-by-channel stacked bar, trend line for revenue with YoY overlay, top products table, alerts/notifications panel. Multi-store toggle up top for brands running multiple Shopify instances.
- **Key metrics shown:** Net revenue, orders, sessions, conversion rate, AOV, customer count — all with YoY and period-over-period comparisons.
- **Data density:** High — intentionally, because it's a Looker dashboard and the user is expected to be data-literate.
- **Date range control:** Looker-standard — presets plus custom ranges; all tiles inherit it.
- **Interactions:** Click into any tile for the full Explore with all fields pivotable. Drill paths are deep — you can end up 5 clicks into a pivot table inspecting a single SKU.
- **Screenshot refs:** https://help.daasity.com (Platform Overview); Shopify App Store listing https://apps.shopify.com/daasity

### Attribution Deep Dive
- **Layout:** Multi-tab dashboard — Pixel Attribution, Survey-Based Attribution (from Fairing integration), Promo Code Attribution, Multi-Touch model. Each tab uses the same chrome so users can compare views.
- **Key metrics shown:** Orders, revenue, CAC, ROAS by channel under each attribution model. Survey tab explicitly shows "Survey Response" as a dimension + derived channel/vendor.
- **Data density:** Analyst-grade. Designed for pivot.
- **Interactions:** Pivot to any grain; filter by new/returning, product, geography.
- **Screenshot refs:** https://www.daasity.com/post/marketing-attribution-dashboard

### LTV & RFM ("Layer Cake")
- **Layout:** The signature "Layer Cake" chart — stacked area where each layer is revenue from a customer cohort acquired in that quarter, over time. New cohorts start at the top, existing cohorts contribute thinner layers as they mature.
- **Key metrics shown:** Revenue per cohort quarter, repeat purchase revenue share, cumulative LTV curves, RFM segment matrix.
- **Data density:** Very high — intentionally executive-facing but finance-grade.
- **Interactions:** Hover a layer for that cohort's exact cumulative revenue; RFM matrix is clickable to drill into the customer list for any cell.

### Inventory Analytics
- **Layout:** Stock-on-hand table, weeks-of-cover heatmap, stockout risk alerts, sell-through rate ranking, demand-vs-supply forecast chart.
- **Interactions:** Filter by warehouse / channel / SKU. Schedule daily email alerts for stockout risk.

### Scheduled Reports & Explores
- **Explores:** Drag-and-drop report builder on top of the Orders / Customer / Marketing / Product / Inventory semantic models. Users pick dimensions and measures, Daasity generates the Looker query.
- **Scheduled Reports:** Send any dashboard or Explore on a cron (daily/weekly/monthly) to email or Slack.
- **Collections:** Curated dashboard groupings you share with stakeholders.

## The "angle" — what makes them different
Daasity's thesis is **"you're going to hire a data engineer anyway — pay us half as much and skip the warehouse design."** They aren't competing with Triple Whale or Segments on the dashboard — they're competing with hiring. The asset they sell is a **standardized consumer-brand data schema** (the semantic layer) plus **120+ dashboards built on that schema**, shipped into *your* warehouse. You keep ownership, compliance stays intact, and if you churn you keep the data.

The two things Nexstage won't replicate: (1) their retail/wholesale/Nielsen data integration is a real moat for omnichannel brands, and (2) their Looker-native analyst workflow is out of scope for SMBs. But the **semantic layer** idea — opinionated business entities (Order, Customer, Campaign) that non-technical users can pivot on without writing SQL — is the durable product idea here and the thing to study.

## Integrations
- **Commerce:** Shopify, Shopify Plus, Shopify B2B, BigCommerce, Magento, Salesforce Commerce Cloud, NewStore
- **Marketplaces:** Amazon Vendor Central, Amazon Seller Central, Walmart Marketplace
- **Retail / Wholesale:** Whole Foods, SPINS, Nielsen, RetailNext
- **Ads:** Google Ads, Bing, Meta, TikTok, Snapchat, Pinterest, Criteo, AppLovin, Impact Radius, Pepperjam, Rockerbox, Northbeam
- **Email / SMS:** Klaviyo, Attentive, Iterable, Postscript, Ometria
- **Subscription:** Recharge, Stay AI, Skio
- **Reviews / Support:** Okendo, Yotpo, Alchemer, Fairing, KnoCommerce, Gorgias, Zendesk
- **OMS / 3PL:** NetSuite, Fulfil, Order Desk, Extensiv, ShipBob, ShipStation
- **Returns:** Loop Returns, Narvar
- **Warehouses:** Snowflake, BigQuery, Redshift (destinations, not sources)
- **Reverse ETL destinations:** Meta, Google Ads, Klaviyo, Attentive
- **Databases:** MySQL, Postgres, MongoDB, MSSQL
- **Files:** CSV, Excel

## Notable UI patterns worth stealing
- **"Layer Cake" cohort chart.** Best executive-readable cohort LTV visualization in the industry. Every cohort visibly contributes to revenue over time as a stacked area; new cohorts enter as fresh layers. Nexstage's `QuadrantChart` could generalize this.
- **Semantic-layer Explores.** Business users pivot pre-modeled Orders / Customers / Campaigns without touching SQL. This is the antidote to "the report I need doesn't exist."
- **Attribution tabs for side-by-side comparison.** Pixel, Survey, Promo, Multi-Touch all live as tabs on one dashboard so you can flip views with the same date range.
- **Explicit vendor-reported attribution tab.** They treat "what Meta says" as one dataset among many, not the ground truth. Same philosophical move Nexstage's six-badge system makes.
- **Dashboards organized by question, not data source.** Top-level categories are "Acquisition", "Retention", "Inventory" — not "Shopify", "Meta", "GA4".
- **Scheduled reports with no extra upcharge.** Email/Slack delivery of any dashboard makes it stickier than pure UI-only tools.

## What to avoid
- **Don't require a Looker skillset.** Daasity's UX ceiling for non-analysts is exactly why Triple Whale exists. Nexstage's SMB user will give up before they write LookML.
- **Don't price on destinations.** "Pay more to connect more things" punishes the exact behavior that makes the product valuable.
- **Don't sell on implementation services.** If onboarding takes months, SMBs churn before they see value. Aim for <24h to a useful dashboard.
- **Don't treat the warehouse as user-facing.** Daasity's "BYO warehouse" is a moat for enterprise but dead weight for SMBs — they don't have one and don't want one.
- **Avoid dashboard sprawl.** 120+ dashboards sounds impressive on a sales deck but overwhelms users. Better: 10 opinionated views with deep drill-downs.
- **Don't couple to a single BI vendor.** Looker dependency is an architectural risk (Google deprecated Data Studio's Looker branding, pricing shifts, etc). Own your rendering layer.

## Sources
- https://www.daasity.com/
- https://www.daasity.com/integrations
- https://www.daasity.com/post/building-a-data-stack
- https://www.daasity.com/post/marketing-attribution-dashboard
- https://apps.shopify.com/daasity
- https://apps.shopify.com/daasity/reviews
- https://www.g2.com/products/daasity/reviews
- https://help.daasity.com
- https://help.daasity.com/sitemap.md
- https://help.daasity.com/advanced/marketing-attribution/survey-based-attribution.md
- https://www.aisystemscommerce.com/post/daasity-review-ecommerce-data-platform
- https://softpulseinfotech.com/shopify-app/daasity
