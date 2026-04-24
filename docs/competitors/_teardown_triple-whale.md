# Triple Whale — UI teardown

**Scope:** screen-by-screen deep dive. Companion to [`triple-whale.md`](triple-whale.md). Reference from page specs in `/docs/pages/`.

Sources: `kb.triplewhale.com` help center articles, triplewhale.com product pages (Summary Dashboard, Creative Cockpit, Moby AI, Lighthouse, Pixel), Head West Guide Triple Whale review, YouTube walkthrough transcripts, Practical Ecommerce coverage of Moby Agents, and the Triple Whale Shopify App Store listing. Everything here is synthesised from written walkthroughs, help-doc captions, marketing copy and demo transcripts — no images were interpreted directly.

## Global chrome

### Top bar
- **Logo placement:** Top-left corner. Clicking returns to Summary. Triple Whale wordmark with whale glyph; on dark theme the wordmark is white, on light theme it's navy.
- **Workspace/store switcher:** Top-left adjacent to the logo. A dropdown showing the current Shopify store name and favicon. For multi-store accounts it opens a searchable list with an "All Stores" blended view pinned at the top — blended view is the key selling point for agencies. Agencies see shop-group folders above individual shops.
- **Global date range:** Top-right area of the main canvas (not the chrome). Presets are: Today / Yesterday / Last 7 / Last 14 / Last 30 / MTD / QTD / YTD / Custom. A second dropdown picks comparison: "vs previous period" / "vs prior year same period" / "no comparison". Date picker is a calendar popover with two-month view side-by-side.
- **Global filters:** Data Platform Filter — a chip-row under the top bar letting the user scope every tile to country, channel, product, campaign, or utm_source. Shows as pill-shaped removable chips. Applies across the whole dashboard, not per-tile.
- **User menu, notifications, help:** Top-right cluster. Order is: search icon → notifications bell (Lighthouse anomaly count badge) → help (?) icon → Moby chat toggle → user avatar. User avatar opens Profile / Billing / Team / Logout.
- **Command palette / search:** `Cmd+K` opens a global search across dashboards, metrics, reports, SKUs, customers, and orders. Search results are grouped by type.

### Sidebar
- **Sections (in order, top to bottom):**
  1. **Summary** (home icon) — the default dashboard
  2. **Pixel** (eye/radar icon) — Attribution section (sub-items: Attribution All, Live Orders, Customer Journeys, Channel Overlap, Sonar, Pixel Settings)
  3. **Creative Cockpit** / Creative Analysis
  4. **Dashboards** — expandable list of custom/preset dashboards (75+ templates ship by default; "My Dashboards" and "Shared Dashboards" folders)
  5. **Lighthouse** — anomalies, activities, correlations
  6. **LTV & Cohorts**
  7. **Benchmarks** — peer comparison
  8. **Moby Agents** — chat + agent runs
  9. **Reports** — scheduled email reports
  10. **Integrations** (plug icon, near bottom)
  11. **Settings** (gear icon, bottom)
- **Collapsible?:** Yes — an arrow at the bottom of the sidebar collapses it to an icon rail. When collapsed, labels only show on hover.
- **Active state visual:** Indigo/teal vertical bar on the left edge of the active item + light tint of the row background. Icon and label switch from grey to full-colour brand blue.
- **Nesting depth:** Up to 2 levels. Pixel → Attribution All is level 2. Dashboards → individual dashboard is level 2.

### Color / typography / density
- **Primary accent color:** Deep teal / cyan (`#00b4a6`-ish) for CTAs, selected states, and positive deltas. Gold / amber for Moby and AI features (gold lightbulb icon). Negative deltas in red.
- **Chart palette:** Channel-coded — Meta/Facebook is deep blue, Google Ads is Google red, TikTok is black/magenta, Snapchat yellow, Pinterest red-orange, Klaviyo green. Consistent across every chart so a glance at colour = a glance at channel.
- **Font family, number formatting:** Sans-serif (looks like Inter / SF Pro). Numbers are tabular-figure so columns of $$ align. Large hero numbers use a slightly condensed display weight. Currency formats follow store setting; automatically abbreviates $1,234,567 → $1.23M on tiles, full precision on hover.
- **Approximate density:** 15–25 widgets above the fold at 1440×900 on the default Summary layout. Tiles are small (roughly 180×110px per tile), which is why density reads as "high, almost cluttered" in reviews.

