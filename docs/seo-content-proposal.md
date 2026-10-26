# Mzigo.online â€” SEO Content & Copy Proposal

**Status: DRAFT FOR REVIEW â€” nothing on the live site has been replaced.**
Prepared 25 October 2026. Approve section by section (reply with the section numbers), or send edits.

---

## 0. How to review this document

- **Â§1â€“4** are the strategy and technical foundation (titles, meta, schema, indexing).
- **Â§5** contains the exact replacement copy for every public page â€” this is the part to read closely.
- **Â§6** is the FAQ bank (currently CMS-editable in Admin â†’ Website).
- **Â§7** are net-new pages/content for a second phase â€” not needed for launch.
- **Â§11** lists the decisions I need from you before implementation.

Every string in Â§5/Â§6 is written to be pasted as-is. Items needing your input are marked **âš ï¸ DECISION**.

---

## 1. Where the site is today (audit summary)

| Area | Current state | Opportunity |
|---|---|---|
| Home | Title 42 chars, meta 127 chars, H1 is the emotional CMS headline | Add primary keyword to H1/title; add crawlable explanatory copy |
| Send / Errand / Track / Partners | App-first pages; only form labels + one line of intro copy | Add 150â€“350 words of useful copy, headings and FAQs around the tool |
| Pricing | â€œLoading plansâ€¦â€ with no static copy | Static plan explanation + FAQ for crawlers and humans |
| Contact / Signup / Legal | Reasonable but thin/missing meta descriptions | Sharpen meta, add trust copy |
| Private pages (`/orders`, `/profile`, `/billing`, `/settings`, `/admin`, `/freelancer`, `/customer`, auth) | Indexable by default | Add `noindex` â€” they are app screens, not content |
| Canonical tags | None | Add self-referencing canonicals |
| Open Graph / Twitter cards | None | Add per-page OG/Twitter tags |
| Structured data | None | Organization, WebSite, Service, FAQPage, BreadcrumbList, ContactPage |
| `sitemap.xml` / `robots.txt` | Missing | Add both |
| Coverage claims | 76 destinations / 6 partners in DB, but includes test rows and near-duplicates | Clean data before quoting numbers publicly |

**Data hygiene before publishing coverage numbers:** `Test`, `Test 2`, `Test Office` and duplicate pairs (`Gwa-kairu`/`Gwakairu`, `Membley`/`Membley oj`/`Membly oj`, `Ruiru`/`Ruiru ndani`, `Thika`/`Thika cascade`) should be removed/merged. After cleanup the real number is comfortably **â€œ70+ destinationsâ€**.

---

## 2. Positioning, voice and value propositions

**Positioning statement:** *Mzigo.online is the simplest way for Kenyan businesses and individuals to send a parcel or errand through trusted courier partners â€” with live tracking and payment on collection by M-Pesa.*

**Voice:** confident, plain Kenyan English; benefit-first; concrete; never hypey. No â€œcheapestâ€, â€œfastestâ€, â€œguaranteed same-dayâ€ claims â€” we cannot verify them.

**Four proof-backed value propositions (used across pages):**

1. **One network, more choice** â€” 6 trusted courier partners, 70+ destinations, one account.
2. **Live, scan-based tracking** â€” every drop-off, transit and collection scan on one page.
3. **Pay on collection with M-Pesa** â€” receiver pays at collection and gets a collection code.
4. **Built for business** â€” workspaces, team roles, saved senders, QR labels and API access.

**Audiences:** individuals sending to family/documents; e-commerce sellers shipping orders; business ops teams needing records/API; receivers collecting parcels; freelancers running errands; courier partners.

---

## 3. Keyword map (no fabricated volumes â€” validate in GSC/Keyword Planner)

| Cluster | Primary keyword | Supporting keywords | Intent | Target page | Priority |
|---|---|---|---|---|---|
| Parcel delivery | parcel delivery Kenya | courier services Kenya, send parcel Kenya | Commercial | `/` (then `/parcel-delivery-kenya`) | High |
| Sending | send a parcel Kenya | send parcel Nairobi, parcel drop-off | Transactional | `/send-mzigo` | High |
| Tracking | track parcel Kenya | Mzigo tracking, Locker ID tracking | Transactional / support | `/track-mzigo` | High |
| Partners/routes | courier partners Kenya | courier routes, Chania/Kasese/Lopha partners | Commercial investigation | `/partners` | Medium |
| Price | parcel delivery cost Kenya | courier prices Kenya, parcel rates | Commercial | `/pricing` + FAQ | High |
| Payment | pay on delivery M-Pesa | M-Pesa collection code, cash on delivery | Informational â†’ transactional | Home FAQ + `/terms` | Medium |
| Business | parcel delivery for businesses | e-commerce delivery Kenya, courier API Kenya | Commercial | `/signup`, `/pricing` | Medium |
| Claims/trust | lost parcel Kenya claim | damaged parcel compensation | Informational | `/terms`, help article | Lowâ€“Medium |
| Coverage corridors | send parcel to Mombasa/Nakuru/Nyeri | courier Nairobiâ€“Mombasa | Transactional | location pages (Phase 2) | Medium |
| Partner onboarding | become a courier partner | courier partnership Kenya | Commercial | `/partners/become` (Phase 2) | Low |

