# Mzigo.online — MVP status and pending work

**Updated:** 29 October 2026  
**Scope:** current MVP/UAT build, production readiness and remaining launch work.  
**Canonical launch register:** `docs/ENGINEERING_V1_READINESS.md`  
**Production audit:** `docs/MVP_GO_LIVE_AUDIT.md`  
**Production runbook:** `docs/PRODUCTION.md`

---

## 1. MVP verdict

The MVP workflow is demonstrable end-to-end with demo payments and manual operations:

1. Sender registers a parcel from the public send wizard.
2. Partner staff opens `/scan`, chooses an action, scans the label and records the scan.
3. Receiver is notified, signs in, sees the parcel and pays the delivery fee.
4. Collection code is released after payment.
5. Partner scans collection.
6. Sender, receiver and admin can track the parcel; claims and admin workflows exist.

**This is not yet a public-production launch.** Production is live on `mzigo.online` with `APP_ENV=production`, but M-Pesa is still sandbox, admin 2FA is disabled, and email/SMS/insurance launch gates remain open. See `docs/MVP_GO_LIVE_AUDIT.md`. The core collection loop has a working sandbox STK path, but public charging still needs production Daraja credentials and one real payment. Live email/SMS delivery, insurance and admin hardening also need sign-off.

### Launch classification

| Scope | Status |
|---|---|
| Internal demo / UAT | Ready |
| Closed pilot with manual payment reconciliation | Possible with sign-off |
| Public customer launch | **No-Go** until live payments, auth delivery, migrations and production hardening close |
| Partner scanner pilot | Ready for device QA; operator identity and settlement remain manual |

---

## 2. Shipped since the previous readiness audit (26 Oct 2026)

### Authentication and accounts
- Customer and freelancer email OTP login channel; login SMS option hidden for now.
- Original **Business / Freelancer / Customer** login tabs restored.
- Merchant accounts can receive customer/freelancer role grants without changing merchant registration.
- Merchant multi-role access via `user_roles` (migration `039_multi_role_access.sql`).
- Soft-deleted users can be restored by admin.
- Signed-out users are redirected to the home/login flow.

### Parcels and notifications
- Product photo and package photo capture during **Send Mzigo** order creation.
- Photos stored in `parcel_photos`, viewable by admin from **Unassigned parcels**.
- Photo gallery intentionally hidden from the public order-confirmation page.
- Creator/receiver phone → database email resolution for anonymous orders.
- Order and payment notifications forced over SMS + email; customer and creator payment receipts.
- M-Pesa selected by default in Send Mzigo and Send Errand payment dropdowns.
- Transactional notification emails changed to the same lightweight mail path as OTP emails.

### Scanner PWA (`/scan`)
- Task-first flow: choose drop-off, handover, arrival or collection before scanning.
- Scan button remains disabled until a task is selected.
- Camera stays open until a valid Mzigo label is detected or cancelled.
- Invalid labels keep the camera open; repeated taps no longer open multiple camera streams.
- Offline scan queue with automatic retry when the connection returns.
- Torch/flash toggle where supported.
- `jsQR` fallback for browsers without native `BarcodeDetector`.
- Recent scans and queue status moved into the gear/Settings panel.
- Admin can enable/disable manual tracking entry per scan point.
- Admin can reorder and rename scan actions/sub-labels.
- Workspace enforcement:
  - Drop-off is open to the receiving point.
  - Handover, arrival and collection require the parcel workspace to match the point workspace.
- PWA cache now uses `mzigo-scan-v7`; hard refresh/reopen required after deployment.

### Fees, insurance and admin controls
- Admin **Insurance** controls:
  - Percentage-of-declared-value charge
  - Fixed charge per order
  - Minimum percentage charge
- Admin **Settings → Platform fees** controls holding fee and platform fee; values flow into quotes and the public estimate.
- Admin scan-point manual-entry controls.
- Admin platform fees and insurance pricing are stored in `platform_settings`; no schema change needed.

### Database and deployment
- Migration `039_multi_role_access.sql` fixed for production DB names.
- Web DB updater strips hardcoded `USE database;` statements.
- Migration `040_scan_manual_entry.sql` adds per-point manual-entry control.
- Migration `041_parcel_photos.sql` adds product/package photo storage.
- **Production migration application is still pending confirmation** — run the web updater after deploying the latest commit.

