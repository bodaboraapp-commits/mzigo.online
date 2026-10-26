# Mzigo.online — Runner Incentives & Referral Growth Proposal

**Status: proposal only — no implementation until approved.**
Goal: reward high-quality runners, turn runners/senders/receivers into a referral engine, and lower acquisition cost for freelancers and customers.

---

## 1. Design principles

1. **Pay for quality, not just volume.** Bonuses should be triggered by verified delivery + customer rating, never by accepting jobs.
2. **Small, transparent amounts.** Every bonus must show as a line item in the runner ledger and be explainable in one sentence.
3. **Two-sided referrals.** Reward both sides so sharing feels like a gift, not a sales pitch.
4. **WhatsApp-first virality.** Kenya runs on WhatsApp; every referral surface gets a share link + QR, plus SMS fallback.
5. **Budgeted and pausable.** Admin controls caps, campaign windows and per-user limits.
6. **Fraud-resistant.** Rewards only after qualifying activation (completed job / paid parcel), with identity and self-referral checks.

---

## 2. Runner incentives

### 2A. Quality tiers (rolling 90 days)

| Tier | Requirement | Fee bonus | Perks |
|---|---|---|---|
| **Bronze** | 0–19 completed jobs | 0% | Standard feed |
| **Silver** | 20+ jobs · rating ≥4.5 · completion ≥90% | **+3%** | Priority alerts, Silver badge |
| **Gold** | 50+ jobs · rating ≥4.7 · completion ≥95% | **+5%** | Priority feed, faster payout review, Gold badge |
| **Platinum** | 100+ jobs · rating ≥4.8 · completion ≥97% | **+8%** | First access to high-fee/peak jobs, Platinum badge, monthly bonus draw |

- Bonuses are calculated per delivered-and-rated job and capped at **KES 2,000/month** (configurable).
- Ratings should come from **customers after delivery** (SMS/WhatsApp link); admin can override with a note. Current admin-only rating is not enough at scale.
- Tier status is shown on the runner dashboard with progress to the next tier.

### 2B. Behaviour bonuses

| Bonus | Trigger | Suggested reward |
|---|---|---|
| **Welcome bonus** | First 3 jobs delivered with rating ≥4 | KES 100 |
| **Reliability streak** | 10 jobs in a week, no cancellations/no-shows, rating ≥4.5 | KES 100/week |
| **Peak/route boost** | Admin-funded: underserved area or time window | +KES 50/job |
| **Comeback bonus** | Return after 14+ days and complete 3 jobs | KES 100 |
| **Referral quality bonus** | Referee reaches Silver tier | KES 250 to referrer |

### 2C. Economics check

- Base runner fee in demo: **KES 250/errand**.
- Gold bonus at 5% = **KES 12.50/job**; a 100-job/month Gold runner costs ~KES 1,250 in bonus.
- Reliability bonus ~KES 400/month + welcome ~KES 100.
- Total quality-bonus budget is a small share of completed-job value and much cheaper than replacing churned runners.

### 2D. UX surfaces

- Runner dashboard tier card: **“Gold · 4.8★ · 5% bonus · 32 jobs to Platinum”** with progress bar.
- Earnings ledger rows: `Job RJ-… KES 250`, `Gold bonus KES 12.50`, `Reliability KES 100`.
- Monthly bonus summary: earned / capped / paid.
- Badge on profile and account; notification when tier changes.
- Admin config: tiers, rates, caps, rating source, campaign pause.

---

## 3. Referral programme

### 3A. Referral types & suggested rewards

| Programme | Referrer | Referee | Qualifying event |
|---|---|---|---|
| **Runner → Runner** | KES 500 after referee completes 5 jobs and is KYC-approved | KES 100 after first delivered job | Referee activates |
| **Business → Business** | KES 500 workspace bill credit after referee’s first paid parcel/invoice | 10% off first month (or 1 free month on annual) | Referee sends first parcel |
| **Customer/Receiver → Sender** | KES 100 parcel credit after referee’s first delivered parcel | KES 100 off first delivery fee + insurance credit | Referee’s parcel delivered |
| **Partner office QR** | KES 200 per activated runner | Runner welcome bonus | Runner completes 3 jobs |

All monetary rewards are demo values; admin config controls them.

### 3B. Creative virality mechanics

1. **Referral landing page** `/refer` or `/invite` with a personal code, WhatsApp share button, SMS share and QR poster.
2. **WhatsApp share card:** prewritten message + link; “Send a parcel with Mzigo” / “Earn as a runner with Mzigo”.
3. **Milestone ladder:** 3 friends = KES 300, 10 friends = +KES 1,000, 25 friends = city leaderboard badge.
4. **City launch race:** monthly leaderboard for Nairobi/Mombasa/Nakuru; top referrers get bonus + recognition.
5. **Insurance-first hook:** refer a sender and both get parcel-insurance credit — reinforces the Kenyan-first differentiator.
6. **QR posters for partner offices:** each partner point gets a tracked QR code so offline customers can join.
7. **Post-delivery share prompt:** after payment/collection, “Know someone sending? Share Mzigo and earn”.
8. **Referral code vanity option:** users can request a memorable code (e.g., MZIGO-JANE) after first activation.
9. **Team/workspace referral:** invite another business owner from the dashboard; reward paid as bill credit.

