# Mzigo.online — UI & Customer-Centric CTA Review

**Status: the Top 8 fixes below are implemented (26 October 2026).** P1/P2 items remain proposals.
Prepared 26 October 2026. Evidence: live CTA inventory across all public pages + merchant, freelancer and customer dashboards (`_raw/saas-proof/cta-audit/cta-inventory.json`).

---

## 1. Executive summary (what I'd change first)

The site has a strong visual system, a clear send/track hero and a genuinely differentiated insurance story. The main weakness is **audience navigation**: the experience is built primarily for merchants, while freelancers and receiving customers have almost no acquisition path, and the public header offers no navigation at all.

**Top 8 fixes, in order:**

1. **Give the public header real navigation + a task-based primary CTA.**
   Today guests see only `CALL US TOLL FREE`, `Sign in`, `Start free trial` — no Send, Track, Partners or Help. Add nav links and make **“Send a parcel”** the filled CTA, with `Start free trial` as an outlined business CTA.
2. **Add a 3-audience CTA band on the home page** (merchants / freelancers / receivers) directly after the hero area. Freelancers currently have **zero** public calls-to-action.
3. **Add a receiver CTA on the Track page** — “Sign in as a customer to see all your parcels and pay at collection” — the page currently dead-ends after the result (only claim/contact).
4. **Make Signup and Login cross-role.** Add “Receiving a parcel? Track it” and “Want to earn? Join as a runner” beneath signup; add helper CTAs inside the freelancer/customer login panels.
5. **Turn the home audience cards into action cards** (“For individuals / e-commerce / teams” currently end with text and no button).
6. **Give the freelancer dashboard a primary action.** It currently has only “Reset PIN” + a Track tab — no way to find work, update availability or see earnings from the dashboard hero.
7. **Add support + payment reinforcement on the customer dashboard** (“Pay with M-Pesa” exists; “Contact support” is missing next to it, and there is no sticky pay CTA).
8. **Make the footer audience-aware** — add “For merchants / For runners / For receivers” link groups so each visitor can self-select from any page.

---

## 2. What already works (keep it)

- Clear, benefit-led hero with **Send** and **Track** as the two primary tasks.
- Consistent green/white design system; primary vs outline buttons are visually distinct.
- Insurance is now a strong differentiator with the Kenyan-first claim repeated sensibly.
- Merchant dashboard has a useful quick-action row (Send a parcel · View orders · Book an errand · Billing).
- Freelancer and customer logins exist behind tabs, and the customer dashboard leads with **Pay with M-Pesa**.
- Help Centre, FAQs, location pages and partner programme give good SEO/low-intent paths.
- Mobile has a persistent bottom nav (Home · Send · Track · Profile).

---

## 3. Audit snapshot

| Page | Current CTA count | Main gaps |
|---|---|---|
| `/` | ~51 links/buttons (many repeated) | No freelancer CTA; audience cards are dead ends; header nav missing; many competing outline buttons |
| `/send-mzigo` | 6 | No merchant signup context for guests; no help/insurance link near form |
| `/track-mzigo` | 5 | No receiver sign-in / customer dashboard CTA; no “send a parcel” cross-sell |
| `/partners` | 12 | Good partner CTAs; no sender CTA at end |
| `/pricing` | 5 | Merchant-only; no “not a business?” switch |
| `/signup` | 5 | Merchant-only; no receiver/freelancer escape hatch |
| `/login` | 9 | Role tabs exist but panels lack audience-specific helper CTAs |
| `/about` | 9 | Good; no merchant signup CTA |
| `/help` | 14 | Good; no audience segmentation |
| `/partners/become` | 7 | Good courier CTAs |
| Merchant dashboard | 24 | Strong; missing invite team / upgrade / API CTAs in context |
| **Freelancer dashboard** | **5** | **No primary CTA** — only Reset PIN + Track tab |
| **Customer dashboard** | **10** | Pay CTA exists; **no Contact support CTA**; no sticky pay |

**Header inventory today (guest):** toll-free link, Sign in, Start free trial. **No desktop nav links.**

---

## 4. Audience journeys — where the leaks are

### Merchant (workspace owner / e-commerce seller)
Needs: send a parcel, save senders, track orders, manage team, understand pricing, pay/upgrade.
Current path: Home → Send/Trial → Signup → Dashboard → Orders. **Works, but** the header and home page carry most of the load; no contextual “create workspace” CTA inside the send flow, and no “invite team / upgrade / API” CTAs where those features are used.