---

## Screen: Summary Dashboard (home)

- **URL path:** `/summary` (first page after login).
- **Layout grid:** 12-column responsive flex grid. Default rows: (1) Pinned row — user's favourites. (2) Store section — Shopify metrics. (3) Meta section. (4) Google section. (5) TikTok / other channels. (6) Klaviyo. (7) Web Analytics. (8) Custom Expenses. Sections are collapsible with a chevron.
- **Above-the-fold elements (top-left → bottom-right):**
  1. Top-bar chrome + date range + comparison toggle
  2. "Pinned" section header + row of user-pinned tiles (variable; empty by default)
  3. **Store Metrics** section header with channel icon and "15m ago" refresh timestamp
  4. Tile: Net Sales — $ value, MoM delta arrow, sparkline
  5. Tile: Gross Sales — same shape
  6. Tile: Orders — integer, delta, sparkline
  7. Tile: Sessions — from Shopify analytics
  8. Tile: AOV — shows as three stacked numbers (mean / median / mode) labelled
  9. Tile: CVR — % with 2 decimals
  10. Tile: Refunds — negative styling
  11. Tile: COGS (if set up) — else placeholder with "Set up COGS" CTA
  12. "Meta" section header with Facebook icon
  13. Tile: Meta Spend
  14. Tile: Meta Purchases
  15. Tile: Meta ROAS
  16. Tile: Meta CPA
  17. "Google" section header
  18. Tiles mirroring Meta: Spend / Purchases / ROAS / CPA
- **Below-the-fold (scroll):**
  - TikTok section (same shape)
  - Snapchat / Pinterest / AppLovin (appears only if connected)
  - Klaviyo section — Email Revenue, Attributed Orders, Flow Revenue, Campaign Revenue
  - Web Analytics section — Top Pages, Top Referrers
  - Custom Expenses section — user-defined fixed-cost subtractions (used for profit)
  - Profit block — Net Profit, Profit Margin, Contribution Margin (paywalled on higher tier)
- **Interactions:**
  - Hover on tile: tile elevates (shadow), sparkline becomes interactive (point-hover tooltip shows exact daily value), a "📌" icon appears top-right of tile.
  - Click on tile: opens a full-page drill with a large chart + breakdown tables below. URL changes to `/summary/metric/<slug>`.
  - Click on chart: crosshair with value label at that day.
  - Right-click on tile: context menu — "Pin" / "Remove from dashboard" / "Open in Metrics" / "Copy link".
  - Drag tile: can reorder within a section; drag by header reorders sections.
  - Each tile header has a three-dot menu for "Change metric" / "Change comparison" / "Hide".
- **Empty state:** When a store just connects, tiles show skeletons with "Syncing historical data (~5 min remaining)" strips. When a channel is not connected, the section shows a dashed-border placeholder with a channel logo and "Connect Meta to see this data" button.
- **Loading state:** Tiles show animated grey bars (shimmer); sparkline region shows a pulsing placeholder line. The whole dashboard loads progressively section-by-section; Store Metrics paints first, ad channels lag 1–2s.
- **Screenshot URLs:**
  - `https://cdn.prod.website-files.com/61bcbae3ae2e8ee49aa790b0/67f045e914cd9f0d6d7413ac_5db6f838b80b0190ede7974f216e0a70_Summary%20Dashboard%20Blended%20Metrics.webp`
  - `https://cdn.prod.website-files.com/61bcbae3ae2e8ee49aa790b0/69407c5f598e31bb269cbdc4_Hero%20_image_desktop.png`
  - `https://cdn.prod.website-files.com/63ed1d46df8ff31dcbd86b98/6622b25cb540d271a84afe6d_Triple%20Whale%20Home%20Page.JPG`
  - `https://cdn.prod.website-files.com/63ed1d46df8ff31dcbd86b98/6622b67ff58546af2d1fc6be_Triple%20Whale%20Founders%20Dash.jpg`

---

## Screen: Pixel / Attribution All

