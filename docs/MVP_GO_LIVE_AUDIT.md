# MVP go-live audit

**Audit date:** 29 October 2026, 12:52 EAT  
**Target:** `https://mzigo.online`  
**Scope:** public launch of the current MVP, not the longer V1 roadmap.

**Decision: CONDITIONAL NO-GO for a public real-money launch.**  
The app is ready for controlled sandbox/pilot use. It is not ready to charge the public or promise real insurance until the P0 items below are closed.

---

## 1. Verified production baseline

| Area | Verified state |
|---|---|
| Environment | `APP_ENV=production`, demo mode off |
| Pricing | `pricing_mode=free` |
| Health endpoint | HTTP 200 healthy |
| Site version | `site.js?v=20261028r` |
| Database migrations | None pending |
| Admin login | Working |
| Admin 2FA | **Disabled** |
| DB | `tokeqvlx_mzigo_online` |
| Public partners | 6 active carriers |
| Scan points | 1 active point |
| Unassigned parcels | 23 (read-only report) |
| Claims | 0 |
| Email channel | SMTP active; `hello@mzigo.online` |
| SMS channel | Twilio active; US sender `+17655387167` |
| WhatsApp | Inactive |
| M-Pesa | Live mode, **Sandbox** environment, ready |
| M-Pesa callback | `https://mzigo.online/api/mpesa-callback.php` |
| Payouts | Demo mode |
| Insurance | **Demo mode**, demo policies issued |
| CTA event IP/geo | Deployed and returning data |

---

## 2. Go-live P0 blockers

### GO-01 — Public M-Pesa is still sandbox

**Evidence:** production `mpesa_payment_config`:

```text
mode:        live
environment: sandbox
shortcode:   174379
```

Sandbox STK is working. It cannot charge real customers.

**Required:**
- Create/confirm the **production** Daraja app with Lipa na M-Pesa Online enabled.
- Obtain the production shortcode, passkey, consumer key and consumer secret.
- Switch **Admin → Settings → M-Pesa payments** to **Production**.
- Run **Test Daraja auth**.
- Make one small real payment end-to-end and confirm:
  - STK prompt received on a real phone
  - callback updates `mpesa_payments` and the parcel
  - M-Pesa receipt shown in the customer portal
  - collection code released
  - transaction ledger row is correct

**Owner:** Safaricom Daraja account / platform admin  
**Status:** Blocked on production credentials and one live test

---

### GO-02 — Transactional email delivery is not verified

**Evidence:** recent order emails are recorded as `sent` to the customer and merchant addresses, but prior real inbox checks did not confirm delivery. `admin@mzigo.online` currently fails SMTP with:

```text
550 No Such User Here
```

Mail-Tester did not receive the earlier test. No bounce/suppression events are recorded.

OTP email reportedly reaches inboxes, so the auth path may work while the order/payment template path still has a delivery problem.

**Required:**
- Send one order and one payment receipt to real customer and merchant Gmail inboxes.
- Confirm arrival in **Inbox**, **Promotions** and **Spam**.
- Check cPanel **Track Delivery** and **Mail Queue**.
- If accepted but absent, move to a verified relay/provider.
- Fix or remove the non-existent `admin@mzigo.online` mailbox used in tests.
- Verify bounce/complaint callbacks before scale.

**Owner:** platform/admin + Namecheap mail  
**Status:** Blocked/ops

---

### GO-03 — SMS delivery to real Kenyan phones is unverified

**Evidence:** Twilio SMS is active from a US long code and recent rows show `sent`, but earlier messages failed with `21612` for the same sender/route. Status “sent” is not proof of handset delivery.

**Required:**
- Send real order/payment SMS to a real Kenyan handset.
- Confirm delivery, sender identity and message content.
- Resolve the Twilio sender/route or replace it with a Kenya-capable provider.
- Confirm daily-limit behaviour and alerting.
- Keep the login SMS option hidden until this passes.

**Owner:** platform/admin + SMS provider  
**Status:** Blocked/external verification

---

### GO-04 — Admin security defaults are not launch-safe

**Evidence:**

```text
Admin 2FA: disabled
Admin password still the documented demo password
Admin access: no visible VPN/Cloudflare/IP restriction
```

**Required:**
- Rotate the platform-admin password immediately.
- Remove/rotate all demo merchant, customer and runner accounts.
- Enable and enforce admin 2FA.
- Restrict `/admin` by Cloudflare Access, VPN or an IP allowlist.
- Confirm full HTTPS/HSTS/security headers at the edge/origin.
- Verify backups and restore procedure.

