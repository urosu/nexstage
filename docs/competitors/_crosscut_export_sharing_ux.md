# Export / sharing / scheduled delivery — cross-competitor deep dive

Cross-cuts 30+ competitor docs plus targeted research on Triple Whale, Polar, Peel, Lifetimely, Northbeam, Looker Studio, Metabase, Metorik, Glew.io, Varos, Shopify, Klaviyo, Swydo, Whatagraph, AgencyAnalytics. Use case: **the founder sharing with a CFO, the agency sharing with a client, the data person exporting to a sheet.** Every competitor has a take on this, and the range of sophistication is very wide.

## Export formats

| Tool | CSV | XLSX | PDF | PNG / image | Direct to Google Sheets | Direct to Snowflake / BigQuery | API |
|---|---|---|---|---|---|---|---|
| **Triple Whale** | Yes | Yes | Limited (report-level only) | Yes (chart screenshot) | No native; via Zapier | Snowflake export (Enterprise) | REST API |
| **Polar Analytics** | Yes (up to 100k rows) | Limited | No (explicitly not supported for schedules) | No | Google Sheets destination | Snowflake as destination AND source | REST API |
| **Peel Insights** | Yes (per widget) | No | No | Limited | Google Sheets, OneDrive | No | REST API (limited) |
| **Lifetimely (AMP)** | Yes | Yes | Yes (attached to scheduled email) | No | No native | No | No public API |
| **Northbeam** | Yes (one-time + scheduled) | No | No | No | No (emailed link only) | Yes (Snowflake secure share, Enterprise) | Data Export API |
| **Shopify native analytics** | Yes | Limited | No | No | No | No | Admin API |
| **Shopify Plus Reporting (ShopifyQL Notebook)** | Yes | Limited | No | No | No | No | Storefront / Admin API |
| **BigCommerce Analytics** | Yes | No | No | No | No | No | API |
| **Klaviyo** | Yes (manual per report) | No | No | No | Via Coupler / Funnel.io / Zapier | Via integration partners | REST API |
| **Putler** | Yes | Yes | Yes | No | No | No | REST API |
| **Metorik** | Yes | Yes | Yes (digest PDFs) | No | No | No | REST API |
| **Glew.io** | Yes | Yes | Yes | No | Yes | Yes (Enterprise) | REST API |
| **Daasity** | N/A (warehouse-native) | via BI tool | via BI tool | via BI tool | via BI tool | Native | N/A (SQL) |
| **Looker Studio** | Yes | Yes | Yes (scheduled) | No (embed only) | Native | BQ native | Looker API |
| **Metabase** | Yes | Yes | Yes (whole dashboard) | No | Limited | via warehouse | REST API |
| **Mixpanel** | Yes | No | No | Yes (individual chart) | via Zapier | Warehouse connectors | REST API |
| **Amplitude** | Yes | No | No | Yes | via Zapier | Warehouse sync | REST API |
| **Fairing** | Yes | No | No | No | via Zapier | No | REST API |
| **Varos** | Limited (benchmark exports) | No | No | No | No | No | No public |
| **Hyros** | Yes | No | No | No | No | No | Limited |
| **Rockerbox** | Yes | Yes | No | No | No | Snowflake (Enterprise) | Yes |
| **Wicked Reports** | Yes | Yes | No | No | No | No | Yes |
| **Motion** | Limited (creative CSV) | No | No | Creative thumbnails download | No | No | Limited |
| **Segments Analytics** | Yes | No | No | No | No | No | Yes |
| **MonsterInsights** | Yes (via report email) | No | Yes (PDF scheduled) | No | No | No | No |
| **Elevar** | Not a reporting product; data quality audit exports CSV | — | — | — | — | — | — |
| **RoasMonster** | Yes | No | No | No | No | No | Limited |
| **Atria** | Yes | No | No | No | No | No | — |
| **Mailchimp** | Yes | Yes | Yes | No | via Zapier | Via partners | REST API |
| **Omnisend** | Yes | No | No | No | via Zapier | No | REST API |
| **Shopify Plus Flow / BI package** | Yes | Yes | No | No | Via ShopifyQL | Via Hydrogen / data pipelines | Yes |
| **Instagram Shopping Insights** | Limited (in-app) | No | No | Yes (story / post screenshot) | No | No | Graph API |
| **TikTok Shop Analytics** | Yes | No | No | Yes | No | No | Shop Seller API |