- **URL path:** `/attribution` or `/pixel/attribution-all`.
- **Layout grid:** Two-row header (controls) + a giant flat table below.
- **Header row 1 — model & window controls:**
  - Attribution Model dropdown: "Triple Attribution" (default MTA), "Last Click", "First Click", "Linear", "Last Platform Click", "Total Impact" (pixel + post-purchase survey blend), "Clicks & Deterministic Views (beta)". Each option has a short tooltip description.
  - Attribution Window chips: `1d / 7d / 28d` pill toggle; only applies to applicable models.
  - "New Customers Only" toggle — hides returning-customer revenue.
  - "Include Klaviyo email" checkbox.
- **Header row 2 — grouping & breakdowns:**
  - Breakdown level: Channel / Campaign / Adset / Ad — segmented pill control.
  - "Breakdown by" dropdown: country, device, gender, age, product.
  - Column settings gear — opens modal to toggle visible metrics.
  - CSV export icon; share-link icon.
- **Table:**
  - Left-locked first column: channel/campaign/adset/ad name with platform favicon
  - Sortable columns: Spend, Impressions, Clicks, CTR, CPM, CPC, Purchases, Revenue, ROAS, CPA, NC-Purchases, NC-Revenue, NC-ROAS, NC-CPA
  - Each numeric cell right-aligned with tabular figures; small deltas in superscript coloured green/red
  - Row expansion chevron — clicking drills down one hierarchy level inline (campaign opens its adsets in-place)
  - Sticky header on scroll; sticky first column on horizontal scroll
- **Model Comparison view:** Toggle "Compare Models" at top-right switches the table so each row repeats under multiple attribution models side-by-side with a delta column. Closest thing in market to Nexstage's "disagreement" view but less opinionated.
- **Interactions:**
  - Hover a row: reveals "View Customer Journeys" icon linking to Customer Journeys filtered to that campaign
  - Click a campaign name: navigates to that campaign's detail page with a breakdown below
  - Right-click row: "Compare across models" / "Send to creative cockpit" / "Download"
  - "Processing" pill next to today's/yesterday's numbers indicates pixel backfill still running
- **Empty state:** "Your Triple Pixel hasn't collected attribution data yet. Install the pixel on your Shopify store (<step-by-step CTA>) and see data within 24 hours."
- **Loading state:** Skeleton rows with pulsing bars; controls remain interactive so users can tweak attribution model while data loads.
- **Screenshot URLs:**
  - `https://cdn.prod.website-files.com/63ed1d46df8ff31dcbd86b98/6622b6dc0fe3a2f24cc0c752_Triple%20Whale%20Triple%20Pixel.png`
  - `https://cdn.prod.website-files.com/61bcbae3ae2e8ee49aa790b0/67ed4917b423c93557c80875_Product%20-1.webp`

---

## Screen: Pixel / Customer Journeys

- **URL path:** `/pixel/journeys`.
- **Layout:** Left column — filter sidebar (date, channel, product, new vs returning). Main canvas — list of customer journey cards.
- **Each journey card shows:**
  - Customer initial + masked email (`b***@gmail.com`)
  - Order value (e.g. $184.50) + purchase date/time
  - Horizontal timeline from first touch to purchase: each touchpoint is an icon (platform colour) with campaign/adset name beneath, connected by a left-to-right arrow
  - Number of touchpoints, days to convert
  - Expand chevron to show full touchpoint list in a table (time, platform, campaign, adset, ad, UTM, landing page)
- **Interactions:**
  - Click a card to open full-page journey with full timeline
  - Filter to customers who touched 2+ channels; sort by revenue, touchpoints, time-to-convert
- **Screenshot URLs:** None publicly linked but referenced in kb article `5960333-understanding-and-utilizing-attribution-models`.

---

## Screen: Pixel / Live Orders

- **URL path:** `/pixel/live-orders`.
- **Layout:** A chronological stream (newest top) of orders-as-they-happen. Auto-refreshes every ~5 s with a subtle row slide-in animation.
- **Per row:** Order ID, time (relative: "12s ago"), customer first initial, channel attribution pill (Meta / Google / etc.), UTM, revenue, total touchpoints.
- **Interactions:** Clicking a row opens that customer's journey in a side sheet; "Pause stream" toggle freezes updates so the user can read a specific order.

---

## Screen: Creative Cockpit / Creative Analysis

- **URL path:** `/creative-cockpit`.
- **Layout grid:**
  - Top strip: Creative Highlights — 6–10 "trophy" cards for "Top by Spend", "Top by ROAS", "Top by CTR", "Top by Thumb-Stop Ratio", etc. Each trophy card shows a thumbnail + metric + metric value.
  - Main canvas: gallery view (cards) or table view — toggle top-right.
  - In gallery view: rows of creative cards, each ~320×280 px. Thumbnail on the left (image or video with play-on-hover), metrics panel on the right.
