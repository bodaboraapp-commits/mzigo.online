# Mzigo.online â€” SaaS parcel platform (local build)

A multi-tenant SaaS version of the Mzigo parcel platform: business workspaces,
team roles, subscription plans with trials and quotas, invoices, a platform admin
console, API keys and a public REST API â€” on top of the rebuilt Mzigo.online
front-end (parcel registration, QR labels, tracking).

**Preview:** <http://localhost:8090/>
**Health:** <http://localhost:8090/api/health.php>

> Local/demo deployment. Not affiliated with Mzigo. Rotate the demo credentials and
> remove demo workspaces before exposing this to real customers â€” see
> `docs/PRODUCTION.md`.

---

## Demo accounts

| Role | Email | Password | Workspace |
|---|---|---|---|
| Platform admin | `admin@mzigo.online` | `Admin123!` | `/admin` |
| Merchant owner | `amina@example.com` | `Demo1234!` | Amina Crafts (Growth, active) |
| Merchant owner | `sokoni@example.com` | `Demo1234!` | Sokoni Traders (Starter, trial) |
| Freelancer | `0700111222` | PIN `1234` | `/freelancer` |
| Customer | `0744333444` | code `1234` | `/customer` (has a parcel ready to pay) |

Sign-up creates a fresh workspace with a 14-day Starter trial. Payments use the
`demo` provider (the â€œPay with M-Pesaâ€ button settles the invoice and activates
the workspace) â€” the M-Pesa integration seam is documented in
`docs/PRODUCTION.md`.

## User classes

| Class | Sign-in | What they can do |
|---|---|---|
| **Admin** | email/phone + password (platform admin seed) | `/admin` platform console, analytics, billing overrides, audit |
| **Seller / Merchant** | sign up / sign in with email + phone + password | owns a workspace: parcels, errands, billing, team, API keys |
| **Employee** | email/phone + password, invited by the workspace owner in Settings | workspace staff with the role assigned by the owner (`admin`/`staff`) |
| **Freelancer** | **phone number + system-generated 4-digit PIN** | `/freelancer` account: assigned jobs, earnings, PIN reset |
| **Customer** | **phone number + system-generated 4-digit code** | `/customer`: incoming parcels, pay-at-collection, collection code |

Freelancer sign-up and sign-in share one flow: enter a phone number, the system
creates the freelancer account (if new) and issues a 4-digit PIN, then verify the
PIN. Customers enter the phone number the seller used as the delivery phone, then
verify a 4-digit code. In this demo both codes are always **1234**; production
generates random codes and delivers them by SMS (see `docs/PRODUCTION.md`).
Freelancers and customers are redirected away from merchant/admin pages and
cannot call merchant APIs. Customers see only parcels addressed to their phone,
and the collection code is revealed only after the delivery fee is paid.

## Stack

| Layer | What |
|---|---|
| Frontend | Static HTML + the site's production CSS + vanilla JS (`assets/js/site.js`) |
| API | PHP 8.2, PDO prepared statements (`api/*.php`, `api/v1/*.php`) |
| Database | MariaDB 10.4 â€” XAMPP instance on **port 3307**, DB **`mzigo_online`** |
| Server | Dedicated Apache 2.4 instance on **:8090** with PHP + security headers |
| Jobs | `api/cron.php` (trials/invoices), `_server/bin/backup.ps1` |
| Deploy | `deploy/Dockerfile`, `deploy/docker-compose.yml`, Apache vhost, nginx config |

## SaaS features

- **Workspaces (tenants)** â€” each business gets isolated parcels, errands, senders,
  team and billing. Users belong to workspaces via `tenant_members`
  (`owner` / `admin` / `staff`).
- **Auth** â€” email/phone + password (`password_hash`), hardened sessions
  (`HttpOnly`, `SameSite`, secure behind TLS, id rotation), CSRF protection and
  login rate-limiting.
- **Plans & trial** â€” Starter (KES 1,500 / 50 parcels / 5 seats), Growth
  (KES 3,500 / 300 / 15), Scale (KES 7,500 / 2,000 / 50). New workspaces start on
  a 14-day trial. The API enforces the monthly parcel quota (HTTP 402 with an
  upgrade link).
- **Billing** â€” plan changes create invoices; the demo M-Pesa settlement marks
  them paid, activates the subscription and writes an audit entry. Invoices and
  usage are visible at `/billing`.