### Observations

- CSV is universal. It is always the **first** export format and often the only one for the first year of a product's life.
- XLSX support is inconsistent and usually labeled as "advanced" or upsell.
- PDF is the canonical **agency** export; Looker Studio, Swydo, AgencyAnalytics, Whatagraph make PDF the hero. BI-leaning tools (Polar, Peel, Metabase) resist PDF in schedules.
- Direct-to-Google-Sheets is a major differentiator for spreadsheet-oriented operators. Polar and Peel have it; Lifetimely does not and is quieter on this axis. Klaviyo forces third-party Coupler/Funnel.io integrations.
- Snowflake / BigQuery direct is the **enterprise upsell** lever (Polar, Daasity, Rockerbox, Glew, Northbeam at high tiers).
- PNG / chart-image export is rare. Mixpanel and Amplitude are the only category leaders that make it easy.

## Scheduled delivery

| Tool | Email digest | Slack digest | Teams digest | SMS | Webhook | Frequency options |
|---|---|---|---|---|---|---|
| **Triple Whale** | Yes (Summary digest) | Yes (Slack app) | No | No | Limited | Daily, weekly |
| **Polar Analytics** | Yes | Yes (top 10 rows only in Slack) | No | No | n8n, MCP | Hourly (pro), daily, weekly, monthly, custom cron |
| **Peel Insights** | Yes (Daily Report) | Yes (public + private channels) | No | No | No | Day-of-week selectable |
| **Lifetimely (AMP)** | Yes (with CSV/XLSX/PDF attachments) | No | No | No | No | Daily, weekly, monthly |
| **Northbeam** | Yes (link-only; no attachment) | No (roadmap) | No | No | Webhook | Daily, weekly, monthly |
| **Metorik** | Yes | Yes | No | No | Webhook | Any cadence, any time of day |
| **Glew.io** | Yes | Limited | No | No | Webhook | Daily, weekly, monthly |
| **Shopify native** | Limited ("sales summary" email) | No | No | No | Flow webhooks | Configurable via Flow |
| **Shopify Plus Reporting** | Yes | No | No | No | Flow | Daily, weekly |
| **Klaviyo** | Yes (newsletter-style performance roll-up) | No (third-party only) | No | No | No | Daily, weekly, monthly |
| **Looker Studio** | Yes (scheduled PDF) | No native | No | No | No | Daily, weekly, monthly, custom |
| **Metabase** | Yes (dashboard subscription) | Yes (same primitive) | No | No | Webhook | Cron-level |
| **Mixpanel** | Yes (Insight email) | Yes | No | No | Webhook | Daily, weekly |
| **Amplitude** | Yes (alerts + digests) | Yes | Yes (enterprise) | No | Webhook | Flexible |
| **Putler** | Yes (weekly roll-up) | No | No | No | No | Weekly |
| **Hyros** | Yes | No | No | No | No | Daily, weekly |
| **Varos** | Yes ("Monday Morning Benchmark") | No | No | No | No | Weekly |
| **Rockerbox** | Yes | Limited | No | No | Yes | Daily, weekly |
| **Wicked Reports** | Yes | No | No | No | No | Daily, weekly |
| **Fairing** | Yes (survey response digest) | No | No | No | Webhook | Weekly |
| **MonsterInsights** | Yes (PDF weekly) | No | No | No | No | Weekly |
| **AgencyAnalytics** | Yes (client PDF) | Yes | Yes | No | No | Daily, weekly, monthly |
| **Swydo** | Yes (PDF) | Yes | No | No | No | Daily, weekly, monthly |
| **Whatagraph** | Yes (PDF + HTML) | Yes | No | No | Webhook | Flexible |