### Freelancer (runner)
Needs: understand earnings → join with a phone number → find/accept jobs → get paid.
Current path: **none publicly.** They must discover `/login`, guess the Freelancer tab, enter phone + PIN. There is no “earn with Mzigo” page, no home CTA, no footer link, and once signed in, the freelancer dashboard has no primary action.

### Customer (receiver)
Needs: know a parcel is coming → track it → pay at collection → keep the collection code → get support if something is wrong.
Current path: Track (good, no login required) → result. **Leak:** no invitation to sign in as a customer to see all parcels/pay; the only post-track actions are claim/contact. Dashboard pay CTA exists but support is missing next to it.

---

## 5. CTA system rules (to apply everywhere)

**Hierarchy**
- **Primary (filled `mz-btn`)** — one per viewport/section; the main task for that audience.
- **Secondary (`mz-btn mz-btn--outline`)** — alternative task or low-commitment path.
- **Tertiary (`mz-link`)** — contextual help/segmentation, never styled like a button.
- Minimum touch target 44–48px; labels start with a verb (“Send…”, “Track…”, “Join…”, “Pay…”).
- Keep **one dominant action per band**; current home sections sometimes show 3–4 equal-weight outline buttons in a row.

**Naming by audience**
- Merchant: *Send a parcel · Create workspace · Start free trial · View plans · Invite your team*
- Freelancer: *Join as a runner · Find errand jobs · Update availability · View earnings*
- Customer: *Track a parcel · Sign in as a customer · Pay with M-Pesa · Contact support · Open a claim*

**Microcopy that reduces friction**
- Merchant: “14-day Starter trial · no card required”
- Freelancer: “Sign up with your phone — no email needed”
- Customer: “No account needed to track · pay at collection”

---

## 6. Recommendation matrix (proposed placements)

Priority: **P0** = do first, **P1** = next, **P2** = polish.

### Global / header / footer

| # | Placement | Audience | Proposed CTA | Destination | Style | Pri |
|---|---|---|---|---|---|---|
| G1 | Desktop header, after logo | All | **Send a parcel** | `/send-mzigo` | Filled primary | P0 |
| G2 | Desktop header nav | All | **Track · Partners · Help** | `/track-mzigo`, `/partners`, `/help` | Text nav links | P0 |
| G3 | Header utility (keep) | Merchant | **Start free trial** | `/signup` | Outline | P0 |
| G4 | Header utility (keep) | All | Sign in | `/login` | Text | P0 |
| G5 | Mobile bottom nav | Role-aware | Guest: Home · Send · Track · Help | — | Icon tabs | P2 |
| G6 | Footer new group “For merchants” | Merchant | Start free trial · Pricing · API access | `/signup`, `/pricing`, `/contact` | Links | P1 |
| G7 | Footer new group “For runners” | Freelancer | Join as a runner · How errands work · Freelancer sign in | `/login?role=freelancer`, `/help`, `/login?role=freelancer` | Links | P0 |
| G8 | Footer new group “For receivers” | Customer | Track a parcel · Pay on collection guide · Help Centre | `/track-mzigo`, `/help/mpesa-pay-on-collection`, `/help` | Links | P0 |

### Home `/`

| # | Placement | Audience | Proposed CTA | Destination | Style | Pri |
|---|---|---|---|---|---|---|
| H1 | Hero | All | **Send a parcel** + **Track a parcel** (keep) | Send/Track | Filled + outline | — |
| H2 | New 3-audience band after hero/send card | All | see §7A | — | 3 cards, one CTA each | **P0** |
| H3 | “For individuals” card | Customer/individual | **Send a parcel** | `/send-mzigo` | Outline | P1 |
| H4 | “For e-commerce sellers” card | Merchant | **Start free trial** | `/signup` | Outline | P1 |
| H5 | “For teams” card | Merchant | **Explore workspaces** | `/signup` | Outline | P1 |
| H6 | Final CTA band | All | **Start free trial** (filled) + **Track a parcel** + **Earn as a runner** | `/signup`, `/track-mzigo`, `/login?role=freelancer` | 1 filled + 2 text/outline | P1 |
| H7 | Partners section | Partner/courier | Keep Browse partners + Become a partner | — | — | — |

### Send `/send-mzigo`

| # | Placement | Audience | Proposed CTA | Destination | Style | Pri |
|---|---|---|---|---|---|---|
| S1 | Above wizard (guest) | Merchant | **Save senders next time — Create a workspace** | `/signup` | Outline + microcopy | P1 |
| S2 | Step 1 helper | All | **Need help choosing a courier?** | `/help` | Text link | P2 |
| S3 | Insurance copy block | All | **How parcel insurance works** | `/about` | Text link | P2 |

