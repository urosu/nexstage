# Fairing — UI teardown

**Scope:** screen-by-screen deep dive. Companion to [`fairing.md`](fairing.md). Reference from page specs in `/docs/pages/`.

Sources: `docs.fairing.co` (main index, shopify, shopify-checkout-extensions, creating-and-editing-questions, question-stream-functionality, question-stream-custom-location, sample-question-stream-html, question-templates, analytics, exporting-your-data, shopify-pos-ui-extensions, full-page-takeover-advanced), `fairing.co/product/`, Shopify App Store listing, `fairing.canny.io/changelog` (2025–2026 product updates), ATTN Agency Fairing review. All descriptions synthesised from help-doc captions, changelog entries, and walkthrough text.

## Global chrome

### Top bar
- **Logo placement:** Top-left. "Fairing" wordmark — Fairing rebranded from Enquire a few years ago, so older screenshots may show the old brand.
- **Workspace/store switcher:** Top-left next to the logo. Fairing is Shopify-first and one account typically = one store, but multi-store accounts get a dropdown selector. Agencies see a list of client stores. Less emphasised than in Triple Whale/Polar.
- **Global date range:** Top-right of Analytics pages. Presets: Today / Yesterday / Last 7d / Last 30d / Last 90d / Last month / Previous Month / Custom. "Previous Month" was added as a default option in Feb 2026 — a small but cited win in the changelog.
- **Global filters:** Analytics pages filter by question (which survey), customer segment, product, and UTM. These filters live on the Analytics page header, not app chrome.
- **User menu, notifications, help:** Top-right. Notifications, help (docs link), Intercom chat bubble, user avatar (Profile / Team / Billing / Logout).
- **Command palette / search:** Search within questions/responses is a bar at the top of relevant tabs; no app-wide palette.

### Sidebar
- **Sections (in order, top to bottom):** Fairing added an Analytics shortcut directly in the sidebar in Feb 2026, elevating it from a sub-tab. Current order:
  1. **Question Stream** (primary home / default landing) — the list of active survey questions
  2. **Analytics** (added Feb 2026 as sidebar shortcut) — response analytics
  3. **Responses** — raw response feed
  4. **Customer View** — per-customer history
  5. **Live Feed** — real-time responses as they come in
  6. **AI Insights** — weekly AI summary
  7. **Integrations** — connect Klaviyo, GA4, Meta, Shopify Flow, TikTok, Triple Whale, etc.
  8. **Question Templates** — library of 30+ tested questions
  9. **Settings** — account, billing, translations, targeting rules
- **Collapsible?:** Yes. Sidebar collapses to icons.
- **Active state visual:** Green/teal left-edge bar (Fairing brand accent). Active icon switches to brand teal.
- **Nesting depth:** 1 level in the sidebar. Sub-tabs live inside pages (Analytics has Overview / Comparison / Time Series / NPS / LTV).

### Color / typography / density
- **Primary accent color:** Mint/teal green (`#00c9a7`-ish) — Fairing's brand. Secondary is deep navy for text.
- **Chart palette:** Green-dominant with multi-hue for channel breakdowns. Channel colours aren't as strictly coded as ad-centric tools because Fairing is survey-first and the channels users self-report don't always map to ad platforms (e.g. "Podcast", "Friend", "Billboard").
- **Font family, number formatting:** Sans-serif (Inter-like). Numbers with commas, decimals to 2 places for % and AOV. "AOV" and "Revenue" in shop currency; auto-detected from Shopify.
- **Approximate density:** Intentionally low — ~4–8 widgets above the fold. Fairing leans on "CMO-reads-in-10-seconds" positioning. It's a narrow, focused tool compared to Triple Whale/Polar.

---

## Screen: Question Stream (home)

- **URL path:** `/questions` or `/` (default after login).
- **Layout:** Full-width list of survey questions in display order. A header bar above with date range, primary CTA, and overview KPIs.
- **Header elements:**
  1. Page title "Question Stream™"
  2. Overview chips: Active questions count, Views (total), Responses (total), Response rate %
  3. Date range picker (top right)
  4. Primary CTA "New Question" (top right)