- **Per creative card metrics (stacked):**
  - Spend
  - Impressions, CPM
  - Clicks, CTR, CPC
  - **Thumb-Stop Ratio** (3-second view / impressions — their signature metric)
  - Hold Rate (% of viewers who watch to end)
  - Purchases, Revenue, ROAS, AOV, CAC
  - A small badge showing Triple Pixel enrichment applied
- **Controls:**
  - Group-by dropdown: Individual Ad / Naming Convention / Creative Type / Ad ID / Segment
  - Platform filter (all / Meta / Google / TikTok / X)
  - Date range
  - Sort: Spend / ROAS / CTR / Thumb-Stop / custom metric
  - "Compare up to 6" checkbox beside cards — opens a side-by-side compare modal with overlaid line charts
- **Segments panel (right side drawer):**
  - User-defined clusters (e.g. "UGC Videos", "Static Product Shots"). Shows aggregate metrics per cluster and creatives inside each.
- **Interactions:**
  - Hover over a video thumbnail: it plays muted; click for full-screen with soundtrack
  - Click metric value: opens time-series chart for that creative on that metric
  - "Chat with Moby" inline button on each card — opens Moby modal with creative-specific context. If Moby Pro, "Regenerate ad" produces a new variant.
- **Empty state:** "Connect Meta to see your creatives. Thumb-Stop Ratio requires Triple Pixel."
- **Loading state:** Skeleton cards with shimmer thumbnail area.
- **Screenshot URLs:**
  - `https://cdn.prod.website-files.com/63ed1d46df8ff31dcbd86b98/6622b725255b93e73207377a_Triple%20Whale%20Creative%20Cockpit.png`

---

## Screen: Lighthouse (anomalies + activities + correlations)

- **URL path:** `/lighthouse`.
- **Layout:** Three tabs — Anomalies / Activities / Correlations.
- **Anomalies tab:**
  - A chronological feed of cards. Each card: severity chip (Critical / Warning / Info), metric name, baseline vs actual ("ROAS dropped 42% vs 7-day avg"), an inline sparkline with the anomaly point highlighted in red, and a "Possible causes" collapsible section referencing correlated changes.
  - Actions per card: Acknowledge / Snooze / "Ask Moby".
- **Activities tab:**
  - Timeline of all connected-platform changes: new campaigns, budget changes, creative swaps, Shopify product edits, price changes, Klaviyo flow launches.
  - Each activity row: icon + platform + "what changed" + timestamp + user (if known).
  - Filter by platform and activity type.
- **Correlations tab:**
  - A matrix or grid showing Pearson-style correlations between metrics — which metrics move together. Not as statistically rigorous as Northbeam's Metrics Explorer; more of a quick-look.
- **Notifications:** Anomalies push to mobile app, desktop browser, Slack, and email — configured in Settings > Notifications.

---

## Screen: Moby Chat (AI sidebar)

- **URL path:** Global overlay, triggered from the top-bar Moby toggle or `Cmd+J`.
- **Layout:** Right-docked panel, ~420 px wide. Can also open full-screen at `/moby`.
- **Elements:**
  - Top bar with "Moby" title, gold lightbulb icon, close X, and a "New Chat" + button.
  - Context chip showing what Moby knows about the current page ("Viewing: Summary Dashboard, Nov 1–30")
  - Scrollable message history
  - Bottom input: multi-line text with "Send", file-attach icon, voice-input mic, and a suggested-prompts chip row
- **Response rendering:** Moby returns a mix of text, inline charts (rendered from same components as dashboard tiles), data tables, and action buttons ("Apply this budget reallocation to Meta").
- **Moby Agents:** Separate tab/surface listing predefined agents ("Creative Briefer", "Budget Reallocator", "Inventory Watcher", "Audience Builder"). Each agent card: name, icon, description, "Run" button. Agent runs appear as async jobs with progress.
- **Credits meter:** Top-right of Moby panel shows remaining AI credits; clicking opens a billing modal.
- **Screenshot URLs:**
  - `67ed3b04bbf9896e2f61dfa2_Moby%20agents.webp`
  - `67ed3b528c78fbdc089d8965_Moby%20chat.webp`