### Known unresolved delivery issues
- Order/payment notification emails are recorded as `sent` by SMTP, but Gmail delivery has not been confirmed.
- Mail-Tester delivery via the current Namecheap SMTP path has not arrived.
- OTP email reportedly reaches the inbox, so the remaining issue is transaction-email deliverability/queue or content filtering, not the notification code path.
- Twilio SMS trial account hits the **50 daily messages** limit; later SMS rows fail with that provider error.
- WhatsApp remains inactive/blocked on sender/template approval.
- Production `hello@mzigo.online` SMTP/mailbox behavior needs cPanel delivery-log verification.

---

## 3. Current capability matrix

| Area | MVP state |
|---|---|
| Public send wizard | Ready; photos, M-Pesa default, quotes, QR/Locker |
| Public tracking | Ready |
| Merchant workspace, orders, labels, CSV, claims | Ready in demo |
| Customer/freelancer OTP email | Ready (delivery verification recommended) |
| Customer delivery-fee payment | **Demo only** (`DEMO-MPESA-…`) |
| Collection code release | Works in demo; must be gated on real payment |
| Scanner PWA | Ready for pilot; device QA pending |
| Partner identity/permissions | Token-only; no operator identity |
| Admin console | Broad; admin roles/2FA enforcement pending |
| Claims | Ready in demo; real insurer pending |
| SMS notifications | Code exists; Twilio trial limit/credentials block production |
| Email notifications | Code sends; live deliverability unconfirmed |
| Runner payouts | Demo/live code path; live callback/reconciliation incomplete |
| API v1 / webhooks / rate cards | Ready baseline |

---

## 4. Pending MVP work

### P0 — must close before public launch

| ID | Work | State | Why it matters |
|---|---|---|---|
| MVP-01 | Real M-Pesa delivery-fee payment: STK push, callback verification, replay protection, retry states, reconciliation, refunds; release collection code only on confirmed payment | **Blocked** — currently demo settlement | Core customer promise is not real |
| MVP-02 | Deploy and apply migrations `039`, `040`, `041`; verify multi-role, scan manual entry and photos in production | **Pending deploy/DB** | Admin/user features can fail on production until applied |
| MVP-03 | Fix transaction-email delivery: verify SMTP queue/provider, check cPanel Track Delivery, add external relay if needed, validate order/payment emails to real inboxes | **Blocked/ops** | Customers receive no order/payment confirmations |
| MVP-04 | Resolve SMS path: upgrade/raise Twilio daily limit or replace provider; keep login SMS hidden until delivery is verified | **Blocked/external** | SMS notifications and any SMS auth cannot be relied on |
| MVP-05 | Decide and complete production auth delivery: email OTP is active; SMS login remains hidden; validate password/OTP/account recovery flows | **Pending QA/decision** | Customer/freelancer access depends on reliable delivery |
| MVP-06 | Production hardening: `APP_ENV=production`, `DEMO_MODE=false`, rotate/remove demo accounts, enforce admin 2FA + access restriction, HTTPS/HSTS/WAF, secrets/backups, health monitoring | **Pending deployment** | Public exposure is unsafe in demo state |
| MVP-07 | Real insurer contract/API or explicitly gate/remove public insurance claims; legal disclosures and sign-off | **Blocked/external** | Insurance promise must not imply cover that does not exist |
| MVP-08 | Live payout callback/reconciliation for runners if payouts are enabled; otherwise publish manual payout policy | **Pending/decision** | Runner money state can remain `processing` |
| MVP-09 | Schedule cron, monitoring and alerting; independent penetration test and load test | **Pending** | Operational and security readiness |

### P1 — required for a safe pilot / operational completeness