### Track `/track-mzigo`

| # | Placement | Audience | Proposed CTA | Destination | Style | Pri |
|---|---|---|---|---|---|---|
| T1 | Immediately below search/results | Customer | **Sign in as a customer** to see all incoming parcels and pay at collection | `/login?role=customer` | Filled | **P0** |
| T2 | Same block (secondary) | Customer | **Pay on collection guide** | `/help/mpesa-pay-on-collection` | Text link | P1 |
| T3 | End of SEO copy | All | **Send a parcel with Mzigo** | `/send-mzigo` | Outline | P1 |

### Pricing `/pricing`

| # | Placement | Audience | Proposed CTA | Destination | Style | Pri |
|---|---|---|---|---|---|---|
| P1 | Per plan | Merchant | **Start free trial** (exists in paid mode) | `/signup` | Filled | — |
| P2 | Under plans | Customer/Freelancer | “Not shipping as a business? **Track a parcel** or **join as a runner**” | `/track-mzigo`, `/login?role=freelancer` | Text links | P2 |

### Signup `/signup`

| # | Placement | Audience | Proposed CTA | Destination | Style | Pri |
|---|---|---|---|---|---|---|
| SU1 | Under form | Customer | **Receiving a parcel? Track it** | `/track-mzigo` | Text link | **P0** |
| SU2 | Under form | Freelancer | **Want to earn running errands? Join as a runner** | `/login?role=freelancer` | Text link | **P0** |
| SU3 | Under form | Courier business | **Run a courier business? Apply as a partner** | `/partners/become` | Text link | P2 |

### Login `/login`

| # | Placement | Audience | Proposed CTA | Destination | Style | Pri |
|---|---|---|---|---|---|---|
| L1 | Business panel (exists) | Merchant | Start a 14-day free trial | `/signup` | Text | — |
| L2 | Freelancer panel step 1 | Freelancer | **Continue — new runners get an account automatically** (label + microcopy) | — | Filled | **P0** |
| L3 | Freelancer panel footer | Freelancer | **What runners do / earn** | `/help` or `/faq` | Text | P2 |
| L4 | Customer panel | Customer | **Track without signing in** | `/track-mzigo` | Text | P1 |
| L5 | Deep links | All | Pre-select tab from `?role=freelancer/customer/business` | — | — | P0 |

### Dashboards

| # | Page | Placement | Proposed CTA | Destination | Style | Pri |
|---|---|---|---|---|---|---|
| D1 | Freelancer `/freelancer` | Hero/empty state | **Find errand jobs** (primary) | jobs section/anchor | Filled | **P0** |
| D2 | Freelancer | Hero secondary | **Update availability/profile** | `/profile` | Outline | P0 |
| D3 | Freelancer | Utility row | **View earnings** (when data exists) · **Help** | — / `/help` | Outline/text | P1 |
| D4 | Customer `/customer` | Beside unpaid parcel | **Pay with M-Pesa** (keep) + **Need help with this parcel?** | `/contact` | Filled + text | **P0** |
| D5 | Customer | Sticky while unpaid | **Pay now** | in-page pay action | Filled (sticky) | P1 |
| D6 | Merchant `/profile` | Usage/team cards | **Invite your team** · **Upgrade plan** · **Create API key** | `/settings`, `/billing`, `/settings` | Outline | P1 |
| D7 | Merchant | Empty states | **Send your first parcel** / **Create a saved sender** | `/send-mzigo`, `/profile` | Filled | P2 |

---

## 7. Detailed new blocks to approve

### 7A. Home — 3-audience CTA band (new, after hero/send card)

A three-card row, each card = one audience + one primary CTA:

| Card | Heading | Copy | CTA |
|---|---|---|---|
| Merchant | **Ship your orders with one workspace** | Save senders, invite your team, connect your store and track every parcel from one dashboard. | **Start free trial** (filled) · *See plans* (text) |
| Freelancer | **Earn money running errands** | Pick up local errands, track your jobs and get paid — sign up with just your phone number. | **Join as a runner** (filled) · *How it works* (text) |
| Customer | **Receiving a parcel?** | Follow every scan, pay the delivery fee by M-Pesa at collection and keep your collection code. | **Track a parcel** (filled) · *Pay on collection guide* (text) |