### 3C. Attribution & fraud rules

- Attribution window: **60 days** from click (cookie + server-side code).
- One code per account; cannot self-refer, same phone/device/IP cluster, or same KYC identity.
- Rewards only after a **qualifying event** (job completion / first delivered parcel / invoice paid).
- Clawback if the qualifying order is cancelled, refunded, charged back or deleted.
- Monthly caps: e.g. KES 25,000 platform-wide (demo) / KES 100,000 (scale); per-user cap KES 10,000/month.
- Admin can pause referrals per programme or globally.
- Update Runner Terms and add Referral Terms; tax/reporting handled per payout type.

### 3D. UX surfaces

- Home audience band + footer: “Refer a business / Refer a runner / Refer a sender” links.
- Runner dashboard: **“Earn with friends”** card with code, share buttons, clicks/signups/activated count and pending rewards.
- Customer dashboard: **“Invite a sender”** card with WhatsApp share and credit balance.
- Merchant dashboard: **“Refer a business”** card with bill-credit balance.
- Public `/refer` page with personal link, QR, earnings examples and referral terms.
- Admin: **Referrals** section with codes, clicks, signups, activated users, rewards paid/pending, fraud flags, campaign budget, pause toggles.

### 3E. Suggested data model

- `referral_codes` (user_id, code, type, created_at, active)
- `referral_clicks` (code, ip_hash, user_agent, created_at)
- `referrals` (referrer_user_id, referee_user_id, type, code, status, attributed_at, qualified_at)
- `referral_rewards` (referral_id, beneficiary, amount, kind, status, paid_at, note)
- `runner_bonus_ledger` (runner_id, job_id, rule_code, amount, status, created_at)
- `incentive_rules` or platform_settings JSON (rates, caps, active windows)
- `runner_ratings` (customer rating, comment, job_id, source, created_at) — currently admin rating only

---

## 4. Marketing & acquisition loop

```
Runner/customer sees “Invite & earn” → WhatsApp share → friend signs up
   → first parcel/job completed → both rewarded → share again
```

- Trigger sharing at the happiest moments: after successful delivery, after first payout, after a 5★ rating.
- Referral leaderboard + badges for social proof; show “X runners joined this month”.
- A/B test reward copy and WhatsApp card text.
- Keep referral landing pages `noindex` initially to avoid thin-content SEO issues; use `/refer` noindex, while `/earn` and location/blog pages remain indexed.

---

## 5. Phased implementation (after approval)

### Phase 1 — Earnings & ratings foundation (1–1.5 weeks)
- Customer rating capture after delivery (SMS/WhatsApp link + dashboard prompt); admin override.
- Bonus rules + `runner_bonus_ledger`; tiers and progress UI.
- Reliability/welcome bonuses.
- Admin config for rates/caps/pause.
- Bonus included in pending payout and payout history.

### Phase 2 — Referral engine (1–1.5 weeks)
- Referral codes, clicks, attribution, qualification, rewards; fraud checks.
- Runner/customer/merchant referral cards, share links, QR.
- `/refer` landing page + referral terms.
- Admin referrals dashboard and budget controls.

### Phase 3 — Virality & optimisation (1 week)
- Milestone ladder, city leaderboard, post-delivery share prompts.
- WhatsApp/SMS campaigns, email triggers.
- A/B tests, K-factor and CAC reporting.

---

## 6. Metrics

- **Runner:** tier mix, rating distribution, completion rate, bonus cost per completed job, runner retention, payout NPS.
- **Referral:** clicks → signups → activated (job/parcel), K-factor, CAC per activated user, reward cost, fraud/chargeback rate.
- **Customer:** first-parcel conversion, repeat senders, credit redemption rate.
- **Guardrails:** monthly bonus/referral spend vs budget, capped users, support contacts about rewards.

---

## 7. Approval checklist

Reply with **approve / change** on each. Recommended values are in brackets.

1. **Runner tiers & rates** — Silver 3%, Gold 5%, Platinum 8%; cap KES 2,000/month? [approve]
2. **Bonus triggers** — welcome, reliability, peak/route, comeback? [approve]
3. **Rating source** — customer post-delivery rating + admin fallback? [approve]
4. **Referral rewards** — runner/referrer 500 & referee 100; business 500 credit & 10% off; customer 100 credit both sides? [approve]
5. **Qualification events** — runner 5 jobs (referee first job), business first paid parcel, customer first delivered parcel? [approve]
6. **Attribution window** — 60 days? [approve]
7. **Budget caps** — KES 25k/month platform (demo), per-user KES 10k? [approve]
8. **Reward delivery** — M-Pesa for runners, parcel/bill credit for customers/merchants? [approve]
9. **Public surfaces** — `/refer` landing + home/footer/dashboard cards + WhatsApp share? [approve]
10. **Referral terms** — publish and require acceptance? [approve]
11. **Launch scope** — Phase 1 first, then Phase 2? [approve]

---

## 8. Risks / notes

- Bonus gaming: mitigate with rating source, minimum job thresholds and caps.
- Referral fraud: same-device/self-referral checks, activation gating and clawbacks.
- Payout tax/reporting: runner bonuses are contractor earnings; customer credits are not cash.
- Marketing spend must remain within the platform budget; admin pause is mandatory.
- Customer ratings require new notification links/templates; Phase 1 includes them.