---

## 4. Global SEO foundation

### 4.1 Titles and meta descriptions
- **Title formula:** `{Primary keyword phrase} | Mzigo.online` â€” keep â‰¤60 characters, keyword first, brand last.
- **Meta description:** 140â€“158 characters, one benefit + one proof + implicit CTA.
- **H1 rule:** one per page, contains the primary keyword phrase naturally. Current home H1 is a slogan â€” proposed change in Â§5.1.
- H2s carry supporting keywords (â€œHow Mzigo worksâ€, â€œWhere we deliverâ€, â€œParcel sizes explainedâ€).
- No duplicate titles across pages (current site has none).

### 4.2 Indexing policy

| Page(s) | Index? | Notes |
|---|---|---|
| `/` | âœ… | Primary landing page |
| `/send-mzigo`, `/send-errand`, `/track-mzigo` | âœ… | Tool pages with added explanatory copy |
| `/partners`, `/pricing`, `/contact` | âœ… | Commercial pages |
| `/signup` | âœ… | Acquisition page (optional; can be noindex if you prefer) |
| `/legal`, `/terms`, `/privacy` | âœ… | Trust/E-E-A-T; include in sitemap |
| `/login`, `/forgot-password`, `/reset-password`, `/verify-email` | âŒ `noindex, follow` | Utility screens |
| `/orders`, `/billing`, `/settings`, `/profile`, `/claims`, `/admin`, `/freelancer`, `/customer` | âŒ `noindex, nofollow` | Authenticated app screens |
| `403/404/500` | âŒ noindex (already) | Keep |

### 4.3 Canonical, Open Graph, Twitter
- Self-referencing canonical on every indexable page: `<link rel="canonical" href="https://mzigo.online{path}">`.
- OG defaults: `og:site_name=Mzigo.online`, `og:type=website` (home) / `article` (help), `og:title` = SEO title, `og:description` = meta description, `og:url`, `og:image=1200Ã—630`.
- Twitter: `summary_large_image`, same title/description/image.
- âš ï¸ DECISION: final production domain (`mzigo.online` assumed) and an OG image (I can design one from the hero later).

### 4.4 Structured data plan

| Schema | Where | Purpose |
|---|---|---|
| `Organization` + `logo`, `contactPoint` | Site-wide (home + inner pages) | Brand entity, support contact |
| `WebSite` | Home | Site identity |
| `Service` (`ParcelDelivery`, `areaServed: KE`) | Home | Service understanding |
| `FAQPage` | Home, Send, Track, Pricing (matching visible FAQs) | FAQ rich results |
| `BreadcrumbList` | Send, Track, Partners, Pricing, Contact, Terms, Privacy | Breadcrumbs + SERP path |
| `ContactPage` + `ContactPoint` | Contact | Contact rich result |
| `Product` + `Offer` | Pricing | Plan prices, only if prices stay public |
| `Article` | Help centre (Phase 2) | Content rich results |

JSON-LD samples are in Â§8.

### 4.5 Sitemap, robots and internal linking
- `sitemap.xml` listing only indexable pages, `lastmod` from build; submit in Google Search Console and Bing.
- `robots.txt`: allow public pages/assets; `Disallow: /api/`, `/admin`, `/orders`, `/billing`, `/settings`, `/profile`, `/claims`, `/freelancer`, `/customer`, `/verify-email`; reference sitemap.
- Internal linking: home â†’ send/track/partners/pricing/contact; send â†” track â†” partners; each page links to one relevant FAQ/help article; footer keeps legal links.
- Keep existing URLs â€” no redirects required. New URLs only in Phase 2.

### 4.6 Image SEO & performance
- Alt text rules: describe content + context, no keyword stuffing (list in Â§9).
- Hero remains `fetchpriority="high"`, `loading="eager"`; below-fold images lazy.
- Already fixed: CLS (0.002), consistent logo sizing, cached static assets, reduced-motion support.

---

## 5. Page-by-page copy

Legend: **Title** = `<title>`, **Meta** = meta description, **H1** = page heading. Character counts noted for limits.

### 5.1 Home `/` â€” index

**Title (47):** `Send & Track Parcels Across Kenya | Mzigo.online`
**Meta (149):** `Send parcels with trusted courier partners across Kenya. Compare couriers, drop off, track every scan and pay on collection with M-Pesa. Start free.`

**H1 options:**
- **A (recommended):** `Send and track parcels across Kenya`
- B (keeps current emotional tone): `Parcels delivered fast, affordably with care`
- C: `Kenya's simpler way to send and track parcels`

