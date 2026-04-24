# Plausible Analytics — dashboard UX reference

**URL:** https://plausible.io · live demo https://plausible.io/plausible.io
**Why it's referenced:** The deliberate anti-GA4. A single-page analytics dashboard that deletes everything non-essential. Useful as the *floor* of the complexity spectrum — the reference point for "what does 'one page, one minute, one insight' actually look like?" We won't ship something this sparse, but we need to borrow the *filter-as-narrative* idiom and the single-page IA.

## Layout & navigation
- **Nav pattern:** Almost no nav. A single page per site. The top strip has the site name (acts as a home/reset), a date range picker, and a filter-add button. No sidebar. No tabs. No submenus. Settings are a separate page accessed from the header.
- **Page structure:** Header (site + date range + filter) → KPI strip (6 top-line numbers) → hero chart → grid of report cards (Sources, Pages, Locations, Devices, Goals). All visible on one scroll.
- **Density:** Low-to-moderate. Intentionally airy. Report cards each show the top 5 rows + a "Details" link that expands into a modal/drawer with full list.

## Components worth borrowing
- **Clickable metric selectors above the chart:** The 6 KPIs (Unique visitors, Total visits, Pageviews, Views/visit, Bounce rate, Visit duration) aren't just numbers — clicking one re-renders the hero chart with that metric as the Y-axis. Six metrics, one chart, zero chart-picker UI.
- **"Current visitors" pill:** A small live-updating pill in the header showing visitors in the last 5 minutes; clicking it opens a realtime view with a 30-minute rolling graph that updates every 30s. Perfect model for our "live orders in the last hour" indicator.
- **Filter-by-click on every report row:** Clicking any row in any report *adds a filter* for that value and the rest of the page re-computes. Country="United States" click → the whole dashboard is now US-only. No separate filter dialog needed for the 90% case.
- **Filter chips as a readable sentence at the top:** Applied filters render as removable pills: `Country is United States × | Source is Google ×`. The dashboard title effectively becomes a sentence describing what you're looking at.
- **Comparison toggle with semantic options:** Date picker has Compare → Previous period / Year over year / Custom period / "Match day of week" — the last option is a small but smart touch for retail/marketing seasonality.
- **Dotted line for incomplete periods:** The rightmost segment of a chart (today, this week-to-date) is drawn as a *dotted* line to signal "this isn't complete yet, don't over-read the dip." Should be a default in every TimeSeries component we ship.
- **Details drawer pattern:** Each report card has a "Details" link that opens a drawer with more columns (bounce rate, visit duration, scroll depth, conversion rate). Keeps the default view sparse but gives power users a way in.
- **Interval selector via 3-dot menu (⋮):** Each card/chart has a small overflow menu for interval (minute / hourly / daily / weekly / monthly), keeping the chrome clean.

## Interactions worth borrowing
- **Filter-propagation:** A single click in the Sources card filters every other card simultaneously. Fast, no "apply" button.
- **Deep-linkable filtered state:** Every filter/date/comparison combination is in the URL. Bookmarkable, shareable, no "save view" ceremony required.
- **Smooth metric switching:** When you click a different KPI, the chart Y-axis morphs to new units with a ~200ms animation — never a flash-of-empty.
- **Filter operators (is / is not / contains / does not contain):** Default is "is"; an affordance inside the filter pill lets you flip to negation or substring. Simple, no query builder modal.
- **Funnel & goal stepping:** Funnels render as horizontal bars with drop-off percentages between steps, not as a stacked bar chart. More scannable for pipeline-style flows.

## Color, typography, visual
- **Palette:** Neutral — off-white background, near-black text, single brand accent (indigo/purple) for the chart line and CTAs. Sparing use of red/green for positive/negative deltas. No 8-color categorical palette, because there's almost never more than one series.
- **Typography:** System sans-serif default (no custom font). Large KPI numbers (~32px), small report table rows (~13–14px). No monospace. It works because the data model is flat.
- **Data viz style:** Single-line area chart with a soft fill gradient, no y-axis gridlines, sparse x-axis labels (Mon / Tue / Wed). Tooltips on hover show value + comparison delta. Report rows use a horizontal bar filling the row background to visualize share — no separate bar chart needed.
- **Empty states:** Text-only. "No visitors yet. Your snippet might not be installed — [verify installation]." One sentence, one action.
- **Loading states:** Near-invisible. The whole page is server-rendered fast; filter changes swap content with a brief shimmer on the chart area only.

## Specific screens worth stealing
- **The whole home dashboard:** The single-page IA is the star. We cannot copy it directly (our product has more data), but Nexstage's Home page can borrow the **6-KPIs-above-a-shared-chart** pattern and the **click-to-filter propagation**.
- **Realtime view:** A dedicated 30-minute rolling graph with live tick. Our "Live orders" page should use this same pattern rather than a full dashboard clone.
- **Goals / Funnels page:** Steps as a horizontal flow with drop-off percentages, not as a stacked chart.
- **Filter sentence bar:** "Country is US × | Device is Mobile × | Source is Google × | Clear all" as a readable sentence above everything. Nexstage should adopt this instead of a filter sidebar.

## What NOT to copy
- **Intentional feature absence:** Plausible doesn't ship cohort analysis, paid-ad attribution reconciliation, product analytics, or a revenue-per-source view. Our product has to. Their simplicity comes from scope, not from UI magic.
- **Single-series chart philosophy:** Plausible rarely shows multi-series comparison. Our core value prop *is* multi-source comparison (Facebook vs Google vs Store vs Real). We need more color differentiation than Plausible allows.
- **No drill-down hierarchy:** Plausible's drill-downs are flat (click → filter). We have deeper structures (campaign → ad set → ad → creative) that need breadcrumbs and back-stack.
- **Single-page everything:** Plausible gets away with it because they have 5 reports. We have dozens. A sidebar is unavoidable for us.
- **System font stack:** Nexstage should use a typographic identity. Plausible defaults to system fonts because minimalism is their brand — but Nexstage competes on information density where a tuned typeface (tabular nums, distinct glyphs) pays off.

## Screenshot refs
- https://plausible.io (home with embedded dashboard preview)
- https://plausible.io/plausible.io (live production dashboard of Plausible's own site)
- https://plausible.io/docs/guided-tour (annotated walkthrough)
- https://plausible.io/docs/funnel-analysis
- https://plausible.io/changelog (screenshots of each feature as introduced)