### Per-tool scheduled-delivery deep dive

#### Varos — "Monday Morning Benchmark" email

- Weekly cadence, arrives Monday morning before the team stand-up. Documented in `varos.md`.
- Content: user's key KPIs (ROAS, MER, CAC, AOV) with **percentile rank against peer group** + delta vs last week + delta vs peer median. Short commentary on market-wide movements.
- Every KPI in the email links deep into the web dashboard.
- The **ritual** is the product. Most Varos users only see the email.

#### Polar Analytics — scheduled tables to Slack + email

- Custom Tables (not charts) can be scheduled.
- Users pick metrics + dimensions + filters, save as a Report, attach a Schedule.
- Delivery: email, Slack channel, or both.
- Slack delivery is **limited to top 10 rows** inside the channel message (with a link to the full table online) — smart constraint; keeps Slack readable.
- PDF delivery is explicitly unsupported for schedules; Polar's product stance is that PDFs belong elsewhere.
- Polar help center labels this pattern "the #1 time-saver" per reviewer interviews (`polar-analytics.md` cites "2–3 hours/day of spreadsheet work gone").

#### Peel Insights — Daily Report to Slack + email

- Dashboards (not individual metrics) are the shareable unit.
- Schedule sends to team Slack channel (public or private) with highlights + trend commentary.
- Day-of-week selectable for weekly schedules; useful for Monday-morning agency cadence.
- Email recipients don't need Peel accounts — classic "send to client" use case.
- Magic Insights AI-headlines attached to the Daily Report give it a "newsletter" feel instead of a raw dump.

#### Lifetimely — scheduled email with attachments

- Email schedule with **CSV, XLSX, or PDF attachments** — the most agency-friendly of the Shopify-native set.
- Role-based dashboard templates (Founder, CFO, Performance Marketer) that can be scheduled independently.
- Daily / weekly / monthly cadence.
- No Slack — explicitly email-only. Reflects a CFO-first persona.

#### Putler — Daily Snapshot email

- Weekly roll-up; content is the Pulse (current-month) plus top-3 products / top-3 customers.
- Light on customization; heavy on "default" — which is actually a selling point for SMBs who don't want to configure.

#### Metorik — digests to email or Slack, any cadence

- Fully custom builder — any report becomes a digest; frequency, send-time, and recipients all configurable.
- Slack delivery is first-class: tables render in Slack with color-coded deltas.
- The `metorik.md` doc flags this as a core feature users churn when they lose.

#### Triple Whale — Slack app + Summary email

- Slack app posts daily Summary to a chosen channel, pre-formatted with ROAS, MER, revenue, spend, orders.
- Summary email duplicates Slack content in inbox.
- Both are auto-refresh as of ~15-minute cadence.

#### Looker Studio — scheduled PDF via Gmail

- Picks a report, sets a schedule, delivers as PDF to email recipients.
- Password-protectable, can filter scope before delivery.
- Major limitation: the PDF is a snapshot, not interactive — recipients who want to change the date range have to go to the web.
- Agencies use Looker Studio schedules as their **client-facing** report, often as the *only* product the client ever sees.

#### Metabase — Dashboard Subscriptions

- Single primitive covers both email and Slack. Send "results of questions on a dashboard" on a cadence; recipients can be non-Metabase users (email typed in).
- PDF export of whole dashboard works as scheduled attachment.
- Dashboard subscription filter customization is Pro/Enterprise-only (different views for different recipients).

#### Shopify native — "sales summary" email

- Baseline daily/weekly roll-up: today's sales, orders, best-selling products, visitor count.
- Not customizable; not Slack-capable.
- Reference point for what "free analytics tool from the platform" looks like — deliberately minimal.

#### AgencyAnalytics, Swydo, Whatagraph — agency-focused PDF delivery

- Built for the exact "agency emails client" use case.
- Swydo starts $69/mo and includes white-label at all tiers.
- AgencyAnalytics white-label is gated behind Agency+ plan (not on Freelancer).
- Whatagraph emphasizes 50+ integrations and data-joining for multi-client pipelines.
- All three lean heavily on **monthly PDF** as the default cadence — mirrors agency retainer billing.

