# Google Analytics 4 — dashboard UX reference

**URL:** https://analytics.google.com
**Why it's referenced:** The elephant in the room. Almost every Nexstage prospect has GA4 burn-in — both the familiarity (Explore, segments, funnels, cohorts) and the trauma (slow, 24h latency, buried settings, aggressive data thresholding). GA4 is a double reference: *do* steal the Explore canvas and segment builder; *don't* inherit the navigation rot, the sampling silence, or the 11-click paths to basic answers.

## Layout & navigation
- **Nav pattern:** Three-level left sidebar (Home / Reports / Explore / Advertising / Admin) with nested "library" pages. Each section has its own chrome. Account/property/view switcher sits in the top bar — this alone has burned 10 years of user support tickets.
- **Page structure:** Sticky header with date range (top-right, always) → page title + share/export icons → "scorecard" strip of 3–4 KPIs → hero chart → tables below. Most pages are one of 3 or 4 stock layouts reused everywhere.
- **Density:** Low per screen, very high across screens. Each page shows a modest amount but the app has hundreds of pages. Users report feeling lost — "where was that report last time?"

## Components worth borrowing
- **Explorations canvas (3-panel layout):** Left = **Variables** (date range, segments, dimensions, metrics). Middle = **Tab Settings** (technique, filters, rows, columns, values, cell type). Right = **Canvas** (the actual visualization). The three-panel "variables → settings → canvas" IA is genuinely good for any deep-drill exploration surface. Nexstage's "Advanced" or "Custom report" should use this layout.
- **Technique picker:** Seven named techniques — Free Form, Funnel, Path, Segment Overlap, User Explorer, Cohort, User Lifetime. Each is a different visualization shape but shares the same variable/settings/canvas shell. Good pattern: instead of one "build-your-own" chart, ship a handful of named templates that make sense.
- **Segment builder:** A conditional builder with "Include users/sessions/events when..." rules, AND/OR groupings, and a sequence mode ("step 1 happens before step 2 within N minutes"). The 3-tier choice (user / session / event segment) is a genuinely load-bearing abstraction.
- **Comparisons panel:** Up to 4 comparisons (e.g. Mobile vs Desktop) overlaid on the same chart, each rendered as a separate line with a legend chip. Chip includes user count preview.
- **Right-click to drill into segment:** In any table, right-click a row → "Create segment from selection". Very fast exploratory workflow.
- **Data thresholding disclosure (done badly but the pattern is necessary):** A yellow icon on reports that are sampled or thresholded, with a tooltip explaining why. The *idea* is correct; the execution is opaque. Do the pattern, but make the disclosure actionable.
- **Realtime card:** "Users in the last 30 minutes" hero card with a per-minute bar chart. Simple and useful.

## Interactions worth borrowing
- **Save an Exploration and share it:** A built exploration can be saved and shared with collaborators. Nexstage's custom views should be first-class objects with share URLs.
- **Drag dimensions/metrics into slots:** Variables panel holds a library; you drag items into the Settings panel's Rows/Columns/Values slots. The drag-based composition is more learnable than a pure form, *if* the slots are labelled clearly (GA4's are minimal).
- **Date range picker with compare:** Top-right date picker with preset ranges (Last 7 / 28 / 90 days, This/Last month) plus compare-to. Standard pattern, well-executed.
- **Cohort grid heatmap:** Rows = acquisition week, columns = relative week, cell color = retention %. Clean and immediately readable.

## Color, typography, visual
- **Palette:** Google Material accents — blue, green, yellow, red. Sampling/threshold warnings in amber. Multi-series charts use Material's categorical palette (blue, red, yellow, green, purple). Works, but not distinctive.
- **Typography:** Google Sans / Roboto. Numbers are not tabular by default — amounts in tables don't line up cleanly, which is a visible weakness.
- **Data viz style:** Material charts. Adequate but uninspired. Tooltips are OK; interactive drill-downs vary by report (inconsistent).
- **Empty states:** Usually a tutorial-style link-out to docs rather than an actionable in-product step. Feels like a content farm instead of guidance.
- **Loading states:** Spinners everywhere. Often 3–10 second waits because data is being re-sampled. No skeleton, no optimistic rendering. This is the #1 felt-pain point.

## Specific screens worth stealing
- **Explore > Free Form:** The 3-panel canvas with drag-to-compose and a technique selector. This is the single piece of GA4 worth cloning, structurally.
- **Explore > Funnel:** Multi-step funnel with drop-off % between steps, optional open/strict sequence, and segment overlays. Better than most dedicated funnel tools.
- **Explore > Path Exploration:** Tree-style node visualization for page paths. Underrated; most tools do Sankey or sequence tables, but a tree is clearer for branching behaviour.
- **Explore > Cohort Exploration:** Retention heatmap grid by acquisition cohort. Standard but correctly done.
- **Audiences / Segments page:** A saved library of user segments with counts, applicable in any report. Elevating segments to first-class reusable objects is good IA.

## What NOT to copy
- **24-hour data latency with no in-product warning.** Fresh data quietly lies — a metric shown for "today" might actually be 40% of today. Our Data Freshness badge should be loud when data is stale.
- **Sampling without explaining what's sampled.** GA4 silently samples high-cardinality reports. Users don't know which rows are estimates vs. exact. If we ever sample, the exact method and scope must be visible.
- **Events that don't appear in reports for 24h after creation.** A user creates a goal/event and it seems to "not work" — actually it's just the report pipeline. Avoid any pattern where a setup change has invisible delay.
- **Left nav with "Library" pages hiding report collections.** GA4's reports live in a separate library you configure, detached from the nav. The primary sidebar often doesn't show the report you want. Keep our IA flat and predictable.
- **Dropdowns as the primary filter mechanism.** Long flat dropdowns (with type-ahead that only helps if you know the name) are GA4's default. Inline filter chips (Plausible-style) or a command palette (Linear-style) beat this every time.
- **Single-dimension selection at a time.** You can only break down by one dimension in standard reports — forces users into Explore for basic combinations. Our breakdown tables should support multi-dim natively.
- **Inconsistent chrome across Reports vs Explore vs Advertising.** Three different styling languages in one product. We ship *one* app shell.
- **Account/Property/View switcher in the top bar.** Multi-tenant switching deserves its own deliberate component (workspace switcher with search, recent, and pin). GA4's cramped dropdown is a cautionary tale.
- **"Insights" ML callouts that can't be dismissed or acted on.** GA4 auto-generates "Revenue dropped 12% vs last week" chips with no link to a drill-down that explains why. If we ship anomaly callouts, they must deep-link into the explanatory view.
- **Thresholding that silently zeroes out small cohorts.** GA4 hides rows below a user count for privacy; the UI shows 0 or blank with no indication the data existed. Disclose or don't hide.

## Screenshot refs
- https://support.google.com/analytics/answer/9328518 (segments in Explore, official)
- https://infotrust.com/articles/google-analytics-4-explore-reports/ (annotated walkthrough with screenshots of each technique)
- https://www.searchenginejournal.com/google-analytics-4-backlash/411392/ (user complaints, informative for what NOT to do)
- https://searchengineland.com/google-analytics-4-we-hate-428942 (detailed UX critique list)
- https://measureu.com/google-analytics-4-segments/ (segment builder screenshots)
- https://www.analyticsmania.com/post/google-analytics-4-segments/ (visuals of user / session / event segment distinction)
