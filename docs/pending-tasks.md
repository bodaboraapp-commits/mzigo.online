# Pending development tasks

**Updated:** 28 October 2026  
**Scope:** remaining product engineering, integrations, launch hardening and decisions.  
**Environment:** local/demo at `http://localhost:8090`, live MariaDB, SMTP email configured; live inbox delivery still unconfirmed.

> **Canonical V1 register:** role-by-role production gaps are now in `docs/ENGINEERING_V1_READINESS.md`. Open devlist items were migrated there. This file remains a historical operational list.

There are no queued Team tasks, scheduled reminders or active goals. The only background process is the local Apache preview server.

---

## Shipped baseline

The core SaaS build, scanner/PWA, claims, payouts, tenant branding/rate cards, webhooks, email adapters, security suite, API v1, growth programme, admin FAQs/coupons, SEO section and workspace-order rules are complete.

Most recently shipped:

- Scanner PWA task-first flow, persistent camera, offline queue, torch, jsQR fallback, Settings recent scans and admin-defined action order/wording
- Admin scan-point manual-entry toggle; workspace enforcement shifted to handover/arrival/collection
- Product/package photo capture on order creation, stored for admin and hidden from the public confirmation page
- Admin insurance charge mode (percentage/fixed), platform holding/platform fees and M-Pesa default payment
- Email OTP login, merchant multi-role access, deleted-user restore, production migration updater fix
- Migrations 039 (user_roles), 040 (scan manual entry), 041 (parcel_photos)

- Receiver OTP delivery proof, runner incentives and distance/size matching
- Partner route landing pages (`/partners/{slug}`) and sitemap coverage (38 URLs)
- Search Console/Bing fields, real-user Core Web Vitals collector and admin dashboard
- Dedicated **Admin → SEO** section with sitemap/robots/indexing links and CTA/funnel events
- Strict workspace-order visibility, workspace-only platform-admin Orders state and read-only unassigned-parcels report
- Registration email with courier, sender, value, size, insurance reference/cover and total cost
- Tracking-number search from the header and public tracking fallback from Orders
- Admin Sessions pagination at 10 rows/page
- Newsletter and product-alert opt-in prompt in Admin -> Website, with double opt-in, all-channel configuration and subscriber export
- Campaigns admin menu for consent-based marketing messages, scheduled delivery, recipient tracking and unsubscribe links
- Partner QR poster PNG downloads, configurable referral A/B copy tests, scheduled campaign cron, and source-level attribution/K-factor dashboard

---

## P0 — production and security blockers

These are launch/ops tasks, not missing product code.

| ID | Task | State |
|---|---|---|
| PRD-01 | Production environment: `APP_ENV=production`, `DEMO_MODE=false`, production base URL, strong config key | Pending deployment |
| PRD-02 | Remove/rotate demo admin, merchant, customer and runner accounts and documented passwords | Pending |
| PRD-03 | Enforce admin 2FA and restrict `/admin` via VPN/Cloudflare Access/IP allowlist | 2FA exists but optional; restriction pending |
| PRD-04 | HTTPS redirect, HSTS, WAF/edge limits, production Apache hardening | Pending |
| PRD-05 | Secret manager, least-privilege DB user, encrypted off-site backups and restore drill | Pending |
| PRD-06 | Configure `HEALTH_CHECK_KEY` and external health monitoring | Pending |
| PRD-07 | Install ClamAV and configure `CLAMSCAN_PATH`; heuristic scanner is fallback | Pending |
| PRD-08 | Audit alerting/SIEM for auth failures, admin changes, exports and webhook failures | Pending |
| PRD-09 | Independent penetration test before real-customer launch | Pending |

Full deployment checklist: `docs/PRODUCTION.md`. Security findings: `docs/SECURITY.md`.

---

## P0 — current MVP release blockers

| ID | Task | State |
|---|---|---|
| MVP-02 | Apply migrations 039/040/041 in production and verify `user_roles`, `partner_points.manual_entry_enabled`, `parcel_photos` | Pending deploy/DB |
| MVP-03 | Fix live email delivery for order/payment notifications: SMTP accepts mail but Gmail delivery is unconfirmed; Mail-Tester failed; check cPanel Track Delivery/queue or add a verified relay | Blocked/ops |
| MVP-04 | Resolve SMS delivery: Twilio trial hits 50 messages/day; login SMS is hidden; WhatsApp sender/templates pending | Blocked/external |
| MVP-05 | Verify customer/freelancer email OTP end-to-end and decide whether SMS becomes a fallback | Pending QA/decision |

---

## P1 — product engineering / integrations

