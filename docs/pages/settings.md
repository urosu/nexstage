# Settings

Route: `/settings/*` — six sub-pages under a shared left-nav shell. Default landing: `/settings/workspace`.

## Purpose
One place to configure the workspace, the people, the costs, the money, the noise, and the goals — with honest defaults and destructive actions that can't fire by accident.

## User questions this page answers
- How do I change the display currency / week-start / default attribution model for this workspace?
- Who else is in this workspace, and what can they do?
- Where do I enter COGS, shipping, fees, and VAT so ProfitMode stops showing amber warnings?
- What am I paying this month, why, and how do I cancel without emailing support?
- How often does the email digest go out, to whom, and can I send myself a test first?
- What targets are we chasing this quarter, and where do they show up?

## Data sources

(Column header is `Category` rather than `Source (UX §4 color)` because this page's data is configuration, not store data — the six-source contract applies to data pages only.)

| Category | Required? | Provenance | Freshness |
|---|---|---|---|
| Workspace config | yes | `workspaces`, `WorkspaceSettings` value object | on-save (optimistic) |
| Team | yes | `workspace_users` pivot (role column) + `users` | on-save |
| Costs | no | `store_cost_settings` (workspace) + `product_cost_overrides` (per-variant) + `shipping_rules` + `transaction_fee_rules` + `tax_rules` + `opex_allocations` | on-save; changes trigger Klaviyo-style retroactive recalc ([UX §6](../UX.md#6-interaction-conventions)) |
| Billing | yes | `billing_subscriptions` + Stripe customer + `daily_snapshots` (for current-month revenue-share calculator) | live via Stripe for invoices; daily_snapshots read for revenue calc |
| Notifications | no | `notification_preferences` + `digest_schedules` + `slack_webhooks` + `anomaly_rules` | on-save |
| Targets | no | `workspace_targets` (per metric, per period) | on-save |

## Above the fold (1440×900)

**Shell** — a left-nav settings chrome inside `AppLayout`. 240px left-nav column + content area. Sub-pages are independent routes but share this shell; clicking a nav item swaps the right-hand content without remounting the `AppLayout` (Inertia partial reload, [UX §6 SWR](../UX.md#6-interaction-conventions)).

**Left-nav items** (order): `Workspace` · `Team` · `Costs` · `Billing` · `Notifications` · `Targets`. Active item: left border accent + bold label, per [UX §3 Sidebar](../UX.md#sidebar) convention. Each item shows a `StatusDot` only when that sub-page has an actionable state (e.g., amber dot on Costs when COGS is unconfigured and ProfitMode is being used; amber dot on Billing when the payment method failed).

A small header bar above the content area shows: page title · last-edited-by-and-when · `Export settings (JSON)` kebab action (for support escalations; not a backup product).

### Sub-page: `/settings/workspace`

Single-form layout. Two columns on desktop; stacks on tablet. Every field is inline-editable with optimistic writes ([UX §5.5.1](../UX.md#551-inline-editable-cell-datatable-sub-primitive), [UX §6.2](../UX.md#62-optimistic-writes--undo-window)).

- **Identity section**
  - Workspace name (text, 60-char limit).
  - Workspace avatar / emoji (the same avatar shown in the `WorkspaceSwitcher` and Portfolio contribution bars — see [_crosscut_multistore_ux.md § workspace colour/avatar/emoji](../competitors/_crosscut_multistore_ux.md)).
  - Primary country code (ISO-2, autocomplete).
  - Timezone (dropdown, IANA names; defaults to store timezone on creation).
  - Reporting currency (ISO-4217; defaults to Shopify/Woo native currency at connect — Klaviyo auto-extraction pattern, [_crosscut_onboarding_ux.md](../competitors/_crosscut_onboarding_ux.md)). Changing this triggers a visible retroactive recalc banner: `Recomputing metrics in EUR…`.
  - Week start (dropdown: Sunday — default, matches Shopify — or Monday). Copy below: `Picking Sunday matches Shopify's weekly reports so your numbers reconcile. [Why this matters]` ([UX §5.3](../UX.md#53-daterangepicker)).
- **Attribution defaults section**
  - Default attribution model (radio group: First-touch · Last-touch · Last-non-direct* · Linear · Data-driven). Default starred.
  - Default window (radio group: 1d · 7d* · 28d · LTV).
  - Default accounting mode (radio: Cash Snapshot* / Accrual Performance — per [UX §5.26](../UX.md#526-accountingmodeselector-global-topbar)).
  - Default ProfitMode (toggle, default Off).
  - Default breakdown (dropdown: None* / Country / Channel / Campaign / Product / Device / Customer segment).
  - Copy below the group: `These are the starting filters for every page in this workspace. Per-card overrides still work as always.`
- **Confidence threshold section**
  - One numeric input per metric family (orders / sessions / impressions) — default 30 / 100 / 1000 per [UX §5.27](../UX.md#527-confidencechip). The `ConfidenceChip` on every MetricCard reads from these.
- **Advanced section** (collapsible, closed by default)
  - Workspace slug (editable; warns that old URLs break).
  - Created / updated metadata.
  - **Danger zone**: `Delete workspace` button — rose outline. Opens a typed-confirmation modal requiring the user to type the workspace name; spells out billing implications and deletion timeline. This is the one place the undo-toast pattern is replaced by a hard modal, per [UX §6.2 carve-out](../UX.md#62-optimistic-writes--undo-window).

### Sub-page: `/settings/team`

- Toolbar: `+ Invite member` primary button · `Role = any` filter chip · search input.
- `DataTable` ([UX §5.5](../UX.md#55-datatable)) of members:
  - `User` — avatar · name · email (JetBrains Mono per [UX §4](../UX.md#typography)) · `EntityHoverCard` on hover.
  - `Role` — inline-editable dropdown (`Owner` / `Admin` / `Member`, per [_crosscut_multistore_ux.md § v1 role model](../competitors/_crosscut_multistore_ux.md)). Exactly one Owner enforced; demoting the Owner requires first promoting someone else (app guides this with an inline hint, not an error after save).
  - `Status` — `StatusDot` (`Active` / `Invited` / `Disabled`).
  - `Last active` (relative; `EntityHoverCard` shows full timeline).
  - `Actions` — kebab: `Resend invite` (Invited state only) · `Transfer ownership` (Owner-only action) · `Remove` (destructive, 10-second undo toast).
- **Invite modal** — email input (comma-separated for bulk), role dropdown, optional message. Submits → rows appear in the table with `Invited` status optimistically.
- **SSO placeholder strip** at bottom: `SAML SSO available on Enterprise. [Talk to us]` — per [_crosscut_multistore_ux.md § SSO/SCIM](../competitors/_crosscut_multistore_ux.md), we don't apply the SSO tax but we do gate it behind Enterprise. Links to pricing page marketing site.

v1 explicitly ships **three roles** (Owner / Admin / Member). Guest/Client role is v2 (agency tier). Per-dashboard / per-metric access is v3. Do not ship a "custom roles" builder.

### Sub-page: `/settings/costs`

This sub-page is **desktop-only** (1280+) — the inline-editable tables don't compress cleanly.

Top-of-page `AlertBanner` (`info`, dismissable) on first visit: `Nexstage never estimates costs. Missing inputs degrade ProfitMode per-metric with amber dots — they never silently defaults to zero.` ([UX §7 Cost inputs](../UX.md#cost-inputs).)

Sections via sub-tabs (vertical list with `StatusDot` per section — green when configured, amber when partial, grey when unset). Each section is one `DataTable` with inline-editable cells.

1. **COGS** — per-product-variant. Columns: `Product` · `Variant` · `Supplier SKU` · `Cost per unit` (inline-editable, defaults blank) · `Source` (`Manual` / `Shopify cost_per_item` / `Woo meta` / `CSV upload`). Toolbar: `Upload CSV` (bulk-set), `Pull from Shopify` (when store supports `cost_per_item`), `Reset to platform-native`. Empty-state copy: `No costs configured — ProfitMode will not compute for any product until at least one cost is set.`
2. **Shipping** — rule builder. Radio at top: `Flat rate` · `Per-order` · `Weight-tiered` (matches [UX §7 Cost inputs](../UX.md#cost-inputs)). Selecting a mode reveals the corresponding rule table (e.g., weight-tiered = `Min weight (g)` · `Max weight (g)` · `Cost` rows).
3. **Transaction fees** — one row per connected processor (`Shopify Payments`, `Stripe`, `PayPal`, `Mollie`, `Klarna`, `Manual`). Columns: `Processor` · `Percentage` · `Fixed fee` · `Applies to` (`All orders` / `Orders where payment_gateway = X`). Default values seeded from canonical processor rates and flagged as `Seeded — verify against your contract`.
4. **Platform fees** — Shopify plan subscription · app subscriptions. Columns: `Item` · `Monthly cost` · `Allocation` (`Per-order` / `Per-day`). Fixed costs are spread via the selected allocation method in ProfitWaterfallChart ([UX §5.17](../UX.md#517-profitwaterfallchart)).
5. **Taxes / VAT** — per country. Columns: `Country` · `Standard rate %` · `Included in price?` (boolean) · `Override for digital goods?` (boolean). Seeded from an internal VAT table (EU rates as of workspace creation date); editable.
6. **OpEx** — monthly fixed costs (salaries, rent, tools). Columns: `Category` · `Monthly cost` · `Allocation` (`Per-order` / `Per-day`).

Every cell edit is optimistic with rollback toast ([UX §6.2](../UX.md#62-optimistic-writes--undo-window)). Saving any cost change triggers the same `Recomputing metrics…` banner used by date-range / window changes, because profit metrics across every page retroactively recalc.

Below all sections: a **Preview card** — a compact `ProfitWaterfallChart` ([UX §5.17](../UX.md#517-profitwaterfallchart)) showing how the current cost inputs would break down last month's revenue. Missing inputs render as dashed "Not configured — click to add" bars that deep-link back up to the relevant section.

### Sub-page: `/settings/billing`

Single-column layout. Anti-Hyros tone — no dark patterns, cancellation in-product, revenue-share disclosed inline (per [_crosscut_pricing_ux.md § Nexstage positioning](../competitors/_crosscut_pricing_ux.md)).

- **Current plan card** (full-width):
  - Plan name: `Standard · €39/mo + 0.4% of revenue processed`.
  - Current-month to-date cost preview: `This month so far: €39 base + €184.20 revenue share = €223.20 (projected month-end: €312)`. Computed from `daily_snapshots` MTD revenue; projection uses same-day-last-month curve.
  - `Revenue-share calculator` — slider/input mirroring the marketing pricing page calculator; user can check "at €100k monthly revenue, I'd pay €439" ([_crosscut_pricing_ux.md § Metorik slider, § Nexstage positioning](../competitors/_crosscut_pricing_ux.md) pattern 1 + 8).
  - `Change plan` / `Cancel plan` buttons at bottom right; Cancel opens a flow with an honest screen (reason picker, optional feedback, effective date = end of current billing period, instant confirmation; no retention wizard loops).
- **Billing workspace hierarchy** (only rendered when `workspaces.billing_workspace_id` has children, i.e., agency-master):
  - Lists child workspaces with their per-workspace base + revenue-share contributions.
  - `Per-workspace` vs `consolidated` toggle controls how the next invoice renders.
  - Matches [_crosscut_multistore_ux.md § billing consolidation](../competitors/_crosscut_multistore_ux.md) — uses the existing `billing_workspace_id` column.
- **Payment method** — card-brand · last-4 · expiry. `Update` opens a Stripe Elements iframe. Failed-charge state surfaces here with a red `AlertBanner` ([UX §5.11](../UX.md#511-alertbanner) danger) and also in the left-nav as a red dot on `Billing`.
- **Invoice history** — `DataTable` of invoices:
  - `Issued` · `Period covered` · `Base` · `Revenue share` · `Total` · `Status` (`Paid` / `Open` / `Failed`) · `Download PDF`.
  - Export all invoices CSV (for bookkeeping).
- **Tax / VAT details** — EU VAT ID field (validated against VIES), billing address, company name. Appears on invoice PDFs.

### Sub-page: `/settings/notifications`

- **Email digest** card:
  - `Frequency`: Off / Daily / Weekly / Monthly (radio).
  - `Day-of-week` (weekly only; Peel pattern per [_crosscut_export_sharing_ux.md](../competitors/_crosscut_export_sharing_ux.md)).
  - `Time-of-day` (workspace timezone).
  - `Recipients` — multi-email input; recipients do not need a Nexstage account, per [_crosscut_export_sharing_ux.md § prevents seat inflation](../competitors/_crosscut_export_sharing_ux.md) / [UX §5.30](../UX.md#530-exportmenu). Comma-separated.
  - `Content` — which pages' data to include (checkbox group: Dashboard summary · Ads summary · SEO summary · Attribution summary · Orders summary).
  - `Send test now` button — delivers the exact payload to the current user before save ([UX §5.30 ExportMenu](../UX.md#530-exportmenu) mandate). Not behind a confirmation.
- **Slack** card (on-demand v1 per [_crosscut_export_sharing_ux.md](../competitors/_crosscut_export_sharing_ux.md)):
  - `Connect Slack workspace` OAuth button.
  - Once connected: pick a default channel for "Send to Slack" actions on data pages.
  - Copy strip: `Scheduled Slack digests arrive in v2 — [vote on the roadmap]`.
- **Anomaly alerts** card — thresholds per rule:
  - `Real ↔ Store delta > X%` (default 15%, per [UX §5.22 TriageInbox](../UX.md#522-triageinbox) + [_crosscut_export_sharing_ux.md § anomaly alerts](../competitors/_crosscut_export_sharing_ux.md)).
  - `Platform over-report > X%` (default 20%).
  - `Ad spend DoD ±X%` (default 40%).
  - `Integration down > X hours` (default 6h).
  - Delivery channels per rule: Email · In-app TriageInbox · Slack (when connected).
- **Who receives what** — a condensed `DataTable` listing team members × notification types, with checkboxes per cell. Lets an Owner see at a glance that the CFO isn't on the anomaly alerts list.

### Sub-page: `/settings/targets`

- **Target list** (`DataTable`):
  - `Metric` (Revenue / Profit / ROAS / MER / CAC / LTV / Orders / AOV / etc. — sourced from the `_crosscut_metric_dictionary.md` "Our pick" column).
  - `Period` (This week · This month · This quarter · Custom range).
  - `Target value` — inline-editable, tabular-num.
  - `Current / Pacing` — live value with a `TargetProgress` mini-bar ([UX §5.23](../UX.md#523-target)).
  - `Owner` (dropdown of workspace members; for accountability, not access control).
  - `Visible on` — chip list of pages where the `TargetLine` / `TargetProgress` will render (Dashboard + the relevant metric page).
  - Actions: `Edit` · `Archive` (10-second undo toast).
- Toolbar: `+ Add target` opens a guided modal (Polar-style step-by-step, per [_patterns_catalog.md](../competitors/_patterns_catalog.md#pattern-step-by-step-not-drag-drop-report-builder)): pick metric → pick period → set value → pick owner → preview where it'll appear.
- Admin+ only authors targets ([UX §5.23](../UX.md#523-target)). Members see them but cannot edit. Inline-edit cells are disabled with a tooltip for Members: `Only Admins can edit targets.`

## Below the fold

- **Audit log panel** (collapsible, closed by default) — on each sub-page, shows the last 20 setting changes scoped to that sub-page. Columns: `When` · `Who` · `What changed` · `From → To` · `Revert` (for reversible changes; destructive changes grey this out). JetBrains Mono for diff values.
- **Export this workspace's config** (Workspace sub-page only) — JSON download with all non-secret settings. Useful for support escalations and moving config between staging/prod. Secrets (Stripe customer id, OAuth tokens) are redacted.

## Interactions specific to this page

- **Optimistic writes everywhere**. Every inline edit reflects instantly with a subtle saving ring; failures revert with a Toast ([UX §6.2](../UX.md#62-optimistic-writes--undo-window)). Never use browser-native `confirm()` dialogs.
- **Destructive-action carve-out**. Delete workspace + cancel subscription + remove Owner use typed-confirmation modals. All other destructive actions (remove member, delete target, archive rule, reset mappings) use the 10-second undo toast. Per [UX §6.2](../UX.md#62-optimistic-writes--undo-window).
- **Retroactive recalc banner**. Editing any cost / window / attribution default shows the page-level `Recomputing metrics…` strip (Klaviyo-style retroactive recalc, [UX §6](../UX.md#6-interaction-conventions)). Banner dismisses itself when the recompute completes.
- **Left-nav `StatusDot`s are opinionated.** Amber on Costs when ProfitMode would fail; amber on Billing when the last charge failed; amber on Notifications when a digest delivery failed in the last 24h. They never fire for "setup is incomplete" nagware — only for actionable states.
- **Cross-sub-page deep-links.** Clicking `Add COGS to see profit` on any data page lands on `/settings/costs?section=cogs&highlight=<product_id>` with the relevant row scrolled into view and focused.
- **Billing `Cancel plan` flow** is one screen + confirmation — no retention gauntlet. Anti-[Hyros](../competitors/hyros.md) / anti-[Putler](../competitors/putler.md) cancellation-friction pattern from [_patterns_catalog.md Anti-pattern: Cancellation friction](../competitors/_patterns_catalog.md#anti-pattern-cancellation-friction).
- **Role enforcement**. Members see every sub-page but cannot save; save buttons are tooltip-disabled with `Only Admins can change workspace settings`. Billing sub-page is **Owner-only** — hidden from the left-nav for Admins and Members.

Shared interactions (URL state, Cmd+K, Esc, keyboard shortcuts) live in [UX §6](../UX.md#6-interaction-conventions) and are not restated here.

## Competitor references

- [Elevar email alerts / monitoring settings](../competitors/elevar.md#email-alerts--monitoring-settings) — the anomaly-alerts card models its rules after Elevar's configurable-threshold pattern.
- [Shopify native Settings chrome](../competitors/shopify-native.md) — left-nav settings convention our shell copies. Merchant familiarity > novelty.
- [Stripe Dashboard billing screen](../competitors/_inspiration_stripe.md) — invoice table shape, hosted payment update, no dark patterns on cancellation.
- [Vercel / Linear team settings](../competitors/_inspiration_linear.md) — role editing as inline-editable table cells; invite via email multi-input.
- [Klaviyo brand-asset auto-extraction](../competitors/klaviyo.md) — Workspace sub-page prefills logo / color / currency / country from Shopify OAuth, not a blank form. Per [_crosscut_onboarding_ux.md](../competitors/_crosscut_onboarding_ux.md).
- [Glew inline COGS on products](../competitors/glew.md) — the inline-editable cell pattern applied to the COGS table.
- [Lifetimely payment-gateway fees as manual-entry step](../competitors/lifetimely.md) — Transaction fees section copies this honest-disclosure approach; seeded rates are flagged `verify against your contract` instead of silently defaulting.
- [_crosscut_pricing_ux.md § Nexstage positioning](../competitors/_crosscut_pricing_ux.md) — the Billing sub-page's revenue-share calculator mirrors the marketing pricing page so users can sanity-check what they'll pay.
- [_crosscut_multistore_ux.md § v1 role model + billing consolidation](../competitors/_crosscut_multistore_ux.md) — Team roles (Owner / Admin / Member), `billing_workspace_id` consolidation in the Billing sub-page, no SSO tax.
- [_crosscut_export_sharing_ux.md § v1 must-haves + send-test button](../competitors/_crosscut_export_sharing_ux.md) — Notifications digest config (day-of-week, recipients-without-accounts, send-test-before-save, anomaly alerts).
- [Putler Pulse targets · Lifetimely forecast-as-goal lock · Polar Custom Targets](../competitors/_patterns_catalog.md#pattern-goal-progress-bar--target-line) — Targets sub-page pattern.
- Anti-[Hyros](../competitors/hyros.md) + anti-[Triple Whale](../competitors/triple-whale.md) billing chrome (no upgrade nags inside paid tier, no friction-to-cancel).

## Mobile tier

**Mobile-usable** (768+) for Workspace / Team / Billing / Notifications / Targets. **Desktop-only** (1280+) for **Costs** (inline-editable tables need horizontal room and a mouse for rapid cell navigation). On narrower viewports the Costs sub-page renders a `View on desktop` banner consistent with other desktop-only surfaces ([UX §8](../UX.md#8-responsive-stance)).

- Left-nav collapses into a top segmented-control strip (horizontally scrollable).
- Tables collapse to card-stacks; inline editing becomes tap-to-open-sheet on mobile.
- Stripe Elements iframe (payment method update) works mobile-native.
- Send-test and save buttons stay full-width at the bottom so thumbs reach them.

## Out of scope v1

- **SSO / SCIM provisioning UI** — SAML is Enterprise-plan only and configured out-of-band; SCIM deferred to v2+ per [_crosscut_multistore_ux.md § SSO/SCIM](../competitors/_crosscut_multistore_ux.md).
- **Guest / Client role** — v2 with the Agency SKU.
- **Per-dashboard / per-metric access control** — v3, only if 3+ customers request it.
- **Custom roles builder** — deliberately not built; three roles are enough for SMBs ([_crosscut_multistore_ux.md § role granularity anti-pattern](../competitors/_crosscut_multistore_ux.md)).
- **Scheduled Slack digests** — v2 ([_crosscut_export_sharing_ux.md](../competitors/_crosscut_export_sharing_ux.md)); v1 has on-demand "Send to Slack" + email digests only.
- **Google Sheets direct-export destination** — v2.
- **White-label settings** (agency logo on PDFs, custom email "From") — v2 with the Agency SKU.
- **API keys / personal access tokens / REST API** — no public API in v1 per [_crosscut_export_sharing_ux.md § anti-scope](../competitors/_crosscut_export_sharing_ux.md).
- **Data residency / EU-only hosting toggle** — separate plan conversation, not a self-serve setting.
- **Custom anomaly-rule builder** (arbitrary metric × threshold × comparison) — v1 ships the four canonical rules; custom rules are v2.
- **Workspace templates** ("copy settings from existing workspace") — v2 agency feature per [_crosscut_multistore_ux.md § 3 agency tier features](../competitors/_crosscut_multistore_ux.md).
- **In-app CSV upload for team invites** (bulk-invite across multiple workspaces) — v2 agency feature.
- **Audit log export / retention configuration** — v1 shows last 20 events per sub-page; full audit export is v2.