**Hero subtitle (with A, 131):**
`One network of trusted courier partners, live tracking and M-Pesa pay on collection â€” built for businesses and everyday senders.`

**Hero CTAs:** primary `Send a parcel` â†’ `/send-mzigo`; secondary `Track a parcel` â†’ `/track-mzigo`.

**Trust strip below hero (new):**
`6 courier partners · 70+ destinations · Live tracking · Parcel insurance available · Pay on collection`
`6 courier partners Â· 70+ destinations Â· Live tracking Â· Pay on collection`

**Intro paragraph (new, under hero/step section, 205 chars):**
`Mzigo.online connects you to trusted courier partners across Kenya. Register a parcel online, drop it off at a partner office and track it all the way to collection â€” while the receiver pays the delivery fee by M-Pesa.`

**â€œHow Mzigo worksâ€ (3 cards):**
1. **Choose your courier and route** â€” `Compare partner offices, routes and delivery options for your parcel, then pick the one that fits your budget.`
2. **Register and drop off** â€” `Add sender and receiver details, declare the parcel value and print your QR label. Hand the parcel in at the partner office.`
3. **Track it to collection** â€” `Every scan appears on the tracking page. The receiver pays the delivery fee by M-Pesa at collection and gets a collection code.`

**â€œWhy send with Mzigoâ€ (replaces current 3 cards, 4 cards):**
- **One network, more choice** â€” `Send through six established courier partners and 70+ destinations across Kenya without opening multiple accounts.`
- **Live, scan-based tracking** â€” `Follow your parcel from drop-off through transit to collection. Share the tracking link with your receiver.`
- **Peace of mind with parcel cover** — `Declare your parcel's value for cover, follow every scan, and if something goes wrong our claims process reviews approved payouts against the declared value.`
- **Built for business & Freelance** — `Workspaces, team roles, saved senders, QR labels and API access for e-commerce stores — plus errand jobs and earnings for freelancers.`

**Coverage section (new):**
H2: `Where we deliver`
`Our partner network currently covers 70+ destinations, including Nairobi, Mombasa, Nakuru, Nyeri, Naivasha, Thika, Machakos, Kitui, Embu, Kilifi, Malindi and Voi â€” with new routes added regularly.`

**Audience blocks (new):**
- **For individuals** â€” `Send a gift, documents or a parcel home without queuing at multiple offices. Register online, drop off once, track to collection.`
- **For e-commerce sellers** â€” `Register parcels from your workspace, share a tracking link with each customer and let receivers pay at collection.`
- **For teams** â€” `Invite staff, assign roles, save frequent senders and connect your store with the Mzigo.online API.`

**Final CTA (new):**
H2: `Send your first parcel today`
`Create your account in minutes, register a parcel and drop it off at the nearest partner office. No card required to start.`
Buttons: `Start free` â†’ `/signup` Â· `Track a parcel` â†’ `/track-mzigo`

**Internal links:** send-mzigo, track-mzigo, partners, pricing, contact, terms.
**Schema:** Organization, WebSite, Service, FAQPage, BreadcrumbList.
**Image alts:** hero â†’ `Courier handing over a parcel at a Mzigo partner drop-off in Kenya`; partner cards â†’ `{Partner name} courier partner in Kenya`.

âš ï¸ **DECISION:** H1 option A/B/C. If B, I will still add the keyword to the intro paragraph and title (title/meta do the heavy lifting).

---

### 5.2 Send a parcel `/send-mzigo` â€” index

**Title (55):** `Send a Parcel in Kenya â€” Compare Couriers | Mzigo.online`
**Meta (145):** `Compare courier partners, get a delivery quote and register your parcel in minutes. QR label, live tracking and M-Pesa pay on collection across Kenya.`

**H1:** `Send a parcel across Kenya`

**Intro paragraph (190):**
`Choose the courier partner and route that suit your parcel, add sender and receiver details, declare the value and drop it off. A QR label and tracking number are generated the moment you register.`

**Step helper copy (shown above each step):**
- Step 1 â€” `Choose courier and route` / `Compare partner offices and destinations for this parcel.`
- Step 2 â€” `Sender, receiver and parcel details` / `Add contacts, describe the parcel and declare a value for cover.`
- Step 3 â€” `Review and register` / `Confirm the details to generate the QR label and tracking number.`

**Trust note under form:**
`The receiver pays the delivery fee at collection by M-Pesa and receives a collection code by SMS or WhatsApp.`

**Sizes/coverage copy (below the tool, new):**
H2: `Parcel sizes and what fits`
`Envelopes are ideal for documents; Small and Medium suit everyday boxes; Large and above cover bulky items. If your item is fragile, high-value or unusually shaped, declare it accurately and contact support before drop-off.`
H2: `Where your parcel can go`
`Our partner network covers Nairobi, Mombasa, Nakuru, Nyeri, Naivasha, Thika, Machakos, Kitui, Embu, Kilifi, Malindi, Voi and more than 60 other destinations.`