- **Question list rows — each row contains:**
  1. Drag handle icon (left) — "The order of your question list is the chronological order your customers will experience" — supports drag-and-drop reordering
  2. Question title (editable on click)
  3. Status pill: Live (green) or Paused (grey) — one-click toggle
  4. Views (last 30d or selected range)
  5. Response count + response rate %
  6. Type badge (Single / Multi / Open / Date / NPS / Auto Suggest)
  7. "More actions" dropdown (three dots) — options: Preview / Edit / Duplicate / Archive
  8. Analytics button — direct link to that question's analytics page
- **New Question flow:**
  - Click "New Question" button → template picker modal
  - Choose template from 30+ pre-built templates OR "Start from scratch"
  - Opens question editor (see next screen)
- **Empty state:** "No questions yet. Start with a tested template like 'How did you hear about us?' — usually gets 40–80% response rate."
- **Loading state:** Skeleton rows.
- **Screenshot URLs:**
  - `https://cdn.shopify.com/app-store/listing_images/b012f4ba86663a72ddc26dcdfe1f9ffe/desktop_screenshot/CJSLwI657ZADEAE=.png`
  - `https://files.readme.io/a813b770c136df947ce1b0f789491dc7dc1cef6a8a4a75ff5d73b3bfd1d2d209-image.png` (Live/Paused)
  - `https://files.readme.io/fe249bddfd04c5d7bcbed3318ab1cb07c482166c6bee3ce823c35cf4594f4c67-image.png` (More actions menu)
  - `https://files.readme.io/efdcb551e9861fa9e0ceffa2d02b681dd05d7cdac88e9c88a995d1c5dc66b040-image.png` (Question ordering)

---

## Screen: Question Editor (new/edit question)

