# Engineering — V1 Production Readiness & Role Workflow Audit

**Date:** 26 October 2026  
**Decision:** **No-Go for full V1 production** in the current state. The app is feature-rich, but the core customer → payment → collection loop and runner/customer authentication are not production-ready.  
**Purpose:** role-by-role workflow gaps, launch classification, and the canonical pending engineering register moved out of the dev backlog.

---

## 1. V1 launch gate

A V1 production launch is approved only when all **V1 blocker** items below are closed or an explicit manual fallback is signed off.

Core promise that must work end-to-end:

1. Sender registers a parcel.
2. Partner office scans intake/handover/arrival.
3. Receiver is notified, signs in, pays the delivery fee.
4. Payment confirms asynchronously.
5. Collection code is released.
6. Partner scans collection.
7. Sender/receiver/admin can see the completed event; claims and support work.

The current app cannot complete steps 3–4 without demo mode, and cannot sign in customers/runners in production without an SMS provider.

---

## 2. Ready baseline (not re-opened)

| Area | Ready |
|---|---|
| Public | Send wizard, route/carrier quotes, tracking, labels/QR, contact, blog/help/location pages, referral page |
| Merchant | Signup/login, password reset, 2FA, email verification, workspace, team invites, parcels/orders, labels, CSV, claims/evidence/threads, billing documents, API keys, webhooks, branding, rate cards, KYC upload |
| Customer | Phone-code sign-in (demo), parcel portal, demo payment, collection code, claims with OTP, notification inbox, preferences, CSAT |
| Runner | Onboarding, KYC, availability, job feed/filtering, accept/pickup/deliver, proof photo + OTP, cancel, ratings, incentives, referral, earnings, payout request (demo/live code path) |
| Partner | Scanner PWA, scan-point tokens, intake/handover/arrival/collection, idempotent scan ingestion, admin scan-point CRUD |
| Admin | Customer overview, analytics, workspaces, invoices, transactions, claims, runner jobs, KYC, insurance config, messages, communications, sessions, users, scan points, coupons, CMS/SEO, audit |
| Claims | Eligibility rules, evidence, dual approval, 48h hold, customer reports, blacklist, recovery rails |
| Security | CSRF, rate limits, session revocation, 2FA available, upload scanning seam, audit log, security suite |
| Infra | Migrations, Docker/Apache/nginx deployment docs, health endpoint, cron jobs |

---

## 3. Role-by-role workflow gaps

### 3.1 Public sender / visitor

**Ready:** send, track, contact, insurance display, email confirmation when an email is captured.

| ID | Missing workflow | Impact | V1 class |
|---|---|---|---|
| SND-01 | **No sender account/portal.** Guest senders can only track by number; they cannot list their previous parcels or manage a claim. | Poor repeat-sender experience; support burden. | Pending (post-V1) |
| SND-02 | **Anonymous sender cannot file a claim.** Claims are merchant/customer-context flows. A public sender who bought insurance has no self-serve claim path. | Insurance promise cannot be honoured self-serve. | **V1 blocker if public insurance is sold** |
| SND-03 | **Sender notifications depend on email; no SMS.** Only a phone number is captured at registration. | Sender may receive no drop-off/transit/collection updates. | **V1 blocker via notifications** |
| SND-04 | **Insurance cover is demo unless a real insurer is contracted.** The app labels demo policies, but production marketing must not imply real cover. | Regulatory/trust risk. | **V1 blocker if insurance is offered** |
| SND-05 | No pickup request, address book, saved senders or resend/return self-service. | Product gap, not launch-critical. | Post-V1 |

### 3.2 Customer / receiver

**Ready:** portal, parcel list, payment modal (demo), collection code, claims, CSAT, notification preferences.

