# Viral Growth Toolkit — partner posters, campaigns, A/B tests & TikTok

Implemented 26 October 2026. This is the operating guide for the Phase 3 extras.

## 1. Partner QR posters

- **Admin → Coupons → Partner QR posters**: choose a referral code, programme and partner label; generate a poster link.
- Poster page: `/partner-poster?ref=CODE&program=runner|business|customer&label=…`
- The QR points to `/refer?ref=CODE&src=partner-poster&program=…`, so scans are attributed with a `partner-poster` source.
- The poster is print-ready (A4-friendly, print CSS hides the site header/footer) and has **Download PNG** for a 1080 x 1350 social/print asset.
- Partner rewards still go to the owner of the referral code, so assign each partner office an owner account/code.

## 2. Scheduled referral campaigns

- **Admin → Coupons → Scheduled referral campaigns**: name, message, schedule time and recurrence (once/weekly/monthly).
- Variables: `{code}` and `{link}` are personalised per recipient.
- Audience: active users who have a referral code and no attributed referral in the last 30 days (max 60 per run).
- **Run now** sends immediately; scheduled campaigns are processed by both the dedicated CLI cron and the central `api/cron.php`:
  ```
  C:\xampp\php\php.exe C:\xampp\htdocs\www\mzigo\_server\cron\referral_campaigns.php
  ```
  Schedule every 15 minutes with Windows Task Scheduler.
- Sends use the configured notification channels; only active channels deliver externally (email is active in the demo).
- Due campaigns are claimed atomically so overlapping cron runs cannot double-send. One-shot campaigns clear their next run after sending.
- Campaign status, next run and sent count are shown in the admin table; pause/resume and delete are supported.

## 3. A/B copy tests

- `/refer` assigns a sticky A/B variant (`mz_ref_variant` in localStorage).
- Variant changes the WhatsApp/SMS copy, TikTok caption link and 9:16 card link.
- Copy variants are editable in **Admin -> Coupons -> Referral A/B copy test**; use `{code}` in each copy.
- Events recorded: `referral_variant_exposure`, `referral_share` (with channel + variant).
- Share links carry source tags: `src=viral-share-A/B` and `src=tiktok-A/B`; referral clicks store the source.
- Use **Admin → Coupons → Click sources** to compare partner-poster, campaign, viral-share and TikTok traffic.

## 4. 9:16 card + TikTok content kit

- `/refer` now has a **Create TikTok content** card:
  - choose a content angle,
  - get a ready caption with code, link and Kenyan hashtags,
  - copy caption, native share, or open the 9:16 card.
- `/refer-card?ref=CODE&variant=A` renders a 9:16 visual with QR and code; **Download 9:16 PNG** exports a 1080×1920 card.
- The card is designed to be used as a video cover / end screen, with the referral link in the caption or bio.

## 5. TikTok content strategy

TikTok does not make caption links clickable for most accounts. Treat TikTok as **attention → profile → link in bio / DM / QR**, and give every creator a personal code.

### Content pillars

1. **POV parcel drama** — “POV: you sent a parcel across Kenya without visiting three offices.” Show register → drop-off → tracking → collection.
2. **Runner day-in-life** — a real runner picks an errand, delivers, shows the payout; human proof beats ads.
3. **Receiver education** — “Your parcel is ready. Here is how to pay by M-Pesa and collect without stress.”
4. **Seller packing** — small business owner packs orders and hands them over once; demonstrates business value.
5. **Partner-office street content** — partner staff scan a poster QR and explain the service; local trust.

### Video recipe (15–30s)

- 0–2s hook: bold claim or relatable problem.
- 2–8s problem/demo: queue, missed parcel, late delivery.
- 8–20s solution: app / drop-off / tracking / M-Pesa collection.
- 20–28s proof: code, reward, customer reaction.
- End card: 9:16 card with QR/code; CTA “Use code X — link in bio.”

### Creator / partner programme

- Give each creator their own account + referral code.
- Track with `?src=tiktok-<handle>-A/B`.
- Pay for **activated referrals**, not views; disclose paid partnerships (`#ad` / TikTok branded content).
- Bonus milestones (3/10/25 referrals) are already automated.
- Seed 5–10 nano-creators per city before scaling; ask for raw footage rights.

### Cadence

- 3–5 posts/week per creator; 2 education + 1 runner story + 1 offer + 1 UGC/duet.
- Duet/stitch customer reactions; run a `#MzigoMove` challenge.
- Pin the best-performing video; keep referral link in bio fresh.
- Repurpose the same 9:16 card across TikTok, Reels and Shorts.

### Compliance

- No guaranteed-income or “get rich” claims.
- Show that runner earnings depend on completed jobs.
- Tag paid partnerships; follow TikTok community guidelines.
- Link to `/terms#referral-terms` and `/terms#runner-terms` in bios/landing pages.

## 6. Measurement

- TikTok views → profile clicks (platform analytics).
- Referral clicks by source (`tiktok-A`, `tiktok-B`, `partner-poster`, `campaign`).
- Source-level clicks, sign-ups, qualified referrals, conversion and K-factor (qualified per inviter) from the admin growth dashboard.
- Compare A/B variants by share channel and source.
- Weekly: top creator, top video, activation rate, cost per activation; pause losing creative.

## 7. Runbook

1. Create a partner code/user and generate the poster in admin.
2. Print posters with the partner label; QR tracks `partner-poster`.
3. Create a scheduled campaign with `{code}`/`{link}`; enable the 15-minute cron.
4. Share `/refer` with creators and give them `src=tiktok-<handle>` links.
5. Review clicks/sources, K-factor and A/B variants weekly; adjust copy and creators.
