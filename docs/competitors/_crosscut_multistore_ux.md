# Multi-store / agency UX — cross-competitor deep dive

Cross-cut of 11 ecommerce analytics tools and three adjacent UX benchmarks (Shopify Plus native, Linear, Vercel), focused on how each handles multiple stores, multi-brand aggregation, and agency workflows. Profiles in `/home/uros/projects/nexstage/docs/competitors/*.md` are the baseline. This doc lives above them and feeds directly into the Nexstage workspace switcher + permissions v1 decisions.

## The three user shapes

1. **Single-store founder.** One WooCommerce or Shopify store. Often the only user. Doesn't need a switcher and actively dislikes UI chrome that implies complexity they don't have. Today this is the Nexstage modal ICP. A workspace switcher that is invisible for this user — zero chrome overhead when there's only one store — is a hard requirement.

2. **Multi-brand operator.** 2–10 stores under one organization. Variants include (a) a holding company with 5 DTC brands on Shopify, (b) one brand with a Shopify storefront plus a WooCommerce B2B site, (c) a brand with Shopify US + Shopify EU + Shopify UK expansion stores. Wants a consolidated revenue number across brands, but also wants to drill into "which brand is dragging us down this week?" Sharply distinguishes aggregated vs per-brand in a way agencies do not — the brands are theirs, not clients, and they want per-brand contribution visibility, not client-style isolation.

3. **Agency / consultant.** 10–100+ client stores. Performance-marketing agencies, growth consultants, Shopify Plus SIs. Needs (a) one login that sees every client, (b) scoped access so a junior strategist on client A can't see client B, (c) scheduled reports to clients, (d) consolidated agency billing (one invoice, many stores) or agency-paid-on-behalf-of-client, (e) white-label for the "our analytics platform" pitch. Nexstage's revenue-share pricing (€39 min + 0.4% GMV per store) fits this persona particularly well if we make the per-store rollup math legible.

## Research method — what we studied

Tools surveyed. "Yes native" = first-class feature, "via workspaces" = implemented by linking separate accounts together, "no" = not supported as described:

| Tool | Multi-store native | Consolidated/aggregated | Agency mode | White-label | Source |
|---|---|---|---|---|---|
| **Triple Whale** | Yes via "Multi Shop View" (formerly Pods) | Yes (Blended ROAS across shops) | Yes — Bronze/Silver/Gold/Platinum Partner Program | Partial (branded reports not full UI) | `triple-whale.md`, kb.triplewhale.com |
| **Northbeam** | Via separate dashboards 1:1 per domain, rollup dashboard for reporting | Limited — aggregation is an add-on rollup | Yes — Partner Program, referral network | Not documented | `northbeam.md`, docs.northbeam.io/docs/multi-brand-configuration |
| **Polar Analytics** | Yes — Workspaces (one per brand) with "Advanced Views" for aggregation | Yes across Workspaces on Custom/agency plan | Yes — agencies get bulk discounts, Partner Program, 14-day trial | Yes — white-label reports with agency branding | `polar-analytics.md`, intercom.help/polar-app |
| **Lifetimely (AMP)** | Yes — multi-store dropdown + Consolidated view | Yes — combined P&L across stores | Not a dedicated program; each store = separate subscription | No | `lifetimely.md`, help.useamp.com |
| **Peel** | Yes — per-tier store cap (Essentials 3, Accelerate 7, Tailored unlimited) | Partial — store-switcher in top bar | Not a dedicated program | No | `peel-insights.md` |
| **Glew** | Yes — designed for this from day 1 | Yes — "All Brands" mode with per-brand contribution bars | Yes — agency-targeted; white-label included | Yes — agency logo on all insights at no extra cost | `glew.md`, glew.io/white-label-solution-easier-building |
| **Putler** | Yes — unified dedup across payment sources + multiple stores | Yes — consolidated view, currency-normalised, timezone-normalised | No dedicated agency tier | No | `putler.md`, putler.com/multi-currency-reporting |
| **Daasity** | Yes — omnichannel (Shopify + wholesale + retail + Amazon in one warehouse) | Yes — semantic layer unifies everything | Via Shopify Plus partner channel, not a self-serve agency tier | Partial — Looker-level branding possible | `daasity.md` |
| **Shopify Plus Org Admin** | Yes — native org-level analytics across stores in org | Yes — multi-store reporting section or custom section | N/A (merchant tool) | N/A | help.shopify.com/en/manual/organization-settings/analytics, changelog.shopify.com |
| **Hyros** | Partial — Shopify native; WooCommerce is second-class; multi-brand Agencies SKU exists but is demo-gated | Not documented publicly | Agencies SKU exists, pricing opaque | Not documented | `hyros.md` |
| **Metorik** | Yes — one subscription covers multiple stores per their pricing page | Not a headline feature — each store gets its own context | No agency mode | No | `metorik.md`, metorik.com/pricing |

UX benchmarks (not competitors):
- **Linear** — keyboard-first workspace + team switching (Cmd+K command palette, `G` then `T` for team nav) — the reference for keyboard-friendly multi-context switching.
- **Vercel** — top-left team switcher that preserves current view when switching; "favourite projects across teams" for cross-team pinning.
- **Stripe Connect / Clerk / Linear** — permissions models for org-scoped roles vs resource-scoped roles.

## Switching UX

### Store/workspace switcher component

**Triple Whale — Multi Shop View (ex-Pods View).** The top chrome includes a shop selector. Clicking opens a panel listing all shops. Agencies create named **Views** (formerly Pods) that group shops — e.g. "US brands", "EU brands" — and pick any View to see aggregated metrics across that group. The redesigned summary layout shows Blended ROAS, Ad Spend, Order Revenue across the selected group. Clicking a shop name in the panel jumps into that shop's full single-tenant dashboard. Source: kb.triplewhale.com/en/articles/13045107.

