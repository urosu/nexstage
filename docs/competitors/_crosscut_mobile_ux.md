# Mobile UX — cross-competitor deep dive

Cross-cuts 30+ competitor docs in this folder to answer: **what does mobile look like in ecommerce analytics, and what does Nexstage need to ship?** Most SMB analytics vendors are desktop-first; mobile is usually either a native companion app aimed at the "morning coffee glance" or a responsive web that degrades gracefully. Agency / BI vendors (Polar, Looker, Metabase) largely punt on mobile.

## Mobile support matrix

| Tool | Native iOS | Native Android | Responsive web | Push / notification | Notes |
|---|---|---|---|---|---|
| **Triple Whale** | Yes | Yes | Partial | Push + home-screen widgets (ROAS / Sales / Ads / Pinned) | Marketed as the "mobile companion for the morning check". Reviewers call it buggy ("feels like a clunky web app", crashes). Requires iOS 16.6+. |
| **Shopify (merchant app)** | Yes | Yes | Good | Push on new order, refund, low stock, fulfillment | The reference benchmark for mobile e-commerce. App-badge counter for open orders. Not an *analytics* app but the default "is my store alive" glance. |
| **Shopify Plus Reporting (web)** | No | No | Partial | Email only | Full admin available in Shopify app but advanced reports do not render well on phones. |
| **Klaviyo** | No native analytics app (sends for mobile SDK only) | No | Partial | Email / Slack; mobile-push to *shoppers*, not merchants | Community threads show no dedicated analytics iOS app as of 2026. Dashboards technically load on mobile, tables collapse. |
| **Northbeam** | iOS only (companion) | No | Partial | Email | Dashboards are dense grids, collapse poorly; iOS app is a reduced summary view. |
| **Polar Analytics** | No | No | Partial | Slack + email schedules | Explicit web-first stance. "Mobile is a scheduled digest, not a UI." Custom Tables render on phones but charts squish. |
| **Peel Insights** | No | No | Partial | Slack + email digests | Daily Slack/email insight report is the mobile strategy. |
| **Lifetimely (AMP)** | No | No | Partial | Email only | Scheduled email reports (CSV/XLSX/PDF attachments) substitute for mobile. |
| **Varos** | No | No | Partial | Weekly "Monday Morning Benchmark" email | The digest *is* the product for most users most weeks. |
| **Putler** | Has iOS app (historic) | Has Android | Weak | Limited | Reviewers consistently describe the app as "weak"; users revert to browser shortcuts (see `putler.md`). |
| **Metorik** | No | No | Good | Slack + email digests | Known for fast web UI that works decently on phones; no native app. |
| **Hyros** | No | No | Weak | Email | Desktop BI feel; phone is not a target. |
| **Glew.io** | No | No | Partial | Scheduled email reports (daily/weekly/monthly) | Classic "email is your mobile experience" posture. |
| **Daasity** | No | No | Partial | Email | Warehouse-backed BI; viewing is Looker/Tableau/Sigma, which inherit those tools' mobile. |
| **Rockerbox** | No | No | Weak | Email | Attribution tool, dense tables, not a phone product. |
| **Wicked Reports** | No | No | Weak | Email | Same posture. |
| **Motion** | No | No | Partial | Slack | Creative analytics with video playback; phone rendering of video grid is usable but awkward. |
| **RoasMonster** | No | No | Partial | Email | Lightweight web; works on phone for top-level numbers. |
| **Fairing** | No | No | Good | Email | Post-purchase survey tool — survey editor needs desktop; results table is phone-readable. |
| **Segments Analytics** | No | No | Partial | Email | Shopify-embed tool, inherits Shopify admin chrome on mobile. |
| **Atria** | No | No | Partial | Email | Unknown native apps. |
| **Lifetimely** (already listed) | — | — | — | — | — |
| **Looker Studio** | No native dedicated app | No | Partial | Email (scheduled PDF) | Mobile web is notorious for clipping; agencies use scheduled PDFs as the phone experience. |
| **Metabase** | No | No | Good | Email + Slack subscriptions | Mobile web actually holds up on dashboards; table/card layout is responsive. |
| **Mixpanel** | No dashboard app; has SDK for *your* app | No | Partial | Email | Full product is desktop-first. |
| **Amplitude** | No dashboard app; has SDK for *your* app | No | Partial | Email / Slack alerts | Same. |
| **Mailchimp** | Yes (campaign manager) | Yes | Good | Push | The app is for campaign launch / viewing opens, not deep analytics. |
| **Omnisend** | Yes | Yes | Good | Push | Same posture as Mailchimp — ops app, not analytics. |
| **MonsterInsights** | No | No | Partial | Email | WP plugin; dashboard lives inside WP admin, which itself is responsive-ish. |
| **Shopify native analytics (web)** | via Shopify app | via Shopify app | Good at glance, weak at depth | Push on order | See Shopify row — merchant app surfaces summary metrics. |
| **BigCommerce Analytics** | No | No | Partial | Email | Admin-embedded; phone layout collapses. |
| **TikTok Shop / Instagram Shopping / Shopify Plus Reporting** | Viewed inside their parent native apps | Same | Good | Push inside parent app | Not standalone. |
| **Elevar** | No | No | Weak | Email | Tagging / server-side tool — config UI requires desktop. |
| **Lifetimely** (listed) | — | — | — | — | — |