Placement: directly after the send-form card, before the “Send parcels across Kenya with Mzigo.online” SEO intro. This fills the current freelancer gap without disrupting the hero.

### 7B. Header (guest)

```
[logo]  [Send a parcel ▸filled]  Track   Partners   Help        CALL US TOLL FREE  [Sign in]  [Start free trial ▸outline]
```
- One filled button only: **Send a parcel** (highest-frequency universal task).
- `Start free trial` becomes outline — merchant acquisition still visible, but no longer the only primary action on every page.
- Mobile: keep bottom nav; role-aware after login (`Home · Send · Orders · Account` for merchants, `Jobs · My work · Profile · Help` for freelancers, `My parcels · Pay · Track · Help` for customers).

### 7C. Track — receiver CTA block (new)

Below the search/results area:

> **Receiving a parcel?**
> Sign in with the phone number on the parcel to see all your incoming parcels and pay the delivery fee at collection.
> **[Sign in as a customer]** *(filled)*  **Pay on collection guide** *(text)*  **Track another parcel** *(text)*

### 7D. Signup — cross-role escape hatches

Under the form, a single quiet line:
`Receiving a parcel? Track it · Want to earn? Join as a runner · Run a courier business? Apply as a partner`
(with each as a link; no competing buttons).

### 7E. Freelancer dashboard hero (new CTA row)

> **Ready for your next errand?**
> Jobs appear here when senders book an errand on a route you serve.
> **[Find errand jobs]** *(filled)*  **[Update availability]** *(outline)*  **Track a parcel** · **Help**

Also add an explicit empty state (“No jobs right now — update availability to get matched sooner”).

### 7F. Customer dashboard payment block

Keep **Pay KES x with M-Pesa** as the filled CTA. Add directly beneath:
`Parcel not as expected? **Contact support** · **Open a claim**`
and make the pay button sticky on mobile while the parcel is unpaid.

### 7G. Footer role groups

Add three compact link groups beneath the existing columns:

- **For merchants:** Start free trial · Pricing · API access
- **For runners:** Join as a runner · How errands work · Freelancer sign in
- **For receivers:** Track a parcel · Pay on collection · Help centre

---

## 8. Copy bank (pre-approved phrasing)

| Audience | Primary | Secondary | Tertiary |
|---|---|---|---|
| Merchant | Start free trial · Create workspace · Send a parcel | See plans · Invite your team · Connect your store | Talk to us |
| Freelancer | Join as a runner · Find errand jobs | Update availability · View earnings | How errands work |
| Customer | Track a parcel · Pay with M-Pesa · Sign in as a customer | Pay on collection guide · Contact support | Track without signing in |

Microcopy standards:
- Merchant: “14-day Starter trial · no card required”
- Freelancer: “Phone number only — no email needed”
- Customer: “No account needed to track”

---

## 9. Phased rollout

### Phase P0 — fix the leaks (recommended to implement first)
- Header nav + primary “Send a parcel” CTA (G1–G4)
- Home 3-audience band (H2)
- Track receiver CTA block (T1) + role deep links (L5)
- Signup cross-role links (SU1–SU2)
- Freelancer dashboard primary CTA + empty state (D1–D2)
- Customer dashboard support CTA next to pay (D4)
- Footer role groups (G7–G8)

**Effort: medium (copy + CSS + small JS for role query params).** Impact: high.

### Phase P1 — strengthen each path
- Audience buttons on existing home cards (H3–H5), final-CTA split (H6)
- Send page guest workspace CTA (S1), Track cross-sell (T2–T3)
- Login panel helper CTAs (L4), freelancer utility CTAs (D3), customer sticky pay (D5)
- Merchant dashboard contextual CTAs (D6)
- Footer merchant group (G6)

### Phase P2 — polish & personalise
- Role-aware mobile bottom nav (G5)
- Pricing audience switch (P2), courier-partner signup link (SU3)
- Merchant empty-state CTAs (D7), send-page help/insurance links (S2–S3)

---

## 10. Measurement

- **Events:** `cta_click` (with `location`, `audience`, `label`), plus existing conversions `signup_start`, `send_registered`, `track_search`, `customer_pay`, `freelancer_login`.
- **Funnels to watch:** Home → audience band → signup; Track → customer sign-in; Freelancer login → first job; Customer dashboard → pay.
- **Success signals:** fewer dead-end sessions on `/track-mzigo` and `/login`; increase in freelancer sign-ups; higher customer pay completion; lower bounce on home mobile.
- **Experiment:** A/B header primary CTA (“Send a parcel” vs “Start free trial”) before locking it in.