**Polar Analytics — Workspaces.** A Workspace is a unique brand/account. Switching is under the avatar menu top-right; "Switch Workspaces" opens a list. Each Workspace is an independent account (custom dashboards don't auto-sync across), but reports and dashboards can be copied between them. One login, multiple Workspaces. Google Sign-In works across all of them. Source: intercom.help/polar-app/en/articles/10128572.

**Lifetimely — Store dropdown in left nav.** Lives in the sidebar, not the top bar. Options are each connected store plus a "Consolidated view" that shows combined totals. Subtle warning: "Consolidated metrics may not perfectly match the sum of individual stores if they use different time zones." Each store requires its own subscription. Source: help.useamp.com/article/660-connect-multiple-shopify-stores.

**Glew — top-chrome brand/store selector.** Persistent dropdown at the top. Two primary modes: **"All Brands"** aggregated and **Per-brand drill-down**. A single click toggles between them; the rest of the dashboard re-renders. Brand, region, channel, and tag filters on top of either mode. Permissions: brand-scoped users who only see their brand. Source: `glew.md` (our existing profile); glew.io/solutions/brands.

**Putler — consolidated by default, per-store as a view.** Putler unifies payment sources and stores under one dashboard (it's their entire pitch — PayPal + Stripe + Shopify + Woo in one view). "Separate views" can be created per store for drill-down. Consolidated is the default; individual-store is the exception. Source: `putler.md`, putler.com/cross-platform-analytics.

**Vercel (UX benchmark).** Top-left team switcher. Opens a panel listing teams with the user's default team starred. Critically, **switching a team preserves the current view** — if you're on Web Analytics and switch teams, you land on the new team's Web Analytics, not their default landing. "Favourite projects across teams" lets you pin a resource that lives in team A to your global sidebar. Source: vercel.com/changelog/improved-experience-for-moving-between-your-teams-and-projects.

**Linear (UX benchmark).** Cmd+K opens a command palette; typing a workspace or team name jumps there instantly. No explicit switcher chrome. The whole navigation is keyboard-first. This works because Linear users are developers; works less well for a merchant persona — but the command-palette pattern is the best keyboard-friendly multi-context switch in any B2B SaaS. Source: shortcuts.design/tools/toolspage-linear.

**Shopify Plus Org Admin.** Multi-store reporting is at the organization level, not per-store. You see a dashboard "across your entire organization or for select stores" — selection is via a filter inside the dashboard itself, not a switcher. For per-store work you bounce to the individual admin. This two-tier split (org dashboard vs per-store admin) is the native baseline. Source: help.shopify.com/en/manual/organization-settings/analytics.

**Takeaway on switcher placement.** Top-chrome persistent dropdown (Triple Whale, Glew, Vercel) is the dominant pattern for this category. Sidebar (Lifetimely) is the exception and reviewers don't complain but it's harder to reach during rapid switching. Avatar-menu-gated (Polar) hides the switcher — fine if switching is infrequent (one merchant with 3 stores) but bad for agencies bouncing between 20 clients. Command palette (Linear) is a second-class accelerator, not a primary surface, and is always paired with a visible switcher.

### Deep-linking

**Triple Whale — sharable links preserve filter/shop/date state.** Copy URL, paste to colleague, they land on the exact view. The shop context is in the URL.

**Northbeam — sharable deep-links with filter/sort/model state preserved.** Our profile calls this out (`northbeam.md` line 150). Shop context is domain-scoped — a deep-link is always in the context of one dashboard.

**Vercel — current view persists across team switches** (not the URL but the intent — if you were on Analytics, you stay on Analytics). Opposite of Polar, where switching Workspaces drops you back to the Workspace's home.

**Lifetimely — store switcher preserves date range but drops to the store's home page.** Consolidated view has its own date-range setting that lives separately from per-store.

**Takeaway on deep-linking.** The correct pattern is: URL encodes workspace + current page + filters. Switching workspace rewrites the workspace portion of the URL and keeps the page + filter portion where possible. Drop-to-home is lazy and infuriating on repeat switches — it is the single worst agency UX pattern in the set.

### Aggregated vs per-store toggle

**Glew is the benchmark.** Top-chrome dropdown with two explicit states: "All Brands" aggregated vs per-brand drill-down. Same dashboard layout in both; only the scope changes. KPI cards in the All Brands mode carry a stacked horizontal contribution bar underneath — "$1.2M total revenue" shows brand A = 42%, brand B = 35%, brand C = 23% as coloured segments. This is the specific UX we most want to steal.

**Polar Analytics Advanced Views.** Per their multi-store Shopify page — "analyze aggregated brand metrics and see data for each store." Country-specific reporting across the portfolio. The mechanics are an aggregation layer over independent workspaces — less fluid than Glew's single-click toggle but more powerful for cross-workspace drilldown.

**Shopify Plus Org Admin.** The dashboard has a store-selection control within each section. Default section aggregates across all stores; users can add custom sections scoped to specific stores. Compare-between-stores is first-class: "Compare performance by store, region, or brand and in multiple currencies." No single-click aggregated/per-store toggle — it's a filter, not a mode. Still one of the cleaner implementations because the filter is on the cards themselves, not a global chrome-level switch.

**Triple Whale Multi Shop View.** "Views" (named groups of shops) are the aggregation primitive. Switch to a View = see aggregated; click a shop inside the View = drill down. Named groups are a stronger primitive than Glew's binary toggle because agencies often want "US East brands" and "EU brands" as separate views rather than a single "All Brands".

**Takeaway.** Three design choices:
1. **Binary toggle** (Glew) — "All Brands" vs the currently-selected brand. Simple, fast, good for 2–10 stores.
2. **Named groups** (Triple Whale) — user-defined Views. Scales to 20+ stores and agencies managing client cohorts.
3. **Filter-in-card** (Shopify Plus) — the "aggregate" is itself a filter. Powerful but harder to discover.

Nexstage should offer (1) as default UX and (2) as the power-user escape, because we have both single-brand operators and agencies. (3) is for enterprise and can wait.

## Aggregation UX

### Revenue across stores

**Putler sets the bar for currency handling.** 36 currencies supported. User picks a base/reporting currency once. Every transaction auto-converts **at the mid-market closing rate of the day the transaction was made** — not current rate. This is important: current-rate conversion would silently change historical numbers whenever FX moved. Multi-store aggregation: transactions from a USD store, an EUR store, and an INR store all convert to the base and roll up. Source: putler.com/multi-currency-reporting.

This matches Nexstage's `fx_rates` cache design (historical FX is stored per-date; we don't hit Frankfurter at query time). Our `WorkspaceSettings` already carries `reporting_currency`; the missing piece is surfacing the conversion in the UI. Putler doesn't do this well — their reviewers cite opaque number provenance as a recurring complaint (`putler.md` line 37).

**Lifetimely — consolidated-view setting for currency, timezone, refund behaviour.** The consolidated view is a computed object with its own settings separate from each store's native settings. Important admission per their docs: "Consolidated metrics may not perfectly match the sum of individual stores if they use different time zones." Honest, but indicates the computation is fraught.

**Shopify Plus Org Admin — compares performance "in multiple currencies".** Implies per-card currency toggle rather than one base currency for the whole dashboard. This is the opposite of Putler's approach; it preserves per-store native currency where users want it. Good for ops teams that think in native currency per market; bad for CFOs who want one number.

**Per-store labels inside aggregated charts.** Best-in-class: Glew's stacked contribution bars under each KPI card (brand split at a glance). Lifetimely shows a legend-style brand breakdown next to charts. Triple Whale Views show summary metrics but rely on drill-down for per-shop contribution. None of these tools does a great job with **per-store sparklines inside a single KPI card** — that's a pattern Nexstage could invent.

**Takeaway.** For Nexstage:
- Pick one reporting currency per workspace (already in `WorkspaceSettings`). 
- Convert at transaction-day FX (already in `fx_rates`).
- Every KPI card on the aggregated dashboard should display the converted amount with a **hover tooltip** showing native amount per store. ("$1.24M combined — hover: $800k USD + €380k × 1.08 + £50k × 1.27"). The `MetricCard` already has six source badges; this adds a seventh "FX conversion" detail on hover without bloating the card.

### Per-brand contribution

**Glew's stacked contribution bar** (covered above) is the specific pattern to steal. Under the revenue KPI card, a 16–24px horizontal segmented bar. Each segment represents one brand, colour-coded, sized by share. Hover shows the exact amount per brand. One glance = total + composition.

**Triple Whale's Shop Breakdown.** Multi-shop reporting with shop breakdown is a dedicated report surface, not inline on KPI cards. A table with one row per shop, columns for each metric. More data-dense, less at-a-glance. Good for exports; bad for the dashboard glance.

**Putler's 80/20 breakdown chart** — visually separates "top 20% that drive 80%" from the long tail. Could be repurposed for multi-brand: "top 20% of your brands drive 80% of revenue", with a slim contribution chart underneath. A different framing than Glew's but in the same family.

**Daasity's Layer Cake chart.** Every cohort (or in our case, every brand) visibly contributes to revenue over time as a stacked area — new brands enter as fresh layers, existing brands contribute thinner layers as they stabilise. Executive-readable. Works specifically for multi-brand operators tracking acquisition cadence across a portfolio.

**Takeaway.** Glew's stacked contribution bar inside KPI cards is the cheapest high-impact pattern. Daasity's Layer Cake is a complementary chart type for the aggregated dashboard. Triple Whale's flat shop-breakdown table is the export/report surface. Ship all three — they serve different user intents (glance / time-series / export).

### "This brand vs. portfolio" comparison

**Glew — per-brand drill-down mode** shows the brand's dashboard alongside a "% of portfolio" chip on each KPI (e.g., "Revenue: $420k — 35% of portfolio"). Simple, directly answers "how big am I in the context of our group?"

**Northbeam's Metrics Explorer with correlation tiles.** Not brand-specific, but the same idea: how does this entity compare to peers? Pearson correlation tiles surface relationships. For multi-brand, you could correlate brand A's spend with brand B's revenue to detect halo effects.

**Triple Whale's Benchmarks dashboard.** Your store vs. peer cohort median — shaded peer band around your line. Generalises to "your brand vs. the portfolio average" for multi-brand ops.

**Takeaway.** For Nexstage: when a user is in per-brand mode, the top of every KPI card should show "% of portfolio" chip. Adds ~20 pixels; high information density per pixel. Separately, a portfolio-benchmark view (this brand vs portfolio median) is a credible v2 feature for the multi-brand operator persona but not essential for v1.

## Agency / white-label UX

### Client reporting

**Polar Analytics — scheduled reports with white-label branding.** Agencies get white-label reports "featuring agency branding to build credibility." Tables and dashboards schedule to email and Slack. Agencies can invite the client as a **Viewer** on their single-brand account, and scheduled reports deliver customized output. Source: polaranalytics.com/solutions/agencies; intercom.help/polar-app/en/articles/5649880.

**Glew — white-label at no extra cost** for agencies. "Your agency's logo becomes the look of all customer insights, reporting, and analytics." Scheduled exports per client, shareable dashboards, and no-code custom reporting. Source: glew.io/white-label-solution-easier-building.

**Triple Whale — branded reports, not full UI white-label.** Reports can carry agency branding; the app itself is Triple Whale-branded. Partner Program has a referral/revenue-share component but the UI isn't re-skinnable per client.

**AgencyAnalytics (adjacent — not a competitor but a client-reporting benchmark).** Pricing runs approximately $10–$15 per client campaign per month. Full white-label including a custom domain (insights.youragency.com). Automated scheduled reports to clients. This is the "pure client reporting" model — what agencies typically pair with one of the analytics tools above. Source: almcorp.com/blog/white-label-client-reporting-agencies.

**Takeaway.** Two flavours of white-label:
- **Light** — agency logo on scheduled PDFs and emails. Glew and Polar offer this; easy to build.
- **Heavy** — custom domain, full CSS override, login screen branding. Only AgencyAnalytics-tier tools offer this; expensive to build and maintain. Not a v1 priority.

A **client portal pattern** (client logs in directly, sees only their brand, can't see agency chrome) is available in Polar's Distributed Dashboards approach: agency creates one Polar account per brand, invites the customer as Viewer. This works today without any extra feature build; just needs documentation.

### Billing consolidation

**Polar Analytics — bulk discounts for agencies managing multiple Workspaces.** Each Workspace is priced independently, with a volume discount on the Analyze (Custom) plan. Source: intercom.help/polar-app/en/articles/10128572. Not a single invoice — each Workspace bills separately but the agency is the owner.

**Triple Whale Partner Program — revshare, not consolidated billing.** 0–15% revenue share paid over 12–18 months. Each brand pays Triple Whale directly; the agency earns commission. Not an "agency pays for all clients" model.

**Lifetimely — each store requires its own subscription.** No consolidated billing. Agencies would either ask the client to subscribe directly or absorb per-store cost on their card.

**Glew — pricing opaque; consolidated billing is unclear from public docs.** Likely available through Glew Plus enterprise tier.

**AgencyAnalytics — per-client-campaign pricing inside one agency subscription.** One invoice, many clients. $10–$15/client/month added to base agency plan. This is the model agencies expect.

**Takeaway.** Agency-tier billing needs both modes:
- **Agency-paid** — one card on file, one invoice, all stores billed to agency. Agency marks up and invoices the client separately.
- **Client-paid** — each workspace has its own payment method. Agency has admin access but never pays.

Nexstage's existing `billing_workspace_id` column on `workspaces` already supports this (a child workspace references a parent billing workspace). This is exactly the right primitive — we just need UX on top.

### White-label depth

Category summary:

| Feature | Glew | Polar | Triple Whale | Peel | Lifetimely | Shopify Plus |
|---|---|---|---|---|---|---|
| Agency logo on reports | Yes (free) | Yes (Custom plan) | Yes (reports only) | No | No | N/A |
| Agency logo in app UI | Yes | Partial | No | No | No | N/A |
| Custom domain | Not confirmed | No (documented) | No | No | No | N/A |
| Full CSS override | No | No | No | No | No | N/A |
| Custom login screen | Not confirmed | No | No | No | No | N/A |

Full CSS white-label is rare in this category because the tools themselves are complex enough that re-theming is a maintenance nightmare. Agency-logo-on-reports is table stakes at the Custom/Plus tier. Custom domain is aspirational and usually restricted to tools whose entire value prop is "be my analytics layer" (e.g., AgencyAnalytics, Zoho Analytics).

**Takeaway for Nexstage.** For v1 agency tier: (a) agency logo on the scheduled PDF exports; (b) agency name in email "From" (e.g., "Dashboard from Acme Agency <reports@acme.com>"); (c) per-workspace branding settings (logo + primary colour) so clients see *their* brand, not the agency's. Skip full CSS override and custom domain for v2+.

## Permissions / roles

### Role model

**Polar Analytics.** Per-workspace role assignment. Documented roles include at least "Visitor" and "Admin"; they call them "custom roles" so the role taxonomy appears flexible. Source: intercom.help/polar-app/en/articles/10128572.

**Triple Whale.** "Secure, role-based access" for portfolio management. Specific roles not publicly documented but they mention "Adding Team Members to Multiple Shops at Once" as a dedicated help article — implies per-shop role assignment with a bulk-invite surface.

**Northbeam.** "Role-based permissions allow you to grant agency partners or team members access to specific dashboards without exposing full account data." Dashboard-scoped permissions, not just store-scoped.

**Glew.** "Custom roles and permissions" across brands. Brand-scoped users are explicitly supported.

**Shopify Plus Org Admin.** "Organization > View sales and orders across all of your live stores" is a specific permission, separate from per-store admin permissions. Org-level analytics are gated to a specific role bit.

**Linear (UX benchmark).** Three tiers (Admin / Member / Guest) with workspace-global roles plus team-scoped overrides. Guests are the elegant primitive for client-level access — a guest sees only the teams they're invited to, nothing else in the workspace.

**Takeaway — v1 role model.** Three global roles inside a workspace:
- **Owner** — billing, delete workspace, invite admins.
- **Admin** — manage integrations, invite members, edit settings.
- **Member** — view everything, edit dashboards, can't touch billing or integrations.

For agencies (v2), add:
- **Guest / Client** — sees only specific workspaces the agency grants, cannot see other workspaces in the agency's master account.

Per-resource role overrides (e.g., "this user can see everything except the profit view") are a v3 concern. Most SMBs don't want this, and multi-brand operators who do will upgrade to a separate tier.

### Data access gating

**Northbeam.** Dashboard-scoped permissions — "access to specific dashboards without exposing full account data." The primitive is the dashboard, not the metric.

**Polar.** Workspace-scoped. A Visitor on Workspace A cannot see Workspace B, period.

**Glew.** Brand-scoped users in their per-brand mode.

**No tool in the set restricts access by metric.** You cannot hide profit margin from performance marketers while still showing them ROAS. Every tool that has granular access gates at the dashboard or workspace level, not the metric level. This is an underserved pattern — a growth-marketing director who doesn't want their junior media buyer to see profit margin (because the junior will over-optimise for ROAS) currently has no in-tool solution.

**Takeaway for Nexstage.** v1: workspace-scoped roles. v2: per-dashboard access lists for agencies with sensitive clients. v3 (speculative): per-metric redaction for the "hide profit from perf marketers" use case, if we hear that request from 3+ customers.

### SSO / SCIM

- **Linear** — SCIM 2.0 on Enterprise plan only. Source: stitchflow.com/scim/linear.
- **Analytics SaaS category** — SSO/SAML is an Enterprise feature across the board. SCIM is rarer; typically locked behind the highest tier.
- **"SSO tax" is universal** — tools charge 2–4× the base product price for the enterprise plan that unlocks SSO/SCIM. Documented pattern across the industry. Source: securityboulevard.com/2026/02/best-sso-scim-providers-for-b2b-saas-selling-to-enterprise-2026-ranked-guide.

**Takeaway.** SAML SSO on the Enterprise plan only (matches Nexstage's `billing_plan = 'enterprise'` already in the schema). SCIM provisioning deferred to v2+; agencies with <20 members handle user lifecycle manually without friction. Don't levy an SSO tax on our self-serve tier — we're positioning against Enterprise-gate tools, and charging €500/mo extra for SAML undercuts that.

## Onboarding for multi-store

### Adding additional stores after signup

**Triple Whale.** App Store install per store — OAuth each individually. Each new shop runs its own Triple Pixel install. Multi Shop View then groups them. Documented friction: invites and permissions have to be re-set per shop unless you use the bulk-invite surface.

**Polar.** "Create Workspace" from the user menu, pick a name, select which account's data to import, contact support to finalize. Human-in-the-loop — not self-serve at the activation step. Friction point.

**Lifetimely.** Connect a second Shopify via the Settings > Multiple Shops screen. Automatic — no human step. However, each store requires a separate paid subscription, so the friction is financial rather than procedural.

**Shopify Plus Org Admin.** Stores are added at the organization level by a Plus Admin. Near-zero friction if you're already inside the Plus org; impossible from outside.

**Glew.** Self-serve from Settings > Integrations. No separate subscription per store — all included in the plan (Plus tier pricing scales with brand count anyway).

**Takeaway.** Two patterns:
- **Per-store pricing** (Lifetimely, Polar) — adding a store = adding a subscription. Transparent; capacitates scale.
- **Plan-included** (Glew, Triple Whale at higher tiers) — plan includes N stores. Simpler onboarding; less transparent.

Nexstage's model is neither of these — we price at €39/mo minimum + 0.4% GMV per workspace. Each new workspace is a new subscription that starts at €39 and scales with GMV. This is more like Lifetimely's per-store model but auto-scaling. The UX implication: **adding a store is adding a new workspace**, and the pricing impact should be visible at the add-store step. ("Adding 'Acme EU' will add €39/mo minimum, scaling to 0.4% of its monthly GMV once imports complete.")

### Historical backfill for newly-added stores

- **Triple Whale** — full historical backfill on Triple Pixel install for the shop. Ad-platform history limited by OAuth token. Sync progress visible in Setup Guide.
- **Polar** — automatic backfill of Shopify "as far as Shopify allows" (2+ years). CSM-guided for other connectors.
- **Lifetimely** — 30 min – 24 hr per store. Explicit progress bar. Each store backfills independently.
- **Glew** — documented as automatic but timing unclear; Plus tier offers warehouse-level backfill.
- **Shopify Plus Org** — no separate backfill; data is already in the Plus org.

**Takeaway.** Historical backfill per new store is expected behaviour and no tool charges extra for it. Nexstage's `AdHistoricalImportJob` and `ShopifyHistoricalImportJob` already handle this; the UX question is whether to run all new-store backfills at normal priority (risk: overwhelms queue if an agency adds 10 stores at once) or throttled. Recommend throttled — max 3 concurrent historical imports per agency master account.

## Patterns worth stealing (specific)

1. **Top-chrome aggregated/per-store toggle with stacked contribution bars inside KPI cards** — Glew. Binary toggle for the 80% case (1–5 stores); single click between "All stores" and "This store". When in All-stores mode, every `MetricCard` shows a 16–24px horizontal segmented bar underneath the headline number, one coloured segment per store, sized by share of total. This is the highest-leverage UX move in the entire research set.

2. **Named "Views" as a power-user escape** — Triple Whale Multi Shop View. User-defined groups of stores with custom names ("US East brands", "EU brands", "Client cohort Q1"). Lives alongside the binary toggle so single-brand users never see it.

3. **Workspace switcher preserves current view on switch** — Vercel. If the user is on the Ads page in Workspace A and switches to Workspace B, they land on the Ads page in Workspace B, not B's home. Rewrite the workspace segment of the URL, keep the rest.

4. **Cmd+K command palette as keyboard accelerator** — Linear. Ship the visible top-chrome switcher first; add Cmd+K as a shortcut for power users. The command palette should list workspaces at the top, then recent pages, then all pages, then actions.

5. **Per-store sparklines inside aggregated KPI cards** — unclaimed. Nobody does this well. A 40-wide × 20-tall sparkline showing each store's line (one colour per store) inside each KPI card when in aggregated mode. Communicates trend divergence at a glance ("brand A is dragging, brand B is flat, brand C is up 30%").

6. **Workspace colour / avatar / emoji** — minor but agency-grade. Each workspace gets a user-picked colour or emoji that appears in the switcher, URL breadcrumb, and any cross-workspace surface (scheduled reports list, invoice PDF). Helps agencies spot which client they're looking at without reading the name.

7. **FX conversion on hover for consolidated views** — extension of Putler's approach with better provenance. The KPI card shows the converted aggregated number by default; hover reveals native-currency breakdown per store with the FX rate and transaction-date used.

8. **Contribution chips on per-brand drill-downs** — Glew. "35% of portfolio" chip on each KPI when viewing a single brand inside a multi-brand workspace. Answers the "am I big in this portfolio?" question instantly.

9. **Bulk-invite across workspaces** — Triple Whale "Adding Team Members to Multiple Shops at Once". One modal, pick multiple workspaces, pick role, invite. Agencies with 30 clients don't want to invite their strategist 30 times.

10. **Scheduled per-client reports with agency branding in the PDF header** — Polar, Glew, AgencyAnalytics. Specific: agency logo in header, client logo below, period covered, executive summary at top, charts below. Send via email on schedule (weekly/monthly). This is the MVP of white-label; full UI re-theming is not.

11. **"Guest" role as the client-access primitive** — Linear. An agency invites the client as a Guest; the Guest sees only the workspace(s) they were invited to, nothing else in the agency's master account. Simplest possible implementation of a client portal — no separate surface, just a constrained role.

12. **Workspace-parent relationship for consolidated billing** — analogous to Stripe Connect / Clerk organizations. Our `billing_workspace_id` column already encodes this. UX: the agency has a "master" billing workspace; all client workspaces reference it; one Stripe customer, many invoiced line items.

13. **Per-store subscription with GMV-scaled pricing disclosed at add-store step** — Lifetimely's model, corrected for transparency. When the user clicks "Add store", show the pricing impact up-front: "Acme EU will add €39/mo minimum, scaling to 0.4% of monthly GMV." No surprises at the next invoice.

14. **Multi-Shop Reporting table as the export surface** — Triple Whale. A flat table with one row per store and one column per metric, for the exec report / board deck. Lives alongside but separate from the KPI-card dashboard. Users need both.

## Anti-patterns to avoid

1. **Switching drops to home** — Polar's "Switch Workspaces" lands on the workspace home, not the current view. Fatigue-inducing for agencies switching 20 times a day. Source: intercom.help/polar-app/en/articles/10128572. Vercel is the counter-pattern.

2. **Sidebar-buried switcher** — Lifetimely. Fine for a merchant with 2 stores; terrible for an agency with 30. Top chrome is where users expect the context switcher.

3. **Each store = separate subscription with no bulk UX** — Lifetimely. "Each Shopify store connected to your multi-store account requires its own subscription." Per-store billing is fine; making the user click through 30 payment flows to onboard 30 client stores is not. Agencies need bulk onboarding with one card on file.

4. **No consolidated view across workspaces by default** — Northbeam's "each business has its own dashboard" with rollup only as an add-on. For multi-brand operators, the aggregated view is the whole point of the tool. Making it opt-in is exactly the wrong default.

5. **Human-in-the-loop to create a workspace** — Polar's "contact support to finalize activation" on new workspace creation. For agencies adding a 31st client, a sales conversation is insulting. Self-serve is table stakes.

6. **Opaque currency conversion** — Putler's reviewers cite "unsure what this number means or how it was calculated." Multi-store aggregates silently convert without showing the conversion rate or native amount. Destroys trust exactly where trust is most fragile (the aggregated number).

7. **Name collision on aggregation labels** — every tool calls the aggregated view something different ("All Brands", "Consolidated", "Multi-shop", "Portfolio", "Organization"). We should pick one term and stick to it. Proposal: "Portfolio" for the aggregated view (distinct from "Workspace" for a single brand); keep "Workspace" as the single-tenant entity and introduce "Portfolio" only when the user has 2+ workspaces. Zero chrome for single-workspace users.

8. **Per-shop rebill + per-shop re-install + per-shop re-configure COGS** — common across the category. If an agency adds 10 stores, they shouldn't re-enter shipping fees and COGS defaults 10 times. Workspace templates: create a new workspace, pick "copy settings from [existing workspace]", done.

9. **Role granularity that doesn't match the persona** — Glew offers "custom roles" which most SMB agencies never configure. Over-engineered v1 permissions = agency gives everyone Admin, defeating the point. Start with 3 roles (Owner/Admin/Member) and add Guest/Client later when requested.

10. **Forcing currency choice at workspace create** — some tools require a reporting currency setting before any data loads. Default to the store's native currency (Shopify/Woo tells us this on OAuth); let the user change it later. One less form field.

11. **No bulk operations across workspaces** — Triple Whale has bulk-invite; most others don't. If our agency user wants to update the target ROAS across 30 clients, doing it 30 times is a deal-breaker. Bulk-update surface needed at the agency-master level.

12. **Enterprise-gating SSO at a steep price uplift** — the SSO tax. Nexstage should ship SAML SSO on the Enterprise tier without a 4× price multiplier; agencies with 20 employees and 30 client workspaces need SSO and aren't a $50k ACV sale.

## Proposed Nexstage multi-store UX

Given our workspace-based multi-tenancy (`App\Models\Workspace`), `billing_workspace_id` parent relationship, `WorkspaceScope` for tenant isolation, and our revenue-share pricing (€39/mo floor + 0.4% GMV per workspace), here is the concrete v1 UX.

### 1. The workspace switcher

**Placement.** Top-left of `AppLayout`, adjacent to the logo. Single component, always visible when the user has ≥2 workspaces. **Invisible for single-workspace users** (renders as the workspace name in static text, no dropdown chrome).

**Component shape.** Button with:
- Workspace avatar (user-picked emoji or initial-based avatar, 24px).
- Workspace name (truncated at 18 chars).
- Caret indicator.

Click opens a panel (not a dropdown — a floating card, 320px wide):
- **Current workspace** at top with a checkmark.
- **Portfolio view** link (if user has ≥2 workspaces) — goes to aggregated dashboard.
- **Recent workspaces** section — last 5 used.
- **All workspaces** section — alphabetical list with avatars. Search input at top; filters as you type.
- **Create new workspace** link at bottom (for agency owners).

**Keyboard shortcut.** Cmd+K opens a global command palette that includes workspace switching. Typing a workspace name jumps directly. The panel + command palette live alongside each other; palette is the power-user accelerator, panel is the visible affordance.

**Behaviour on switch.** URL contains `/w/{workspace-slug}/...`. Switching rewrites only the `{workspace-slug}` segment; the rest of the path is preserved. Landing on the equivalent page in the new workspace is attempted; if the page doesn't apply (e.g., new workspace has no GSC integration and user was on Search page), fall back to dashboard with an `AlertBanner` explaining.

**Cross-workspace scope.** The switcher panel also has a toggle "Include portfolio-wide" — when ON, the main nav items (Dashboard, Acquisition, Performance, etc.) link to the Portfolio view rather than a single workspace. This is the binary "All stores / This store" toggle, Glew-style, lifted one level above the dashboard.

### 2. The aggregated (Portfolio) dashboard

**Default behaviour.**
- 1 workspace: no Portfolio view exists; the standard workspace dashboard is what they see.
- 2 workspaces: Portfolio view is a tab adjacent to each workspace in the switcher. Not the default landing — the last-viewed workspace is.
- 3+ workspaces: Portfolio view becomes the default landing on sign-in. A first-time banner explains "This is your portfolio dashboard. Switch to a specific store using the dropdown above."

**Card behaviour in Portfolio view.**
- Every `MetricCard` in Portfolio scope retains the 6 source badges (Store / Facebook / Google / GSC / Site / Real). Each is a **sum across all workspaces** in reporting currency, with FX conversion applied at transaction-date rates.
- Below the headline number: **stacked contribution bar** (16px tall), one coloured segment per workspace, sized by share of total. Max 7 segments visible; overflow rolls into a "Other (N workspaces)" grey segment.
- Hover on the bar: tooltip shows per-workspace breakdown with native-currency amount and FX rate used.
- Per-workspace sparklines (novel — nobody does this well): a 40×20 inline chart next to the headline number, one line per workspace, at most 5 lines visible with overflow rolled up. Shows trend divergence at a glance.

**Charts.** The existing `QuadrantChart` and line-chart components accept a `segmentBy: 'workspace'` prop. When set, they render one segment per workspace with the same colour mapping as the contribution bars. Colours are deterministic per workspace (derived from workspace ID hash) so they're consistent across every chart.

**Date range / filters.** Portfolio-level. All workspaces in the portfolio re-query with the same date range on change. Workspaces that don't have data for a given source (e.g., one workspace has no ad account connected) are omitted from that source's card and counted in "Other / Not connected" — never silently zero.

**Navigation.** Portfolio dashboard links (Acquisition, Performance, etc.) go to Portfolio versions of those pages, which follow the same rules. Breakdown pages (`BreakdownView`) accept a workspace filter — default All, user can pick one or many workspaces to scope the breakdown.

### 3. Agency tier features

Not a v1 requirement — agencies can function on the standard plan by creating multiple workspaces under one master owner. But when we formalise the agency tier (call it "Agency plan"), the feature wedge is:

**At €39/mo minimum × N workspaces (current pricing) + €0.4% GMV:**
- Portfolio view across all owned workspaces.
- Bulk invite across workspaces ("invite user to these 15 workspaces as Member").
- Per-workspace branding (logo + primary colour) — shows in scheduled reports and in per-workspace URLs.
- `billing_workspace_id` parent relationship lets one master workspace pay for its children.

**At a future "Agency+" tier (€199/mo on top of the per-workspace fees):**
- White-label scheduled PDF reports with agency logo in header.
- Custom "From" email on scheduled reports.
- Guest/Client role — invite the client to see only their workspace.
- Workspace templates (create a new workspace copying settings from an existing one).
- Consolidated invoice (one PDF, one charge, broken down per workspace line).
- Client portal link per workspace (a bookmarkable URL that shows only that workspace, with agency branding).

**At Enterprise (custom):**
- SAML SSO via the `billing_workspace_id` parent.
- Custom CSM.
- Bulk API access for client exports.

Price point reference: Glew's agency tier is bundled into Plus (opaque pricing, estimated $1k+/mo). Polar's agency bulk discount applies to their Custom plan (opaque, estimated $1–3k/mo). AgencyAnalytics per-client-report pricing is $10–$15/client/mo added to a $79–$299/mo base. Nexstage's per-workspace revenue share already makes per-client cost transparent; the Agency+ uplift is for the features, not the client count. This is a pricing differentiator worth leaning into.

### 4. Currency + timezone

**One reporting currency per workspace** — already in `WorkspaceSettings`. Default to the store's native currency on connection (from Shopify's `myshopify_domain` metadata or Woo's general settings). User can override.

**One reporting timezone per workspace** — also in `WorkspaceSettings`. Default to store timezone.

**Portfolio view — single display currency.** Picked by the agency owner; can be overridden per user on their own profile. All workspaces convert to this currency at transaction-date FX (using `fx_rates` cache, already in place). The Portfolio dashboard shows "Converted to EUR" in the header, with a help link to the conversion methodology.

**Portfolio view — timezone handling.** Use the portfolio owner's timezone for "today" / "this week" labels. Each workspace's data is converted to the owner's timezone using the stored transaction timestamps. Lifetimely's honest-disclosure pattern: if workspaces span timezones that cross the current date, show an `AlertBanner`: "Store X is in Asia/Tokyo — its 'today' is 6 hours ahead of yours." The user can still view portfolio totals; they just know the edge case exists.

**Native amount on hover** — every KPI card in Portfolio scope shows converted headline; hover reveals native-per-workspace breakdown with the FX rate used. This is the "Putler done right" pattern: transparent provenance on every aggregated number.

**Ad spend in the Portfolio view.** We already know per PLANNING.md §5.7 that `ad_insights` has no country column — country-level ad spend comes from `COALESCE(campaigns.parsed_convention->>'country', stores.primary_country_code, 'UNKNOWN')`. Same primitive works for workspace-level aggregation: the campaign's parent ad account has a workspace_id; the aggregation is just a GROUP BY workspace_id over the existing query.

### 5. Permissions

**v1 role model (minimum viable).**
- **Owner** — billing, delete workspace, invite Admins and Members, change all settings. Exactly one per workspace.
- **Admin** — manage integrations (connect/disconnect Shopify, Facebook, Google, GSC), invite Members, edit workspace settings, create scheduled reports. Cannot access billing or delete the workspace.
- **Member** — view all data, edit dashboards, run breakdowns, export CSVs. Cannot manage integrations or invite users.

Roles are per-workspace. A user can be Owner on one workspace and Member on another. `workspace_users` pivot table already has a `role` column supporting this; no schema change needed for v1.

**v2 additions (agency plan).**
- **Guest / Client** — invited by role to a specific workspace, sees only that workspace, cannot see other workspaces the inviter belongs to. Cannot invite others. Read-only except for personal preferences (dashboard layout, notifications).

**v3 additions (requested-when-requested).**
- **Per-dashboard access lists** — restrict Member access to specific dashboards.
- **Per-metric redaction** — hide profit / COGS from specific roles. Only build this if 3+ customers request it.

**SAML SSO.** Enterprise plan only, priced without an SSO-tax multiplier. Uses the `billing_workspace_id` parent — SSO is configured on the parent and inherited by all child workspaces in the portfolio.

**SCIM.** Deferred to v2+. Manual invite UX is sufficient for agencies <50 members.

---

## Cross-references

- Multi-tenancy primitives: `/home/uros/projects/nexstage/app/Models/Workspace.php`, `/home/uros/projects/nexstage/app/Scopes/WorkspaceScope.php`, `/home/uros/projects/nexstage/app/Models/WorkspaceUser.php`.
- Billing parent relationship already in schema: `workspaces.billing_workspace_id` (see `/home/uros/projects/nexstage/app/Models/Workspace.php` line 28 and the `billingOwner` / `billingChildren` relations).
- Reporting currency/timezone in `WorkspaceSettings` value object (see `/home/uros/projects/nexstage/app/ValueObjects/` directory).
- Pricing model: `/home/uros/projects/nexstage/config/billing.php` (€39/mo min + 0.4% GMV per workspace; enterprise threshold at €250k GMV).
- Existing onboarding + pricing research: `/home/uros/projects/nexstage/docs/competitors/_crosscut_onboarding_ux.md` and `/home/uros/projects/nexstage/docs/competitors/_crosscut_pricing_ux.md`.
- Competitor profiles cited throughout: `glew.md`, `triple-whale.md`, `polar-analytics.md`, `northbeam.md`, `putler.md`, `peel-insights.md`, `lifetimely.md`, `daasity.md`, `hyros.md`, `metorik.md`.

## Sources

External references used in this doc, beyond the competitor profiles:

- Triple Whale Agency Partner Program — https://kb.triplewhale.com/en/articles/7128147-triple-whale-agency-partner-program, https://www.triplewhale.com/agency
- Triple Whale Multi Shop View — https://kb.triplewhale.com/en/articles/13045107-multi-shop-view-what-s-changed-from-pods-view, https://kb.triplewhale.com/en/articles/11554323-multi-shop-reporting-with-shop-breakdown
- Triple Whale bulk invite — https://kb.triplewhale.com/en/articles/6097678-adding-team-members-to-multiple-shops-at-once
- Northbeam multi-brand — https://docs.northbeam.io/docs/multi-brand-configuration, https://www.northbeam.io/partner-program
- Polar Workspaces — https://intercom.help/polar-app/en/articles/10128572-workspaces, https://intercom.help/polar-app/en/articles/5649880-as-an-agency-how-can-i-manage-my-customers-on-polar-analytics
- Polar agencies / multi-store — https://www.polaranalytics.com/solutions/agencies, https://www.polaranalytics.com/solutions/analytics-for-multi-store-shopify-brands
- Glew multi-brand and white-label — https://glew.io/white-label-solution-easier-building/, https://www.glew.io/solutions/brands
- Lifetimely/AMP multi-store — https://help.useamp.com/article/660-connect-multiple-shopify-stores
- Putler multi-currency & cross-platform — https://www.putler.com/multi-currency-reporting, https://www.putler.com/cross-platform-analytics
- Shopify Plus Org Admin multi-store reporting — https://help.shopify.com/en/manual/organization-settings/analytics, https://changelog.shopify.com/posts/multi-store-reporting-is-now-available-in-analytics
- Vercel team switcher — https://vercel.com/changelog/improved-experience-for-moving-between-your-teams-and-projects, https://vercel.com/docs/accounts
- Linear keyboard patterns — https://shortcuts.design/tools/toolspage-linear/, https://blog.superhuman.com/how-to-build-a-remarkable-command-palette/
- AgencyAnalytics (client-reporting benchmark) — https://www.reportingninja.com/blog/agency-analytics-pricing, https://almcorp.com/blog/white-label-client-reporting-agencies/
- SSO/SCIM pricing pattern — https://www.stitchflow.com/scim/linear, https://securityboulevard.com/2026/02/best-sso-scim-providers-for-b2b-saas-selling-to-enterprise-2026-ranked-guide/