**Mini-FAQ (3):** see Â§6 Q1â€“Q3.
**Schema:** BreadcrumbList, FAQPage (if mini-FAQ rendered).
**Internal links:** track-mzigo (â€œTrack a parcel you've already sentâ€), partners, pricing.

---

### 5.3 Run an errand `/send-errand` â€” index

**Title (51):** `Book a Runner for Errands in Kenya | Mzigo.online`
**Meta (137):** `Book a runner to pick up or deliver around town. Set the route, add the item details and confirm the fee before pickup. Pay by M-Pesa or cash.`

**H1:** `Book a runner for errands`

**Intro (168):**
`Need something picked up, delivered or bought around town? Choose a partner, describe the errand and confirm the fee before a runner is assigned. Pay by M-Pesa or cash.`

**Helper notes:**
- `Addresses auto-complete from our route network. A runner is assigned after booking.`
- `The runner fee is confirmed before pickup â€” no surprise charges at the door.`

**Below-tool copy (new):**
H2: `What can a runner do?`
`Pick up documents or parcels, deliver forgotten items, collect purchases, run business errands and handle same-town deliveries. For long-distance parcel delivery, use Send Mzigo instead.`
H2: `Paying for an errand`
`Choose M-Pesa or cash when booking. The estimate is confirmed before pickup so both sides know the cost.`

**Schema:** BreadcrumbList.
**Internal links:** send-mzigo, track-mzigo, contact.

---

### 5.4 Track `/track-mzigo` â€” index

**Title (45):** `Track Your Parcel or Errand | Mzigo.online`
**Meta (148):** `Enter your tracking number or Locker ID to see every scan from drop-off to collection. Track Mzigo parcels and errands instantly â€” no login required.`

**H1:** `Track your parcel`

**Intro (158):**
`Enter the tracking number from your receipt, SMS notification or workspace order to follow your parcel from drop-off to collection.`

**Helper section, H2:** `Where do I find my tracking number?`
- `It is printed on the QR label or receipt given at drop-off.`
- `You also receive it by SMS or WhatsApp when the parcel is registered.`
- `Workspace users can copy it from Orders.`
- `The sender can share the tracking link directly with the receiver.`

**Helper section, H2:** `What each scan means`
- `Dropped off â€” the partner office has accepted the parcel and tracking has started.`
- `In transit â€” the parcel is moving between partner offices.`
- `Ready for collection â€” the parcel is at the destination office and the fee is due.`
- `Collected â€” the receiver has paid (where applicable) and collected the parcel.`

**Support note:**
`No scans yet? Allow a little time for the next office to scan the parcel, then refresh. If a parcel is delayed, lost or damaged, open a claim from the Claims page or contact support.`

**Schema:** BreadcrumbList.
**Internal links:** claims, contact, send-mzigo.

---

### 5.5 Partners `/partners` â€” index

**Title (52):** `Courier Partners & Routes Across Kenya | Mzigo.online`
**Meta (132):** `Browse courier partners and their routes across Kenya. Compare destinations, choose a partner and send your parcel with Mzigo.online.`

**H1:** `Courier partners and routes`

**Intro (180):**
`Every parcel travels with one of our trusted courier partners. Browse their routes, compare coverage and choose the partner that fits your sender and receiver â€” then send directly from the partner card.`

**Partner card support copy (template, generated per partner):**
`{Partner} â€” {n} destinations across {regions}. Send a parcel with {Partner}.`

**Section, H2:** `How to choose a courier partner`
`Start with the destination: filter by route and compare which partners serve that office. Then check the drop-off location that is most convenient for you, and register the parcel with the partner you choose.`

**Section, H2:** `New routes are added regularly`
`Our coverage grows as partners add offices. If you cannot find a destination, contact support and we will check whether a partner can reach it.`

**Optional partner recruitment block (âš ï¸ DECISION â€” only if the programme exists):**
H2: `Run a courier or transport business?`
`Partner with Mzigo.online to receive senders digitally, manage drop-offs and grow your route network. Email hello@mzigo.online with your company details.`

**Schema:** BreadcrumbList, ItemList (partners).
**Internal links:** send-mzigo, pricing, contact.

---

### 5.6 Pricing `/pricing` â€” index

**Title (52):** `Pricing & Plans for Growing Businesses | Mzigo.online`
**Meta (147):** `Simple monthly plans for businesses: Starter, Growth and Scale. Tracking, QR labels, saved senders and M-Pesa pay on collection included on every plan.`

**H1:** `Simple pricing that grows with your business`

**Intro (205):**
`Pick a plan by monthly parcels and team size. Every plan includes tracking, QR labels, saved senders and M-Pesa pay on collection. Delivery fees are quoted per parcel and paid at collection unless your workspace has agreed different terms.`

**Static copy for plan sections (in addition to DB prices):**
- **Starter** â€” `For solo senders and small shops. 50 parcels a month, 5 team members, saved senders, tracking and email support.`
- **Growth** â€” `For growing e-commerce stores. 300 parcels a month, 15 team members, API access, team roles and priority support.`
- **Scale** â€” `For high-volume operations. 2,000 parcels a month, 50 team members, dedicated onboarding, custom rate cards and SLA support.`

**Billing notes:**
`Prices are in KES and billed monthly. Upgrade or downgrade any time from Billing. Delivery fees are separate from the subscription and are shown before you register each parcel.`

**Pricing FAQ (4):** see Â§6 Q10â€“Q12.

**âš ï¸ DECISION â€” pricing mode banner.** The platform currently runs in free mode (`pricing_mode=free`). Either:
- **A (recommended while free):** add a banner `Mzigo.online is free to use while we onboard partners â€” no subscription is charged today.` and keep plans visible as â€œwhat's comingâ€.
- **B:** hide plan prices and say `Plans are being finalised â€” contact us for current pricing.`

**Schema:** Product + Offer (only if prices remain public).
**Internal links:** signup, billing (login), terms, contact.

---

### 5.7 Contact `/contact` â€” index

**Title (46):** `Contact Mzigo.online | Support & Partnerships`
**Meta (141):** `Contact the Mzigo.online team about sending, tracking, billing, workspaces, claims or partnerships. We reply within one business day, Monâ€“Sat.`

**H1:** `Contact Mzigo.online`

**Intro (154):**
`Questions about a parcel, a workspace or a partnership? Send us a message and our team will reply by email within one business day.`

**Support panel copy:**
- Email: `hello@mzigo.online`
- Hours: `Mondayâ€“Saturday Â· 8:00â€“18:00 EAT`
- Response: `Within one business day`
- Tip: `Include your tracking number, invoice number or workspace name so we can help faster.`

**Quick links (already present, tighten labels):**
`Track a parcel Â· Claims Â· Billing & invoices Â· FAQs`

**Schema:** ContactPage, ContactPoint.
**Internal links:** track, claims, terms, pricing.

---

### 5.8 Signup `/signup` â€” index

**Title (41):** `Create a Free Workspace | Mzigo.online`
**Meta (136):** `Start a Mzigo.online workspace: 14-day Starter trial, 50 parcels a month, tracking links, QR labels and team roles. No card required.`

**H1:** `Start your free Mzigo.online workspace`

**Intro (152):**
`Create a workspace for your business and send your first parcel today. 14 days of Starter are on us â€” no card required.`

**Bullets (existing, polished):**
- `Your own workspace, team roles and saved senders`
- `50 parcels a month during the Starter trial`
- `Tracking links and QR labels for every parcel`
- `Upgrade to Growth or Scale when you need more`

**Trust line:** `Your workspace data stays private. Read our Privacy Policy and Terms.`

**Internal links:** login, terms, privacy, pricing.

---

### 5.9 Login and auth screens â€” `noindex, follow`

- **Title:** `Sign in to Mzigo.online`
- **Meta:** `Sign in to your Mzigo.online workspace to send, track and manage parcels.`
- Keep demo-account block **only in development**; hide when `show_demo_accounts=0` before production.
- `forgot-password`, `reset-password`, `verify-email`: refine titles (`Reset your password | Mzigo.online`), add `noindex`.

---

### 5.10 Legal hub `/legal`, Terms `/terms`, Privacy `/privacy` â€” index

| Page | Proposed title | Proposed meta |
|---|---|---|
| `/legal` | `Legal & Policies | Mzigo.online` | `Mzigo.online policies: terms of service, privacy policy and support policy in one place — what applies when you send, track or collect parcels.` |
| `/terms` | `Terms & Conditions | Mzigo.online` | `The terms that apply when you send, track or receive parcels through Mzigo.online, including bookings, fees, claims and liability.` |
| `/privacy` | `Privacy Policy | Mzigo.online` | `How Mzigo.online collects, uses, shares and protects personal data under the Kenya Data Protection Act â€” and the rights you have.` |

Add short SEO intros above the legal documents (they are already detailed; intros help snippets):
- Terms: `These terms explain how sending, tracking and collecting parcels works on Mzigo.online, including fees, claims and liability.`
- Privacy: `This policy explains what personal data Mzigo.online collects, why we collect it, who we share it with and the rights you have under the Kenya Data Protection Act, 2019.`

**Schema:** BreadcrumbList.
**Internal links:** each other, contact.

---

### 5.11 Claims `/claims` â€” `noindex, nofollow`

This is an authenticated app screen. Keep it out of the index. The public-facing claim explainer lives in Terms Â§10 and FAQ Q8. Optionally add a public `/claims-policy` page in Phase 2 (see Â§7).

### 5.12 App screens â€” `noindex, nofollow`

`/orders`, `/billing`, `/settings`, `/profile`, `/admin`, `/freelancer`, `/customer`, `/verify-email`, `/forgot-password`, `/reset-password`.
Titles stay task-focused (e.g. `Orders | Mzigo.online`). No marketing copy needed.

### 5.13 Error pages â€” `noindex`

Keep the production 404/403/500 copy already implemented. Optional addition on 404: a short â€œPopular pagesâ€ list (already present) and a search/track prompt (already present).

---

## 6. FAQ bank (CMS-managed)

Current 6 FAQs are good; proposal: keep the topics, rewrite for keywords, and expand to 12. Home displays all; tool pages can show a subset.

**Q1. How do I send a parcel with Mzigo.online?**
`Open Send Mzigo, choose a courier partner and route, add the sender and receiver details, declare the parcel value and drop it off at the partner office. A tracking number and QR label are generated for every parcel.`

**Q2. How do I track my parcel?**
`Enter the tracking number or Locker ID on the Track page. You will see every scan â€” drop-off, transit, arrival and collection â€” without signing in.`

**Q3. How much does it cost to send a parcel in Kenya?**
`The quote combines the courier partner's base rate for the parcel size and route with holding, declared-value cover and a small platform fee. The exact amount is shown before you register the parcel.`

**Q4. Can the receiver pay the delivery fee at collection?**
`Yes. The receiver pays by M-Pesa when the parcel is ready for collection and receives a collection code. Some workspaces agree different billing terms.`

**Q5. Where do I find my tracking number or Locker ID?**
`It is on the QR label or receipt from drop-off, and is sent by SMS or WhatsApp when the parcel is registered. Workspace users can also copy it from Orders.`

**Q6. What parcel sizes can I send?**
`Envelopes cover documents; Small and Medium suit everyday boxes; Large and above handle bulky items. Declare fragile or high-value items accurately and contact support if you are unsure.`

**Q7. How long does delivery take?**
`Delivery time depends on the partner and route. Most routes move within a few days, and tracking updates appear as the parcel is scanned at each office. Check with support for a specific route before sending urgent items.`

**Q8. What happens if my parcel is lost, damaged or delayed?**
`Open a claim from the Claims page with the tracking number, a description and the estimated value. Claims are reviewed against the declared value and the partner's terms, and approved payouts are recorded in the platform.`

**Q9. Which destinations do you cover?**
`Our partner network currently covers 70+ destinations, including Nairobi, Mombasa, Nakuru, Nyeri, Naivasha, Thika, Machakos, Kitui, Embu, Kilifi, Malindi and Voi. New routes are added regularly.`

**Q10. Do you offer plans for businesses?**
`Yes. Starter suits small shops, Growth adds API access and team roles for growing stores, and Scale supports high-volume operations with custom rate cards and SLA support.`

**Q11. Is the delivery fee included in my plan?**
`No. Plans cover platform features such as parcel limits and team seats. Delivery fees are quoted per parcel and are paid at collection unless your workspace has agreed different terms.`

**Q12. Can I become a courier partner?**
`If you run a courier or transport business, email hello@mzigo.online with your company details, routes and offices. We will get back to you about onboarding.` *(âš ï¸ confirm programme details before publishing.)*

**Placement:** Home = the first 5 with a **View all FAQs** link to `/faq`; `/faq` = all 12 (standalone FAQ page with FAQPage schema); Send = Q1, Q3, Q6, Q10; Track = Q2, Q5, Q7, Q8; Partners = Q9, Q12; Pricing = Q10, Q11, plus a billing question.

---

## 7. Phase 2 â€” new pages and content cluster (after approval)

| Priority | URL | Title | H1 | Primary keyword | Purpose |
|---|---|---|---|---|---|
| 1 | `/parcel-delivery-kenya` | `Parcel Delivery in Kenya â€” Send with Trusted Couriers` | `Parcel delivery across Kenya` | parcel delivery Kenya | Service pillar page linking to all tools |
| 1 | `/help` | `Help Centre | Mzigo.online` | `How can we help?` | Mzigo help | Hub for FAQs and guides |
| 2 | `/send-parcel-nairobi` | `Send a Parcel in Nairobi | Mzigo.online` | `Send a parcel in Nairobi` | send parcel Nairobi | Corridor landing page |
| 2 | `/send-parcel-mombasa` | `Send a Parcel to Mombasa | Mzigo.online` | `Send a parcel to Mombasa` | parcel to Mombasa | Corridor landing page |
| 2 | `/send-parcel-nakuru` | `Send a Parcel to Nakuru | Mzigo.online` | `Send a parcel to Nakuru` | parcel to Nakuru | Corridor landing page |
| 3 | `/help/how-to-send-a-parcel-in-kenya` | `How to Send a Parcel in Kenya (Step by Step)` | `How to send a parcel in Kenya` | how to send a parcel | Guide + HowTo schema |
| 3 | `/help/mpesa-pay-on-collection` | `M-Pesa Pay on Collection Explained` | `Paying for a parcel with M-Pesa` | pay on delivery M-Pesa | Guide |
| 3 | `/help/how-to-track-your-parcel` | `How to Track Your Parcel` | `How to track your parcel` | track parcel Kenya | Guide |
| 3 | `/help/parcel-sizes-and-pricing` | `Parcel Sizes and Delivery Pricing` | `Parcel sizes explained` | parcel sizes Kenya | Guide |
| 4 | `/about` | `About Mzigo.online | Our Courier Network` | `About Mzigo.online` | Mzigo company | E-E-A-T / brand |
| 4 | `/partners/become` | `Become a Courier Partner | Mzigo.online` | `Partner with Mzigo.online` | become courier partner | Partner acquisition |
| 4 | `/blog` | `Mzigo.online Blog` | `Practical delivery advice for Kenyan businesses` | parcel delivery tips | Content hub |

**Flagship article outline â€” â€œHow to Send a Parcel in Kenyaâ€ (~1,200 words):**
1. What you need before you start (sender/receiver, parcel size, value).
2. Choosing a courier partner and route.
3. Registering the parcel and printing the QR label. 4. Drop-off and first scan. 5. Tracking and collection with M-Pesa. 6. Common mistakes (wrong phone number, undeclared value, prohibited items). 7. FAQs. **Schema:** HowTo + FAQPage.

**Flagship article outline â€” â€œM-Pesa Pay on Collection Explainedâ€ (~900 words):**
Who pays, when the fee is due, collection codes, what happens if the receiver cannot pay, refunds and failed deliveries, business alternatives (workspace billing). **Schema:** FAQPage.

**Location page template (~500â€“700 words):** local H1, coverage list, partner offices/destinations, how sending works locally, price/payment notes, corridor FAQ, internal links to Send/Track/Partners.

---

## 8. Ready-to-use JSON-LD

Site-wide Organization (place once, e.g. home + inner pages via template):

```json
{
  "@context": "https://schema.org",
  "@type": "Organization",
  "name": "Mzigo.online",
  "url": "https://mzigo.online",
  "logo": "https://mzigo.online/assets/img/mzigo-logo.png",
  "description": "Send and track parcels across Kenya with trusted courier partners.",
  "contactPoint": [{
    "@type": "ContactPoint",
    "contactType": "customer support",
    "email": "hello@mzigo.online",
    "areaServed": "KE",
    "availableLanguage": ["en", "sw"]
  }]
}
```

Home WebSite + Service:

```json
{
  "@context": "https://schema.org",
  "@type": "WebSite",
  "name": "Mzigo.online",
  "url": "https://mzigo.online"
}
```

```json
{
  "@context": "https://schema.org",
  "@type": "Service",
  "serviceType": "Parcel delivery",
  "provider": { "@type": "Organization", "name": "Mzigo.online", "url": "https://mzigo.online" },
  "areaServed": { "@type": "Country", "name": "Kenya" },
  "description": "Parcel delivery and tracking through trusted courier partners, with M-Pesa pay on collection."
}
```

FAQPage (repeat per question actually displayed):

```json
{
  "@context": "https://schema.org",
  "@type": "FAQPage",
  "mainEntity": [{
    "@type": "Question",
    "name": "How do I track my parcel?",
    "acceptedAnswer": { "@type": "Answer", "text": "Enter the tracking number or Locker ID on the Track page to see every scan from drop-off to collection." }
  }]
}
```

Breadcrumb example for `/send-mzigo`:

```json
{
  "@context": "https://schema.org",
  "@type": "BreadcrumbList",
  "itemListElement": [
    { "@type": "ListItem", "position": 1, "name": "Home", "item": "https://mzigo.online/" },
    { "@type": "ListItem", "position": 2, "name": "Send a parcel", "item": "https://mzigo.online/send-mzigo" }
  ]
}
```

ContactPage:

```json
{
  "@context": "https://schema.org",
  "@type": "ContactPage",
  "name": "Contact Mzigo.online",
  "url": "https://mzigo.online/contact"
}
```

Pricing offers (only if prices stay public):

```json
{
  "@context": "https://schema.org",
  "@type": "Product",
  "name": "Mzigo.online workspace plans",
  "brand": { "@type": "Brand", "name": "Mzigo.online" },
  "offers": [
    { "@type": "Offer", "name": "Starter", "price": "1500", "priceCurrency": "KES" },
    { "@type": "Offer", "name": "Growth", "price": "3500", "priceCurrency": "KES" },
    { "@type": "Offer", "name": "Scale", "price": "7500", "priceCurrency": "KES" }
  ]
}
```

---

## 9. Image alt-text plan

| Image | Current alt | Proposed alt |
|---|---|---|
| Hero photo | `Parcel delivery` | `Courier handing over a parcel at a Mzigo partner drop-off in Kenya` |
| Header/footer logo | `mzigo` | Keep short: `Mzigo.online logo` (decorative duplicate can be `alt=""`) |
| Partner card avatar | `{letter}` only | `{Partner name} courier partner in Kenya` |
| Why-Mzigo icons | decorative | `alt=""` + text label (already text) |
| OG image (new) | â€” | 1200Ã—630 with logo, headline â€œSend & track parcels across Kenyaâ€ |

---

## 10. Implementation and governance

### 10.1 What can change where

| Content | Editable now | Implementation |
|---|---|---|
| Home hero title + subtitle | âœ… Admin â†’ Website â†’ Landing page copy | Already built; paste approved copy |
| FAQs | âœ… Admin â†’ Website â†’ FAQs | Paste Â§6 Q&As |
| Homepage sections, tool-page copy, intros | âŒ static HTML | Edit `_raw/tools/build_pages.py` + regenerate |
| Titles, meta descriptions, canonical, OG, schema, `robots`/`sitemap` | âŒ generated | Add per-page SEO config in the generator |
| New pages (Â§7) | âŒ | New generator templates + routes |
| CMS-editable per-page SEO fields (optional) | âŒ | Could add `seo_title`/`seo_description` settings â†’ admin UI (Phase 2) |

### 10.2 Rollout plan after approval

**Phase A â€” on-page copy (1â€“2 days):** Home hero + intro + sections; Send/Errand/Track/Partners/Pricing/Contact copy; FAQ replacement; Signup/login/legal meta.
**Phase B â€” technical SEO (1 day):** titles/metas for all pages; canonicals; OG/Twitter; `noindex` on private pages; schema; `sitemap.xml`; `robots.txt`; OG image.
**Phase C â€” content expansion (ongoing):** service pillar, help centre, location pages, blog, about/partner pages.

Each phase ends with: HTML validation, Lighthouse (SEO + performance), rich-results test, screenshot review, and a short before/after report.

### 10.3 QA checklist per page

- [ ] One H1 containing the target phrase; logical H2s
- [ ] Title â‰¤60 chars, unique; meta 140â€“158 chars, unique
- [ ] Canonical + OG + Twitter present
- [ ] Page in sitemap (or `noindex` if private)
- [ ] Internal links to send/track/support as relevant
- [ ] Alt text present and descriptive
- [ ] Schema validates (Rich Results Test)
- [ ] Mobile + desktop visual check
- [ ] No fabricated claims, testimonials or ratings

### 10.4 Measurement

- **Tools:** Google Search Console (verify domain, submit sitemap), Bing Webmaster, GA4.
- **GA4 events:** `send_start`, `send_registered`, `track_search`, `signup_start`, `signup_complete`, `contact_submit`, `claim_open`.
- **KPIs:** impressions/clicks/CTR for â€œsend parcel Kenyaâ€, â€œparcel delivery Kenyaâ€, â€œtrack parcel Kenyaâ€, â€œcourier Nairobiâ€; signup and send conversions from organic; indexed page count; rich-result eligibility.
- **Cadence:** review GSC monthly; refresh FAQ/meta quarterly; publish 2 help articles/month after Phase C.

### 10.5 Compliance & accuracy guardrails

- No invented testimonials, review counts or â€œ#1â€ claims.
- Coverage numbers only after the DB test/duplicate cleanup.
- Delivery time wording stays conditional (partner/route dependent).
- Legal pages reviewed by counsel before promotion.
- Demo credentials hidden in production.

---

## 11. Decisions I need from you

1. **Home H1:** option A (`Send and track parcels across Kenya`), B (current slogan), or C?
2. **Coverage wording:** approve â€œ6 trusted courier partners Â· 70+ destinationsâ€ after I clean test/duplicate destination rows? (Or a different number.)
3. **Pricing page:** since the platform is in free mode â€” banner A (`free while onboarding`) or B (`plans being finalised`)?
4. **Courier partner recruitment:** does a partner programme exist? If yes, approve the â€œBecome a partnerâ€ block/page; if not, remove Q12/Â§7 partner page.
5. **Contact details for schema/footer:** is `hello@mzigo.online` the only public email? Do you have a support phone, address or social handles (`sameAs`)?
6. **Production domain and OG image:** confirm `https://mzigo.online` and whether you want me to design a 1200Ã—630 OG image.
7. **Per-page SEO edits in admin:** do you want SEO title/meta fields added to the CMS later, or keep them in the generator?
8. **Phase 2 priority:** which first â€” Nairobi/Mombasa location pages, help centre, or About page?
9. **Signup indexing:** keep `/signup` indexable, or noindex it?

Reply with â€œapprove Â§Xâ€ for each section (or edits), and I will implement **only** the approved parts, page by page, with before/after screenshots.