| ID | Missing workflow | Impact | V1 class |
|---|---|---|---|
| CST-01 | Production code is now sent through the Infobip SMS channel; it still needs live credentials and end-to-end delivery validation. | Receivers cannot access parcels until SMS is verified. | **Pending credentials/QA** |
| CST-02 | **Delivery-fee payment is demo only.** `customer.php` returns `DEMO-MPESA-…` and marks the parcel paid without a real STK push/callback. | Receivers cannot actually pay; collection code cannot be released. | **V1 blocker** |
| CST-03 | No failed payment/retry/refund state, no STK pending/timeout UI. | Payment support gaps. | Pending |
| CST-04 | No SMS fallback for notification/receipts; email is optional. | Some receivers receive nothing. | **V1 blocker via notifications** |
| CST-05 | No profile/phone change/account recovery, no reschedule/redelivery request. | Support workaround; post-V1. | Post-V1 |
| CST-06 | Claims/CSAT are ready, but insurance claims depend on a real insurer. | Claim promise depends on SND-04. | Launch-gated |

### 3.3 Merchant / business owner

**Ready:** signup/login, password reset, 2FA, email verification, team invitations, workspace/orders, labels, CSV, claims and evidence, billing invoices/documents, API keys/webhooks, branding/rate cards, KYC upload, demo settlement, pricing/free mode.

| ID | Missing workflow | Impact | V1 class |
|---|---|---|---|
| MCH-01 | **Live subscription payment/billing is not implemented.** Invoices are settled with `DEMO-MPESA`; free mode blocks collection. | Cannot charge merchants in V1. | **V1 blocker if V1 is paid**; acceptable if V1 is explicitly free |
| MCH-02 | No real payment method/card/M-Pesa mandate, dunning, renewal charge or reconciliation. | SaaS revenue/accounting gap. | **V1 blocker if paid** |
| MCH-03 | KYC is manual admin review; no document verification vendor/SLA automation. | Manual onboarding risk. | Manual acceptable for pilot |
| MCH-04 | Invoice refunds are manual; no merchant self-serve refund/credit reconciliation. | Finance handling overhead. | Pending |
| MCH-05 | No workspace data export/closure/DSR workflow. | Compliance gap. | Pending |
| MCH-06 | Merchant KYC approval matrix is still a decision (merchant vs admin approval). | Onboarding policy unclear. | Decision pending |
| MCH-07 | Team invite email exists; verify deliverability in production (SMTP/provider). | Staff onboarding. | Pending provider |
| MCH-08 | No overage/usage charging; quota enforcement stops work instead of billing uplift. | Revenue leakage at scale. | Post-V1 |

### 3.4 Runner / freelancer

**Ready:** onboarding, KYC, availability, job feed/filters, accept/decline, pickup, deliver with photo/OTP, cancel, ratings, earnings, incentives, referrals, payout request, bankless M-Pesa payout path.

| ID | Missing workflow | Impact | V1 class |
|---|---|---|---|
| RUN-01 | Production runner PIN is now sent through the Infobip SMS channel; live delivery still needs validation. | Runners cannot access jobs until SMS is verified. | **Pending credentials/QA** |
| RUN-02 | **Live payout callback is missing.** B2C initiation stores status `processing`, but there is no Daraja result callback endpoint to mark paid/failed/reconcile. | Runners may never be marked paid; money state uncertain. | **V1 blocker for live payouts** (manual payout acceptable only with sign-off) |
| RUN-03 | SMS/WhatsApp job notifications are not live. | Runners miss jobs. | **V1 blocker via notifications** |
| RUN-04 | No earnings statement/tax export or payout receipt download. | Runner support/accounting. | Pending |
| RUN-05 | No runner location/navigation, shift scheduling, vehicle document verification or background-check vendor. | Operational quality gap. | Post-V1 / policy |
| RUN-06 | Payout schedule semantics (weekly/manual release) still a decision. | Ops policy. | Decision pending |
| RUN-07 | Runner incident/support escalation is only the general contact inbox. | Slow exception handling. | Pending |

### 3.5 Partner office / scanner operator

**Ready:** PWA login with scan-point token, intake/handover/arrival/collection, idempotent ingestion, admin token/point management, low-connectivity indicator.