---

## Screen: Custom Dashboards / Dashboard Builder

- **URL path:** `/dashboards/<id>` for a specific dashboard, `/dashboards/new` for builder.
- **Layout:** Canvas grid with drop zones (12-col), left drawer of widget types, right drawer of widget properties.
- **Widget types available:**
  - Metric Tile (single value + sparkline)
  - Time-series chart (line / area / bar)
  - Breakdown table
  - Pie / donut (channel share)
  - Funnel
  - Cohort heatmap
  - SQL widget (write SQL, visualise result)
  - Text / Markdown block (headings + notes)
  - "Moby Chat" embedded widget (asks a question, pins the answer)
- **Builder flow:**
  - Click "+ Add widget" → drawer slides out from left → select widget type → configure (metric picker, dimension picker, filter, date override) → click "Add"
  - Drag widgets to reorder; resize with corner handle (snap to grid)
  - "Share" button opens modal with team-member picker, view-only/edit toggle, and a public-link option
- **75+ ready-made dashboard templates** accessible from a "Template Gallery" tile when creating a new dashboard. Templates cover: Subscription, Inventory, Profit, Agency Overview, Founders, etc.

---

## Screen: LTV & Cohorts

- **URL path:** `/ltv` or `/cohorts`.
- **Layout:** Top controls (date range, cohort size = daily/weekly/monthly, acquisition source splitter). Main area — cohort heatmap table.
- **Cohort heatmap:**
  - Rows = acquisition cohort (e.g. "Nov 2025"). Columns = LTV at Day 30 / 60 / 90 / 180 / 365.
  - Cells shaded in a gradient (low = pale, high = deep teal). Each cell shows $ value; hover shows cohort size.
  - Row total on the right, column total at the bottom.
- **Acquisition Source split:** Rows can be grouped by first-touch channel (Meta / Google / Direct / Email).
- **Supplementary charts:**
  - Time-between-orders histogram (x = days, y = repeat-customer count)
  - "Golden Path" — sankey diagram of product sequences for repeat customers

---

## Screen: Benchmarks

- **URL path:** `/benchmarks`.
- **Layout:** Grid of chart cards, one per metric.
- **Each chart card:**
  - Metric name header
  - Two-line chart: "Your Store" (bold teal) vs "Peer Cohort Median" (grey)
  - Shaded peer-band (25th–75th percentile)
  - Percentile badge for the user ("You're in the 68th percentile")
- **Peer selection controls (top):**
  - Industry dropdown (~21 categories)
  - AOV bucket: <$100 / >$100 / All
  - Revenue bracket: $0–100k, $100k–1M, $1–10M, $10M+
- **Metrics covered:** CPA, CPC, CPM, CVR, CTR, MER, ROAS, AOV.

---

## Screen: Reports (scheduled email)

- **URL path:** `/reports`.
- **Layout:** Table of scheduled reports — Name / Schedule / Recipients / Last Sent / Next Send / Actions.
- **Add Report flow:** Modal to pick source dashboard → schedule (daily / weekly / monthly, time-of-day, timezone) → recipients (emails, Slack channels) → test send.

---

## Screen: Integrations / Connections

- **URL path:** `/settings/integrations`.
- **Layout:** Grid of logo cards — each card shows platform logo, status pill (Connected / Error / Not Connected), last-sync timestamp, and "Manage" button.
- **Grouped by category:** Ad Platforms / Ecom / Email & SMS / Analytics / Reviews / Subscription / Apps.
- **Error state:** Card border turns red with "Reauth required" CTA.

---

## Specific micro-patterns worth documenting