- **URL path:** `/questions/new` or `/questions/<id>/edit`.
- **Layout:** Two-column. Left = configuration form. Right = live survey preview showing exactly how the question will render on the Shopify order status page.
- **Left column — configuration fields:**
  1. Question text (required, max length indicator)
  2. "Add Description" button → reveals a description field (additional context or incentive)
  3. Question type picker (six options as icon cards):
     - **Single Response** — forces one choice; supports Response Clarification follow-up
     - **Multi-Response** — multiple selections allowed
     - **Open-Ended** — free text, one-line or multi-line
     - **Date Response** — "Month and Day" or "Full Date" sub-option
     - **Net Promoter Score (NPS)** — 0–10 scale with customisable labels; supports Response Clarification
     - **Auto Suggest** — text entry drawing from pre-uploaded responses with optional "Allow Any Customer Input" toggle
  4. **Responses editor** — for choice types, a list of response options with:
     - Drag-handle for order
     - Response label (text input)
     - "Enable" toggle per response
     - Delete button
     - "Add response" button at bottom
     - "Bulk Responses" textarea for auto-suggest (one per line)
  5. **Advanced options** (accordion):
     - Response Clarification — two-step question; answering "Podcast" triggers a follow-up "Which podcast?"
     - Auto Advance — automatically submit the question after a response is picked
     - Randomize Response Options
     - Language Translations — per-response translation inputs
  6. **Targeting Rules** section:
     - New vs Returning customer
     - Geographic Location (country/region)
     - Products Purchased (Shopify product picker)
     - Question Frequency (first-order-only / every-order / Nth order)
     - Quiet period (don't ask the same customer again for X days)
  7. **Where should we ask?** — Survey placement selector (added Jan 2026):
     - Shopify Order Status Page (default)
     - Shopify Checkout Thank-You Page (Checkout Extensibility)
     - Shopify Post-Purchase Page
     - Shopify Landing Page (NPS)
     - Custom location (for advanced setups)
  8. **Save Question** button at bottom
- **Right column — live preview:**
  - Mock of the Shopify order status page with the question embedded
  - Toggle between "New Customer" view and "Returning Customer" view (added Jan 2026)
  - Toggle between Mobile and Desktop preview
  - The preview re-renders live as user edits
- **Success Message configuration:**
  - Separate sub-tab within the editor
  - Fields: Success Message Title, Subtitle, Image, CTA Button (text + URL)
  - Controls what the customer sees after submitting
- **Screenshot URLs:**
  - `https://files.readme.io/a51c729-image.png` (New Question button)
  - `https://files.readme.io/189b1d136c78a1481bf0e6afafecab05ca62d174f90806aa0af98193a1ec43b2-image.png` (New Question interface)
  - `https://files.readme.io/4f7a455cbcb6885a2f0bd3e8e356a40a1aaa08761914f7ce47077e125287fc8b-image.png` (Single response)
  - `https://files.readme.io/b89741136f0bfe63927a80791e2fb94a5113b914f543b1b0ac6289bab1804dce-image.png` (Multi response)
  - `https://files.readme.io/eb9ee64d02e4ec7f9523cd4fcda05cc99141626037ff4d483dc8a9ff0e45424b-image.png` (Open-ended)
  - `https://files.readme.io/dbe768f-Fairing_date_types.png` (Date types)
  - `https://files.readme.io/b96da397426a6c396c3d6c119d3458d79c948419303ad012d42d6f709c7f9dd3-image.png` (Date example)
  - `https://files.readme.io/b1e67dc6f5a98c352aadfe1c4dc0e1faf845c47a0beae3ad25a5a45970531e12-image.png` (NPS)
  - `https://files.readme.io/f27e2563b7994cc502397699a77aa14f21f76bda23009b03f18724760ab89ac9-image.png` (Auto suggest)
  - `https://files.readme.io/7da5391-image.png` (Auto suggest bulk responses)
  - `https://files.readme.io/99112f3-image.png` (Allow Any Customer Input)
  - `https://files.readme.io/4330993d81063c9b996d4718e6eeeb66d9443d2184509e679e949a6a6c6f1a0e-image.png` (Add Description button)
  - `https://files.readme.io/cb4f82e-Question_Description.png` (Question with description example)
  - `https://canny-assets.io/images/8ee11dd44aa15a114bf138371a4dfbf4.png` (Checkout preview tool — new/returning modes)
  - `https://canny-assets.io/images/21bb6dd8cc3365138ab853db5b69f126.png` (NPS Landing Page — "Where should we ask?")

---

## Screen: Analytics (per-question)

- **URL path:** `/questions/<id>/analytics` (from Question Stream row), or `/analytics` (from sidebar shortcut, question-agnostic default).
- **Layout:**
  - Top strip: Views / Responses / Response Rate headline metrics with date-range comparison deltas
  - Centre: Responses table — one row per possible response option
  - Right rail or below: trend chart showing response share over time
- **Headline metrics:**
  - **Responses** — total submitted in date range
  - **Views** — number of times the question loaded (one view per question per customer, deduped)
  - **Response Rate** — (Responses / Views) × 100. Fairing recommends maintaining >35% to minimise non-response bias; lower rates trigger a warning banner
- **Responses table columns:**
  - Response label (e.g. "Podcast", "Instagram", "Friend")
  - **Count** — number of customers who chose that response
  - **Percent** — % of total responses
  - **Revenue** — total shop-currency revenue from those customers' orders
  - **AOV** — Revenue / Count
  - **Extrapolated Count** — observed × (100 / response rate) — projected to 100% respondent basis
  - **Extrapolated Revenue** — same projection for revenue
  - **Original** / **Delta** (after AI recategorisation runs; see Responses tab)
- **Sub-tabs within Analytics:**
  - Overview (described above)
  - **Comparison** — compare two periods or two question versions side-by-side
  - **Time Series** — response share over time as stacked area/line chart
  - **NPS Reporting** — NPS-specific: distribution histogram 0–10, Promoters/Passives/Detractors segmentation, NPS score badge
  - **Discount Code Report** — which discount codes drove which self-reported channels
  - **Customer Lifetime Value Analytics** — responses × LTV analysis. This is the signature Fairing report.
- **Interactions:**
  - Click a response row → drill to the individual customer responses for that label (feeds into Responses tab)
  - Date range comparison toggle — shows a delta column
  - Export — "Export Responses" button opens a modal with checkboxes (include clarifications? include follow-ups?) and triggers CSV download
- **AI Insights card (added July 2025):**
  - Weekly AI-summarised insights about response patterns
  - Button to subscribe to a weekly email report
- **Empty state:** "No responses yet. Check that your question is Live, and that Shopify Order Status Page renders the Fairing block."
- **Loading state:** Skeleton rows. Real-time — updates as new responses arrive.
- **Screenshot URLs:**
  - `https://files.readme.io/997e8f4b027dc1e64e083b81f4294d289ea57bcf15d0bb231bca56562ee67c3a-image.png` (main analytics view)
  - `https://canny-assets.io/images/ab3d13e37b0ee2d022bd8c5eb2dc20df.png` (AI Insights email)
  - `https://canny-assets.io/images/4a1d031d2c0960ddbcf2c8518039d60c.png` (Analytics sidebar shortcut)
  - `https://canny-assets.io/images/68984932cad4f27e41a94c8b3790438f.png` (Previous month default filter)

---

## Screen: Analytics — Customer Lifetime Value Analytics

- **URL path:** `/analytics/<question-id>/ltv`.
- **Layout:** Cohort-style table. Rows = response values (channels/sources). Columns = LTV at 30 / 60 / 90 / 180 / 365 days, plus repeat order rate and AOV.
- **Key insight:** Lets users see that "TikTok-self-reported" customers have 2× LTV of "Meta-self-reported" customers even when CAC looks identical. Unique to Fairing.
- **Screenshot URLs:**
  - `https://cdn.shopify.com/app-store/listing_images/b012f4ba86663a72ddc26dcdfe1f9ffe/desktop_screenshot/CPbk9fKmy5MDEAE=.png` (LTV/AOV/Revenue analysis)

---

## Screen: Responses (raw response feed)

- **URL path:** `/responses`.
- **Layout:** Paginated table of individual responses with filter sidebar.
- **Filter sidebar:** Date range, question, response value, customer type (new/returning), product purchased, order total range.
- **Per-row columns:**
  - Timestamp
  - Customer email (masked)
  - Order ID + order total
  - Question name
  - Response label
  - Other response text (if open-ended or clarification)
  - UTM source / medium / campaign (pulled from Shopify order)
  - Recategorisation tag ("AUTO" badge if AI recategorised; added Dec 2025)
  - Pen icon — edit this response's category (for manual recategorisation)
- **Bulk recategorisation (added Feb 2026):**
  - Select multiple rows via checkboxes
  - "Update All" button opens modal to reassign to a different category
  - Useful for cleaning up free-text answers that should roll up under a canonical label
- **Export controls:** "Export Responses" button at top; modal with date range, question picker, include-clarifications checkbox, and CSV download.
- **Screenshot URLs:**
  - `https://canny-assets.io/images/db49802790addcec4e4f51d4389237fa.png` (Bulk Recategorisation)
  - `https://canny-assets.io/images/6adc7e0e9ce376ba0a030150337deeae.png` (AUTO tag)

---

## Screen: Customer View

- **URL path:** `/customers/<email>`.
- **Layout:** Per-customer page showing all Fairing interactions for that customer.
- **Elements:**
  - Customer header — email, first/last order date, LTV, order count
  - Timeline of question views and responses — chronological
  - Responses list with question text + response for each
  - Linked Shopify orders with values
- **Use case:** Customer support and VIP customer verification.

---

## Screen: Live Feed

- **URL path:** `/live`.
- **Layout:** Chronological stream of responses as they come in. Auto-refreshes.
- **Per entry:** Timestamp, masked customer, question, response, order value. Row slides in from top on new arrival.
- **Use case:** Team monitors during campaign launches or product drops to see real-time response patterns.

---

## Screen: AI Insights

- **URL path:** `/ai-insights`.
- **Layout:** Weekly card-based digest.
- **Each card:** An AI-generated insight ("Podcast responses grew from 4% to 11% this week — and podcast-sourced customers have 2.3× AOV"). Links back to the underlying question/analytics.
- **Weekly email subscription:** Checkbox to receive this digest via email. Subscribable per-user.

---

## Screen: Integrations

- **URL path:** `/integrations`.
- **Layout:** Grid of integration cards. 25+ integrations grouped by category:
  - **Ecom** (Shopify, WooCommerce, Salesforce Commerce Cloud)
  - **Email/SMS** (Klaviyo, Postscript, Attentive, Mailchimp)
  - **Analytics** (GA4, Meta CAPI, TikTok CAPI, Triple Whale, Elevar, Daasity, Segment)
  - **Data Warehouses** (Snowflake, BigQuery)
  - **Spreadsheets** (Google Sheets)
  - **Workflow** (Shopify Flow)
  - Plus recently-added: **Shopify Analytics** (April 2026), **Hazel** (March 2026)
- **Per card:** Logo, status (Connected / Not Connected), Connect / Manage button.
- **Klaviyo deep-integration:** Fairing pushes survey responses into Klaviyo as custom properties, enabling segmentation like "customers who said 'Podcast' in survey".
- **Screenshot URLs:**
  - `https://canny-assets.io/images/2f8b547fab11362ace7b57af27d723fe.png` (Shopify Analytics)
  - `https://canny-assets.io/images/0ddf62131eab98ea78d224031b2133df.png` (Hazel)

---

## Screen: Question Templates Library

- **URL path:** `/templates`.
- **Layout:** Grid of 30+ template cards, categorised.
- **Categories:** Attribution (How Did You Hear About Us), NPS, Product Research, Audience Discovery, Repeat Customer, Churn Risk.
- **Per template card:** Title, description, typical response rate, 1-click "Use this template" button.
- **Example templates:** "How did you hear about us?", "What almost stopped you from purchasing?", "What are you planning to use this for?", "How likely are you to recommend us?" (NPS).

---

## Screen: Survey Preview / Customer-facing Stream

- **URL path:** Rendered inline on Shopify Order Status Page / Thank-You Page, not a page on fairing.co.
- **Layout (customer-facing):**
  - Clean, minimal block embedded between the order confirmation and shipping info
  - Question text + description
  - Response options as tappable cards (mobile) or pill buttons (desktop)
  - Progress indicator (if multi-step with clarifications) — a subtle dot row at top
  - Submit button (or auto-advance on tap if enabled)
  - After submit: success message with custom title/subtitle/image/CTA
  - Customer can dismiss and come back — cookie-stored progress
- **Implementation:**
  - Shopify Checkout Extensibility: Fairing app block added to Thank You and Order Status pages via theme editor
  - WooCommerce: Web SDK embed via snippet
  - Salesforce Commerce Cloud: Cartridge install
  - POS: UI extensions for in-store
- **Full-Page Takeover (advanced):**
  - Option to take over the full order status page rather than embed inline — higher response rates but more intrusive
- **Screenshot URLs:**
  - `https://cdn.shopify.com/app-store/listing_images/b012f4ba86663a72ddc26dcdfe1f9ffe/desktop_screenshot/CO-WyY657ZADEAE=.png` (Survey customization)
  - `https://cdn.shopify.com/app-store/listing_images/b012f4ba86663a72ddc26dcdfe1f9ffe/desktop_screenshot/CN7q2r3apJEDEAE=.png` (NPS landing pages)
  - `https://cdn.shopify.com/app-store/listing_images/b012f4ba86663a72ddc26dcdfe1f9ffe/pos_screenshot/CNPQsPK57ZADEAE=.png` (POS integration)
  - `https://cdn.shopify.com/app-store/listing_images/b012f4ba86663a72ddc26dcdfe1f9ffe/promotional_image/CJXY7Nuky5MDEAE=.png` (promotional)

---

## Screen: Settings (account, billing, team)

- **URL path:** `/settings`.
- **Layout:** Tabbed settings page.
- **Tabs:**
  - Account — company info, timezone, branding
  - Billing — current plan, response cap, usage meter, invoices
  - Team — invite users with role (Admin / Editor / Viewer)
  - Translations — manage translated response labels and question texts
  - Targeting Defaults — app-wide defaults for targeting rules
  - API — API keys, webhook URLs
- **Usage meter:** Prominent strip showing "X of Y responses used this month" with a progress bar; tier-change CTA when near cap.

---

## Specific micro-patterns worth documenting

- **Live/Paused toggle as a single click:** Question status is a one-click pill toggle — the fastest way in the category to A/B test a survey on/off. No confirmation modal for going live.
- **Drag-handle ordering with live impact:** Reordering questions in the Question Stream immediately changes what the next customer sees — no "Save order" step. Feels immediate and gives the tool a "stream" feel.
- **Live preview two-column editor:** Every edit to a question updates the preview panel in real time. Preview also toggles between new/returning customer states and mobile/desktop. Category-leading.
- **Response-rate banner threshold:** Fairing explicitly recommends >35% response rate and shows a warning banner when below, citing non-response bias.
- **Extrapolation columns:** Analytics table includes "Extrapolated Count" and "Extrapolated Revenue" — applying (observed / response rate) × 100 to model what the full customer base would have said. Controversial statistically but tells the narrative users want.
- **AUTO recategorisation badge:** AI-recategorised responses get an "AUTO" pill in the Responses table — users can manually override with a pen icon.
- **Bulk recategorisation flow:** Multi-select + Update All modal for cleaning free-text survey responses that should roll up under a canonical label. Critical for clean analytics at volume.
- **Previous Month default filter:** Small but frequently-cited: default date range can be set to "Previous Month" across the app, removing the clicks to switch.
- **Success Message is a configurable screen:** Post-submit message supports title / subtitle / image / CTA — users can drive the customer to a discount code, blog post, or membership signup.
- **Per-question Analytics link:** Every question row has a direct "Analytics" button — skips the two-step "go to analytics then filter by question".
- **Question Templates with tested response rates:** Template cards show expected response rate benchmarks — sets correct expectations before the question ships.
- **LTV-by-channel cohort:** Cohort-style LTV table pivoted by survey response rather than first-touch channel. This is unique — nobody else in the category has self-reported-channel × LTV.
- **Integration output not input:** Unlike other tools, Fairing's integrations are mostly outbound — pushing survey responses into Klaviyo/Meta/Triple Whale. Inbound integrations are limited to Shopify.
- **Targeting rules per-question:** Each question gets its own targeting rules (new vs returning, products purchased, frequency) rather than application-wide rules. Allows different questions for different segments.
- **Question Frequency control:** "Only ask on first order", "Every order", "Every Nth order" — controls respondent fatigue. Rare in survey platforms.
- **Syncing indicator:** Not heavily emphasised — Fairing is real-time for responses. Integration sync health appears on the Integrations page per connector.
- **Estimated/modeled indicator:** Extrapolated columns visually distinguished (italic or tagged "Ext") so users don't confuse them with observed values.
- **Date picker:** Simple presets + custom range popover; "Previous Month" is prominent as recent default addition.
- **Number formatting:** Shop currency, 2 decimals for AOV and %; counts shown as integers with commas.
- **Customer-facing block is minimal:** The in-checkout survey block is deliberately plain — three-ish card buttons + submit — so it reads as native Shopify content, not a third-party popup.
- **POS UI Extensions:** Fairing supports Shopify POS UI extensions so in-store purchases can also trigger surveys via the POS receipt flow.
- **No ad-spend layer:** Fairing is survey-only — no blended CAC/ROAS calculations. Users pair it with Triple Whale/Northbeam/Polar for cost attribution.
- **Deliberate scope narrowness:** The entire app has maybe 10 top-level pages. Compared to Triple Whale's 30+ surfaces, Fairing is the "one-page tool done exceptionally well" archetype.

---

## Screenshot inventory

```
https://cdn.shopify.com/app-store/listing_images/b012f4ba86663a72ddc26dcdfe1f9ffe/promotional_image/CJXY7Nuky5MDEAE=.png
https://cdn.shopify.com/app-store/listing_images/b012f4ba86663a72ddc26dcdfe1f9ffe/desktop_screenshot/CPbk9fKmy5MDEAE=.png
https://cdn.shopify.com/app-store/listing_images/b012f4ba86663a72ddc26dcdfe1f9ffe/desktop_screenshot/CJSLwI657ZADEAE=.png
https://cdn.shopify.com/app-store/listing_images/b012f4ba86663a72ddc26dcdfe1f9ffe/desktop_screenshot/CO-WyY657ZADEAE=.png
https://cdn.shopify.com/app-store/listing_images/b012f4ba86663a72ddc26dcdfe1f9ffe/desktop_screenshot/CN7q2r3apJEDEAE=.png
https://cdn.shopify.com/app-store/listing_images/b012f4ba86663a72ddc26dcdfe1f9ffe/pos_screenshot/CNPQsPK57ZADEAE=.png
https://files.readme.io/a51c729-image.png
https://files.readme.io/189b1d136c78a1481bf0e6afafecab05ca62d174f90806aa0af98193a1ec43b2-image.png
https://files.readme.io/4f7a455cbcb6885a2f0bd3e8e356a40a1aaa08761914f7ce47077e125287fc8b-image.png
https://files.readme.io/b89741136f0bfe63927a80791e2fb94a5113b914f543b1b0ac6289bab1804dce-image.png
https://files.readme.io/eb9ee64d02e4ec7f9523cd4fcda05cc99141626037ff4d483dc8a9ff0e45424b-image.png
https://files.readme.io/dbe768f-Fairing_date_types.png
https://files.readme.io/b96da397426a6c396c3d6c119d3458d79c948419303ad012d42d6f709c7f9dd3-image.png
https://files.readme.io/b1e67dc6f5a98c352aadfe1c4dc0e1faf845c47a0beae3ad25a5a45970531e12-image.png
https://files.readme.io/f27e2563b7994cc502397699a77aa14f21f76bda23009b03f18724760ab89ac9-image.png
https://files.readme.io/7da5391-image.png
https://files.readme.io/99112f3-image.png
https://files.readme.io/4330993d81063c9b996d4718e6eeeb66d9443d2184509e679e949a6a6c6f1a0e-image.png
https://files.readme.io/cb4f82e-Question_Description.png
https://files.readme.io/a813b770c136df947ce1b0f789491dc7dc1cef6a8a4a75ff5d73b3bfd1d2d209-image.png
https://files.readme.io/fe249bddfd04c5d7bcbed3318ab1cb07c482166c6bee3ce823c35cf4594f4c67-image.png
https://files.readme.io/efdcb551e9861fa9e0ceffa2d02b681dd05d7cdac88e9c88a995d1c5dc66b040-image.png
https://files.readme.io/997e8f4b027dc1e64e083b81f4294d289ea57bcf15d0bb231bca56562ee67c3a-image.png
https://canny-assets.io/images/2f8b547fab11362ace7b57af27d723fe.png
https://canny-assets.io/images/0ddf62131eab98ea78d224031b2133df.png
https://canny-assets.io/images/db49802790addcec4e4f51d4389237fa.png
https://canny-assets.io/images/68984932cad4f27e41a94c8b3790438f.png
https://canny-assets.io/images/4a1d031d2c0960ddbcf2c8518039d60c.png
https://canny-assets.io/images/21bb6dd8cc3365138ab853db5b69f126.png
https://canny-assets.io/images/8ee11dd44aa15a114bf138371a4dfbf4.png
https://canny-assets.io/images/6adc7e0e9ce376ba0a030150337deeae.png
https://canny-assets.io/images/ab3d13e37b0ee2d022bd8c5eb2dc20df.png
```