| ID | Missing workflow | Impact | V1 class |
|---|---|---|---|
| PTR-01 | **No partner accounts/roles.** Scanner access is a stored bearer token; there is no operator identity, permission model or per-user audit. | Shared-device abuse and weak accountability. | Pending for pilot; **V1 blocker if many partners** |
| PTR-02 | **No offline scan queue.** The UI shows Offline but scans require a live request; there is no queued sync. | Partner operations fail on unstable connectivity. | Pending/manual workaround |
| PTR-03 | No partner dashboard: volume, scan history, exceptions, settlement or performance. | Partners cannot self-serve; admin does everything. | Post-V1 |
| PTR-04 | Scanner cannot record damage/loss/refusal/exception with evidence beyond standard scans. | Claims rely on manual support. | Pending |
| PTR-05 | No partner onboarding contract/KYC/settlement workflow. | Commercial/legal risk. | Manual for pilot |
| PTR-06 | Token lifecycle lacks operator rotation policy. | Security/ops. | Pending |

### 3.6 Platform admin / support / finance

**Ready:** broad console, overview/analytics, KYC, insurance config, claims, runner jobs, messages, sessions, users, communications, scan points, coupons, CMS/SEO, audit, alerts.

| ID | Missing workflow | Impact | V1 class |
|---|---|---|---|
| ADM-01 | **Only one platform-admin role.** No support/finance/ops roles or least-privilege permissions. | Everyone can do everything; hard to separate duties. | Pending; **V1 blocker if multiple staff** |
| ADM-02 | Admin 2FA is optional; `/admin` has no VPN/Cloudflare/IP restriction. | Account takeover risk. | **V1 blocker** |
| ADM-03 | Alert Ack/Escalate logs an event but does not assign/notify/resolve/snooze. | Queue does not actually clear. | Pending (Wave 2) |
| ADM-04 | No filtered segmentation on the overview (city/carrier/workspace/sender/payment). | Slow root-cause analysis. | Pending (proposal approved for design) |
| ADM-05 | No saved views/export/board pack; audit export limited. | Reporting overhead. | Post-V1 |
| ADM-06 | No real payment reconciliation/refund workflow for customer delivery fees; subscription refunds manual. | Finance risk. | **V1 blocker if live payments** |
| ADM-07 | Unassigned parcels are read-only; no assign-to-workspace action. | Manual handling only. | Decision pending |
| ADM-08 | No customer/support 360 view spanning parcels, claims, payments and messages. | Support context switching. | Pending |
| ADM-09 | Alert routing/owners/SLA are visual metadata only; no on-call notification. | SLA accountability gap. | Pending |
| ADM-10 | Demo accounts and demo data must be removed/rotated. | Security/trust. | **V1 blocker** |

### 3.7 Claims / insurance

**Ready:** merchant/customer claims, evidence, OTP, dual approval, holds, reversals, recovery rails, blacklist.

| ID | Missing workflow | Impact | V1 class |
|---|---|---|---|
| CLM-01 | Live insurer policy/claim integration not contracted/credentialed; demo policies are generated. | Cannot honour real insurance cover. | **V1 blocker if insurance sold** |
| CLM-02 | Claim payout rails include live config, but provider credentials/callbacks need sandbox/live validation. | Claim payments may not settle. | Pending credentials |
| CLM-03 | Public sender claim path missing (see SND-02). | Sender cannot claim. | Launch-gated |

---

## 4. Cross-cutting production blockers

