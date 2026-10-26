# Runner (Freelancer) Flow — Review & Optimisation Proposal

**Status: recommendations only — nothing implemented in this pass.**
Prepared 26 October 2026. This reviews the runner journey after the P0 CTA rollout (home band, footer links, `/login?role=freelancer`, dashboard CTA row).

---

## 1. Verdict on the quoted gap

The original gap statement is now **partly outdated**:

| Original gap | Current state |
|---|---|
| No public path at all | **Fixed:** home “For freelancers” band, footer “For runners” group, and `/login?role=freelancer` deep link |
| Must guess the Freelancer tab | **Fixed:** role query pre-selects the freelancer tab |
| Dashboard has no primary action | **Partially fixed:** CTA row exists, but two of the three CTAs are broken loops and “Find errand jobs” scrolls to an empty state |
| No “earn with Mzigo” page | **Still missing** — “How it works” points at the sender-focused Help Centre |
| Must enter phone + PIN with no context | **Still weak** — no earnings explanation, no terms consent, no resend PIN, no onboarding |

**Core conclusion:** discovery and entry now exist, but the runner *product* behind the entry point is not built. The journey currently promises **jobs, earnings and availability** while the backend only supports phone + PIN auth. The highest-risk items are **broken CTAs** and **missing job/earnings data**, not the lack of a landing page.

---

## 2. Current flow, step by step (as built today)

1. **Discover** — Home audience band “Join as a runner” → `/login?role=freelancer`; footer “For runners” links to the same place. No dedicated runner page; “How it works” opens the sender Help Centre.
2. **Join** — Login page, freelancer tab selected. Enter phone → Continue → a PIN is issued → enter PIN → `/freelancer`. New accounts are named automatically (“Freelancer 1234”).
3. **Dashboard** — “My work”: avatar, phone, static PIN reset, **static** stats (`Earnings this month KES 0`, `Assigned jobs 0`, `Completed jobs 0`), CTA row:
   - **Find errand jobs** → `#freelancer-jobs` (empty state)
   - **Update profile** → `/profile` → immediately redirects back to `/freelancer` (**loop**)
   - **Help & guides** → sender Help Centre
   - Empty state repeats **Update availability** → `/profile` (**same loop**)
4. **Work** — No job feed, accept/decline, job detail, availability toggle, notifications, KYC, or proof-of-delivery.
5. **Get paid** — No earnings ledger, payout balance/history, payout number, or payout schedule; the “Active” badge is cosmetic.

**Evidence:** `initFreelancerPage` in `assets/js/site.js`; `/profile` redirects freelancers at `initProfile`; only `freelancer-start` / `freelancer-login` actions exist in `api/auth.php`; no runner jobs/earnings/payout endpoints or tables.

---

## 3. What "optimised" should mean

A runner should be able to answer these six questions before and after signing in:

1. **What will I do and earn?** → dedicated earn page with honest, non-fabricated pay model
2. **What do you need from me?** → phone, name, availability, areas, payout number, ID (where required)
3. **Am I approved / active?** → clear account status
4. **Is there work right now?** → availability toggle + live job feed
5. **What exactly is this job?** → fee, pickup/drop-off, distance, time, accept/decline
6. **When and how do I get paid?** → earnings ledger, pending vs paid, M-Pesa payout history

---

## 4. Recommended target journey (5 stages)

### Stage A — Discover: dedicated `/earn` page (public, SEO)

Create a public **“Earn with Mzigo as a runner”** page and point all runner CTAs to it.