| ID | Task | Type | Notes |
|---|---|---|---|
| PAY-01 | Real M-Pesa customer and subscription payments | Code | STK push, callback validation/replay protection, reconciliation and refunds. Current customer/invoice settlement remains demo/simulated outside claim payout rails. |
| MVP-06 | Production hardening: env, demo account/data removal, admin 2FA/access restriction, HTTPS/WAF, secrets/backups, health monitoring, cron, penetration/load tests | Ops | See `docs/ENGINEERING_V1_READINESS.md` PRD-01..11. |
| MSG-01 | SMS/WhatsApp transport + codes | Code/QA | Twilio SMS is configured for parcel notifications; trial daily cap and delivery validation remain. Login SMS is hidden; WhatsApp remains blocked on sender/template approval. |
| PAY-02 | Validate live Daraja payout/recovery credentials | External/QA | B2C shortcode, consumer key/secret, passkey, initiator/security credential, result/timeout URLs. Code paths already support demo/sandbox/live modes. |
| MAIL-01 | Validate live email provider | External/QA | Current SMTP accepts order/payment notifications but delivery to Gmail is unconfirmed; OTP email reportedly works. Check cPanel queue/Track Delivery or add a verified relay; add bounce/complaint callbacks. |
| SEO-01 | Enter Search Console/Bing tokens and CWV key | External/Admin | Fields and p75 dashboard are ready in **Admin → SEO**. Property ownership and account access are still needed. |
| SOC-01 | Enter real social profile URLs | External/Admin | **Admin → Website → Social media links**; placeholder/default links currently fall back. |
| LEG-01 | Legal counsel sign-off | External | Runner/referral terms published. Need approval reference, effective dates and recorded sign-off; see `docs/legal-launch-signoff.md`. |

---

## P1 — decisions required before building

| ID | Decision | Current state |
|---|---|---|
| DEC-01 | Merchant KYC approval role | Current flow: merchant submits → platform admin approves. Decide whether merchants can approve their own team documents. |
| DEC-02 | Payout queue semantics | Decide exact weekly/manual timing, release policy and admin approval gates. |
| DEC-03 | Unassigned-parcel handling | Report is read-only. Decide whether admins need an assign-to-workspace workflow or a permanent public-only policy. |
| DEC-04 | Insurance reference integration | Email shows a platform-generated `INS-…` cover reference. Decide if a real insurer policy number/API is required. |

---

## P2 — growth status (implemented)

Core Phase 3 growth automation is already live: milestone rewards, post-delivery referral prompt, scheduled/admin referral campaigns, A/B copy testing, partner poster downloads and K-factor/attribution reporting.

---

## P2 — operations and quality

- Schedule and alert on `php api/cron.php` (billing, claim SLA, webhook retries)
- Run encryption-key rotation drills and verify backups
- Configure SPF/DKIM/DMARC monitoring and provider bounce/complaint callbacks
- Add retention/DSR and customer data-deletion workflows
- Dependency/PHP/Apache patch cadence and secrets scanning in CI
- Load/soak testing for public tracking, parcel intake, scanner ingestion and API rate limits
- Accessibility and mobile QA pass across customer/admin/scanner flows
- Re-run the one-primary CTA audit when new landing sections/campaign bands are added

---

## External inputs needed

1. Google Search Console + Bing Webmaster verification tokens, CWV/PageSpeed key and property access
2. Official social profile URLs/handles
3. Legal counsel sign-off reference
4. Daraja B2C production credentials and callback URLs
5. SMS/WhatsApp provider credentials and sender IDs
6. Live email-provider credentials for bounce/complaint validation

---

## Recommended execution order

1. Deploy the latest code and apply migrations 039/040/041 through the web updater.
2. Fix email delivery for order/payment notifications and validate OTP delivery.
3. Resolve SMS provider limits and confirm the auth/notification channel decision.
4. Implement real M-Pesa customer/subscription payments and validate the full payment lifecycle.
5. Resolve commercial/insurance decisions, then complete production hardening and the penetration test.
6. Work through P2 operations/quality items before public scale-up.

---

## Admin dashboard v2

- Implementation: `docs/admin-dashboard-implementation.md`
- Proposal/audit: `docs/admin-dashboard-audit-proposal.md`

## References

- `docs/PRODUCTION.md` — production checklist and runbooks
- `docs/SECURITY.md` — security findings and controls
- `docs/insurance-integration.md`
- `docs/legal-launch-signoff.md` — legal/social/SEO input checklist
- `docs/SEO_MONITORING.md` — Search Console, sitemap and CWV setup
- `docs/growth-coupons-implementation.md` — growth programme status
- `docs/viral-growth-toolkit.md` — poster, campaign, A/B and TikTok operating guide
- `docs/NEWSLETTER_PROMPTS.md` — newsletter/alert prompt and consent operations
- `docs/CAMPAIGNS.md` — marketing campaign operations
- `docs/BACKLOG.md` — engineering backlog and shipped history
- `docs/MVP_STATUS.md` — current MVP capability matrix, verification checklist and pending work

## Viral growth toolkit

- Partner QR posters at `/partner-poster`, scheduled campaigns with cron at `_server/cron/referral_campaigns.php`, A/B share copy, TikTok 9:16 card at `/refer-card`, source/click analytics. Guide: `docs/viral-growth-toolkit.md`.

## Admin dashboard — Wave 2 pending

Implemented P0/P1/P2 is closed (`docs/admin-dashboard-implementation.md`). Remaining, per updated audit `docs/admin-dashboard-audit-proposal.md`:

- **P0:** alert detail + persistent ack/snooze/assign/resolve + owner notifications; KPI targets/definitions/freshness/sample size + drilldowns; legacy performance charts moved to the new Overview sidebar.
- **P1:** city/carrier/workspace/payment filters and saved views; route/carrier promise windows and business calendar; support/claims SLA aging/breach %; finance reconciliation (refunds, credits, payouts, COD vs M-Pesa); alert escalation rules.
- **P2:** retention/LTV/CAC and coupon lift; scheduled board pack/export; role-based dashboards; mobile cockpit; accessibility polish.