### Summary

Only Triple Whale and Shopify ship a real native analytics-capable app. Northbeam has a partial iOS companion. Mailchimp/Omnisend have mobile apps but they are campaign-ops, not analytics. **Every other tool in this set treats email or Slack as the mobile experience** and lets the web responsive behavior fall wherever it falls.

## Native app analysis

### Triple Whale

- **Platforms:** iOS and Android. Current iOS app requires iOS 16.6+ (App Store listing). App ID `1511861727`.
- **App Store rating:** Mixed public sentiment — App Store reviews swing from "absolutely essential" to "unusable in its current state" with complaints about freezing, infinite loading spinner, and crashes at the ad level.
- **Core use case on mobile:** The pitch is "check on the go" — founders glancing at morning revenue / spend / ROAS / MER while commuting. Triple Whale's own blog calls the Summary Dashboard "built for the morning coffee check" and the mobile app is the extension of that.
- **Screens present:** Summary dashboard (MER, ncROAS, POAS, sessions, revenue, spend), Customer Insights, Cohorts, 60/90-day LTVs, Pinned metrics.
- **Screens absent:** Creative Cockpit (creative gallery does not work at phone aspect), SQL editor, drag-drop widget-grid customization (readonly on mobile), Model Comparison, Benchmarks dashboard.
- **Push notifications:** Configurable alerts on metrics (ROAS drop, spend spike); frequency and threshold user-settable. Not fully described in public docs.
- **Unique-to-mobile features:** **Home-screen widgets** for ROAS / Sales / Ads / Pinned metric, configurable in under 30 seconds per their marketing. This is the single most-copied pattern from Triple Whale that no one else has replicated cleanly.
- **UX quality (from reviews):** Reviewers call it buggy: "freezes and glitches endlessly", "crashes on open", dropdown layering issues, slow loads. The `triple-whale.md` competitor doc explicitly lists it as a weakness: *"iOS/Android apps feel like a clunky web app, crash on open, layering issues with dropdowns, slow data loads."*
- **Screenshot URLs:** `https://apps.apple.com/us/app/triplewhale/id1511861727`

### Shopify (merchant mobile app)

- **Platforms:** iOS and Android, continuously updated. Tens of millions of installs across both stores.
- **Core use case on mobile:** Order ops — get notified of a new order, fulfill, process refunds, check today's sales. The `shopify-native.md` doc flags that the Shopify app is the *default* "is my store alive" experience that every analytics competitor is measured against.
- **Screens present:** Today's sales, live-view visitor map, orders list + order detail, products, customer list, basic reports (online store sessions, top products), discounts, POS receipts.
- **Screens absent:** Custom reports (Advanced plan), Shopify Plus enterprise reports, ShopifyQL notebook, UTM attribution depth.
- **Push notifications:** New order (with sound), new draft order, refund, fulfillment status change, low stock, staff mention. App-badge counter for unread orders — this is sticky.
- **Unique-to-mobile features:** Fulfill-from-phone workflow (print label, scan barcode via camera on some integrations), "Live view" map of active sessions with pins, one-tap "charge via Shopify Payments" from phone.
- **UX quality:** Generally high ratings; complaints tend to be about push-notification reliability on specific carrier networks, not the app itself.

### Klaviyo

