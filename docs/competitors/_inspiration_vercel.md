# Vercel — dashboard UX reference

**URL:** https://vercel.com/dashboard · design system at https://vercel.com/geist
**Why it's referenced:** Best-in-class developer-facing dashboard for streaming real-time state (deployments, logs), with a design system (Geist) specifically tuned for small type, high density, and tabular numbers — exactly the constraints of an analytics app.

## Layout & navigation
- **Nav pattern:** Top bar + contextual in-page sidebar. Global top bar has account/team switcher, global search (Cmd+K), docs/help. Inside a project, a left sub-nav lists Deployments, Analytics, Logs, Speed Insights, Storage, Settings. No persistent global sidebar — context switches with the object you're in.
- **Page structure:** Breadcrumb (team > project > resource) → page title with action cluster on the right → tabbed sub-sections → content.
- **Density:** Moderate. Generous vertical spacing around tables, but inside each row the data is tight (12–14px text, tabular nums, icon + text combos). Favours breathing room over information density compared to Linear.

## Components worth borrowing
- **Deployment card:** A compact card with status dot, commit message, branch, timing, author avatar, environment badge (Production/Preview). One primary card type reused everywhere — list view, history, detail header.
- **Status Dot component:** A 6–8px colored dot that appears next to every deployment/resource — building (amber pulse), ready (green), error (red), queued (grey). Animation differentiates states without extra pixels. We should use this for "sync status" on every store/integration row.
- **Log viewer (inline, not modal):** Logs stream directly inside the deployment detail page with: copy-to-clipboard on hover, filter by function, millisecond timestamps, color-coded severity, auto-scroll toggle. No modal, no new tab.
- **Command Menu (Cmd+K):** Part of Geist as a named component — navigation + search + actions, same pattern as Linear.
- **Skeleton + optimistic state:** When you click Deploy, the card shows "building" state *before* the server confirms. SWR-pattern revalidation reconciles when data arrives.
- **Entity component (from Geist):** A named row/card primitive — icon + primary text + secondary metadata + trailing action — used for projects, domains, team members, integrations. One component, many uses.
- **Context Card:** An expandable information tile that summarises a resource (project health, domain status). Click to expand into full detail.
- **Gauge + Progress + Status Dot** named primitives in Geist — we should study these as our atomic meters/indicators library.
- **Theme Switcher:** Three-way (System / Light / Dark) in the footer, not hidden in settings. Low friction.
- **MiddleTruncate:** A text utility that truncates in the middle for long IDs/URLs while keeping the suffix visible (`abc123...xyz789`). Massively useful for our order IDs, campaign IDs, UTMs.

## Interactions worth borrowing
- **Browser favicon + tab title as status channel:** While a deployment builds, the *favicon* animates and the *tab title* gets a prefix (▶ building, ✓ ready, ✕ error). You can have 10 tabs open and see which one finished. We could do this for long-running historical imports and report generations.
- **Streaming logs with tail:** New log lines appear at the bottom of the viewer in real time; user can pause auto-scroll by scrolling up manually, and a "Jump to latest" pill appears.
- **Click a commit SHA to deep-link into GitHub:** Inline external links are styled subtly but always clickable. Every ID is a link, not just text.
- **Hover on timing label → breakdown tooltip:** A "Built in 42s" chip expands on hover into the individual phase timings.
- **Optimistic everything:** Rename project, add domain, add env var — all reflect instantly, errors roll back with a toast. Same model as Linear.
- **Deploy timeline with per-phase timestamps:** Queued → Building → Deploying → Ready as a vertical rail with timestamps per phase. Clear cause-and-effect when something is slow.

## Color, typography, visual
- **Palette:** Near-monochrome, pure black (#000) or near-white base depending on mode. Semantic accents: green (#00DC82) for success, amber (#FFAA00) for building/warn, red for error, blue for info. High-contrast accessible. Dark mode is the default and the aesthetic reference.
- **Typography:** **Geist Sans** for UI, **Geist Mono** for code, IDs, URLs, commit SHAs, numbers in dense tables. Optimized for 12–14px. Distinct glyph shapes (l vs I vs 1 are unambiguous). Tabular numbers standard. We should seriously consider Geist Mono for our amounts, UTM strings, and campaign IDs.
- **Data viz style:** Sparse. Analytics panels use thin-stroke lines, no heavy fills, and minimal axis labels. When data is absent, the chart area shows a faint dotted baseline — not a spinner and not an empty state illustration.
- **Empty states:** Copy-paste-friendly. An empty "no domains yet" state shows the exact CLI command (`vercel domains add`) in a monospace block with a copy icon. Instruction-as-UI. For us: an empty integrations state could show the Shopify/Woo install URL as a copyable block.
- **Loading states:** Skeleton screens + SWR optimistic rendering. A tiny 14px ring appears in the top-right of a region that's mid-revalidation; it doesn't block the rendered-from-cache content underneath.

## Specific screens worth stealing
- **Deployment detail page:** Top-of-page metadata strip (status, commit, author, duration, environment) → deployment timeline rail → collapsible build logs → runtime logs. Single page, no modals, everything diagnosable without a tab switch. Our equivalent: "Store sync detail" or "Historical import run detail".
- **Project analytics tab:** Minimal hero chart (requests, bandwidth), then small cards with tiny sparklines underneath. The "cards under the chart" layout is a good pattern for supplementary-metric contextualization.
- **Domains page:** Each domain is an Entity row with a Status Dot, cert info, and a trailing action menu. Clean pattern for our "Stores" or "Connected integrations" list.
- **Usage page:** Progress bars for quota usage against limits. Color shifts from green → amber → red as thresholds are crossed. Useful for our workspace plan limits.

## What NOT to copy
- **Dark-mode-first with near-pure-black:** Beautiful for developers, but finance/marketing users skew to light mode and high-luminance displays. Ship both modes, but default to light.
- **Pure-monochrome data viz when you have multi-source comparison:** Vercel shows one series at a time. We need Facebook vs Google vs Store vs Real in one chart — don't neutralize the palette so much that sources become indistinguishable.
- **CLI commands in every empty state:** Our users are not devs. Our empty states should reference the equivalent UI action or Settings page, not a command.
- **Top-nav-only pattern:** Vercel hides most navigation one level deep (team > project > tab). For a dashboard where users jump between Home, Campaigns, Channels, Audience, SEO frequently, a persistent sidebar works better.

## Screenshot refs
- https://vercel.com/geist (live design system)
- https://vercel.com/geist/introduction
- https://vercel.com/design
- https://www.figma.com/community/file/1330020847221146106/geist-design-system-vercel
- https://vercel.com/blog/refined-logging (log viewer refresh)
- https://blakecrosley.com/guides/design/vercel (deep analysis of the UX patterns)