- **Long numbers:** Currency truncation rules — $1,234 stays full, $12,345 stays full, $123,456 stays full, $1,234,567 abbreviates to $1.23M on tiles. Full precision is always revealed on hover tooltip. Negative values use parentheses plus red colour.
- **Syncing indicator:** A pulsing dot + timestamp string "Synced 12 min ago" in the top-right of each section. Clicking the text opens a sync-status modal with per-integration last-sync times and error details.
- **Estimated / modeled indicator:** The word "Modeled" appears in superscript next to metric values where attribution is imputed (e.g. iOS 14 loss recovery, view-through modelled). Tooltip explains.
- **Tooltips:** Dark-background tooltips with a thin teal top-border. Each metric tile has a `?` micro-icon top-right that opens a full definition modal, not a hover tooltip, because definitions are long.
- **Date range picker:** Two-month side-by-side calendar. Quick presets in a left rail. "Compare to" dropdown appears below the calendar. Time-of-day selector hidden by default but expandable for sub-daily granularity.
- **Pin affordance:** Hover a tile → a small 📌 pin icon appears top-right. Click pins to the "Pinned" row at the top of the Summary Dashboard. Pinned tiles get a thin teal corner indicator.
- **Store switcher on multi-store:** Shows combined revenue in the dropdown preview; "All Stores" creates a genuinely blended view (summed, not concatenated).
- **Mobile app parity:** iOS/Android app mirrors Summary + Pixel tabs only — not feature-complete; reviews say it feels like a scaled-down web view.
- **Divergent-data warnings:** When platform-reported revenue and pixel-attributed revenue disagree by >X%, a yellow banner appears at the top of the Pixel page explaining the likely reason (iOS 14 / consent mode / ad-block). This is rare — most tools hide the disagreement.
- **Creative Cockpit thumb-play:** Video creatives autoplay muted on hover; hover exits, pause. No scrubber in gallery view; scrubber appears in full-screen.
- **"Set up" empty states:** Every paywalled feature has a friendly but persistent upsell card. Free-tier users frequently cite these as intrusive ("80% of the product is paywalled").

---

## Screenshot inventory

```
https://cdn.prod.website-files.com/61bcbae3ae2e8ee49aa790b0/67f045e914cd9f0d6d7413ac_5db6f838b80b0190ede7974f216e0a70_Summary%20Dashboard%20Blended%20Metrics.webp
https://cdn.prod.website-files.com/61bcbae3ae2e8ee49aa790b0/69407c5f598e31bb269cbdc4_Hero%20_image_desktop.png
https://cdn.prod.website-files.com/61bcbae3ae2e8ee49aa790b0/69407c761841667ac1294a26_Hero-image-mobil.png
https://cdn.prod.website-files.com/61bcbae3ae2e8ee49aa790b0/690b8b85e12711bf54f82b78_image-5.jpg
https://cdn.prod.website-files.com/61bcbae3ae2e8ee49aa790b0/690b8b85e12711bf54f82b85_image-2.jpg
https://cdn.prod.website-files.com/61bcbae3ae2e8ee49aa790b0/690b8b85e12711bf54f82ba3_image-1.webp
https://cdn.prod.website-files.com/61bcbae3ae2e8ee49aa790b0/690b8b85e12711bf54f82b93_image-4.jpg
https://cdn.prod.website-files.com/61bcbae3ae2e8ee49aa790b0/67ed4917b423c93557c80875_Product%20-1.webp
https://cdn.prod.website-files.com/61bcbae3ae2e8ee49aa790b0/67ed49c4f47e53bf476b2c33_Product-2.webp
https://cdn.prod.website-files.com/61bcbae3ae2e8ee49aa790b0/67ed56e13703df62d42eac33_Product-3.webp
https://cdn.prod.website-files.com/61bcbae3ae2e8ee49aa790b0/67ed3b04bbf9896e2f61dfa2_Moby%20agents.webp
https://cdn.prod.website-files.com/61bcbae3ae2e8ee49aa790b0/67ed3b528c78fbdc089d8965_Moby%20chat.webp
https://cdn.prod.website-files.com/63ed1d46df8ff31dcbd86b98/6622b25cb540d271a84afe6d_Triple%20Whale%20Home%20Page.JPG
https://cdn.prod.website-files.com/63ed1d46df8ff31dcbd86b98/6622b67ff58546af2d1fc6be_Triple%20Whale%20Founders%20Dash.jpg
https://cdn.prod.website-files.com/63ed1d46df8ff31dcbd86b98/6622b6dc0fe3a2f24cc0c752_Triple%20Whale%20Triple%20Pixel.png
https://cdn.prod.website-files.com/63ed1d46df8ff31dcbd86b98/6622b725255b93e73207377a_Triple%20Whale%20Creative%20Cockpit.png
https://cdn.prod.website-files.com/600ece313e616e6fe12df5d3/675c438ff7d61c7c1d858f35_h5vpAgEtLkeN07YATvxP8kaZ0WL9axt75relrThks58.png
```