- **Team & API keys** â€” add/remove staff (seat limits enforced), generate/revoke
  hashed API keys; keys are shown once.
- **User management** â€” `/admin` Users panel lists every account (5/page) with role,
  workspace, status and last login; create users (merchant/employee/freelancer/customer,
  with workspace assignment), suspend/activate (active sessions revoked instantly),
  reset credentials (temporary password / PIN / demo code) and soft-delete. Suspended
  or deleted users cannot sign in or request codes; the last platform admin and your own
  account are protected.
- **Platform admin** â€” `/admin` shows workspaces, plan status and pending invoices;
  suspend/activate workspaces, override plans, mark/void invoices, and inspect the
  audit log.
- **DB-backed sessions** â€” PHP sessions are stored in MySQL (`sessions`: user,
  tenant/user-agent/IP, idle + absolute expiry) and can be viewed/revoked from the
  admin console; logout deletes the row, and session lifecycle is in the audit log.
- **Transactions ledger** â€” every payment (subscription, pay-at-collection, manual
  settlement) is written to `transactions` inside the same DB transaction as the
  invoice/parcel update, so money and state commit atomically. Merchants see a
  Payments table on `/billing`; admins see a paged Transactions table.
- **Admin analytics (BI)** â€” `/admin` includes a 30/90/365-day dashboard: MRR,
  ARPA, trialâ†’paid conversion, revenue vs previous period, parcels per workspace,
  parcel GMV; charts for revenue (subscriptions + platform fees), volume, revenue
  mix, parcel status mix, carrier share and top workspaces. All aggregates are
  computed server-side from MySQL via `/api/admin-analytics.php`.
- **Public API v1** â€” `POST /api/v1/parcels.php` and `GET /api/v1/track.php?q=â€¦`
  with `X-API-Key`, tenant-scoped and rate-limited.
- **Parcel flows** â€” carrier/destination pickers, 3-step send wizard, QR labels,
  Locker IDs, fee quotes, scan-based tracking, errands, saved senders.

## Pages

| Route | Purpose |
|---|---|
| `/` | Marketing home: send/track, partners, FAQ |
| `/pricing` | Plans from MySQL + free-trial CTA |
| `/signup`, `/login` | Onboarding and authentication |
| `/profile` | Workspace dashboard: plan, usage, parcels, errands, senders |
| `/billing` | Subscription, usage meter, plan changes, invoices, demo payment |
| `/settings` | Business profile, team, API keys |
| `/admin` | Platform console (platform admin only) |
| `/freelancer` | Freelancer dashboard: account, PIN reset, assigned jobs |
| `/customer` | Customer portal: incoming parcels, pay at collection, collection code |
| `/send-mzigo`, `/send-errand` | 3-step parcel / errand wizards |
| `/track-mzigo` | Public tracking (tracking number, Locker ID, errand ref) |
| `/partners` | Courier catalog with route filters |

## Database

User classes are stored as `users.role` (`user`, `platform_admin`, `freelancer`)
plus tenant membership for merchant/employee roles; freelancers additionally use
`users.pin_hash` and customers use `users.otp_hash`/`otp_expires_at`. Parcels carry
`receiver_phone_key` (`payment_status`, `paid_at`, `payment_ref`, `paid_by_user_id`)
for customer lookups and pay-at-collection. Migrations `002_freelancers.sql` and
`003_customers.sql` add these.

Operational tables `sessions` (DB-backed sessions) and `transactions` (payments
ledger) are added by migration `004_sessions_transactions.sql`; user lifecycle
(`users.status`) is added by `005_user_status.sql`.

Core tables: `users`, `tenants`, `tenant_members`, `plans`, `subscriptions`,
`invoices`, `api_keys`, `audit_log`, `rate_limits`, `partners`, `destinations`,
`parcels`, `scans`, `errands`, `senders`, plus `schema_migrations`.

Run the installer (creates DB, base schema, migrations, catalog and SaaS seed â€”
idempotent):

```powershell
php _server\db\install.php
php _server\db\migrate.php --status    # migration state
php api\cron.php                       # trials / overdue invoices / rate limits
php _server\db\seed_demo_activity.php  # OPTIONAL: ~120 days of demo parcels/invoices for the BI charts
```

`seed_demo_activity.php` is demo-only: it tags rows (`TRK-DEMO*`, `ERD-DEMO*`,
`INV-DEMO-*`) and is idempotent. Do not run it on a production database.