#### Klaviyo — performance roll-up email

- Newsletter-style monthly performance summary; not configurable as a dashboard schedule.
- Data users work around this with Coupler.io / Funnel.io / Zapier for Google Sheets pipelines.

## Shareable links

| Tool | Public read-only link | Password-protected | Expiring link | Embed iframe | SSO-gated internal | Workspace-based invite |
|---|---|---|---|---|---|---|
| **Triple Whale** | Yes (via Snapshot) | No | No | No | Yes | Yes |
| **Polar Analytics** | Yes | No | No | Limited | Yes | Yes (unlimited users) |
| **Peel Insights** | Yes (dashboard share) | No | No | No | Yes | Yes |
| **Lifetimely** | Limited | No | No | No | Yes | Yes |
| **Northbeam** | Limited | No | No | No | Yes | Yes |
| **Metorik** | Yes (shared dashboard link) | No | No | No | Yes | Yes |
| **Shopify** | Limited (order view only) | No | No | No | Yes | Yes (staff accounts) |
| **Looker Studio** | Yes (unlisted or public) | Yes (for scheduled delivery) | No (built-in) | Yes (iframe) | Yes | Yes |
| **Metabase** | Yes (public link per question / dashboard) | No native (relies on embed token) | No | Yes (iframe with JWT) | Yes | Yes |
| **Mixpanel** | Yes | No | No | Yes | Yes | Yes |
| **Amplitude** | Yes | No | No | Yes | Yes | Yes |
| **Putler** | Limited | No | No | No | Yes | Yes |
| **Glew.io** | Yes | No | No | Yes | Yes | Yes |
| **AgencyAnalytics** | Yes (client portal) | Yes | No | No | Yes | Yes |
| **Swydo** | Yes (client link) | Yes | No | No | Yes | Yes |
| **Whatagraph** | Yes | Yes | No | Yes | Yes | Yes |
| **Varos** | No | No | No | No | Yes | Yes |
| **Daasity** | N/A (warehouse only) | — | — | — | — | — |

### Notes

- Password-protected links are rare in commerce analytics and common in BI/agency tools. Most SMB tools use "workspace invite" rather than magic links.
- True **iframe embed** is offered primarily by BI-leaning tools (Looker Studio, Metabase, Glew, Mixpanel, Amplitude). Ecommerce-first tools (Triple Whale, Polar, Peel) don't prioritize it — their audience is founders, not product teams embedding dashboards in an internal portal.
- Expiring links are essentially absent from the category. This is a differentiator Nexstage could steal.

## Custom report building (the "spreadsheet replacement" use case)

Every review site lists "custom report builder" as a top purchase criterion. The implementation shape varies wildly.

### Tool-by-tool: how you build a custom report

- **Triple Whale:** Drag-drop widget-grid with preset metrics. 75+ templates ship by default. Power users get a SQL editor (rare for the category). Custom metrics via formula (no-code) or SQL.
- **Northbeam:** Column-picker builder with metric + dimension + filter rows. No templates library. No SQL.
- **Polar Analytics:** Step-by-step Custom Report builder with **templates library** to avoid blank-canvas problem. Metric → dimension → filter → view (table / chart). Saved reports support schedules. Widely cited as the category-best no-code builder in `polar-analytics.md`.
- **Lifetimely:** Drag-drop widget layout with **role templates** (Founder, CFO, Performance Marketer). Not a free-form builder — you pick from a curated set of widgets.
- **Peel Insights:** Explicit three-step hierarchy: **Metric → Report → Dashboard**. Metrics are pre-computed; Reports are filtered views of metrics; Dashboards compose Reports as Widgets. **Magic Dash** uses AI to compose dashboards from a natural-language question.
- **Daasity:** SQL only. You model in the warehouse and visualize in Looker / Tableau / Sigma. No GUI builder.
- **Shopify Plus (ShopifyQL Notebook):** Notebook-style SQL-adjacent DSL (ShopifyQL). Gated behind Advanced ($399/mo) and Plus tiers.
- **Looker Studio:** GUI drag-drop canvas. Data sources plug in via connectors. Templates library. LookML is the power-user escape hatch (Looker Core only, not Studio).
- **Metabase:** Three tiers — (1) question builder (column-picker), (2) native SQL, (3) models for reusable transformations. Questions compose into dashboards.
- **Mixpanel / Amplitude:** Event-based. Pick event → break down by property → filter. Retention / funnel / flow are specific chart primitives, not composable queries.
- **Klaviyo:** "Custom reports" are filtered views of metrics; less a builder, more a saved-filter UI.
- **Glew.io:** Drag-drop widget grid with 200+ pre-built reports.
- **Putler, Metorik, Lifetimely:** Curated report catalogs with filters rather than open builders.