**Page structure**
- Hero: *“Earn money running errands around you”* — subline: choose when you’re available, see the fee before accepting, get paid for completed jobs by M-Pesa.
- **How it works (4 steps):** join with your phone → set your areas and availability → accept jobs that fit → complete and get paid.
- **What you need:** smartphone, phone number, ID for verification, M-Pesa number for payouts; own transport optional (state which vehicle types are supported).
- **Earning transparency:** “Every job shows its fee before you accept. There are no joining fees. Payout schedule: weekly / per completed job subject to terms.” *(Exact model to be approved.)*
- **Where runners are needed:** Nairobi, Mombasa, Nakuru, Thika, Nyeri… (only cities actually supported).
- **Runner FAQ:** getting started, jobs, pay, availability, safety, support.
- **CTA:** **Join as a runner** (filled) · **I already run with Mzigo — sign in** (text).
- Bottom cross-links: send/track/partner; footer runner links.

Also add a **runner guide** to the Help Centre (`/help/how-mzigo-runners-get-paid` or similar) and make “How it works” link to `/earn#how-it-works`.

### Stage B — Join: make it feel like signing up, not logging in

Replace the bare “enter phone → PIN” with a runner-specific join screen (either `/runner/join` or a strengthened freelancer login panel):

- Headline: *“Join Mzigo as a runner”*; microcopy: “Phone number only · no email needed · see fees before accepting”.
- Phone field + **consent checkbox** (“I agree to the Runner Terms and Privacy Policy”).
- Continue → PIN screen with **Resend PIN**, **change number**, and “PIN sent by SMS” expectation.
- After first verification → **onboarding**, not straight to a zeroed dashboard.
- If a logged-in freelancer opens `/login` or `/signup`, redirect them to their dashboard.

### Stage C — Onboard (first login only)

A short, resumable wizard with a progress indicator:

1. **Your details** — name, profile photo (optional), preferred language.
2. **Where you work** — city/areas/routes you can serve.
3. **Availability** — days and time windows; “available now” default off.
4. **Vehicle & capacity** — walker / boda / car / van; max parcel size.
5. **Payouts** — M-Pesa number (prefill phone), confirm name.
6. **Verification** — ID number/upload if required; terms acceptance.
7. **Done** — “You’re ready for jobs. Turn on availability.”

Do not show a job feed until required steps are complete; show “1 of 3 steps to get your first job” checklist instead.

### Stage D — Work: the dashboard should be a job console

Top-to-bottom layout:
- **Account status** strip: Approved / Pending review / Paused, with reason + support link (remove the unconditional “Active” badge).
- **Availability toggle** — “Available for jobs” (primary switch) + next available window.
- **Primary job card:**
  - No job: “No jobs right now · Update availability · Get notified” with refresh timestamp.
  - Assigned job: pickup, drop-off, fee **before acceptance**, distance/time, **Accept / Decline** (decline with reason), contact/notes.
  - Active job: status stepper (Heading to pickup → Picked up → Delivered), proof (photo/OTP/signature as designed), support button.
- **Job list** tabs: Available · Assigned · Completed.
- **Earnings summary card:** this week, pending, last payout + “View earnings”.
- **Profile completeness/onboarding nudge** when incomplete.
- **Support and safety**: Report an issue, Help for runners.

### Stage E — Earnings: transparent ledger

Dedicated earnings section/page:
- Balance summary: **Available to pay out**, **Pending**, **Paid to date**.
- Payout method: M-Pesa number, verified name.
- Per-job table: job ID, date, route, fee, status, payout reference.
- Payout history + schedule (e.g. weekly, or per-job instant — needs approval).
- Statement download (optional).
- Clear note that **parcel insurance applies to parcels, not runner earnings**.

### Stage F — Support & terms

- Runner-specific FAQ/help guide.
- Runner Terms (independent contractor status, fees, cancellation, safety, conduct, no employment relationship) — required before going live.
- Incident/safety reporting and support contact in dashboard.

---

## 5. Fix broken/weak CTAs before anything else (quick wins)

