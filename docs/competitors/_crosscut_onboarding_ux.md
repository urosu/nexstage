# Onboarding UX — cross-competitor deep dive

Cross-cut of how 11 ecommerce analytics / adjacent tools handle first-run experience, from signup click through "a useful screen with real data". This is the moment users decide whether to stay. Profiles in `/home/uros/projects/nexstage/docs/competitors/*.md` are baseline.

## What "onboarded" means for an ecommerce analytics tool

The critical path every tool in this category walks the user through: **signup (email or platform OAuth) → store connection (Shopify/Woo/BigCommerce) → first data ingest (historical backfill, usually 30 min – 24 hr) → ad platform connections (Meta, Google, TikTok) → pixel/tracking install (server-side or theme snippet) → cost data entry (shipping, COGS, gateway fees) → dashboard with populated metrics**. A tool is "onboarded" when the user sees at least one number they didn't already know on a dashboard. Best-in-class hits this in 2–10 minutes; worst-in-class needs a scheduled call and 30 days before the main features unlock.

The hard part is **the data-syncing gap**. Ad platforms only expose 7 days of data via most OAuth tokens before you have to explicitly re-auth or wait for a full backfill. Historical store imports run async. That leaves a 2-minute-to-24-hour gap where the user has signed up but the dashboard is empty — and if you don't fill that gap with something, churn lives there.

## Structure patterns we see across tools