### Who uses what UX pattern

- **Drag-drop widget grid:** Triple Whale, Lifetimely, Peel (for Dashboards on top of Reports), Looker Studio, Glew, AgencyAnalytics, Swydo, Whatagraph.
- **Column-picker / builder wizard:** Polar, Northbeam, Klaviyo, Metabase question builder.
- **Raw SQL:** Daasity, Triple Whale (power-user), Metabase, Shopify Plus ShopifyQL, Looker (LookML).
- **Natural language:** Peel Magic Dash, Polar AI Assistant, Triple Whale Moby, Amplitude Ask.
- **Pre-built catalog only (no builder):** Shopify native, BigCommerce analytics, Metorik, Putler, MonsterInsights.

## White-label / client-reporting

Specifically for the agency use case.

- **AgencyAnalytics:** Client-facing portals with agency branding, color, logo, custom domain (CNAME). Each client gets a scoped dashboard. White-label gated behind Agency+ plan (~$299/mo). Per-client unlimited dashboards.
- **Swydo:** White-label included at every tier. Custom domain, custom logo, custom PDF cover page. Client login gated by email.
- **Whatagraph:** White-label at every paid tier; strong PDF template library; joins multi-source data pre-render.
- **Polar Analytics:** Not explicitly white-label, but **unlimited users** and custom report schedules cover 80% of the agency use case. Agencies use Polar for themselves and share dashboards.
- **Triple Whale:** Agency plan exists; client workspaces are first-class; shared Summary dashboards can be branded to some extent. Not a white-label agency tool per se.
- **Lifetimely:** Unlimited dashboards, unlimited scheduled reports — de facto agency-friendly without explicit white-label chrome.
- **Peel:** Agency tier includes custom consulting + human-built dashboards. Not DIY white-label.
- **Glew.io:** Multi-client dashboards, white-label on higher tiers.
- **Looker Studio:** De-facto agency tool; you change the theme + logo and the report is client-ready. No domain-level white-label without Looker Core.
- **Metabase:** Full-app embedding with custom branding (Pro / Enterprise).
- **Shopify native / Shopify Plus Reporting:** Not a white-label surface; merchant-only.
- **Klaviyo, Mailchimp, Omnisend:** Partner / agency workspace support but no embedded client portals.

### White-label patterns worth noting

- **Custom domain (CNAME) for the client portal** — AgencyAnalytics, Swydo, Whatagraph. `reports.acme.agency` instead of `agencyanalytics.com/clients/acme`.
- **Custom PDF cover + footer** — all three agency tools and Lifetimely scheduled exports.
- **Per-client metric definitions** (some clients exclude subscription revenue, some include) — Polar handles this via saved-report-per-client; AgencyAnalytics handles via "custom metrics per client".
- **Client view = read-only; agency view = editable** — common pattern. Nexstage's `WorkspaceScope` model maps well.

## Patterns worth stealing

