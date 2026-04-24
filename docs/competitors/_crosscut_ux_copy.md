# UX copy — cross-competitor patterns

Cross-cut style guide for every piece of UI text in an ecommerce analytics product — button labels, tooltips, error messages, empty states, onboarding copy, status badges, and tone. Source material is the full set of `/home/uros/projects/nexstage/docs/competitors/*.md` profiles plus targeted help-doc fetches for tooltip / error phrasing. Abbreviations follow the metric dictionary (TW = Triple Whale, NB = Northbeam, Pol = Polar, LT = Lifetimely, Peel = Peel Insights, Shop = Shopify native, Klav = Klaviyo, Mlc = Mailchimp, Omni = Omnisend, Put = Putler, Mtk = Metorik, Mot = Motion, Wk = Wicked Reports, Hyr = Hyros).

## Button labels

| Role | Who uses what | Our pick |
|---|---|---|
| **Primary CTA on marketing hero** | TW "Start for free"; NB "Get a demo"; Pol "Start free trial"; LT "Install on Shopify"; Peel "Apply for access"; Wk "Request demo"; Mot "Start free trial"; Hyr "Book a demo". | **"Start free trial"** (matches LT/Pol/Mot — the most copied phrasing). Secondary: **"See a demo"**. Never "Book a call". |
| **Primary CTA inside empty dashboard** | TW "Connect Shopify"; Pol "Configure Shopify"; LT "Connect store"; Klav "Connect your ecommerce store"; Mtk "Connect your WooCommerce store". | **"Connect store"** (platform-agnostic) — dynamically swap to "Connect Shopify" / "Connect WooCommerce" once platform is known. |
| **Connect ad platform** | TW "Connect Facebook"; NB "Connect channel"; Pol "Add connector"; LT "Connect Meta"; Klav "Connect integration"; Put "Connect your sources". | **"Connect [Platform]"** — always name the platform. "Add integration" (generic) is weaker. |
| **Disconnect** | Shop "Uninstall"; Klav "Disconnect integration"; LT "Remove connection"; Pol "Disconnect"; NB "Revoke". | **"Disconnect"** (don't say "Remove" — sounds destructive). Confirmation dialog: **"This will stop syncing data from [Platform]. Historical data stays. Continue?"** |
| **"Export" variants** | Shop "Export as CSV"; Klav "Export CSV"; NB "Download CSV"; Pol "Export" (dropdown: CSV / Schedule to Slack / Schedule to email); LT "Export to CSV / Excel"; Mtk "Export" (drag-to-reorder columns then download); Peel "Export to CSV / Google Sheets / OneDrive". | **"Export"** (primary button) → dropdown: `CSV` · `Google Sheets` · `Schedule email` · `Schedule Slack`. Never "Download" (implies a file is already prepared). |
| **Schedule / automate a report** | Pol "Schedule"; Mtk "Schedule this report"; Mlc "Schedule send"; Klav "Set up recurring email"; Peel "Schedule email"; LT "Email schedule". | **"Schedule"** — opens scheduler modal. |
| **"Drill down" / explore deeper** | NB "View details"; Pol "View report"; Peel "Double-click to explore"; TW "Drill down"; Mot "Open ad detail"; Plausible (inspiration) "Filter by"; GA4 (inspiration) "Show more". | **"View details"** (primary) for cards. For rows, support **click = open split pane**, matching Linear. Never "Drill down" — too internal-jargon. |
| **Compare mode** | NB "Compare models"; Mot "Compare selected"; Mlc "Comparative reports"; Pol "Compare"; Wk "Attribution comparison". | **"Compare"** — enters compare mode (up to 5 items, copying Mlc). |
| **Filter** | Plausible "Filter"; Linear "Filter…" (chip); Mtk "Add filter"; Metorik "New filter"; Peel "Filter by". | **"Filter"** (primary button in table toolbar). Chips read as sentences: `Country is US ×`. |
| **Save view** | Stripe "Save view"; Linear "Save as view"; Mtk "Save segment"; Pol "Save as report"; Peel "Save dashboard". | **"Save view"** (ad-hoc filter combo) vs **"Save as segment"** (reusable customer filter) — keep distinct. |
| **Create new dashboard / report** | Pol "+ New dashboard"; LT "+ Create dashboard"; Peel "Create Magic Dash"; Klav "+ Create dashboard"; Mtk "New dashboard". | **"New dashboard"** — icon `+` prefix. |
| **AI chat / assistant** | TW "Ask Moby"; LT "Ask Amp"; Put "Ask Copilot"; Peel "Ask a question"; Klav "Marketing Assistant"; Pol "Ask Polar"; Shop "Ask Sidekick". | **"Ask Nexstage"** — matches Put/LT/Pol/Shop pattern (`Ask <product-name>`). Never "AI chat" (generic). |
| **Learn / help** | Shop "Learn more" (contextual link); Klav "Learn about this metric"; NB "Read docs"; Pol "?" icon opening hover card. | **"Learn more"** as text link; **`?` hover icon** for inline help. Don't use "Help" — users associate with support tickets. |
| **Upgrade** | TW "Upgrade"; LT "Upgrade plan"; Klav "Upgrade now"; Shop "Change plan"; Mlc "Upgrade". | **"Upgrade"** — solo button. Never "Buy now" or "Subscribe" inside-product. |
| **Contact support** | Klav "Contact support"; Hyr "Your analyst"; Pol "Contact CSM"; Mtk "Email us". | **"Contact support"** — opens Intercom/Help Scout inline. Paid tiers with named CSM: **"Message your CSM"** (copy Hyros' "your analyst" framing for premium feel). |
| **Cancel / destructive** | Every tool: "Cancel" as modal dismiss. Destructive primary: TW "Delete"; Linear "Archive / Delete"; Shop "Uninstall". | **"Cancel"** (modal dismiss, left-aligned, tertiary) + **"Delete"** (primary, red) for destructive. Never "OK"/"No thanks". |
| **Onboarding next step** | TW "Next"; LT "Continue"; Klav "Continue"; Mlc "Next step". | **"Continue"** (not "Next" — feels less forced). Final step: **"Finish setup"**. |
| **Skip a step** | Mot "Skip"; TW "Skip for now"; Klav "Do this later"; LT "I'll do this later". | **"Do this later"** (matches Klaviyo; less dismissive than "Skip"). |
| **Retry failed action** | Vercel "Retry"; Shop "Try again"; Linear "Retry". | **"Retry"** — concise, matches dev-tool norms. |
| **Refresh data** | Shop "Refresh"; NB "Reload"; TW "Refresh"; Pol "Sync now". | **"Refresh"** — for view-level refresh. **"Sync now"** — for integration-level pull. |
| **View comparison (vs previous period)** | Shop "Compared to previous period"; NB "Previous period / Prior year same period"; Pol "Compare to: previous period / year ago". | **Toggle labeled "Compare"** with dropdown (`Previous period` · `Same period last year` · `Custom`). Don't spell out "vs" — implicit. |
| **Invite team member** | Linear "Invite people"; Klav "Invite member"; LT "Invite teammate"; Mot "Invite team". | **"Invite teammate"** (warmer than "Add user"). |

## Tooltip / explainer patterns

| Pattern | Who does it how | Our approach |
|---|---|---|
| **Explaining a metric** | NB: inline tooltip on column header (click → full definition + formula). LT: hover reveals formula. Pol: `?` icon opens hover card with definition + method. Put: no explainer (cited as a weakness). | **`?` icon adjacent to metric name**, hover = tooltip with one-sentence definition; click = full popover with formula, source badge, and link to glossary. Mandatory on every computed metric (the Putler weakness we avoid). |
| **Explaining a data source** | Pol: side-by-side columns with platform logo; hover platform logo reveals source description. TW: source chips on metric cards. Put: source badge per transaction row. Rkr: de-dup vs platform toggle. | **Source badge = platform logo + short code (`FB` · `GA` · `GSC` · `Shop` · `Site` · `Real`)** on every metric. Hover badge: `Source: Facebook Ads Manager · Last synced 4 min ago · Window: 7d click`. |
| **Explaining attribution window** | NB: typography — `ROAS (7d)` is self-explanatory. Klav: inline text on campaign report — *"Attribution window: 5d click / 5d open"* with "Change window" link. Wk: customizable lookback exposed as top-level control. | **Suffix on metric name** for default. **Chip under card** when non-default: `Window: 7d click · Change`. Click chip → inline editor with live-recomputation preview (copy Klaviyo). |
| **Explaining a model or method** | NB: "Attribution model" dropdown at page top with brief description in each option. Rkr: Scenario Planner + confidence bands. Hyr: claims single truth with no explainer (cautionary tale). | **Model selector in page header**; each option has a one-line description in the dropdown. **"How is this computed?"** link in every Real Revenue surface. Never claim truth; always say "Nexstage's reconciliation." |
| **Explaining "Not Tracked"** | TW: "Unattributed" with no explainer. Pol: side-by-side with platform columns. | **Dedicated tooltip** on Not Tracked chip: *"Store revenue that none of your connected sources claimed. Can go negative when platforms over-report. Learn about the Six Sources →"* |
| **Sample size confidence** | Varos: "Based on 12 orders — low confidence" chip. | **Chip below metric when n < threshold**: `Based on 12 orders — low confidence`. Grey-tinted. No delta % shown below threshold. |
| **Peer benchmark** | Klav: `Excellent / Good / Fair / Poor` badge with hover showing peer-group composition. Varos: percentile bar with P25/P50/P75 markers. | **Klaviyo pattern**: status badge on metric card + hover reveals `Peer group: 100+ Shopify stores · $500k–$2M GMV · rebuilt weekly`. |
| **Currency / FX note** | Pol: "Values in workspace currency (USD). Converted at transaction time." | **Footer note** on P&L: *"Values in [currency]. Non-[currency] transactions converted at transaction-time FX rate."* Hover `?` links to rates source. |
| **Freshness** | Vercel: `Last deploy 4m ago`. Linear: `Updated 2 min ago`. Shop: `Live` / `Just now`. | **Relative timestamp in page header**: `Updated 3 min ago`. Click → absolute time + source-by-source sync table. |
| **Locked feature (paywall)** | Shop: gear icon with upgrade tooltip. Mlc: lock icon + "Upgrade to Standard to unlock." | **Lock icon + one-line reason**: *"Available on Growth — Schedule a report"*. Never modal-interrupt. |

## Empty state copy

| Scenario | Who says what | Our recommendation |
|---|---|---|
| **Pre-first-sale store** | Shop: *"Your first sale will appear here."* (warm). Klav: *"No activity yet. Send your first campaign to see results."* | **"Your first order will show up here — usually within 60 seconds of capture."** Include skeleton layout so the shape is visible. |
| **No data yet, still syncing** | LT: *"Importing orders: 4,200 of 18,400 (23%) — estimated 8 minutes."* (best in class — explicit count). TW: skeleton widgets, no ETA. Put: demo data until real data loads. Mot: "While data loads, explore the inspiration library." | **"Still importing — [COUNT] of [TOTAL] orders so far (~[MIN] min remaining). Come back soon, or explore benchmarks while you wait →"** Pair a progress bar with a productive CTA (copy Motion). |
| **No orders in this range** | Shop: *"No orders to show for this date range. Try a different range."* LT: *"No data for selected period."* Mtk: *"No orders match these filters."* | **"No orders in this range. Try expanding the date range, or clear filters."** Include a "Reset filters" link inline. |
| **Filter returned zero results** | Linear: *"No results match your filters. Clear filters"* with clear-all button. Plausible: *"No data found with applied filters."* Klav: *"No segments match these conditions."* | **"No matches. [Clear filters]"** — primary action is the clear button, not prose. |
| **Pre-integration empty state** | TW: *"Connect Facebook to see ad performance"*. Pol: *"Add a Meta Ads connector to populate this view."* Klav: *"Connect your ecommerce store to see customer data."* NB: *"No channel connected. Connect your first ad platform to begin."* | **"Connect [Platform] to see [what you'll see]."** Examples: *"Connect Facebook Ads to see ROAS by campaign."* / *"Connect Google Search Console to see organic traffic."* Concrete payoff > generic "connect to see more." |
| **AI agent has no context** | Peel Magic Dash: *"Ask a question about your business"* + example suggestions. LT Ask Amp: example prompts seeded below input. TW Moby: empty state offers 5 canned queries. | **Seed 3-5 example prompts** below the input: *"Which campaigns lost budget this week?" / "Show me customers at risk of churn." / "Why did Saturday's orders spike?"* Click fills input. |
| **Benchmark has insufficient peer data** | Klav: *"We need at least 100 peer brands to show a benchmark. Come back soon."* Varos: *"Peer group: not yet available for this segment."* | **"Benchmark not yet available for your cohort — we need 100+ peer stores to publish."** Never hide the surface; label the absence. |
| **Dashboard / report has no widgets** | TW: drag-widget prompt. Pol: template picker. Peel: ships 4 default dashboards (no empty state). Shop: pre-configured default. | **Never ship a literal empty canvas.** Default layout with 6-8 widgets; "Customize" button opens the editor. Copies Shop / Peel / Pol best practice. |
| **AI can't answer** | LT Ask Amp: *"I couldn't answer that — try rephrasing."* Pol: *"Not sure how to query that yet. Here's what I can do →"* | **"I can't answer that yet. Try one of these instead:"** + 3 contextual suggestions. Never apologize twice; fail forward. |
| **Sync failed / integration broken** | Mlc: *"Disconnected from Shopify. [Reconnect]"* Pol: *"Sync error — Meta Ads token expired. [Reauthorize]"* Vercel: *"Deploy failed. [View logs] [Retry]"*. | **"[Platform] sync paused — [reason]. [Reconnect]"** Specific reason (token expired / rate-limited / permission revoked) — never "something went wrong." Pair with one-click fix. |
| **Search returns nothing** | Linear: *"No results for '[query]'"* Mtk: *"No orders match this search."* | **"No matches for '[query]'. Try a different spelling or broader term."** |

## Error copy

| Scenario | Who says what | Our recommendation |
|---|---|---|
| **Integration disconnected** | Mlc: *"Your Shopify connection is inactive. Reconnect"*. Pol: *"Meta Ads token expired — reauthorize to resume syncing."* TW: red banner + "Reconnect" CTA. Klav: in-app alert + email. | **Inline alert banner at top of affected page**: *"Facebook Ads sync paused — token expired. Reauthorize to keep data flowing."* Inline `[Reconnect]` button. Email sent only if unresolved after 24h. |
| **API rate limit hit** | Meta: *"We've temporarily paused to respect Facebook's rate limit. Will resume at [TIME]."* (internal Pol phrasing from reviews). Shop: *"Rate limited. Try again in N seconds."* | **"Sync paused until [TIMESTAMP] — [Platform] rate limit hit. No action needed; data will resume automatically."** Transparency > apology. |
| **Permission denied on OAuth scope** | Klav: *"We need permission to read your orders. [Reauthorize]"* Pol: *"Missing scope: ads_read. Reconnect with full permissions."* | **"Can't read [data type] from [Platform] — permission `[scope]` not granted. [Fix permissions]"** Link goes back through OAuth with the specific scope pre-selected where possible. |
| **Data mismatch / reconciliation warning** | Pol side-by-side view flags discrepancies visually, not as error. Mtk: doesn't reconcile (our gap). | **Non-blocking info banner on metric card**: *"Store and Facebook disagree by 12% on this metric — [See breakdown]."* Treat disagreement as information, never error. |
| **Plan limit reached** | Mlc: *"You've hit your 5,000 sends. [Upgrade plan]"* Klav: *"Your free tier allows 250 profiles. Upgrade to keep growing."* Shop: quota bar in billing. | **"You're at your [plan] limit of [X]. [Upgrade] or archive unused [items]."** Offer an archive/cleanup path, not just upgrade. |
| **Historical sync too large for plan** | LT: *"Your plan imports up to 120× monthly order volume. Orders before [DATE] aren't included."* | **"Historical data before [DATE] isn't on your plan. [Upgrade] to import older orders or archive [N] recent to make room."** Specific and reversible. |
| **Validation error on form** | Linear / Stripe: inline red text below the field. Klav: toast + inline. | **Inline under field** with specific fix. *"Date must be before 2026-05-01"* not *"Invalid date."* Never use browser-default `Please fill out this field.` |
| **Network / unknown error** | Vercel: *"Something went wrong. Our team has been notified. [Retry]"* Linear: *"Error saving. [Retry]"* | **"Couldn't save — something went wrong on our end. [Retry] — if it keeps happening, contact support with code [UUID]."** Include support code. |
| **Missing required connection for this view** | TW: empty-state variant. Pol: inline banner. | **"This view needs [Google Search Console]. [Connect →]"** Block the content, don't show stale data. |
| **Destructive-action confirmation** | Linear: *"Delete issue? This can't be undone."* Shop: *"Uninstalling removes [thing]. Customer data stays."* | **"[Action] [object]? [Specific consequence]. [Cancel] [Destructive action]"** Always name what stays vs what goes. |

## Status / badge copy

| Role | Who uses what | Our pick |
|---|---|---|
| **Fresh / live data** | Shop: `Live`. Stripe: `Live` dot + pulse. Vercel: `● Live`. Linear: `Updated 2 min ago`. | **`Live`** (when refresh < 2 min) with pulsing green dot. Otherwise `Updated N min ago`. |
| **Stale data warning** | Vercel: `Last deployed 3d ago` (amber). Pol: reviewers complain lag isn't labelled — lesson: label it. | **Amber `Updated Nh ago` chip** when > 1h. Red `Last synced 3d ago — [Reconnect]` if over 24h. |
| **Estimated / projected** | LT: `Projected Revenue` with range. Put: `Forecast: $X–$Y`. Klav: `Predicted CLV`. | **`Est.` prefix** for point estimates (`Est. $1.2M`). **Range notation** `($1.1M–$1.3M)` for forecasts with uncertainty. |
| **Pending / syncing** | Vercel: amber `Building…` pulse. TW: `Processing`. LT: progress bar with %. | **`Syncing… 76%`** with progress ring for quantifiable ops. **`Syncing…`** amber pulse for indeterminate. |
| **Error / broken** | Vercel: red `Failed`. Linear: red icon. | **`● Error — [one-word reason]`** — e.g., `● Error — token expired`. |
| **Healthy** | Vercel: green `Ready`. Linear: green `Done`. | **`● Healthy`** — green dot, no pulse. |
| **Peer benchmark status** | Klav: `Excellent` / `Good` / `Fair` / `Poor`. | **Copy Klaviyo verbatim**: `Excellent` / `Good` / `Fair` / `Poor` — semantic, not numeric. |
| **Paid-tier lock** | Shop: gear icon greyed. Mlc: lock icon + upgrade-required tooltip. | **Small lock icon** + tooltip: *"Available on [tier] — [feature]"*. |
| **Signal type (attribution)** | NB: `Deterministic` vs `Modeled`. | **Chip under metric** when needed: `Deterministic` (green) or `Modelled` (amber). |
| **Sample size low** | Varos: `Based on 12 orders — low confidence`. | **Grey chip below metric**: `Based on 12 orders — low confidence`. Metric grey-tinted, no delta. |
| **Retroactive change warning** | Klav: *"Changing window will recalculate reports back 36h."* | **Inline warning on change**: *"This recalculates back [N] days. Takes ~[TIME]."* |

## Onboarding copy

| Scenario | Who says what | Our pick |
|---|---|---|
| **Welcome screen** | Klav: *"Welcome, [Name]! Let's get your account set up."* Put: pre-populated demo + *"This is sample data. Connect your store to see yours."* LT: Setup Guide checklist. | **"Welcome to Nexstage. While you connect your store, explore a demo workspace →"** Pre-populate demo data (Putler pattern). |
| **Step progress** | Klav: `Step 2 of 5` explicit. Mlc: progress bar. | **`Step N of M`** always named. Progress bar bottom of wizard. |
| **Demo data banner** | Put: *"This is demo data. Connect to see your own."* (pinned). | **Pinned banner**: *"You're viewing the Acme Coffee demo workspace. [Connect your store] to see your own numbers — takes 30 seconds."* |
| **Persistent checklist** | TW: right-side setup guide. Shop: home tasks. LT: setup guide with sync progress. | **Right-side slide-over** with ring progress: `Connect store · Connect Facebook · Connect Google Ads · Set COGS`. Never nagging; dismissible per-session. |
| **Backfill time setting** | LT: *"Most stores complete this within 30 minutes — larger stores up to 24 hours."* | **"Importing your last 2 years of orders. Usually 5–30 min; larger stores up to 24h."** Specific window; manage expectations. |
| **Phased feature unlock** | NB: *"Unlocks after 7 days of data."* *"Attribution models calibrate in 25–30 days."* | **"Unlocks in N days — needs [amount] of [source] data to work."** Frame waiting as progress. |
| **First-sync completion** | LT: email + in-app. Klav: *"Your orders are in! Take a look at your new CLV dashboard →"* | **Toast + email**: *"Your store is fully synced — [N] orders imported. [View your dashboard →]"* |

## Tone

**How competitors read:**

- **Triple Whale** — confident, DTC-native, slightly hype-y. *"Blended ROAS and MER, finally in one place."* Uses "we" often. Mobile-app copy is breezy ("Check your morning coffee numbers").
- **Northbeam** — analyst-grade, formal, CFO-safe. *"Profitable growth, powered by laser-accurate first-party data."* Avoids exclamation marks. Heavy use of capital-letter product names (Apex, Metrics Explorer, Profitability Benchmarks).
- **Polar** — business-casual, warehouse-native flex. *"One source of truth — minus the spreadsheets."* Confident but doesn't over-claim.
- **Lifetimely (AMP)** — founder-friendly, warm. Names CSMs in onboarding emails (*"Sam here — let me know if you hit a snag."*). Uses "profit" more than "revenue" — speaks CFO.
- **Peel** — scholarly, retention-focused. *"Your guide to cohort analysis"* posture. Lots of long-form help docs. Product copy is competent but not warm.
- **Shopify native** — clean, merchant-first, encouraging. *"Your first sale will appear here"*. Minimal jargon; shipping-store tone.
- **Klaviyo** — marketing-native, friendly-formal. *"Build better customer relationships"*. Heavy on verbs ("Drive revenue", "Grow your list").
- **Mailchimp** — friendly, freelance-founder-ish, post-Intuit getting more corporate. Known for quirky in-app microcopy but the polish has faded since acquisition.
- **Hyros** — aggressive, claims-heavy, info-marketer vibes. *"We catch the 250% of conversions your pixel misses."* This is the anti-tone for us. Cancellation friction is a direct consequence of this tone.
- **Wicked Reports** — utilitarian, feature-dense. *"Patent-pending attribution"* posture. Less polished than category leaders; functional prose.
- **Putler** — eager, feature-first, occasional over-promise ("Pulse" when data is 15 min delayed). Reviewer-flagged as over-claiming freshness.
- **Motion** — visual-creative, agency-friendly. *"Make ads that win (without getting lucky)"*. Confident without being combative.
- **Rockerbox** — enterprise-sober, methodology-honest. *"Use the right methodology for the right question."* Explicitly acknowledges no single number is right — rare and admirable.
- **Metorik** — minimalist, WP-native. *"Take eCommerce back off hard mode."* Cheeky one-liner, otherwise functional.
- **Linear / Stripe / Vercel (inspiration)** — terse, developer-tool-precise. Single-word button labels, no marketing fluff in-product, data-dense without being dense prose.

## Recommendations for Nexstage tone

1. **Plain, honest, specific.** Write like Linear, not like Hyros. *"Facebook reports 12% more revenue than your store recorded"* beats *"We caught the 12% Facebook was hiding from you."* Our thesis sells itself if we describe it accurately; the moment we over-claim, we become Hyros.
2. **Never claim "truth." Claim "reconciliation."** The only number that carries a gold lightbulb is `Real Revenue`, and its tooltip always says *"Nexstage's reconciliation across six sources — here's how we compute it →"*. No "true", "real" (except the branded term), "accurate", "proven."
3. **Merchant-first vocabulary > analyst-first.** `Gross Sales` not `gross_revenue`. `Campaign` not `ad set`. When we must use a platform-specific term, badge it (`Ad Set · Facebook`). Copy Shopify-native's warmth; borrow Northbeam's precision only on the Attribution and Breakdown surfaces.
4. **Show your math.** Every computed metric has a `?` icon; every `?` icon opens a real formula. Putler's weakness ("I don't know how that was computed") is our wedge. *"AOV = Net Sales ÷ Orders · Based on 1,842 orders from Apr 1–14"* beats any adjective.
5. **Be warm in empty states, boring in errors.** *"Your first order will show up here"* is warm; *"Sync paused until 14:22 — Facebook rate limit hit"* is usefully boring. Don't over-apologize for errors — users want to know what happened and what to do next.

## Edge / misc copy contexts

| Scenario | Who says what | Our pick |
|---|---|---|
| **Multi-store switcher** | Shop: store dropdown. Pol: workspace switcher. TW "Pods View" (agency-specific). Peel: "Switch store" in top bar. | **"Workspace"** (top-left), with keyboard shortcut `⌘K` then `switch`. Aggregate mode: **"All workspaces"** with per-workspace contribution bars on KPIs (copy Glew). |
| **Date range picker** | Shop: preset chips + custom. NB: `Previous period / Prior year same period` compare. Linear: inline calendar. | **Preset chips** (`Today` · `Yesterday` · `Last 7d` · `Last 30d` · `MTD` · `QTD` · `YTD` · `Custom`). Compare toggle with `Previous period` default; `Same period last year` option. |
| **Period comparison delta** | Shop: `▲ 12%` green / `▼ 8%` red. Stripe: arrow + %. Plausible: arrow + %. | **Arrow + %** — green up / red down for revenue-like; reverse for cost-like. When delta is noise-level (`|Δ| < 2%`), render neutral grey. |
| **N/A (divide by zero)** | Shop: `—` (em-dash). NB: `—`. Plausible: `—`. | **`—`** (em-dash). Never "N/A" (caps visually loud), never "0" (misleads). Tooltip: *"Not enough data to compute — requires [X]."* |
| **Currency formatting** | All tools: locale-aware. | **Workspace currency symbol + thousands sep.** Show unconverted amount on hover for FX'd values. |
| **Large-number abbreviation** | Shop: `$1.2K` / `$1.2M`. Linear: same. Stripe: same. | **`$1.2K` / `$1.2M`** on cards (tight space); full number on hover and in tables. |
| **Time-to-refresh on data sources** | Rkr: "Data Status Reporting" as standalone page. Vercel: inline badges. | **Dedicated `/settings/data-health` page** with per-source `Last sync · Next sync · Status`. Surface via top-right icon when any source degraded. |
| **AI confidence / caveat copy** | NB: *"at least 30 days of data needed"*. Pol: *"correlation ≠ causation"*. Peel: Magic Insights cite data windows. | **Every AI-generated insight carries**: *"Based on [N] orders from [date range]. Confidence: [high/medium/low]."* Never present AI output as fact. |
| **Cancel subscription** | Hyr: hostile (cautionary). LT: *"We're sad to see you go. Let us know why →"* Klav: confirmation page with export prompt. | **One-click self-serve cancel.** Confirmation: *"Your plan will continue until [DATE]. [Cancel plan] [Keep plan]"*. Optional feedback field, never required. Never phone-to-cancel. |
| **Switching plans** | Shop: clear compare table. LT: feature parity + volume-based. | **Comparison table** side-by-side, toggle current plan highlighted. Prorate on upgrade; credit on downgrade. Never force annual-to-monthly downgrade delay. |
| **Consent / GDPR notice** | Omni, Klav: explicit consent capture. Shop: bundled in store settings. | **"Nexstage stores data in [region]. See [Privacy] · [DPA]"** on connect step. Don't hide in ToS. |
| **Notification preferences** | Klav: granular toggles per alert type. Linear: per-project toggle. | **Per-alert-type email/Slack/in-app toggles** with one master off. Default: anomalies + weekly digest on; everything else off. |