**Owner:** platform admin  
**Status:** Pending

---

### GO-05 — Insurance is still demo cover

**Evidence:** `Admin → Insurance` is in **demo mode**; demo policies have been issued. The public site and receipts must not imply real cover while this remains demo.

**Required:** choose one:
1. Contract a licensed insurer/provider, configure live API credentials and complete a policy test, or
2. Explicitly gate/remove public insurance claims and revise marketing/terms.

Also complete legal review of insurance disclosures, claims, privacy and DSR/retention.

**Owner:** business/legal + platform admin  
**Status:** Blocked/external decision

---

### GO-06 — Launch operations are incomplete

**Required before public traffic:**

- Cron jobs scheduled and alerted:
  - billing/trials
  - claim SLA
  - webhook retries
  - referral campaigns
- External uptime and error monitoring using `HEALTH_CHECK_KEY`
- WAF/edge rate limits and production server hardening
- Penetration test and remediation
- Load/soak test: public tracking, parcel intake, scanner ingestion and API limits
- Off-site encrypted database backups with a restore drill
- Retain an incident/on-call contact and admin escalation path

**Owner:** platform/ops  
**Status:** Pending

---

## 3. P1 — required for a safe pilot, not necessarily day-one public

- **Partner operator identity:** scanner uses a shared point bearer token, no operator identity/audit.
- **Scanner device QA:** low-end Android, camera, offline queue, torch and label scanning.
- **Partner exception capture:** damage/loss/refusal evidence.
- **Partner dashboard/settlement/contracting.**
- **Runner payouts:** live if runner payouts are enabled; otherwise publish a manual payout policy.
- **Admin support 360, granular admin roles, reconciliation/refunds.**
- **Customer payment failure/retry state:** implemented for M-Pesa pending; verify real failed/cancelled cases.
- **Email/SMS retry and delivery observability.**
- **Order-photo review workflow** beyond admin links.
- **Unassigned-parcel handling decision** (23 currently read-only).
- **Data export/DSR/retention workflows.**

---

## 4. Confirmed ready

- Production environment and demo-mode flag
- Health endpoint
- Pending migrations cleared
- Public send/track pages
- Customer/freelancer email OTP path
- Merchant multi-role access
- Scan PWA with task-first flow, offline queue, torch, camera persistence and admin action wording
- Admin scan manual-entry toggle and workspace enforcement
- Product/package photo capture and admin photo links
- Insurance pricing controls
- Platform/holding fee controls
- CTA IP/geo tracking
- M-Pesa sandbox STK, waiting UX, receipt and callback settlement
- M-Pesa callback endpoint reachable

---

## 5. What “go live” can mean today

### Safe now

- Internal demo
- Controlled sandbox/UAT
- Closed pilot with manual reconciliation
- Partner scanner pilot with device QA
- No real insurance promise

### Not safe yet

- Public real-money delivery-fee payments
- Public insurance-backed claims
- Marketing that implies live cover
- Broad partner rollout without operator identity
- Launch without admin security hardening and monitoring

---

## 6. Recommended launch sequence

1. Rotate admin/demo credentials; enable 2FA; restrict `/admin`.
2. Verify real email and SMS delivery to customer/merchant handsets.
3. Switch M-Pesa to production credentials; complete one real payment.
4. Decide insurance: real insurer or gate/remove claims and adjust marketing.
5. Schedule cron/monitoring/backups; run pen test and load test.
6. Run a controlled pilot with a small group of senders, one partner point and manual support.
7. Expand only after inbox/SMS/receipt/collection metrics are clean.

---

## 7. Decisions still required

| ID | Decision |
|---|---|
| DEC-01 | Free MVP vs paid subscriptions (current pricing mode is free) |
| DEC-02 | Real insurer vs gated/removed public insurance |
| DEC-03 | Partner operator identity and shared-token policy |
| DEC-04 | Email-only customer auth vs SMS fallback |
| DEC-05 | Live runner payouts vs manual payout policy |
| DEC-06 | Unassigned-parcel assignment workflow |

---

## 8. References

- `docs/MVP_STATUS.md` — capability matrix and verification checklist
- `docs/ENGINEERING_V1_READINESS.md` — canonical V1 register
- `docs/PRODUCTION.md` — deployment/runbook
- `docs/SECURITY.md` — security controls
- `docs/mpesa-payments-integration.md` — Daraja setup and tests
- `docs/insurance-integration.md` — insurer configuration
- `docs/uat-parcel-scan.md` — scanner UAT