- **Templates library for custom reports** — Polar, Lifetimely. Kills the blank-canvas problem. We should ship a "recipes" catalog (e.g. "Real-vs-Platform weekly", "Country P&L", "New vs returning cohort") as the entry point into the builder.
- **Tables to Slack, charts to email, PDF to client, CSV to analyst** — Polar's stance. Each surface plays to its medium. Nexstage should cargo-cult this.
- **Top-10-rows-in-Slack with link to full report** — Polar's constraint. Respects the Slack reader.
- **Recipient can be non-user (email typed in)** — Metabase, Peel. Prevents seat inflation when you want to blast a digest to the CFO who doesn't want a login.
- **Three-step Metric → Report → Dashboard hierarchy** — Peel's clean mental model. We already lean this way; make it explicit in IA.
- **Anomaly alerts via Slack / email instead of scheduled** — Polar. Threshold-triggered notifications beat noise-floor daily digests.
- **CSV attachments on scheduled email, not link-only** — Lifetimely gets this right, Northbeam gets it wrong (their own users request "attach files directly to emails" per public feedback).
- **Day-of-week selector on weekly digest** — Peel. Monday for agencies, Friday for founders. Let users pick.
- **Role-templated scheduled exports** — Lifetimely (Founder / CFO / Performance Marketer). Bakes persona into the ritual.
- **White-label at every tier (not gated behind Enterprise)** — Swydo. Strong agency adoption signal. If Nexstage wants agencies, white-label should be in the Agency SKU (whatever we call it), not withheld.
- **Public snapshot link with no login required** — Triple Whale, Metabase, Looker Studio. High-friction logins are the #1 reason shared dashboards don't get looked at.
- **Embed iframe with JWT-signed token** — Metabase. Lets agencies drop a live Nexstage chart into a Notion page / intranet.
- **Annotations on charts that travel with the export** — Peel. Supply disruption on Nov 4, campaign launch on Nov 18 — both show on the shared image. Turns an analytics tool into a shared event log.
- **AI-generated commentary attached to the digest** — Peel Magic Insights. Raises a CSV-dump from "table" to "newsletter".
- **Cron-level custom schedule** — Metabase. Not everyone wants daily-at-9am; let power users pick.

## Anti-patterns

- **Link-only scheduled email (no attachment)** — Northbeam. Adds a click for the recipient every time. Users publicly request file attachments; the team "has no timeline".
- **Scheduling that ignores the receiving medium** — Sending a 50-column PDF to Slack (Whatagraph historical behavior) or a raw CSV dump to a CFO's email. Delivery format should adapt to channel.
- **PDF-only schedule with no HTML inline** — Looker Studio default. A PDF attachment on a phone gets ignored; inline HTML gets read.
- **Per-seat pricing on scheduled-report recipients** — some competitors charge per viewer. Polar explicitly avoids this ("unlimited users"). Seat-metering kills distribution.
- **Export limits without visible progress** — Polar caps CSV at 100k rows silently; large exports fail with an unclear error.
- **White-label gated behind the top tier only** — AgencyAnalytics. Forces agencies to pay Enterprise for basic client-facing branding.
- **Share link with no expiry + no revoke** — most tools. Dashboards shared to ex-employees remain accessible indefinitely.
- **Custom-report builder that requires SQL to filter on a computed metric** — common gap. Users who can't write SQL end up with hand-edited CSVs.
- **Hiding export behind a "…" menu in the corner of each widget** — Metabase. Reviewers consistently miss it. Export belongs at the dashboard level, not only per-widget.
- **Schedule failures that silently stop** — if a Slack token expires or an email bounces, many tools just stop sending. Users discover weeks later their digest stopped. Tools should surface "last delivery failed" in the UI.
- **No "send me a test" button** before saving a schedule — users end up sending broken digests to their boss.

## Proposed Nexstage export / sharing stance

Given we target SMBs and agencies on Shopify + WooCommerce, pre-launch, desktop-first, multi-tenant via `WorkspaceScope`:

### 1. v1 must-haves