| Current | Problem | Recommended |
|---|---|---|
| **Update profile** (`/profile`) | `/profile` redirects freelancers back to `/freelancer` — infinite loop | Create `/runner/profile` (or `/freelancer/profile`) with runner details; until built, change label to “Edit runner details” and point to a real page |
| **Update availability** (`/profile`) | Same loop | Same page, with availability fields; or a modal |
| **Find errand jobs** (`#freelancer-jobs`) | Scrolls to a hard-coded “No jobs” block | After job feed ships: “View available jobs” with count badge. Before that: “Set availability” or “Get job alerts” |
| Header primary CTA for runners | Still says **Send a parcel** | Swap to **My jobs** (or **Go available**) for the freelancer role |
| Mobile bottom nav for runners | Generic Home/Send/Track/Profile | Role-aware: **Jobs · Earnings · Track · Profile** (guest: Home · Earn · Track · Help) |
| Home runner CTA “How it works” | Sender Help Centre | Dedicated `/earn#how-it-works` + runner guide |
| “Active” badge | Always shown | Reflect real `status` (pending/active/paused) from DB |
| Earnings/job stats | Hard-coded `0` | Live from earnings/jobs APIs, or label “No earnings yet” empty states |
| Login phone step | No consent, no resend, no “what happens next” | Add consent, resend/change number, expectation copy |

---

## 6. Backend/product prerequisites

The flow cannot be completed with front-end work alone. Minimum backend scope:

| Capability | Needed for | Notes |
|---|---|---|
| Runner profile & onboarding fields | Stage B/C | Name, areas, availability, vehicle, payout number, terms accepted/version |
| Job model & feed API | Stage D | Job status, fee, route, acceptance, decline reasons |
| Assignment & job status transitions | Stage D | Accept → picked up → delivered; proof/OTP |
| Earnings ledger | Stage E | Per-job fee, platform deduction, status, payout reference |
| Payouts / M-Pesa integration | Stage E | Balance, payout request/schedule, reconciliation |
| Availability + notifications | Stage D | In-app + SMS/WhatsApp/push opt-in |
| Verification/KYC (as required) | Stage C | ID check, approval status |
| Runner terms acceptance | Stage B | Versioned acceptance record |

**Product decisions required first:** Are jobs **self-serve (feed/accept)** or **dispatched by operations**? What is the **fee/payout model** and schedule? Which **cities/vehicle types** are supported at launch? Is ID verification required before first job? Are runners independent contractors? These change the UI materially.

---

## 7. Phased plan

### Phase 1 — fix the promise without new backend (fast)
1. Build `/earn` page + runner help guide; repoint all “how it works” links.
2. Remove the `/profile` loop: create a minimal `/runner/profile` page (name, areas, availability static, M-Pesa number, terms) using existing user/settings data where possible; otherwise add a runner settings subset.
3. Header primary CTA becomes role-aware (**My jobs** for freelancers).
4. Role-aware mobile bottom nav for runners.
5. Replace hard-coded earnings with honest empty/locked states; fix “Active” badge to real status.
6. Strengthen join screen (consent, resend, change number, promise-aligned copy).
7. Add runner Terms + link in join/dashboard.

### Phase 2 — job console (backend)
8. Runner profile/onboarding API + availability.
9. Job feed/accept/decline + job status flow + proof.
10. Notifications (in-app, SMS/WhatsApp).
11. Real dashboard (status strip, availability toggle, job cards, completed history).

### Phase 3 — earnings & payouts
12. Earnings ledger + payout method/history.
13. Payout schedule/M-Pesa integration + statements.
14. Ratings/incentives/analytics.

---

## 8. Measurement

Events: `earn_page_view`, `runner_join_start`, `runner_phone_submitted`, `runner_pin_verified`, `runner_onboarding_complete`, `availability_on`, `job_view`, `job_accepted`, `job_declined`, `job_completed`, `payout_requested`.
Funnels: `/earn` → join → PIN verified → onboarding complete → first job accepted → first payout.
Success signals: % of new runners completing onboarding, time to first job, job acceptance rate, first-payout completion, runner support contacts.