| ID | Item | Why it blocks V1 | State |
|---|---|---|---|
| PRD-01 | Production environment: `APP_ENV=production`, `DEMO_MODE=false`, HTTPS base URL, strong `APP_CONFIG_KEY` | Current local/demo base URL and demo mode. | Pending deployment |
| PRD-02 | Remove/rotate demo admin, merchant, customer, runner accounts and documented passwords; clean demo data | Public demo credentials are unsafe. | Pending |
| PRD-03 | Enforce admin 2FA + access restriction (VPN/Cloudflare/IP allowlist) | Admin takeover risk. | Pending |
| PRD-04 | TLS/HSTS/WAF/edge rate limits, Apache/nginx hardening | Security exposure. | Pending |
| PRD-05 | Secret manager, least-privilege DB user, encrypted off-site backups + restore drill | Data loss/credential exposure. | Pending |
| PRD-06 | `HEALTH_CHECK_KEY` + external monitoring/alerting | No production observability. | Pending |
| PRD-07 | ClamAV + `CLAMSCAN_PATH` for uploads | Malware risk on KYC/claim evidence. | Pending |
| PRD-08 | Audit alerting/SIEM for auth/admin/export/webhook failures | No incident detection. | Pending |
| PRD-09 | Independent penetration test + remediation | Required before public launch. | Pending |
| PRD-10 | Load/soak test public tracking, intake, scanner ingestion and API limits | Unknown capacity. | Pending |
| PAY-01 | Real M-Pesa customer/invoice payments: STK/callback/replay protection/reconciliation/refunds | Core collection loop is demo only. | **V1 blocker** |
| MSG-01 | Infobip SMS transport is implemented for codes, PINs, OTPs and parcel notifications. Production Infobip credentials/sender and live validation are still pending; WhatsApp delivery remains separate. | Auth/notifications can now be configured, but live credentials are still required. | **Pending credentials/QA** |
| PAY-02 | Live Daraja payout/recovery credentials + payout result callback/reconciliation | Live payouts may stay `processing`. | **V1 blocker for live payouts** |
| MAIL-01 | Live email provider validation (SMTP/SendGrid/Mailgun), bounce/complaint callbacks, SPF/DKIM/DMARC | Invites/receipts/reset reliability. | Pending |
| LEG-01 | Legal sign-off on runner/referral/insurance terms, privacy, DSR/retention | Compliance risk. | Pending |
| SEO-01 | GSC/Bing tokens, CWV key, Search Console access | Marketing/analytics gap. | External input |
| SOC-01 | Official social URLs/handles | Footer/social trust gap. | External input |
| INS-01 | Real insurer contract/API credentials; live mode + strict issuance | Insurance promise unresolved. | External |
| OPS-01 | Schedule and alert on `api/cron.php` (billing, claim SLA, webhook retries), growth campaign cron | Silent operational failures. | Pending |
| OPS-02 | Encryption-key rotation drill, backup restore test, dependency/patch cadence | Resilience. | Pending |
| OPS-03 | Retention/DSR/customer deletion workflows | Compliance. | Pending |
| OPS-04 | Accessibility and mobile QA across customer/admin/scanner flows | Quality/compliance. | Pending |

---

## 5. Devlist migrated to pending register

These were open in `docs/BACKLOG.md` / `docs/pending-tasks.md` and are now tracked here as pending V1 work. Historical completed items stay in the backlog.

### P0 — launch blockers
- PRD-01 production environment
- PRD-02 demo account/data removal
- PRD-03 admin 2FA enforcement + access restriction
- PRD-04 TLS/HSTS/WAF
- PRD-05 secrets/backups
- PRD-06 health monitoring
- PRD-07 ClamAV
- PRD-08 audit alerting
- PRD-09 penetration test
- PAY-01 real customer/invoice payments
- MSG-01 SMS/WhatsApp code and notification delivery
- PAY-02 live payout callback/reconciliation
- AUTH-01 **implement actual customer-code and runner-PIN dispatch** (currently generated only; see CST-01/RUN-01)
- INS-01 real insurer or remove/gate public insurance
- LEG-01 legal sign-off + DSR/retention
- ADM-10 demo data/accounts cleanup