- **CSV export** on every BreakdownRow-based page (server-side, no in-browser aggregation — hit `daily_snapshots` / `hourly_snapshots`). Includes source-badge metadata as columns (revenue_store, revenue_facebook, revenue_google, revenue_real). Download directly from the page, not behind a "…" menu.
- **Weekly email digest** — the "Real vs Platform vs Store" summary per workspace. HTML inline, not PDF. Includes all six source-badges per top KPI so the trust thesis comes through in the inbox. Recipients can be non-users (email typed). Configurable day-of-week, frequency (daily / weekly), time zone.
- **Shareable read-only snapshot link** — generates a tokenized URL that renders a date-range-frozen copy of a specific page. No login required; token can be revoked. Single URL works on desktop and mobile.
- **Send-to-Slack button (per workspace)** — connects a Slack workspace + channel; "Send current view to #channel" as a tabular top-10 message with a link back to the full view. Not scheduled yet; on-demand.
- **"Send test" button** on every schedule creation surface, before save.

Explicitly NOT in v1:

- Direct-to-Google-Sheets (requires OAuth for the end user; v2 feature).
- PDF schedule (use PDF for agency SKU only in v2/v3).
- Native Slack schedule (the send-on-demand button ships first; scheduled Slack is v2).
- Embed iframe.
- Password-protected links.

### 2. v2 additions

- **Slack digest on schedule** — weekly Slack post to the same content as the weekly email, with Polar's top-10-rows constraint. Same template as email.
- **Google Sheets direct export** — OAuth-connect a sheet, select the report, write on schedule. Killed workflow: "export CSV, open email, download, open sheets, paste, repeat weekly".
- **PDF export on the agency SKU** — branded with workspace logo + optional custom footer. Single PDF per workspace covers the "send to client" use case.
- **Anomaly alerts** (email + Slack) — threshold-triggered notifications: "Real Revenue diverged from Store by >X%", "Facebook over-reporting >Y%", "Google Ads spend DoD +Z%". Not scheduled; triggered. See `_crosscut_mobile_ux.md` for the list of alert types.
- **Recipient-can-be-non-user** is already in v1 for email; mirror for Slack channel.
- **Revoke + expire** on shareable links (default 30 days, configurable).

### 3. Custom report builder — in what form for v1?