---

## 9. Approval checklist

Reply with the phases/items you want. Nothing will be implemented until approved.

- [ ] Phase 1 quick wins (broken CTA fixes, `/earn`, runner help, role-aware header/bottom nav, honest empty states, join consent/resend)
- [ ] Phase 2 job console
- [ ] Phase 3 earnings/payouts
- [ ] Job model: **self-serve feed** or **operations-dispatched** (or hybrid)?
- [ ] Payout model and schedule (per job / weekly; deductions)
- [ ] Launch cities + vehicle types
- [ ] ID verification required before first job?
- [ ] Runner classification/terms (independent contractor) approved by counsel?
- [ ] Earnings copy in `/earn`: what can we safely promise (“fee shown before accepting”, payout schedule) without inventing rates?

Once approved I’ll implement Phase 1 first, with screenshots and mobile checks, then hand over the Phase 2 API contract.

---

## 13. Implementation status — runner flow fixes (26 October 2026)

Shipped in this pass:

- **`/earn` runner landing page** with how it works, requirements, honest pay explanation, runner FAQ, terms link and join CTAs; added to sitemap, header nav (“Earn”) and all “How it works” links (home band, footer, login).
- **Broken CTA loops fixed:** dashboard now targets the on-page `#runner-profile` section; no `/profile` redirect loop.
- **“Find errand jobs” removed:** replaced with an honest jobs panel (0 available, refresh, explanation) and availability controls; no fake feed.
- **Onboarding:** first login shows “Get ready for jobs” with outstanding steps; profile form captures name, areas, vehicle, availability days/window, M-Pesa payout number, optional ID and terms acceptance. DB table `freelancer_profiles` + `api/freelancer.php` (GET snapshot, `save-profile`, `set-availability`).
- **Availability toggle** on the dashboard; unavailable until onboarding is complete.
- **Earnings explanation** on `/earn`, login panel and dashboard (fee shown before accepting, no joining fee, payouts by M-Pesa; honest KES 0 empty state).
- **Login:** terms consent required before joining; **Resend PIN** button; clearer “how runners earn” copy.
- **Role-aware header CTA:** freelancers see “My jobs”, customers “My parcels”; guest nav adds “Earn”.

Still pending (Phase 2/3): real job feed/accept/status flow, notifications, earnings ledger/payout history, runner-specific terms page, KYC verification.

### Phase 2 — runner job console (shipped 26 October 2026)

- **Jobs are created automatically from errand bookings** (`api/_runner_jobs.php`, hooked into `api/errands.php`) with pickup/drop-off, runner fee (base fee), customer fee, description/instructions and contact details.
- **Job feed** on `/freelancer`: available errands, active jobs, completed history; refresh button.
- **Accept / decline** with atomic accept (`WHERE status='available'`), stale-job guard (`job_taken`, 409) and decline memory so a declined job does not reappear for that runner.
- **Job lifecycle**: Confirm pickup → Mark delivered with a required delivery note/proof. Completed jobs add their fee to earnings.
- **Availability gate:** accepting requires a completed profile and “Available” switched on (UI disabled + API 422 when offline).
- **Earnings & notifications:** dashboard stats derive from delivered jobs; in-app runner notifications for profile complete, job accepted, pickup and delivery, with “Mark all read”.
- **Data:** migration `022_runner_jobs.sql` adds `runner_jobs`, `runner_job_events`, `runner_job_declines`, `runner_notifications`.
- **Verified:** errand → available job → accept → pickup → proof → delivered; earnings KES 250, completed count 1, 4 notifications, double-accept blocked, offline accept blocked, no horizontal overflow at 390px/1440px.

Still pending for later phases: payout ledger/history and M-Pesa disbursement, SMS/WhatsApp runner notifications, area/city-based job matching, ops dispatch console, photo/OTP proof of delivery, sender/customer notifications on assignment.