- **Platforms:** Klaviyo offers mobile *push notifications to shoppers* as a Klaviyo Marketing channel, but for *merchants* there is no mature native iOS analytics app. Klaviyo community threads surface recurring "is there a Klaviyo iOS app" questions that never resolve with a first-party answer.
- **Mobile stance:** Responsive web only. The `klaviyo.md` doc describes the analytics surface (Conversion Summary, Campaign Performance, Flows Performance, etc.) but no mobile-specific treatment.
- **Push notifications:** Outbound-to-customers only (email/SMS/push marketing channel). Not merchant alerts.

### Northbeam

- **Platforms:** iOS companion only. No deep public reviews of usage.
- **Core use case on mobile:** Glance at today's attribution numbers, last-30-days MER/ROAS, ad-set rollups.
- **Screens absent:** Full model-switching UI, MMM+ configuration, cohort heatmaps, SQL-like custom report builder.
- **Push:** Email only per public docs.

### Putler

- **Platforms:** Has iOS and Android apps historically, but the `putler.md` doc documents "users resort to website shortcuts on phones" — i.e. the app is regarded as worse than mobile web.
- **UX quality:** Reviewers describe as "weak". Classic case of native-app-as-checkbox.

### Mailchimp / Omnisend (mentioned as reference)

- **Platforms:** Both have well-rated iOS and Android apps, but these are *campaign management* apps (send a campaign, see opens, reply to inbox). They are the model for what "analytics adjacency" looks like in mobile ops — not a template for a pure analytics phone app.

### Lifetimely / Peel / Polar / Varos / Metorik

- **Zero native apps.** All four explicitly use email or Slack digests as the mobile substitute. Varos is on record in `varos.md` treating the Monday Morning Benchmark email as the primary weekly touchpoint.

## Responsive web analysis

### What works on mobile web across the category

- **Single-metric cards** (MER, ROAS, revenue today) render fine at phone width — one per row, tap to drill.
- **Line charts** collapse acceptably if the y-axis labels rotate or hide.
- **Lists of orders / customers** work — the Shopify admin pattern is well-established.
- **Text-heavy digest / insight feeds** (Peel Magic Insights, Putler live activity feed) render almost without modification.

### What breaks on mobile web across the category