- **Two-path signup:** email-first (Northbeam, Putler, Klaviyo, Varos, Motion, Atria, RoasMonster) vs Shopify App Store OAuth (Triple Whale, Polar, Lifetimely, Peel, Metorik). Shopify-first tools have a massive onboarding advantage because OAuth already identifies the store.
- **Setup wizard with a progress indicator** is the dominant pattern (Klaviyo's is the textbook example). Usually 4–7 steps. Saves partial state so users can return.
- **Checklist sidebar that persists after the wizard** — Triple Whale, Lifetimely, Shopify native all do this. Tasks move from "setup wizard" into a persistent "Getting Started" panel that follows the user around until they tick every box.
- **"Connect Shopify" is almost always step 1.** Store OAuth is easier than ad OAuth because there's one button and no account-picker.
- **Ad platform OAuth is the biggest drop-off.** Meta OAuth requires the user to select ad accounts, pages, pixels; Google Ads requires an MCC-manager distinction. Tools that walk the user through this (screenshots, video) outperform ones that just show an "Authorize" button.
- **Historical backfill is async.** Every tool warns "this can take 30 min to 24 hours". Best-in-class show a live progress bar; worst show a spinner with no ETA.
- **Empty state is rarely well-designed.** Many tools show a gridded dashboard with "—" where numbers should be. Better tools show sample/demo data with a "this is what yours will look like" banner.
- **Paywall moment varies wildly.** Lifetimely / Metorik start trial at install, no CC. Triple Whale has a free tier with paywall crossings embedded inside the app. Northbeam paywalls the entire signup — can't see the product without a demo.
- **Magic-link vs pasted API keys.** Modern tools universally use OAuth; only the legacy/niche ones still accept API keys (WooCommerce REST via application password is the WP-platform exception — Metorik installs a WordPress plugin to avoid it).
- **"Three personalization questions"** is a recurring pattern (Motion explicitly: describe your needs / share your role / invite team). Helps prefill later screens and is used as a segmentation signal.

## Tool-by-tool breakdown

### Triple Whale
- **How signup starts:** Shopify App Store install is the primary path. Free-tier users can also email-signup on triplewhale.com. App install flows through Shopify OAuth → Triple Whale app home.
- **What the first screen after signup looks like:** Empty Summary Dashboard with widget placeholders. Persistent "Setup Guide" panel on the right with a checklist: install Triple Pixel, connect Facebook, connect Google, set COGS, set shipping costs, enable post-purchase survey.
- **Integration connection order (forced or optional):** Shopify is forced (implicit in App Store install). Triple Pixel install is pushed hard (step 2). Ad platforms are "recommended" but optional — can skip. Cost configuration (COGS / shipping / fees) is optional but explicitly flagged as "data will be wrong until you do this".
- **Time to first useful screen:** Shopify data starts flowing "within minutes" after pixel install per their KB. Meaningful attribution needs ≥24–48 hours of pixel fire. Empty summary cards until then.
- **Empty state while data syncs:** Widgets stay skeleton / zero-state. Some widgets show a "we're still building this" tooltip. No explainer video or sample data shown inline.
- **Paywall moment:** Free tier is indefinitely usable but ~80% of app surfaces are locked (users report hitting upgrade modals constantly, which is a real churn complaint on G2). Triple Pixel-powered attribution is a Starter+ feature.
- **Best onboarding moment:** "The Web Pixel Extension is installed by default when you install the Triple Whale App" — they reduced the theme-snippet install step to zero friction by piggybacking on Shopify's Customer Events. Pixel setup becomes one toggle in Settings.

### Northbeam
- **How signup starts:** Demo call required. No self-serve. After the sales call and contract, user gets an activation email. Day 1 of the onboarding timeline begins with a kickoff email.
- **What the first screen after signup looks like:** Workspace creation → team invite → business info → currency → domain → DNS → pixel install → order integration → channel/UTM setup → spend data. 10 explicit implementation steps from their docs. First app screen is the Overview Home Page with "Attribution Model: Clicks-Only" as the default (because view-through data takes 30 days to model).
- **Integration connection order (forced or optional):** Forced sequence: account → domain → pixel → orders → ad channels → spend. Their docs call this an "implementation guide" not a wizard — it's deliberately heavyweight.
- **Time to first useful screen:** Day 1 after kickoff for basic dashboards. **Day 30 for full attribution data, Day 60 for Apex (their flagship modeling), Day 90 for full feature set.**
- **Empty state while data syncs:** No sample data. Dashboards populate over the 30-day warmup. Users are explicitly told which models will "unlock Day 30, Day 60, Day 90".
- **Paywall moment:** Before signup (demo gate). Starter is $1,500/mo month-to-month; pro/enterprise are annual contracts.
- **Best onboarding moment:** **Phased feature unlock is honest about the data-maturity problem.** Instead of showing empty attribution models on Day 1, they hide them and explain when they'll unlock. This reframes "the data isn't ready" as "you're on the path". Most other tools show empty charts and let the user conclude the product is broken.

### Polar Analytics
- **How signup starts:** Shopify App Store install OR direct email signup on polaranalytics.com, both paths converge into a CSM-led onboarding call for the first connection. Some customers report CSM building their dashboards for them.
- **What the first screen after signup looks like:** "Connectors" section — Shopify configure button is the first thing. Upon first Shopify connection, Polar automatically backfills store history "as far as Shopify allows" (2+ years).
- **Integration connection order (forced or optional):** Shopify forced first (it's the platform the entire app is scoped around). Ad connectors optional but all CSM-guided.
- **Time to first useful screen:** User reports "installation took just minutes, data flowing in from their servers within a few hours".
- **Empty state while data syncs:** Not well-documented publicly, but CSM-led onboarding fills the gap with a scheduled call rather than relying on the UI.
- **Paywall moment:** Demo-gated before signup (price hidden).
- **Best onboarding moment:** **CSM onboarding session is bundled on every plan, including entry-level.** Not a self-serve best-in-class, but a "every customer gets a human" best-in-class — positions Polar as less DIY than Triple Whale. The CSM literally builds custom dashboards for you. For Nexstage this is a non-starter (wrong unit economics) but it informs the value-of-included-onboarding-help framing.

### Lifetimely (Amp)
- **How signup starts:** Shopify App Store install → Lifetimely app opens in Shopify admin embedded iframe.
- **What the first screen after signup looks like:** Setup Guide checklist (visible as a progress indicator). First job kicks off immediately: initial Shopify sync begins pulling historical orders + customers.
- **Integration connection order (forced or optional):** Shopify forced (App Store install). Ad accounts (Meta/Google/TikTok/Bing/Klaviyo/Recharge) connected in any order via "Connect" buttons. Payment gateway fees — Shopify Payments auto-syncs; others (PayPal/Stripe) require manual % entry. Amazon is an optional add-on connection.
- **Time to first useful screen:** Initial sync: **30 min for most stores, up to 24 hr for larger.** Then LTV report generation takes an additional 1–2 hours after sync. So realistic time-to-first-LTV-view is ~2–26 hours.
- **Empty state while data syncs:** Setup Guide shows sync progress (explicit progress bar per their docs). Dashboards aren't hidden but show partial data as orders stream in.
- **Paywall moment:** 14-day free trial on paid tiers; indefinite free tier at 50 orders/mo. No CC required. Trial hits naturally as data fills.
- **Best onboarding moment:** **Explicit sync-progress visibility.** "Most stores complete this within 30 minutes, larger stores up to 24 hours — monitor progress via the Setup Guide." Sets expectations without being alarming, and the user can watch the bar move. Also nice: payment-gateway fees are explicitly called out as a manual-entry step rather than silently defaulting to 0%.

### Peel
- **How signup starts:** Shopify App Store install → Peel app. Application-based — Peel states they target stores with under $1M GMV (small-SMB band) and runs an eligibility form.
- **What the first screen after signup looks like:** "5 easy steps to get onboarded" (per their App Store screenshot caption). Within a day of install, users have "dozens of out-of-the-box reports and visualizations".
- **Integration connection order (forced or optional):** Shopify forced. Subscription apps (Recharge/Bold/etc.) and Amazon are headline integrations. Ad platforms are secondary.
- **Time to first useful screen:** ~24 hours to first actionable reports (per reviews).
- **Empty state while data syncs:** Not well-documented publicly. CSM credits are a tier feature that supplies human help during onboarding.
- **Paywall moment:** 7-day free trial, no CC required. Trial is shorter than the 24 hr sync + data-maturation window — users report this as a real issue.
- **Best onboarding moment:** **"Dozens of ready-made templates and 150+ metrics"** — instead of making users configure dashboards, Peel ships with a template library. Time-to-first-report is measured in clicks, not hours of configuration. Template-first is a pattern we should absolutely steal.

### Metorik
- **How signup starts:** Email signup on metorik.com. Then asks for company name + store URL. Validates WooCommerce version + API accessibility.
- **What the first screen after signup looks like:** Connect-your-store wizard. Prompts to install the "Metorik Helper" WordPress plugin for extra features (plugin is optional; basic connection uses WooCommerce REST API alone).
- **Integration connection order (forced or optional):** Store forced. Metorik Helper plugin strongly nudged but optional. Team member invites prompted as a separate step.
- **Time to first useful screen:** Reviews report "up and going in minutes, not weeks". Historical import cap = 120× monthly order volume (so a 1k-order/mo store gets ~120k historical orders synced).
- **Empty state while data syncs:** Metorik starts importing data on connection; user can navigate the app as data populates.
- **Paywall moment:** 30-day free trial, no CC. Full feature access on every plan (no tier-gated features); trial becomes the only paywall.
- **Best onboarding moment:** **30-day trial + no credit card + full features** is the most generous trial in this set. Combined with Metorik's slider-priced-single-plan, the whole funnel is "install, try everything for a month, pick your volume band". Lowest-friction onboarding in the category for WooCommerce stores.

### Putler
- **How signup starts:** Email signup on putler.com. Immediate dashboard mockup with sample data; user is prompted to "Connect your sources" from within the populated dashboard.
- **What the first screen after signup looks like:** Pre-populated demo dashboard with Putler's sample-store data. "Replace this demo with your data" prompt pins a connector flow.
- **Integration connection order (forced or optional):** All connectors optional and parallel — can connect Stripe first, PayPal second, Shopify third, WooCommerce fourth. Multi-source consolidation is the product pitch. Trial limited to previous 90 days of data from each source.
- **Time to first useful screen:** Instant (demo data) → real data within hours of first connector.
- **Empty state while data syncs:** Never empty — demo data until user data loads, then gracefully swaps.
- **Paywall moment:** 14-day free trial, no CC required.
- **Best onboarding moment:** **Pre-populated demo data from the first screen.** The user sees a dashboard that looks like their future dashboard, with enough detail to explore the UI, before any connection is made. Turns the empty-state problem into a "try before you sync" experience. Strongest pattern to steal in this entire doc.

### Motion (creative analytics)
- **How signup starts:** Email signup → app.motionapp.com. Three personalization questions: describe your needs / share your role / invite team.
- **What the first screen after signup looks like:** Workspace setup with the 3 questions, then a project/dashboard builder with pre-filled stages.
- **Integration connection order (forced or optional):** User connects at least one ad account (Meta strongly preferred as first). Motion then auto-syncs ad data, auto-applies AI tags, and builds initial reports.
- **Time to first useful screen:** "Setup takes less than 60 seconds" per their marketing. Real first screen with data is minutes after Meta OAuth.
- **Empty state while data syncs:** **While data loads, users can explore the "Inspo" (creative research) tool and invite teammates.** Actively redirects the user to a useful surface that doesn't require their own data.
- **Paywall moment:** Personalized onboarding on Pro+ plans; Starter is self-serve.
- **Best onboarding moment:** **"While data loads, explore the inspiration library"** — turns the sync-wait into a product-discovery moment. The inspiration library shows top ads from other brands, which is useful regardless of whether your data has loaded. A template-library-as-waiting-room pattern we should absolutely copy (ours could be: benchmark/industry explorer while data syncs).

### Atria
- **How signup starts:** Email signup → Atria app. 7-day free trial on Core plan, no CC.
- **What the first screen after signup looks like:** Connect ad accounts prompt (Meta/TikTok focus — Atria is creative-analytics centric).
- **Integration connection order (forced or optional):** Ad accounts forced before any analytics unlocks; store connection optional.
- **Time to first useful screen:** Minutes after Meta connect.
- **Empty state while data syncs:** Users can browse the ad research library (25M+ competitor ads) without any connection — like Motion's inspo pattern.
- **Paywall moment:** 7-day trial, then $129–$269/mo depending on Core/Plus. No CC for trial.
- **Best onboarding moment:** **Raya AI strategist** (launched Feb 2026) proactively surfaces insights without being prompted — the "something is happening in your account right now" surface replaces empty-state passivity with AI-generated content the moment data lands.

### Klaviyo (adjacent benchmark)
- **How signup starts:** Email signup at klaviyo.com OR Shopify App Store install.
- **What the first screen after signup looks like:** Getting Started Wizard. Platform detection runs automatically (Shopify/BigCommerce). Prompts collected: list size, business address, sender info (name + from email), email confirmation.
- **Integration connection order (forced or optional):** Platform connection auto-detected; wizard skippable if no platform detected. Sender info is required before you can send any email — enforcing a DKIM/CAN-SPAM gate.
- **Time to first useful screen:** Wizard → completed in 5–10 min → can send test campaign immediately. Branding info (logos, colors, fonts, social links) extracted automatically from the connected ecommerce store to prefill email templates.
- **Empty state while data syncs:** Subscriber sync runs async. Templates and flows can be edited before subscribers finish importing.
- **Paywall moment:** Free plan is indefinite (250 profiles, 500 emails/mo). Limit is enforced by "your ability to send may be restricted" when you cross the threshold.
- **Best onboarding moment:** **Auto-extraction of brand assets from Shopify** — logos, colors, fonts, and social links are pulled and prefilled into the email template editor before the user has done anything. Turns "set up your branding" from a 30-minute chore into a zero-click outcome. We can copy this pattern anywhere we need visual settings (report branding, PDF exports, etc.).

### Shopify native analytics (baseline — what every merchant already has)
- **How signup starts:** Bundled with Shopify signup; no separate install. Reports tab available from day 1 of the Shopify account.
- **What the first screen after signup looks like:** Reports nav item with "Live view" as the default — a real-time map of current sessions and orders. Historical reports below.
- **Integration connection order (forced or optional):** Nothing to connect — Shopify already has the order data. Ad platform data is NOT integrated in native reports.
- **Time to first useful screen:** Instant. Day 1, first sale → shows in Live View + Reports.
- **Empty state while data syncs:** Pre-launch stores see zero-state reports with "Your first sale will appear here" — warm, encouraging empty state.
- **Paywall moment:** Advanced reports gated to Shopify plan $105+. Custom reports gated to $399+ (Advanced plan).
- **Best onboarding moment:** **No onboarding needed — the reports are just there.** Every third-party tool in this list has to justify the extra install step. Our onboarding has to be so smooth that the added friction of installing Nexstage is worth it vs "just use the native reports".

## Patterns to steal for Nexstage

1. **Putler's pre-populated demo data.** First screen after signup should be a fully populated dashboard showing a demo store ("Demo: Acme Coffee Co") with a persistent banner: "This is demo data. Connect your store to see yours." Every surface works; every drill-down works; every filter works — all on fake data. User understands the product immediately. When they connect, demo data fades and their data fills in.
2. **Motion's "explore the library while data syncs".** During the backfill gap (30 min – 24 hr) users should be nudged to explore a surface that doesn't need their data yet — in our case, the Industry Benchmarks view (once we have network data) or the educational "Six Source Badges" explainer page.
3. **Klaviyo's brand-asset auto-extraction.** When we connect Shopify/Woo, we can auto-read the store name, currency, primary country, logo, primary color — and use those to prefill the workspace settings. Removes a 5-field form.
4. **Triple Whale's zero-step pixel install.** Use Shopify's Customer Events (Web Pixel Extension) to install our tracking automatically on OAuth — no theme-editing step, no snippet-paste. Same pattern for WooCommerce via our plugin (Metorik does this; we should too).
5. **Northbeam's "day 1 / day 7 / day 30" transparency.** Instead of showing empty attribution models, label them with "Unlocks after 7 days of data" or "Needs 30 days of spend data — currently at day 3". Makes the wait feel like progress instead of brokenness.
6. **Lifetimely's explicit sync-progress bar.** "Importing orders: 18,420 of 24,100 (76%)" beats a spinner every time. Put it in the Setup Guide panel.
7. **Metorik's 30-day no-CC trial + full features.** Every feature on during trial so the user experiences the whole product. Trial length must be ≥14 days (preferably 30) because the "useful insights" window doesn't open until ad platforms have 7+ days of data synced.
8. **Peel's template library as dashboard starter.** Ship 8–12 pre-built "starter dashboards" (Paid Media Health, Meta ROAS Deep-Dive, SEO Monthly, Checkout Funnel, LTV by Acquisition Source, Product Performance, Inventory Risk, Reactivation Opportunity). User picks one → it's instantiated against their connected data. Configuring from scratch is optional, not required.
9. **A persistent setup checklist sidebar** that survives past the wizard. Items: connect Shopify/Woo, connect Facebook, connect Google Ads, connect Google Search Console, install tracking, set COGS, set shipping costs, invite team. Show completion % as a ring. Don't let it nag; do let it track.
10. **Forced-order wizard for the critical path, optional for the rest.** Step 1: OAuth store. Step 2: Connect at least one ad platform (skippable but clearly "your data will be incomplete"). Step 3: Pixel install (one toggle on Shopify). Everything else is optional.

## Anti-patterns to avoid

1. **Empty dashboards with dash placeholders.** Worst pattern in the category (Triple Whale does this). If data isn't ready, show demo data or a waiting-room surface.
2. **Short trials that end before data matures.** Peel's 7-day trial is legitimately broken — the user doesn't have enough data to judge the product. Don't repeat this.
3. **Requiring a sales call before the product is visible.** Northbeam gets away with this because their ACV is $20k+. At €39/mo we'd just bleed the funnel.
4. **"Connect Facebook" buttons with no walkthrough.** Meta OAuth is a minefield (ad account selection, permission scopes, business-manager distinction). Ship a video or step-by-step screenshots inside the wizard.
5. **Manual WooCommerce API key paste.** Metorik solved this with a WP plugin. We do too. Never ask a non-dev user to paste a consumer key + secret.
6. **Hidden sync-progress.** "Syncing..." with no ETA and no count is worse than "Syncing 18k of 24k orders (76%)". Users tolerate long waits when they can see progress.
7. **Onboarding that ends with an empty "Custom Dashboard" screen.** User gets to step 10 and sees a blank canvas with a "Drag widgets to start" prompt. Wrong. End onboarding on a populated dashboard they didn't have to configure.
8. **Mandatory team invite.** Klaviyo and Motion both nudge it; others force it. Solo founders are a big slice of our ICP — make it skippable.
9. **Asking for credit card upfront.** Every tool in this set that gets it right waives this (only RoasMonster requires a call to start trial; it shows in their conversion metrics).
10. **Paywall modals inside the app before the user has seen value.** Triple Whale's free tier has been described by reviewers as "hitting upgrade walls constantly". Keep upsells to one contextual banner and one settings-page prompt until the user has logged in 3+ times.

## Our onboarding critical path (proposal)

**Signup path:** Dual-path signup. (A) Shopify App Store install flow — OAuth auto-creates the workspace and pre-fills store metadata (name, currency, primary country, logo, color). (B) Email signup on nexstage.com for WooCommerce users — email + password, then prompt to install the Nexstage Woo plugin.

**First screen (within 5 seconds of signup):** A fully populated **demo dashboard** (Acme Coffee Co demo store) showing every MetricCard with all six source badges, one populated Trust view with a fake disagreement, and a persistent `AlertBanner` at top: "This is demo data. Connect your store to see your numbers — takes 30 seconds." Every drill-down, filter, date-range, and breakdown works on the fake data. Setup checklist slides in from the right with 4 items: **Connect store** (required) / **Connect Facebook** (recommended) / **Connect Google Ads + GSC** (recommended) / **Set COGS & shipping costs** (optional, clearly labelled "for profit views").

**Connection flow:** Clicking "Connect store" on the checklist triggers Shopify OAuth inline (overlay, not redirect). Success → workspace prefills, historical sync kicks off async. The demo data fades and is replaced by a skeleton dashboard with a live progress counter: **"Importing orders: 4,200 of 18,400 (23%) — estimated 8 minutes"**. As orders stream in, MetricCards populate top-down (revenue first, then orders, then AOV, then GMV). User can keep working throughout.

**The sync-gap (30 min – 24 hr):** While ad platforms backfill, the dashboard shows real Store data (flowing already) next to ad-platform cards labelled "Syncing — 2h remaining". The empty Real Revenue card reads "Computed once all sources land — ~4h remaining". A secondary CTA nudges the user to the Benchmarks surface (industry benchmarks don't require their data) or the Six Sources explainer page — a product-education moment that uses the wait productively.

**First milestone:** ~10 minutes in, the user sees a real dashboard with real Store data and real Facebook data, three out of six source badges lit. Persistent checklist shows "2 of 4 complete". This is the moment we've delivered value — everything after is expansion and depth, not first-time activation.

**Trial mechanics:** 14-day free trial, no credit card. Paywall lifts on day 15; Nexstage continues working on a free tier bounded by "1 store, Store + Site sources only, 30-day lookback". Any drill-down into Facebook/Google/GSC attribution prompts upgrade inline, never modally — contextual "Connect Facebook to see which ads drove this revenue" CTAs on the locked sources. Checklist never disappears until 100% complete.

**Design principles that fall out of this:** (1) Demo data is a first-class product surface, not a marketing mockup. (2) Sync progress is always visible, always quantified. (3) Every wait has a productive alternative (benchmarks, education, setup checklist). (4) Upsells are contextual, never modal-interruptions. (5) The user should have seen one real number they didn't know within 15 minutes of clicking "Install".