**Do not build a full custom-report builder in v1.** It is the second-hardest surface in the category (after attribution), and the competitive benchmark (Polar's step-by-step builder with templates) took years to mature.

Instead, for v1, ship a **templated approach**:

- A curated set of ~8 "Nexstage recipes" (Real vs Platform weekly, Country P&L, New vs Returning cohort, Not-Tracked delta by channel, SEO-to-Revenue attribution, Search Console loss detector, etc.) accessible from a single "Reports" page.
- Each recipe is a pre-configured page with the date-range picker and 1-2 dropdown filters exposed.
- Every recipe is CSV-exportable and email-schedulable.
- Hardcoded filters (e.g. revenue source inclusion) can be toggled via dropdown; no free-form builder.

v2 upgrade path: turn each recipe into a *saved report* that the user can edit filters / columns on. v3 upgrade: full Polar-style step-by-step builder with templates library.

Rationale: Lifetimely and Peel both ship a curated catalog *instead of* a builder and don't get punished for it by their SMB audience. The builder is the feature that separates the "SMB" tier from the "mid-market" tier in this market; we don't need to win it in v1.

### 4. White-label — when to unlock it

- **Free tier / self-serve tier:** No white-label. Standard Nexstage chrome on exports and shared links.
- **Agency SKU (v2, once we have one):** White-label at the *entry* tier, Swydo-style. Custom logo on PDF cover + footer, custom color on MetricCard accent, CNAME for the client-portal URL (`reports.acme.agency`), custom "from" name on scheduled email. Do not withhold white-label for an upper tier — it is the primary reason agencies buy.
- **Per-client scoping** leverages existing `WorkspaceScope`: each client = a workspace; agency user has access to many workspaces; exports/schedules are workspace-scoped already.

### 5. Shareable links — implementation notes

- Token-based URL (`/public/snapshot/:token`), tokens have a `workspace_id`, `date_range_frozen_at`, `page_path`, `created_by`, `expires_at`, `revoked_at`.
- Default expiry: 30 days. User can extend or revoke.
- Rate limit on the public endpoint (no login = DDoS surface).
- Frozen snapshot is a copy of the pre-computed `daily_snapshots` rows at the moment of creation. Do not re-query live. Consistent with "never aggregate raw orders in page requests" from CLAUDE.md.
- Mobile-friendly render (see `_crosscut_mobile_ux.md` §2 for which pages should work on phone).
- Password-protection not in v1; v2 adds optional 6-digit PIN for agency-share scenarios.

### 6. Trust-thesis implications for exports

The trust thesis — six source badges, "Not Tracked" that can go negative — needs to survive export format changes:

- **CSV:** include per-source columns. `revenue_store`, `revenue_facebook`, `revenue_google`, `revenue_gsc`, `revenue_site`, `revenue_real`, `not_tracked` all present as separate columns, not collapsed into one "revenue".
- **Email HTML digest:** the top KPI card shows the six source badges inline. If rendered in a dark-theme email client, the gold lightbulb on Real must survive.
- **Slack top-10 table:** narrow, so collapse to `metric | real | store | platform_best | not_tracked` (four columns), with a link back to the full six-source view.
- **PDF (v2 agency):** full fidelity; it's the document the CFO reads.
- **Shareable link:** same as desktop view, read-only.

Exports that flatten our six sources into one number would destroy the product's unique selling point. The export format spec must match the in-app spec per trust thesis.

### Anti-scope

- No Snowflake / BigQuery direct export in v1 or v2. Polar / Daasity / Glew own this tier; we won't displace them before PMF.
- No REST API for data export in v1. The digest + CSV + shareable link covers 95% of cases.
- No Teams integration (SMBs aren't on Teams; enterprises are, and enterprises aren't our ICP).
- No SMS digest (founders don't want SMS analytics).
- No printed-mail delivery (some whitelabel agencies offer this for legal clients; not our market).

Sources pulled from this repo:

- `/home/uros/projects/nexstage/docs/competitors/polar-analytics.md`
- `/home/uros/projects/nexstage/docs/competitors/peel-insights.md`
- `/home/uros/projects/nexstage/docs/competitors/lifetimely.md`
- `/home/uros/projects/nexstage/docs/competitors/triple-whale.md`
- `/home/uros/projects/nexstage/docs/competitors/varos.md`
- `/home/uros/projects/nexstage/docs/competitors/northbeam.md`
- `/home/uros/projects/nexstage/docs/competitors/metorik.md`
- `/home/uros/projects/nexstage/docs/competitors/putler.md`
- `/home/uros/projects/nexstage/docs/competitors/glew.md`
- `/home/uros/projects/nexstage/docs/competitors/daasity.md`
- `/home/uros/projects/nexstage/docs/competitors/looker-studio.md`
- `/home/uros/projects/nexstage/docs/competitors/metabase.md`
- `/home/uros/projects/nexstage/docs/competitors/klaviyo.md`
- `/home/uros/projects/nexstage/docs/competitors/mailchimp.md`
- `/home/uros/projects/nexstage/docs/competitors/shopify-native.md`
- `/home/uros/projects/nexstage/docs/competitors/shopify-plus-reporting.md`

External sources:

- Polar Analytics Help Center: "Understanding Schedules", "Exporting Data in Polar", "Understanding Custom Tables/Charts"
- Peel Insights Help Center: "Scheduling Dashboards via Email and Slack", "Dashboards"
- Lifetimely / AMP product pages: Custom Dashboard, Profit & Loss, Analytics marketing
- Northbeam Docs: "Exporting Data", "Can I export Northbeam data?"
- Metorik: Digests marketing page
- Glew.io: Scheduled and Automated Multichannel Reports
- Metabase Documentation: "Dashboard subscriptions", "Public sharing", "Full app embedding"
- Looker Studio sharing guides (Coupler.io, Porter Metrics)
- Swydo, Whatagraph, AgencyAnalytics marketing pages on white-label and PDF delivery
- Klaviyo → Google Sheets integration walkthroughs (Coupler, Funnel, Skyvia)