- **Cohort heatmaps** (Lifetimely, Peel, Polar) — the 12×12 grid is unreadable at 375px.
- **Breakdown tables with 8+ columns** (Triple Whale Summary, Polar Custom Tables, Northbeam campaign grids) — horizontal scroll is tolerated but sorting + filtering UI is awkward.
- **Side-by-side attribution comparison** (Polar six-source view, Nexstage's planned MetricCard six-source badges) — requires a width the phone doesn't have. Needs a dedicated mobile treatment (badge stack rather than row).
- **SQL editors** (Triple Whale, Metabase question builder) — unusable on phones.
- **Drag-drop dashboard composition** (Lifetimely, Peel, Polar) — unusable.
- **Creative galleries with ad previews** (Triple Whale Creative Cockpit, Motion) — video playback works, but the grid becomes a single column that loses the "compare creatives" purpose.
- **Date-range pickers** — calendar popups are the #1 source of broken layouts; most tools degrade to "text input" on mobile.
- **Navigation** — apps with 8-12 top-level sections (Peel, Polar, Putler) require a hamburger; hamburger fatigue is real in the category.

### Tool-by-tool notes

- **Metabase:** Surprisingly good. Dashboard cards stack cleanly. Metabase reportedly designed cards with responsive breakpoints in mind. The question-builder UI struggles.
- **Looker Studio:** Notoriously bad on mobile. Reports are designed at fixed pixel dimensions; phones get a scaled screenshot. Workaround is to build a "Mobile" report layout in addition to the desktop one.
- **Metorik:** Fast and usable on phones, explicitly called out in its marketing as "super fast, intuitive". No native app — responsive web is the whole story.
- **Peel:** Dashboards work at phone width but "double-click to drill" doesn't map to touch gracefully; users report long-press confusion.
- **Polar:** Custom Tables render; chart heights clip. Saved reports function as read-only views on mobile.
- **Lifetimely:** Profit & Loss statement renders acceptably because it is a vertical table; cohort pages clip.
- **Shopify Plus Reporting:** Web version is passable; advanced analyst workflow is desktop-only.

## Patterns worth stealing

- **Home-screen widget for the single most-watched metric** — Triple Whale. Tap the widget → opens the app / web to the relevant page. Our analogue: "Real Revenue today" as a widget. The installation moment is <30s and it is the one mobile feature that genuinely changes user behavior.
- **Push on anomaly, not on schedule** — Triple Whale, Polar (when anomaly alerts enabled). Daily "revenue = X" push is noise; "your Google Ads ROAS dropped 32% in the last 6h" is signal. Threshold-based push beats calendar-based push.
- **Daily/weekly digest email as the mobile UI** — Varos Monday Morning, Peel Daily Insight, Polar custom schedules, Lifetimely scheduled email. Reviewers repeatedly cite this as the single most-used "feature" because it's the only time the data enters their life. If Nexstage wants one mobile surface in v1, this is it.
- **Slack as first-class mobile output** — Polar, Peel, Metorik, Metabase. Founders and agency users are already on phone-Slack; dropping a table into #ecom-standup is cheaper than building a phone UI.
- **Shopify-admin-style app-badge counter** — unread order count, unread alert count. Ritualizes opening the app.
- **Single-source-of-truth "today" screen** — Putler's Pulse (live activity feed + top KPIs) maps well to phone. Chronological streams render natively.
- **Responsive card stack over responsive table** — Metabase and Shopify both use card stacks on mobile where desktop shows a table. Preserves information hierarchy without horizontal scroll.

## Anti-patterns

- **Web-view-wrapper native app** — Triple Whale. Users punish it with 1-star reviews because the mobile app is worse than mobile web while asking for install friction. The `triple-whale.md` doc explicitly warns: *"Don't ship a mobile app that's a web-view wrapper. If the native app is worse than the desktop site it damages trust across the entire brand."*
- **Mobile app that requires login every session** (Putler-style complaints) — breaks the "glance" use case.
- **Cohort heatmap as the landing screen on mobile** (Lifetimely risk) — first render is unreadable.
- **Calendar-popup date range picker with no mobile alternative** — most tools in the set.
- **Relying on hover for tooltip text** (Klaviyo chart tooltips, Peel annotations). Touch = no hover. Tap-to-toggle tooltips or always-visible captions are the fix.
- **Scheduled email that attaches a PDF instead of rendering inline HTML** (Looker Studio default, Swydo, some Whatagraph templates) — opening a PDF on a phone is high-friction. Recipients don't.
- **Password-gated share link with no mobile-friendly auth** (Looker Studio, some Metabase setups) — 2FA prompt on a tiny screen is unpleasant. Users prefer "knows my email" over "remembers a password".
- **Native app that duplicates a feature but in a degraded form** (Triple Whale Creative Cockpit attempt on mobile) — better to omit than to ship a worse version of the desktop affordance.

## Proposed Nexstage mobile stance

Given we are desktop-first with mobile-second, pre-launch, and SMBs + agencies are the ICPs:

### 1. What's acceptable for v1

**Responsive web only.** No native app, no PWA yet. Budget goes into making three pages work well at 375×812:

- Dashboard (home) — a phone-legible view of top-of-page KPIs with source-badge compression (see §4).
- Alerts / Notifications center — so when a Slack/email push says "your ROAS dropped", the tap-through lands somewhere usable.
- Orders — list view + order detail. Shopify merchants already expect this to work on phones and will judge us against the Shopify merchant app.

Everything else: render on mobile but do not *optimize*. Cohort heatmaps, BreakdownView tables, creative galleries, attribution side-by-side, SQL editor (when we have one) — desktop-only is fine if the page explicitly says "best viewed on desktop" with a "send to my email" escape hatch.

### 2. Which screens must work on mobile

**Must work (responsive, tested at 375px and 414px):**

1. `/dashboard` — top KPI cards stack vertically; source-badge row collapses to a single "see sources" chip that expands to show Store / Facebook / Google / GSC / Site / Real.
2. `/orders` — list with compact row, order-detail tap target, "refund" and "mark fulfilled" only if we ship those later (currently read-only).
3. `/alerts` — feed of threshold-crossed events (Not Tracked delta > X%, ROAS drop, Search Console errors). This is also the landing page for push/Slack/email tap-throughs.
4. `/stores/:id/overview` — high-level per-store snapshot (for multi-store / agency users who need to flip between clients on the go).

**Should work (responsive but not optimized):**

- Integrations status (on/off per platform) — founders want to confirm Facebook re-auth worked on their phone after acting on an email alert.
- Settings / billing — rare but must not break.

### 3. Which screens should NOT bother

Explicit "desktop required" or gracefully degraded with a "open on desktop" CTA:

- Cohort heatmaps (retention × acquisition month × product segment).
- BreakdownView tables with >4 columns — collapse to "top 5 by revenue, tap to expand".
- Campaign attribution side-by-side (six sources in one row is unreadable — ship a mobile-specific stacked "source comparison" card instead).
- Creative Cockpit-style galleries (we don't have one yet; if we build one, it's desktop-only).
- Custom report builder (when we build it).
- Discrepancy drill-downs with raw JSON payloads.
- SEO/GSC URL-level breakdown tables.

### 4. Push / notification strategy

**Order of priority, cheapest first:**

1. **Email digest (v1).** Weekly "Real vs Platform vs Store" summary per workspace, configurable frequency (daily/weekly). Inline HTML, not PDF. Links deep into specific Nexstage pages. This is the Varos / Peel / Polar consensus pattern and it is the highest-ROI mobile feature for the lowest engineering cost.
2. **Slack digest (v1 or early v2).** Polar's pattern: tables go to Slack, charts stay on web. Ship a Slack app, let a workspace owner connect a channel, send the same weekly digest (plus on-demand "send current view to Slack" from the Dashboard).
3. **Threshold-based alerts via email + Slack (v2).** "Your Not Tracked delta crossed ±15% vs 30d avg" / "Facebook over-reporting by >20%" / "Google Ads spend +50% DoD". Never scheduled, always anomaly-driven. Channels: email + Slack; optionally the in-app `/alerts` feed.
4. **Native push via PWA (v3 at earliest).** Only if we see real engagement on the responsive web. Do not build a native iOS/Android app pre-product-market-fit. Triple Whale's buggy-app penalty is the cautionary tale.
5. **SMS / Teams (no).** SMB founders don't want SMS analytics pings; Teams is enterprise and not our ICP.

**Concrete early alert triggers Nexstage should ship first** (mapped to trust thesis):

- "Real Revenue disagrees with Shopify/WooCommerce store total by >X%" — the trust-thesis alert.
- "Facebook-reported conversions exceed Store-observed by >Y%" — over-attribution flag.
- "Google Ads spend DoD change > Z%" — spend-spike guard.
- "GSC clicks dropped >20% WoW and CTR unchanged" — impressions-loss signal.

### 5. Desktop-bias copy on phone

When a user opens a desktop-only page on phone, show a friendly banner: *"This view is built for desktop. Want us to email this to you instead?"* — one-tap sends the current workspace + date-range snapshot as HTML email. Converts a broken experience into a retention event. None of the competitors surveyed do this cleanly.

### Anti-scope (things we explicitly do NOT do at v1)

- No native iOS app (Triple Whale shipped one badly, we'd ship one worse).
- No home-screen widgets (requires native; revisit when PWA widgets land broadly).
- No in-app chat / support on mobile web — Intercom-style chat dominates the phone viewport.
- No mobile-only features that aren't on desktop. Mobile is a reduced surface, not a divergent one.

Sources pulled from this repo:

- `/home/uros/projects/nexstage/docs/competitors/triple-whale.md`
- `/home/uros/projects/nexstage/docs/competitors/shopify-native.md`
- `/home/uros/projects/nexstage/docs/competitors/klaviyo.md`
- `/home/uros/projects/nexstage/docs/competitors/putler.md`
- `/home/uros/projects/nexstage/docs/competitors/polar-analytics.md`
- `/home/uros/projects/nexstage/docs/competitors/peel-insights.md`
- `/home/uros/projects/nexstage/docs/competitors/lifetimely.md`
- `/home/uros/projects/nexstage/docs/competitors/varos.md`
- `/home/uros/projects/nexstage/docs/competitors/metorik.md`
- `/home/uros/projects/nexstage/docs/competitors/northbeam.md`

External sources:

- Triple Whale App Store listing (`apps.apple.com/us/app/triplewhale/id1511861727`)
- Triple Whale Help Center: "Does Triple Whale have a mobile app?" and "Free Plan Mobile Apps"
- Triple Whale blog: "Best Free Comprehensive Data Platform With a Mobile App"
- Shopify Help Center: "Using the Shopify app for iPhone and Android"
- Klaviyo Community: "Is there a Klaviyo iOS app?"
- Peel Insights Help Center: "Scheduling Dashboards via Email and Slack"
- Polar Analytics Help Center: "Understanding Schedules"
- Metorik: Digests & Reports marketing pages
- Varos (`varos.md`) Monday Morning Benchmark email pattern