## Quick verification

```powershell
curl.exe http://localhost:8090/api/health.php

& 'C:\xampp\mysql\bin\mysql.exe' --protocol=TCP -h 127.0.0.1 -P 3307 -u root -e `
  "USE mzigo_online; SELECT t.name, t.status, p.name AS plan, s.status AS sub FROM tenants t
   LEFT JOIN subscriptions s ON s.tenant_id = t.id LEFT JOIN plans p ON p.id = s.plan_id;
   SELECT tracking, locker, status, tenant_id, fee_total FROM parcels ORDER BY registered_at DESC LIMIT 8;
   SELECT number, amount_kes, status, reference FROM invoices ORDER BY issued_at DESC LIMIT 8;"
```

## API reference (cookie session unless noted)

| Endpoint | Method | Purpose |
|---|---|---|
| `/api/health.php` | GET | DB status, counts, MRR, recent parcels |
| `/api/bootstrap.php` | GET | session context (user/workspace/role/usage), catalog, CSRF |
| `/api/auth.php` | GET/POST | `me`, `signup`, `login`, `logout`, `switch-tenant`, `freelancer-start`, `freelancer-login`, `customer-start`, `customer-login` |
| `/api/register-parcel.php` | POST | register parcel (CSRF, tenant quota) |
| `/api/errands.php` | POST | book errand (CSRF) |
| `/api/track.php` | GET | public tracking lookup |
| `/api/profile.php` | GET | workspace parcels/errands/senders/usage |
| `/api/billing.php` | GET/POST | plans, invoices, `change_plan`, `pay_invoice` |
| `/api/settings.php` | GET/POST | business profile, team, API keys |
| `/api/admin.php` | GET/POST | platform metrics, tenants, invoices, audit |
| `/api/admin-analytics.php` | GET | BI aggregates + time series for `/admin` (platform admin) |
| `/api/customer.php` | GET/POST | customer's incoming parcels + `pay` at collection (customer only) |
| `/api/admin.php` | POST | includes `revoke_session` (admin session management) |
| `/api/plans.php` | GET | public pricing catalog |
| `/api/v1/parcels.php` | GET/POST | public API (X-API-Key) create/list/track |
| `/api/v1/track.php` | GET | public API tracking (X-API-Key) |

## Running locally

MariaDB must be running (XAMPP control panel â†’ MySQL â†’ Start, or
`C:\xampp\mysql_start.bat`; it listens on **3307** on this machine).

```powershell
# dedicated Apache preview on :8090 (XAMPP's port 80 is untouched)
& 'C:\xampp\apache\bin\httpd.exe' -f 'C:\xampp\htdocs\www\mzigo\_server\httpd.conf' -D FOREGROUND
```

or `_server\start-preview.cmd`. Logs: `_server/logs/error.log` (Apache) and
`_server/logs/php-error.log` (PHP).

## Layout

```
index.html â€¦ admin.html        static pages (clean URLs via .htaccess)
assets/                        css, js (site.js, data.js, qrcode.js), fonts, images
api/                           PHP API (multi-tenant SaaS endpoints + v1 public API)
_server/
  config.php                   app/db/payments/mail/security config (env overrides)
  db-config.php                DB credentials (denied over HTTP)
  db/schema.sql                base schema
  db/migrations/               versioned migrations (schema_migrations)
  db/install.php|migrate.php|seed_saas.php|seed.sql
  bin/backup.ps1               dump + 14-day retention
  httpd.conf, logs/            dedicated preview server