---

## 11. Approval checklist

Reply with the phases/IDs you want. Nothing will be implemented until approved.

- [ ] **P0** as listed in §9 (recommended)
- [ ] **P1** as listed
- [ ] **P2** as listed
- [ ] Header primary CTA: **“Send a parcel”** (recommended) or keep **“Start free trial”**
- [ ] Freelancer CTA language: **“Join as a runner”** / “Become a freelancer” / “Earn with Mzigo”
- [ ] Customer CTA language: **“Sign in as a customer”** (recommended) or “View my parcels”
- [ ] Home audience band heading/copy (§7A) approved as written?
- [ ] Role query links (`/login?role=customer|freelancer`) approved?
- [ ] Footer role groups approved?
- [ ] Prefer implementation page-by-page with screenshots, or one release?

Once approved I’ll implement in that order with before/after screenshots and mobile checks.


---

## 12. Implementation status — Top 8 (shipped 26 October 2026)

| # | Fix | Status | Where |
|---|---|---|---|
| 1 | Public header nav + task primary CTA (`Send a parcel` filled, `Start free trial` outlined, `Track · Partners · Help` nav) | ✅ shipped | header patch, guest `appLinks`, responsive header CSS |
| 2 | Home 3-audience CTA band (merchants / freelancers / receivers) | ✅ shipped | home `AUDIENCE_BAND` after the send card |
| 3 | Track receiver CTA (sign in as customer / pay guide) | ✅ shipped | `TRACK_SEO` receiver block |
| 4 | Signup + login cross-role CTAs and `/login?role=` deep links | ✅ shipped | signup template, login helpers, login JS |
| 5 | Home audience cards became action cards | ✅ shipped | “For individuals / e-commerce / teams” card CTAs |
| 6 | Freelancer dashboard primary actions + empty state | ✅ shipped | `initFreelancerPage` |
| 7 | Customer dashboard support links + sticky mobile pay bar | ✅ shipped | `customerParcelCard`, `renderCustomer`, paybar CSS |
| 8 | Footer audience groups (merchants / runners / receivers) | ✅ shipped | `FOOTER_AUDIENCE` via `patch_footer` |

**Verified:** no horizontal overflow at 1440/1280/1024/768/390; header has no nav/search overlap; guest nav links and role deep links resolve; freelancer/customer dashboards render the new CTAs; CLS remains low (home 0.0026, merchant screens ≤0.0074); JSON-LD unchanged and valid.

**Remaining (P1/P2):** send-page guest workspace CTA, pricing audience switch, role-aware mobile bottom nav, merchant dashboard contextual CTAs (invite team / upgrade / API), login secondary helpers, richer empty states.

---

## 14. CTA system + P1/P2 status (shipped 26 October 2026)

Completed in this pass:

- **CTA rules applied:** one filled primary per section; outline secondary and text-link tertiary; verb-led labels; single dominant action per band; 44px minimum touch targets on phones; audience-specific labels and microcopy.
- **7A–7F verified live:** home 3-audience band, guest header with Send primary + nav, Track receiver CTA, cross-role signup/login, freelancer dashboard actions, customer payment + support + sticky pay bar.
- **Send page:** guest-only “Save senders and track every parcel” workspace CTA (hidden when signed in), with Create workspace + See plans.
- **Pricing audience switch:** Track a parcel · Join as a runner · Start a business workspace, visible in free and paid modes.
- **Role-aware mobile bottom nav:** guests Home/Send/Track/Earn; merchants Dashboard/Send/Orders/Account; runners Jobs/Earnings/Track/Profile; customers My parcels/Track/Pay/Support (correct active states).
- **Merchant dashboard contextual CTAs:** Invite your team, Upgrade plan, Connect your store (API), Help centre; plus richer empty states for recent parcels/errands.
- **Richer empty states:** orders (clear filters, send/book CTA), claims (view orders, how claims work), customer parcels (track/help), dashboard lists, freelancer jobs.
- **Login secondary helpers:** create workspace, track without signing in, need help signing in.
- **Receiver flow polish:** collection-code copy buttons on the customer dashboard and paid tracking results, “keep this code” guidance, post-track Contact support / Open claim / receiver sign-in actions.

Verified: all auth roles and mobile/desktop breakpoints checked for overflow; no JS errors; send CTA visible to guests only; pricing switch visible in free mode; mobile nav active state correct; customer copy/keep/support actions render; receiver tracking shows pay + sign-in + support/claim.