### P1 — required for a safe pilot / operational completeness
- PTR-01 partner identity/permissions
- PTR-02 offline scan queue
- PTR-03 partner performance/settlement
- PTR-04 exception capture
- PTR-05 partner onboarding/KYC/contracts
- RUN-04 earnings/payout statements
- RUN-07 runner escalation path
- ADM-01 granular admin roles
- ADM-03 alert state/assignment/notifications
- ADM-06 reconciliation/refunds
- ADM-08 customer/support 360
- CST-03 failed/retry/refund payment states
- MCH-03 KYC verification/SLA
- MCH-05 data export/closure/DSR
- CLM-02 live claim payout validation
- OPS-01 cron scheduling/alerting
- MAIL-01 email provider + deliverability
- SEO-01/SOC-01 external inputs

### P2 — post-launch/scale
- SND-01 sender portal
- SND-05 pickup/address book
- CST-04/05 SMS fallback, profile/recovery, reschedule
- MCH-07 team invite deliverability
- MCH-08 overage charging
- RUN-05 location/background/vehicle checks
- RUN-06 payout schedule decision
- PTR-06 token rotation policy
- ADM-04 filters/segments (layout approved; implementation pending)
- ADM-05 saved views/exports/board pack
- ADM-07 unassigned assign workflow (decision)
- ADM-09 alert on-call routing
- OPS-02 rotation/restore/patch cadence
- OPS-03 retention/DSR/deletion
- OPS-04 accessibility/mobile QA
- Load/soak testing, API rate-limit hardening

### Decisions still needed
- DEC-01 Merchant KYC approval role
- DEC-02 Payout queue semantics
- DEC-03 Unassigned parcel handling
- DEC-04 Insurance: real insurer vs gate/remove offering
- DEC-05 V1 commercial model: free vs paid subscription; if paid, MCH-01/02 become blockers

---

## 6. V1 critical path

1. **Fix auth delivery** — send customer codes and runner PINs through SMS/WhatsApp (or a verified email fallback for pilot), add delivery retry/observability.
2. **Implement real delivery-fee payment** — STK push, callback validation, status state machine, reconciliation, collection-code release only on confirmed payment.
3. **Decide insurance/commercial scope** — real insurer contract or remove/gate insurance messaging; decide free vs paid V1.
4. **Complete production hardening** — env, demo cleanup, 2FA/access, HTTPS/WAF, secrets/backups, health monitoring, cron.
5. **Validate live payouts** — payout result callback, reconciliation, manual fallback.
6. **Pilot partner process** — token/operator policy, offline workaround or queue, settlement.
7. **Legal/compliance** — terms, privacy/DSR, insurance disclosures, social/SEO inputs.
8. **UAT per role + load/pen test**, then go-live.

---

## 7. Role-level go/no-go summary

| Role | Can use V1 today? | Reason |
|---|---|---|
| Public sender | Partial | Can send/track; no production notifications/claims/real insurance. |
| Customer | **No** | Cannot receive login code or make a real payment. |
| Merchant | Manual/demo only | Can operate parcels/claims, but cannot be charged live. |
| Runner | **No** | Cannot receive PIN; live payouts incomplete. |
| Partner/scanner | Pilot only | Token works; offline/no identity/no settlement. |
| Platform admin | Internal only | Broad access, no enforced 2FA, demo data must be removed. |
| Claims/insurance | Claims demo/manual | Real insurer/claim payout integration not contracted. |

**Overall V1:** No-Go until AUTH-01, PAY-01, MSG-01, PRD-01..09 and the insurance/commercial decision are resolved. A closed internal pilot is possible with demo mode and manual operations, but not a public production launch.

---

## 8. References

- `docs/BACKLOG.md` — historical dev backlog (open items migrated here)
- `docs/pending-tasks.md` — previous consolidated pending list
- `docs/PRODUCTION.md` — deployment/runbook
- `docs/SECURITY.md` — security controls
- `docs/insurance-integration.md` — insurer config and contract needs
- `docs/legal-launch-signoff.md` — legal/social/GSC checklist
- `docs/admin-filters-layout-proposal.md` — approved filter design pending implementation