deploy/                        Dockerfile, compose, entrypoint, Apache/nginx configs
docs/PRODUCTION.md             deploy, payments, security, scaling runbook
_raw/                          crawl captures + build scripts (HTTP-denied, not deployed)
```

## Rebuilding the front-end

```powershell
python _raw\tools\build_assets.py   # CSS bundle, fonts, images, fallback data.js
python _raw\tools\build_pages.py    # static pages + 404 + manifest
```

## Production

See **[docs/PRODUCTION.md](docs/PRODUCTION.md)** for the full runbook:
Docker/vhost deployment, environment variables, install/upgrade, demo-account
removal, M-Pesa and email integration seams, cron/backups, security checklist and
the scaling path.

```bash
cp .env.example .env
docker compose -f deploy/docker-compose.yml up --build -d
```

## Notes

- **Freelancer PINs & customer codes:** the demo always issues `1234` (`demo_mode`).
  With demo mode off, random codes are generated and must be delivered by SMS;
  the delivery seam and rate-limit settings are in `docs/PRODUCTION.md`.
- **Charts:** the admin BI dashboard uses a locally vendored Chart.js 4.4.7
  (`assets/js/vendor/chart.umd.min.js`, MIT). It is lazy-loaded only on `/admin`;
  if it fails the KPI cards remain and each chart shows a fallback message.
- **Branding:** every shipped reference is Mzigo.online; the build reapplies it.
- **Security scope:** this is a demo-to-production starting point. The API uses
  prepared statements, tenant-scoped queries, CSRF, rate limits and server-side
  quota/pricing, but still needs TLS, rotated secrets, real payment/email
  providers and the items in the production checklist before public launch.
- `_raw/` holds the original crawl captures, build scripts and comparison
  screenshots; it is denied over HTTP and excluded from container builds.

- **Order notifications** — registering a parcel with a receiver phone looks the number up, creates a customer account when new, and sends tracking via the customer's chosen channel (SMS/WhatsApp/Email); channels are toggled by the admin and all are active by default. Attempts are logged in 
otifications and /admin shows the channels + message log.


- **Pricing controls** — /admin can switch the platform between **Paid plans** and **Free** with one toggle, and edit each plan card's amount, parcel/team limits, package content and active visibility. Free mode suppresses new invoices, makes plan changes immediate and **hides the public pricing page** (/pricing redirects to /signup, all pricing nav/footer links are hidden); /billing shows a free-mode notice.


- **Admin console IA** — /admin uses a sidebar with hash-routed sections: Analytics (KPIs + charts), Workspaces, Invoices, Transactions, Sessions, Users, Communications, Activities and Settings (pricing & platform). Badges show pending invoices, active sessions/users and message counts; sections are deep-linkable (/admin#users) and collapse to a horizontal tab bar on mobile.



- **Stage A integrity pass** — staff see read-only Billing/Settings (no plan, pay, team or API-key actions, and API keys are hidden server-side); suspended workspaces show a banner, block sending and revoke member sessions; free mode hides invoice payment CTAs and signup skips the trial (active immediately); trial expiry now offers a plan→pay→reactivate path; team invitations show a persistent credential panel instead of a transient toast.


- **Stage B merchant dashboard & orders** — signed-in merchants now get a business dashboard at /profile (plan/quota/trial, usage, quick actions, recent parcels/errands) instead of the public profile tabs. A new /orders page supports Parcels/Errands tabs, search, status/date filters, status chips with counts, 10-per-page pagination, CSV export, and printable shipping labels/receipts (QR included).


- **Mobile UI** — responsive mobile pass: dashboard actions/quick tiles in 2-column grids, equal-height stat cards, consistent recent-order rows; /orders uses a grid toolbar, scrollable status chips and card-based orders (no clipped tables); billing and settings replace wide tables with mobile cards; bottom navigation respects safe-area padding and is hidden on auth pages; admin nav scroll-snaps horizontally.


- **Stage C billing lifecycle** — billing details (name, email, address, KRA PIN, VAT rate) editable by owners/admins; invoices snapshot subtotal/VAT/billing details and open as printable/downloadable documents; subscriptions support cancel-at-period-end, resume, and downgrade scheduling with explicit policy (upgrades billed immediately, downgrades/cancellations at period end, no mid-cycle cash refunds); admins can record refunds (invoice → refunded + refund transaction in the ledger).


- **Stage D claims, returns & integrations** — merchants can file lost/damaged/delayed claims on their parcels and withdraw them; admins review, approve/reject and mark payouts (claim_payout ledger). Orders support parcel cancellation and return-to-origin. Billing gains monthly settlement statements (CSV/print). Settings adds signed webhooks (7 events, HMAC-SHA256) with delivery status, and API keys now carry read/write scopes enforced by the public API.


- **Claims Phase 1 hardening** — claims now enforce eligibility windows (loss 7–30 days, damage at destination/24h after collection, delay 3–30 days), value caps by parcel tier/declared value, mandatory evidence for loss/damage, duplicate/receiver/workspace/30-day value limits, mandatory decision reasons, and a 48-hour payout hold before a ledger-only payout. Evidence and hold dates are visible to merchants and admins.


- **Claims Phase 2 governance** — claims above KES 5,000 require two different admin approvals; approvals keep the 48h payout hold and dual-approved payouts must be released by a different admin. Claims have a detail modal with event timeline, parcel scan trail, attachments and approval history. Evidence files (JPG/PNG/PDF, max 5MB, up to 5) upload to private storage and download through an authorised endpoint. 5-day SLA, one appeal within 7 days of rejection, payout reversal (refund ledger) and carrier/insurer recovery tracking.


- **Data normalization & live-DB-only reads** — all parcel journeys were normalized so scan timestamps are chronological by scan order (0 out-of-order/future scans, statuses and registration times aligned; 131 timestamps repaired, backup in `_raw/backups/`). The front end no longer falls back to any bundled catalog or demo records: catalog, workspaces, parcels and demo lookups come only from live MySQL via `api/bootstrap.php`; when the database is unreachable the app clears data and shows a live-database warning instead of displaying fake records. Claims detail and timelines report `source: mysql` and read `claim_events`/`scans` directly.

- **Claims Phase 3 — customer reporting, risk & analytics** — receivers can report lost/damaged/delayed parcels from the customer portal after a phone OTP verification (6-digit, 10-minute expiry, rate-limited). Customer claims carry a computed risk score/flags, notify the workspace owner, and appear in the merchant/admin claims queues. Admins get claim analytics, risk scores, carrier/insurer-style blacklist controls (phone/user/workspace) and linked-claim context; claim status changes notify both the customer and workspace owners. Customer claim detail reuses the DB-backed timeline and scan trail.

- **Claims Phase 4 — collaboration, notification inbox & operations** — claims now have a participant message thread (customer, merchant, admin) with notifications, customer evidence file uploads (JPG/PNG/PDF, stored privately), a per-user notification inbox with mark-read, SLA escalation and blacklist-expiry tasks in the cron job, and an admin claims CSV export. Merchant claims page shows an unread notification inbox; customer portal notifications support mark-all-read.

- **Account recovery & demo visibility** — admin and merchant/business accounts can reset passwords via a single-use, 60-minute emailed reset link (customers/freelancers continue to use code/PIN sign-in). Reset requests are generic to avoid account enumeration and resetting signs out all existing sessions. Admin → Website now has a **Hide/Show demo accounts** toggle for the /login credentials box (stored in `platform_settings.show_demo_accounts`); the page can be shared with real users without exposing demo credentials.

- **Stage E security** — real SMTP delivery through the admin-configured email channel (verified against a local SMTP server), email verification on signup with 24h tokens/resend/verify page, TOTP two-factor authentication for admin and business accounts (login challenge + settings setup/disable), merchant session management (list/revoke own sessions), and an API usage dashboard (daily requests per key, last 30 days) in Settings.

- **HTML email journey** — every transactional email now uses a branded, responsive HTML layout (plain-text alternative included): welcome/verify, password reset/changed, parcel registered, status updates, ready for collection, payment receipt with collection code, claim OTP/reported/status/message/SLA, invoice paid, team invite and SMTP test. Customer journey emails fall back to a workspace-owner business copy when the receiver has no email on file. Admin → Communications has an SMTP delivery test; SMTP encryption/from can also be set with `SMTP_ENCRYPTION`/`SMTP_FROM`.

- **Encrypted communication-channel credentials** — provider configs (SMTP passwords, API keys, tokens) are stored in MySQL as AES-256-GCM ciphertext (`enc:v1:…`) using an application key held outside the database (`_server/config-key.php` or `APP_CONFIG_KEY`). Admin responses mask credential fields; saving masked values preserves the stored secret. `_server` and `_raw` are blocked from web access, and a maintenance script encrypts legacy plaintext channel configs.

- **Admin Communications layout & journey template catalogue** — Communication channels and Notifications log now sit side by side in equal two-column cards, with the SMTP test above and a full-width “Customer journey communication templates” table below. The table lists every end-to-end template (stage, recipient, trigger, sent attempts, last sent) and is driven by `api/_comms_catalog.php` plus live notification counts.

- **Editable journey templates & equal-height Communications** — the two Communications panels now stretch to the same height. The Customer journey templates table supports View, Edit and Add: subject/plain-text/full-HTML overrides are stored in MySQL (`email_templates`), seeded from the system catalogue, and applied to outgoing email with placeholders (`{{name}}`, `{{tracking}}`, `{{status}}`, `{{amount}}`, `{{link}}`, etc.). System templates can be edited or disabled; custom templates can be added and deleted.

- **All journey templates generated in MySQL** — every customer-journey template now has a subject, plain-text body and HTML content stored in `email_templates`, so it can be used and edited from Admin → Communications → Customer journey communication templates. Content is seeded/refreshed with `php _server/db/seed-email-template-content.php`; placeholders (`{{name}}`, `{{tracking}}`, `{{status}}`, `{{amount}}`, `{{link}}`, `{{track_url}}`, `{{code}}`, etc.) are rendered at send time and HTML fragments are wrapped in the branded Mzigo layout.

- **Security & deliverability features** — customer email capture + notification category preferences; hashed one-time 2FA recovery codes; signed bounce/complaint webhook with automatic email suppression and an admin deliverability panel; encrypted webhook signing secrets with exponential-backoff retries via cron; ClamAV/heuristic attachment malware scanning; and an encryption-key rotation CLI. Migrations 017–020.

- **Platform-wide API usage aggregate** — Admin → Analytics now shows a platform API usage card with 30-day/7-day/today request totals, active workspaces and keys, 30-day daily bars, top workspaces and top API keys, aggregated from `api_usage_daily`.

- **Security review & hardening** — see `docs/SECURITY.md` for the full review, findings and production checklist. Critical fixes include public health/tracking data redaction, environment-gated demo mode, safe login redirects, webhook SSRF blocking in production, abuse rate limits and CSP/security-header hardening.

- **Partner scan points + PWA scanner** — Admin → Scan points creates carrier drop-off/handover/arrival/collection points with one-time access tokens. The installable phone scanner at `/scan` performs idempotent scans, validates carrier/point type, requires payment + collection code for collection, records point/source metadata and triggers customer notifications/webhooks. Migration 021.

- **Claim payout & recovery rails** — Admin → Settings configures demo/sandbox/live M-Pesa B2C payouts and generic insurer/carrier recovery webhooks. Provider credentials and callback secrets are encrypted at rest; payouts/recoveries have records, signed callbacks, idempotency and claim-detail history. Manual payout/recovery remains available as a reconciliation fallback.

- **Per-tenant branding & rate cards** — workspace Settings now supports logo upload, brand colours, support contacts and document footers. Branding applies to the account menu, authenticated dashboards and invoice documents, with a public-safe branding endpoint. Tenant carrier/route/size rate cards override platform quote components for sending flows.

- **Webhook delivery UX + idempotency** — outbound events now carry `event_id` and optional `idempotency_key` in the signed payload and headers. Merchant Settings shows delivery history (event ID, status, attempts, next retry, response) with a manual Retry now action; duplicate dispatches with the same key are suppressed and retries reuse the original payload.

- **Email provider adapters** — email delivery supports SMTP, SendGrid Web API v3 and Mailgun, with admin-configured API credentials. Bounce/complaint webhooks are provider-specific: Mailgun timestamp+token HMAC, SendGrid Event Webhook ECDSA, and the generic Mzigo HMAC endpoint. Hard bounces and complaints feed the suppression list.

- **Automated security regression suite** — `tests/security/run.php` covers security headers, health/tracking redaction, authz and tenant isolation, CSRF, login rate limits, attachment scanning, webhook SSRF and unsigned email webhook rejection. See `tests/security/README.md`.

- **Public API v1 documentation** — [docs/API_V1.md](docs/API_V1.md) documents authentication, scopes, parcel creation/listing/tracking, errors, rate limits and webhooks; machine-readable spec at [docs/openapi/v1.yaml](docs/openapi/v1.yaml). API v1 now applies tenant rate cards.

- **Symmetric page gutters** — content shells now reserve equal left/right scrollbar space on tablet/desktop with `scrollbar-gutter: stable both-edges`, keeping page margins visually symmetric.

- **Runner proof, matching & incentives** — receiver OTP delivery proof, distance/size matching filters with errand size/distance capture, and configurable rating/volume bonuses in runner payouts. **SEO-04/05:** partner route landing pages (`/partners/{slug}`), Search Console/Bing verification fields and real-user Core Web Vitals collection; see [docs/SEO_MONITORING.md](docs/SEO_MONITORING.md).