| ID | Work | State |
|---|---|---|
| P1-01 | Partner operator identity/permissions (scanner currently shared bearer token) | Pending |
| P1-02 | Scanner device QA on low-end Android/iOS and physical label tests | Pending QA |
| P1-03 | Partner exception capture: damage/loss/refusal evidence | Pending |
| P1-04 | Partner dashboard, settlement and token rotation policy | Pending |
| P1-05 | Order photo review workflow beyond admin links (claims/support integration) | Pending |
| P1-06 | Admin support/customer 360, granular admin roles, reconciliation/refunds | Pending |
| P1-07 | Customer failed-payment/retry/refund states; SMS fallback | Pending |
| P1-08 | Email/SMS retry observability and bounce/complaint callbacks | Pending |
| P1-09 | Email provider fallback and production mailbox ownership for `admin@` / `hello@` | Pending |
| P1-10 | Production DB backup/restore drill and encryption-key rotation | Pending |

### P2 — post-MVP/scale

- Sender account portal, pickup requests and address book.
- Runner earnings statements, tax exports and payout receipts.
- Admin saved views/board packs, city/carrier filters and alert assignment/escalation.
- Data retention/DSR/deletion workflows.
- Accessibility/mobile QA and load/soak testing.
- WhatsApp production templates/sender approval.
- SEO/Search Console/Bing/social inputs and legal approval references.

---

## 5. Immediate verification checklist

### Database
```text
https://mzigo.online/api/db-update.php
```
Confirm:
- `user_roles` table
- `partner_points.manual_entry_enabled` column
- `parcel_photos` table

### Scanner
1. Open `/scan` after hard refresh/reopen; confirm cache v7.
2. Select a task → scan button becomes enabled.
3. Scan a valid label; camera stays open until valid code/Cancel.
4. Test offline queue by going offline, saving a scan, reconnecting.
5. Test a point with manual entry disabled.
6. Test handover/arrival at a workspace-mismatched point; expect rejection.
7. Test admin renamed/reordered actions appear on the scanner.

### Orders and photos
1. Create an order with product and package photos.
2. Confirm order succeeds even if a photo upload fails.
3. Confirm photos appear only in Admin → Unassigned parcels, not the public confirmation page.
4. Verify admin can open both photo links.

### Fees and insurance
1. Change Admin → Settings platform fees; confirm new quote totals.
2. Change Admin → Insurance to fixed/percentage; confirm quote and registration fee.
3. Restore desired production values.

### Notifications
1. Place a real order and check customer/merchant inbox and spam.
2. Check cPanel Track Delivery and Mail Queue.
3. Check Twilio daily usage/limit and failed SMS rows.
4. Confirm payment receipt emails and collection codes.

---

## 6. External inputs still required

1. Live M-Pesa/Daraja production credentials and callback URLs.
2. Reliable SMS provider credentials/sender and daily limits; or email-only auth decision.
3. Email relay/provider credentials or confirmation that Namecheap SMTP queue is fixed.
4. Real insurer contract/API credentials, or a decision to gate insurance.
5. Legal sign-off: terms, privacy, insurance disclosures, DSR/retention.
6. Search Console/Bing tokens, social URLs and production domain/mailbox ownership.

---

## 7. Decisions still needed

| ID | Decision | Current state |
|---|---|---|
| DEC-01 | V1 commercial model: free pilot or paid subscription | Pending |
| DEC-02 | Real insurer vs gated/removed public insurance | Pending |
| DEC-03 | Partner identity model and shared-token policy | Pending |
| DEC-04 | Customer auth: email OTP only vs SMS/WhatsApp fallback | Email currently active; SMS hidden |
| DEC-05 | Payout queue semantics and manual fallback | Pending |
| DEC-06 | Unassigned-parcel assign-to-workspace workflow | Read-only report today |

---

## 8. References

- `docs/ENGINEERING_V1_READINESS.md` — canonical role-by-role V1 register
- `docs/PRODUCTION.md` — production deployment/runbook
- `docs/SECURITY.md` — security controls
- `docs/BACKLOG.md` — shipped history
- `docs/pending-tasks.md` — operational pending list
- `docs/database-web-update.md` — migration updater
- `docs/email-otp-login.md`, `docs/otp-timezone-fix.md` — auth recovery
- `docs/merchant-multi-role-login.md` — multi-role access
- `docs/insurance-integration.md` — insurer/insurance configuration
- `docs/uat-parcel-scan.md` — scanner UAT
