# Mzigo.online — Growth & Coupons Implementation

Implemented 26 October 2026 after approval of Phases 1–3, plus coupon checkout.

## 1. Runner quality & bonuses (Phase 1)

**Ratings**
- Customer rating page: `/rate-runner?token=…`, noindex; 1–5 stars + optional comment.
- Token is generated on delivery and included in the receiver’s delivered notification (`Rate your runner: …`).
- Admin can still rate a delivered job from **Admin → Runner jobs** (source = admin).
- Ratings are stored in `runner_ratings`; `runner_jobs.rating/rating_note` stay in sync.

**Tiers**
- Rolling 90-day tiers: Silver 3% (20 jobs, 4.5★), Gold 5% (50 jobs, 4.7★), Platinum 8% (100 jobs, 4.8★).
- Runner dashboard shows current tier, average rating, next-tier progress and monthly cap.
- Configurable in **Admin → Growth & coupons**: rates, monthly cap, welcome/reliability amounts.

**Bonuses**
- `runner_bonus_ledger` records tier quality bonus per rated job, welcome bonus after the first 3 delivered jobs and a weekly reliability bonus.
- Monthly cap enforced when writing bonuses; jobs carry `bonus_kes` for transparency.
- Bonuses appear as **Bonus available** in runner earnings and are included in the payout amount. Creating a payout marks the covered bonus rows paid.

## 2. Referrals (Phases 2–3)

**Codes & attribution**
- Each runner/business/customer gets a code (`MZ-XXXXXX`) in `referral_codes`.
- `/refer?ref=CODE` stores a 60-day cookie and records a click; signup, customer login/start and runner login attribute the referral (`referrals`).
- Self-referral blocked; one referral per referee per programme.

**Rewards**
- Runner: referee KES 100 after first delivered job; referrer KES 500 after 5 delivered jobs.
- Business: KES 500 bill credit after the workspace’s first parcel.
- Customer: KES 100 parcel credit to referrer + KES 100 to referee after the referee’s first paid parcel.
- Runner rewards go to the bonus ledger; business/customer rewards go to `credit_ledger`.
- Admin can edit reward amounts and attribution window in **Admin → Growth & coupons**.

**Surfaces**
- Public `/refer` page: rewards explanation, personal link, WhatsApp/SMS share, QR, copy link, leaderboard.
- Dashboard **“Earn with friends”** card for runners and customers: code, clicks, sign-ups, activated, earned, share/copy.
- Simple monthly referral leaderboard on `/refer`.
- Referral terms text published on `/refer`; legal review still recommended.

## 3. Coupons at checkout

**Admin**
- **Admin → Growth & coupons**: create/delete coupons with code, name, fixed/percent value, delivery/subscription/all scope, minimum spend, usage limit, per-user limit, expiry and active state.
- Coupon list shows uses and active state.

**Customer checkout**
- Paying for a parcel now opens a checkout dialog instead of paying immediately.
- Coupon field with **Apply coupon** validation; shows coupon discount and total.
- Referral parcel credit is shown and applied automatically (FIFO from `credit_ledger`).
- Server re-validates the coupon at pay time, records `coupon_redemptions`, stores `coupon_code`, `discount_kes`, `paid_amount_kes` on the parcel and pays the discounted amount.
- Demo checkout works end-to-end; live M-Pesa remains credential-gated.

## 4. Admin configuration

- **Growth & coupons** sidebar section:
  - Coupons table + create form.
  - Runner incentive settings (cap, Silver/Gold/Platinum rates, welcome/reliability amounts).
  - Referral rewards (runner/business/customer amounts, attribution days) + lifetime referral stats.
- **Growth APIs**: `api/growth.php` (summary, coupon validation, rating submit, click tracking), `api/admin-growth.php` (coupons, configs).

## 5. Verification evidence

- Coupon **MZIGO100** (KES 100 off, min KES 200): applied to a KES 620 delivery → **KES 520**; payment recorded, redemption row written, parcel marked paid with coupon fields.
- Test referral credit KES 100: checkout showed **KES 100** credit; with coupon total updated to **KES 420**; credit ledger redemption path verified.
- Customer rating 5★ via `/rate-runner?token=…` wrote `runner_ratings`, synced the job rating and created the expected incentive ledger entries.
- Referral attribution: visiting `/refer?ref=MZ-26B426` set the cookie and a new customer login created `referrals` with status `attributed`.
- Referral qualification/rewards: a referee with one paid parcel produced `referrals.status = qualified` plus KES 100 referral credit for both referrer and referee; credits were then redeemable at checkout.
- Referral page/card render: code, stats, WhatsApp link and QR present; admin growth section saves incentive/referral config.
- PHP lint + JS syntax + SEO validator clean; sitemap 38 URLs; no page errors in tested flows.

## 6. Subsequent completion pass (26 October 2026)

- Subscription invoices now accept coupons and automatically apply bill credit from referrals; invoice paid amount, coupon discount and credit used are stored.
- Admin referral table renders referrer, programme, status and attributed date with K-factor metrics.
- Phase 3 automation: milestone rewards (3/10/25 referrals), post-delivery referral prompt, admin-triggered reminder campaign and K-factor stats.
- UX-08 account/help dropdown and UX-09 one-primary audit completed.
- Referral terms §23 published; sign-off pack at `docs/legal-launch-signoff.md`.
- Launch readiness alerts surface missing GSC/Bing tokens and default social links.

### Still external / optional

- Real social URLs, GSC/Bing tokens and legal sign-off need user-provided values/access.
- Partner QR posters, referral A/B copy tests and scheduled email/SMS campaigns are implemented; see `docs/viral-growth-toolkit.md`.
- Daraja and SMS/WhatsApp production credentials remain external.


## 7. Optional growth features completed (26 October 2026)

- **Partner QR poster downloads:** Admin → Coupons → Partner QR posters generates a partner-labelled `/partner-poster` page; the page has Print poster and Download PNG (1080 × 1350 PNG with QR, code, partner label and contact details).
- **Referral A/B copy tests:** Admin → Coupons → Referral A/B copy test configures two share-copy variants with `{code}` placeholders. Visitors get a stable A/B assignment; exposures and shares are stored in `analytics_events`, clicks use `viral-share-A/B` sources, and sign-ups/qualified referrals are attributed from the source captured at sign-up. Results show exposures, shares, clicks, sign-ups, qualified and conversion.
- **Scheduled referral campaigns:** campaigns already had a schedule/recurrence form; they are now processed automatically by `_server/cron/referral_campaigns.php` and the central `api/cron.php`. Due campaigns are claimed atomically to prevent duplicate sends; one-shot campaigns clear their next run after sending.
- **Attribution/K-factor dashboard:** Admin growth reports now show source-level clicks, sign-ups, qualified referrals and conversion; K-factor is qualified referrals per inviter, with conversion rate shown alongside. Campaign attribution and A/B performance are included.
