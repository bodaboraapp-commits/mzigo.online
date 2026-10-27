# Mzigo.online â€” production runbook

This document covers taking the local SaaS build to a real deployment.

## 1. Architecture

```
             TLS / CDN (optional)
                    â”‚
        â”Œâ”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â–¼â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”
        â”‚  Apache or nginx       â”‚  static pages + /api/*.php
        â”‚  PHP 8.2 (mod_php/FPM) â”‚
        â””â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”¬â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”˜
                    â”‚ PDO (prepared statements)
        â”Œâ”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â–¼â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”
        â”‚  MariaDB / MySQL      â”‚  mzigo_online
        â””â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”˜
```

- **Tenants** are business workspaces. Every parcel/errand/sender carries a
  `tenant_id`; users join workspaces through `tenant_members` with
  `owner` / `admin` / `staff` roles.
- **Platform admins** (`users.role = platform_admin`) use `/admin`.
- **Plans** (`starter`, `growth`, `scale`) define a monthly parcel quota and a team
  seat limit. `subscriptions` tracks trial/active/past_due/cancelled state and
  `invoices` tracks billing. Limits are enforced server-side.
- **Public API v1** (`/api/v1/parcels.php`, `/api/v1/track.php`) authenticates with
  `X-API-Key`; keys are stored hashed and are tenant-scoped.
- **User classes:** platform admin (`users.role`), merchant owner and employee
  (`tenant_members.role`), freelancer (`users.role = 'freelancer'`) and customer
  (`users.role = 'customer'`). Freelancers authenticate with a phone number + a
  system-generated 4-digit PIN (`users.pin_hash`); customers authenticate with the
  delivery phone number + a 4-digit code (`users.otp_hash`, 10-minute expiry) and can
  pay the delivery fee at collection (`parcels.payment_status`). Demo mode pins every
  code to `1234`; with `demo_mode` off codes are random and must be delivered by SMS.

## 2. Requirements

| Component | Version |
|---|---|
| PHP | 8.2+ with `pdo_mysql`, `mbstring`, `openssl` |
| MariaDB / MySQL | 10.4+ / 8.0+ |
| Web server | Apache 2.4 with `rewrite`, `headers`, or nginx + PHP-FPM |
| TLS | Required for production (session `Secure`, HSTS) |

## 3. Configuration

All settings come from `_server/config.php` with environment-variable overrides
(see `.env.example`):

| Variable | Purpose |
|---|---|
| `APP_ENV`, `APP_BASE_URL` | environment + canonical URL used in links/QRs |
| `DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASS` | MariaDB connection |
| `PLATFORM_ADMIN_EMAIL`, `SUPPORT_EMAIL` | contact addresses |
| `PAYMENT_PROVIDER` | `demo` (simulated) or `mpesa` (integration seam) |
| `MAIL_DRIVER` | `log` (writes `_server/logs/mail.log`) or `smtp` |
| `MPESA_*`, `SMTP_*` | provider credentials |

Recommended production DB user: a dedicated account with only
`SELECT, INSERT, UPDATE, DELETE` on `mzigo_online` (not `root`).

## 4. Deploy

### Option A â€” Docker Compose (fastest)

```bash
cp .env.example .env          # then edit secrets + APP_BASE_URL
docker compose -f deploy/docker-compose.yml up --build -d
docker compose -f deploy/docker-compose.yml logs -f app
```

The entrypoint waits for MariaDB, runs `_server/db/install.php`, then starts
Apache. Health: `GET /api/health.php`.

### Option B â€” Apache vhost

1. Point `DocumentRoot` at the project root (see `deploy/apache-vhost.conf`).
2. Enable `rewrite`, `headers` and PHP (module or FPM).
3. `AllowOverride All` so `.htaccess` clean URLs work.
4. Deny `_raw/`, `_server/` and `api/_*` over HTTP.
5. Run the installer once:

```bash
php _server/db/install.php
```

### Option C â€” nginx + PHP-FPM

Use `deploy/nginx.conf` as a starting point; it mirrors the clean-URL rules and
the blocked folders.

## 5. First-run checklist

1. `php _server/db/install.php` completes and `/api/health.php` returns
   `"ok": true`.
2. Sign in as the platform admin (`admin@mzigo.online` / `Admin123!` in the demo
   seed) and **change/delete the demo accounts**. Do **not** run
   `_server/db/seed_demo_activity.php` in production â€” it only exists to populate
   the local BI charts with demo history.
   - rotate the admin password (or create your own admin user and demote the demo one),
   - remove demo workspaces (`Amina Crafts`, `Sokoni Traders`) and demo parcels
     `TRK-4WEWFH1L`, `TRK-9QK2M8D3`.
3. Confirm `APP_ENV=production`, `APP_BASE_URL` uses `https://â€¦`.
4. Confirm the session cookie is `Secure` (automatic when HTTPS is detected â€”
   check `X-Forwarded-Proto` if behind a proxy/load balancer).

## 6. Scheduled jobs

| Job | Command | Schedule |
|---|---|---|
| Subscription lifecycle (expire trials, suspend overdue invoices, prune rate limits) | `php api/cron.php` | daily, e.g. 02:00 |
| WhatsApp queue worker | `php _server/cron/whatsapp_queue.php` | every minute |
| Database backup | `_server/bin/backup.ps1` (or `mysqldump`) | daily |
| Off-host copy of backups | your storage tooling | after backup |

Docker example:

```bash
docker compose -f deploy/docker-compose.yml run --rm cron
```

## 7. Payments

`PAYMENT_PROVIDER=demo` settles invoices immediately (used by the UI's
â€œPay with M-Pesaâ€ button) and records a reference. To go live:

1. Add Daraja credentials to the environment.
2. Replace the settlement block in `api/billing.php` (`action=pay_invoice`) with a
   **STK push** that creates a `pending` payment intent and returns; do not mark
   the invoice paid from the client.
3. Add `api/webhooks/mpesa.php` that verifies the callback signature, dedupes on
   `CheckoutRequestID`, marks the `invoices` row paid, activates the
   `subscriptions` row and writes the audit entry.
4. Add reconciliation (query STK transaction status for pending invoices after
   N minutes) and alerting.

## 8. Email

`MAIL_DRIVER=log` writes rendered messages to `_server/logs/mail.log` (useful for
local testing). For production set `MAIL_DRIVER=smtp` and supply SMTP settings, or
replace the mail helper with your provider (SES, Postmark, â€¦). Templates needed:
welcome, trial ending, invoice paid, payment failed.

## 8a. Freelancer PIN / customer code delivery (production)

`POST /api/auth.php {action:'freelancer-start'}` creates/finds the freelancer and
writes a bcrypt hash of the 4-digit PIN; `{action:'customer-start'}` does the same
for a customer with a 10-minute `otp_hash`. In production:

1. Set `DEMO_MODE=0` in the production `.env` (or `APP_ENV=production`).
2. Configure the **SMS** channel with the Infobip provider in Admin → Communications
   (base URL, API key, approved sender ID) and send a test SMS.
3. The customer sign-in code and runner PIN are now delivered by SMS through
   `api/auth.php` → `api/_sms.php`; never return the production code in the response.
4. Keep/raise the `freelancer-start` / `customer-start` (10/5 min) and
   `freelancer-login` / `customer-login` (6/5 min) rate limits and alert on failures.
   For customers, replace the demo M-Pesa settlement in `api/customer.php` with a
   Daraja STK push + callback that flips `parcels.payment_status` to `paid`.
4. Require PIN rotation after N days if your risk model needs it; the reset path is
   the same `freelancer-start` action.

## 9. Security checklist

- [ ] HTTPS everywhere; enable HSTS in the web-server config.
- [ ] Change the platform-admin password; remove demo tenants/parcels.
- [ ] Dedicated DB user with least privilege; strong password; DB port not public.
- [ ] `APP_ENV=production`, `display_errors` stays off (already set in the API bootstrap).
- [ ] Backups tested by restoring into a scratch database.
- [ ] Review rate limits (`config.security`) and reverse-proxy IP handling.
- [ ] Keep `_server/`, `_raw/`, `api/_*.php` blocked by the web server.
- [ ] Monitor `_server/logs/php-error.log`, `api/health.php`, failed logins.
- [ ] Rotate API keys when staff leave (Settings â†’ API keys).
- [ ] Session cookie: `HttpOnly`, `SameSite=Lax`, `Secure` behind TLS.

## 10. Scaling path

- PHP is stateless (sessions in files by default). For multiple app nodes, switch
  sessions to Redis or DB-backed sessions.
- MariaDB primary + read replica; keep money/invoice writes on the primary.
- Move static assets to a CDN (`assets/css`, `assets/fonts`, images).
- Queue notifications (Redis/BullMQ or a DB queue worker) â€” do not block requests.
- Partition `scans` by month when volumes grow; keep indexes
  `(subject_type, subject_id, scanned_at)`.
- Object storage for QR labels, parcel photos and claim evidence (not yet built).

## 11. Roadmap / known gaps

Implemented in this build: multi-tenancy, roles, plans/trial/quota enforcement,
invoices with demo settlement, admin console, team members, API keys, public API,
audit log, migrations, health check, Docker/vhost deployment.

Not yet production-complete:

- real M-Pesa STK + callback + reconciliation (see Â§7)
- transactional email (welcome, dunning)
- password reset / email verification / 2FA
- claims workflow and insurance pool (see the engineering design doc)
- transporter/point apps and scan ingestion endpoints
- tenant branding (colours/logo) and per-tenant rate cards
- webhooks to merchant systems, export/reporting

## Notifications

Channels (SMS/WhatsApp/Email) are toggled in /admin and default to active. Customers choose a channel in /customer (users.comm_channel, NULL = all active). Replace the log provider in pi/_notifications.php with real SMS/WhatsApp/email gateways; keep every attempt in 
otifications for delivery audit and reconciliation.


## Pricing controls

Platform mode (platform_settings.pricing_mode: paid/ree) and per-plan values are admin-managed in /admin. Free mode blocks new invoice collection (422 platform_free), plan switches take effect without billing, and the public pricing page is gated by pi/pricing-gate.php (302 → /signup) with pricing links hidden; existing invoices remain historical. Paid mode restores the page. All pricing edits and mode changes are audited (dmin.pricing_mode_changed, dmin.plan_edited).



## Workspace lifecycle (Stage A)

Suspending a workspace revokes members' sessions and shows a reactivation banner; staff are read-only for billing/settings (API keys are never returned to staff). Free mode hides pricing/payment CTAs, and signup creates active workspaces (no trial) while cron converts expired trials to active instead of suspending. Trial expiry: choose a plan, settle the invoice, workspace activates immediately.


## Merchant orders & documents (Stage B)

GET /api/orders.php serves tenant-scoped parcels/errands with search/status/date filters, paging and ormat=csv export (CSV bypasses paging and uses the same filters). /orders renders both tabs with printable label/receipt popups (client-side, QR via vendored qrcode.js). Access is limited to workspace owner/admin/staff; suspended workspaces remain readable but cannot create orders.


## Billing lifecycle (Stage C)

Plan prices are VAT-inclusive; invoices store subtotal/tax/tax rate and a billing snapshot (tenant billing name/email/address/KRA PIN) at issue time. /api/invoice-doc.php?invoice=ID[&download=1] renders a print/save-as-PDF invoice/receipt for workspace members. Upgrades invoice immediately; downgrades and cancellations are scheduled for the current period end and applied by pi/cron.php; resume/cancel-scheduled actions are audited. Admin refunds are manual: paid invoice → 
efunded + a 
efund ledger transaction (no automatic M-Pesa refund yet).


## Claims, returns & integrations (Stage D)

Claims (claims table): merchant owner/admin files lost/damaged/delayed claims against a workspace parcel; admin transitions open → under_review → approved/rejected → paid, with a claim_payout transaction recorded on payout. Orders POST supports cancel_parcel (CREATED/AT_ORIGIN_POINT) and 
equest_return (READY_FOR_COLLECTION/ARRIVED_DEST_POINT), each writing a scan and webhook event. Settlement statements (/api/settlements.php) summarise monthly delivery/platform/insurance fees, collections, pending amounts and subscription payments, with CSV. Webhooks (webhooks/webhook_deliveries) deliver signed JSON (HMAC-SHA256, X-Mzigo-Signature) and track status; API keys carry 
ead/write scopes enforced on /api/v1/*. Verify webhook URLs are HTTPS with retry/idempotency in production; provider refunds remain manual; claim payouts support demo/sandbox/live M-Pesa B2C plus manual reconciliation, and recovery supports signed insurer/carrier webhooks plus manual fallback.


## Claims controls (Phase 1)

Eligibility: loss only after 7 days (30-day window, not collected); damage only at destination or within 24h of collection; delay after 3 days (not collected); all claims within 30 days. Caps: min(declared value, Tier A 10k / Tier B 50k). Evidence text/reference required for loss/damage. Abuse limits: max 5 active claims/workspace, 2 claims per receiver phone/30 days, KES 50k claimed value/30 days per workspace, max 2 claims per parcel/30 days. Admin decisions require a reason; approval starts a 48h payout hold; payouts are only released after the hold and are capped at min(parcel value, tier cap) and written to the ledger. Phase 2 adds maker-checker/dual approval, evidence files and a claim detail/SLA view; Phase 3 adds OTP-verified customer reports and risk scoring.


## Claims governance (Phase 2)

Dual approval: claims valued above KES 5,000 need two different platform admins to approve; the same admin cannot provide both approvals. Payouts remain held 48h after final approval, and for dual-approved claims a different admin must release the payout. Evidence: up to 5 JPG/PNG/PDF files (max 5MB each) per claim in _server/uploads/claims (outside web root, streamed via /api/claim-file.php with tenant/admin authorisation); text references remain required for loss/damage. Claim detail (/api/claim-detail.php) exposes the event timeline, parcel scan trail, attachments and approvals to the owning workspace or platform admins. SLA is 5 days to decision; rejected claims can be appealed once within 7 days. Paid claims can be reversed (refund transaction) and carrier/insurer recovery is tracked (
ecovery_status/reference/amount). Production should add malware scanning for uploads, email/SMS notifications on claim events, insurer/carrier API integration, and periodic reconciliation of recoveries.


## Live-database-only data policy & scan normalization

All application data is read from MySQL at runtime: the client `hydrate()` loads catalog, workspaces, parcels, errands, senders and demo lookups exclusively from `/api/bootstrap.php` (`source: mysql`). Bundled fallback catalogs and offline demo parcel arrays were removed; if the database is unreachable the UI clears state, sets `data-mz-source="offline"` and shows a live-database warning rather than rendering stale/demo records. Claims timelines read `claim_events` and parcel scan trails read `scans` (both exposed by `/api/claim-detail.php` with `source: mysql`). Scan timestamps are normalized so each append-only journey is chronological; run the normalization/audit before restoring demo data or importing historical scans. The pre-normalization backup is `_raw/backups/mzigo-before-normalize.sql`.

## Customer claims, risk & blacklist (Phase 3)

Customers can report parcel issues from `/customer`: request a 6-digit OTP (10-minute TTL, max 5 attempts, 3 requests/hour, demo mode returns the code), then submit a claim. Customer claims require evidence references for loss/damage, are capped by declared value/tier, respect per-parcel/per-receiver/30-day limits, and are stored with `claimant_role='customer'`, `risk_score` and `risk_flags`. Creating a customer claim notifies workspace owners (in-app fallback when external channels are disabled) and emits `claim.created`; admin approve/reject/paid/reversed decisions notify the customer and owners. Admin Claims now includes analytics (totals, paid/recovered, high risk, average decision time, monthly history), a risk/blacklist panel (`claim_blacklist` by phone/user/tenant, active-expiry aware) and linked claims by receiver/workspace in the claim detail. Blacklisted claimants get `403 claim_blacklisted` at OTP/file time. Demo OTP codes are only returned when `app.demo_mode` is true; production sends them through the configured SMS/WhatsApp channel.

## Claim collaboration, notification inbox & operations (Phase 4)

Claim participants can message each other on a claim thread (`claim_messages`); every message notifies the other participants (customer + workspace owners) and is recorded in the claim timeline. Customers and merchants can upload evidence files (JPG/PNG/PDF ≤5MB, max 5/claim) which are stored outside the web root and streamed through the authorised `/api/claim-file.php`. Notifications now have `read_at` state: `/api/notifications.php` lists a user's inbox and supports `mark_read`/`mark_all_read`; the customer portal and merchant claims page expose unread counts. The cron job escalates overdue claim SLAs once (event + owner notification) and deactivates expired blacklist entries; the admin claims panel exports CSV via `/api/claim-export.php`. Production should run cron daily, scan uploads for malware, and keep notification/email providers enabled for real delivery (in-app rows are still recorded when channels are disabled).

## Password reset & login demo visibility

Password reset is available only to `platform_admin` and `user` (merchant/business) accounts via `/forgot-password` and `/reset-password?token=…`. Tokens are 32-byte random values stored as SHA-256 hashes in `password_resets`, expire after 60 minutes, are single-use, and request/attempts are rate-limited. A successful reset revokes all existing sessions for the account. Email is sent through the notification service's email channel (log provider in demo mode); in demo mode the reset link is also returned to the requesting page for testing. Customers and freelancers keep phone + code/PIN sign-in and cannot request an emailed password reset. Admin → Website exposes a demo-accounts visibility toggle (`platform_settings.show_demo_accounts`, default visible); when hidden, the credentials box is removed from `/login`. Production should configure a real SMTP/email provider and keep `api/cron.php` scheduled; monitor reset requests and review `audit` entries for `auth.password_reset_*`.

## Stage E — security operations

**SMS (Infobip):** The SMS channel supports Infobip via `api/_sms.php`. Customer codes,
runner PINs, claim OTPs, delivery OTPs and parcel notifications use the active SMS
channel. Configure the provider, base URL, API key and approved sender ID in
Admin → Communications, then use **Send test SMS**. See
`docs/infobip-sms-integration.md`.

**SMTP:** `assets/js` send flows now deliver email through `api/_mailer.php`, which reads the email channel in Admin → Communications (provider `smtp`, config host/port/username/password/encryption, sender) and falls back to environment `MAIL/SMTP_HOST` values. Status and errors are stored on `notifications` (`sent`/`failed`) and `_server/logs/mail.log`. A local SMTP capture test verified real message delivery. Configure the email channel before relying on password-reset or verification emails; demo mode still exposes reset/verification links for testing. Only SMTP transport is implemented — SendGrid/Mailgun API providers remain a seam.

**Email verification:** new signups get a 24-hour single-use verification token (`email_verifications`), delivered by email and surfaced as a dashboard banner with resend; `/verify-email?token=…` confirms. Existing accounts were backfilled as verified in migration 015. Verification is advisory (login is not blocked).

**2FA:** TOTP (SHA-1, 6 digits, 30s, ±1 window) for `platform_admin` and `user` accounts. Setup/enable/disable via `/api/2fa.php`; merchant setup is in Settings, admin setup in Admin → Settings. When enabled, password login returns `twofa_required` and a second `/api/auth.php` `verify_2fa` step issues the session. Secrets are stored in `user_2fa`; production should encrypt them and add recovery codes.

**Sessions:** `/api/sessions.php` lets any signed-in user list active sessions and revoke individual or all other sessions; Settings exposes this for merchants (admins already have the platform Sessions console).

**API usage:** each `/api/v1/*` call increments `api_usage_daily` per key/day; Settings shows 30-day requests per key and a total. Production should add platform-wide usage/rate-limit dashboards.

## HTML transactional email journey

All email is branded HTML with a plain-text alternative (`api/_email_templates.php`, multipart via `_mailer.php`). Templates: welcome/email verification, password reset/changed, email verified, parcel created (new/existing customer), parcel status, ready for collection, collected, cancelled, returned, payment receipt/collection code, claim OTP, claim reported/approved/rejected/paid/reversed/message/SLA, invoice paid, team invite and SMTP test. Customer status/receipt emails go to the customer when an email exists; otherwise a business copy goes to the workspace owner so journey updates are not lost. Configure the email channel in Admin → Communications (provider `smtp`, host/port/username/password/encryption/sender) or with `MAIL_DRIVER`/`SMTP_HOST`/`SMTP_PORT`/`SMTP_USER`/`SMTP_PASS`/`SMTP_ENCRYPTION`/`SMTP_FROM`; Admin → Communications → SMTP delivery test sends a real test email and reports sent/failed. Demo log mode records emails but does not leave the machine.

**Journey triggers:** parcel registered (register API/dashboard), status advance (Orders → Advance), customer payment receipt, order cancellation/return, claims, billing invoice paid, team invite and account security emails. Receiver email is optional today; capturing customer emails is recommended for direct customer delivery.

## Communication-channel credential encryption

`communication_channels.config` is encrypted at rest with AES-256-GCM (`api/_crypto.php`, prefix `enc:v1:`). The encryption key comes from `APP_CONFIG_KEY` or `_server/config-key.php` (never stored in MySQL; add it to your secret manager/backups separately). Admin channel API responses mask password/token/API-key fields (`••••••••`); leaving a masked value unchanged preserves the existing secret, and new saves re-encrypt the full config. Existing plaintext configs can be migrated with `php _server/db/encrypt-channel-configs.php`. `_server` and `_raw` are denied by `.htaccess`; verify direct requests return 403. Rotating the key requires decrypting/re-encrypting configs (planned tool) — keep the old key available until migration completes. Never commit `_server/config-key.php`.

## Admin Communications layout & template catalogue

Admin → Communications shows the SMTP delivery test above an equal two-column layout: Communication channels on the left and Notifications log on the right, followed by a full-width Customer journey communication templates table. The catalogue (`api/_comms_catalog.php`) documents each template's stage, recipient, trigger and live attempt/sent counts from `notifications`. Email-verified and password-changed confirmation emails are now sent as part of the account journey.

## Editable customer-journey templates

Admin → Communications now shows Communication channels and Notifications log in equal-height panels. The Customer journey templates table has View/Edit actions and an Add template button. Templates are persisted in `email_templates` (migration 016), seeded from `api/_comms_catalog.php`, and support subject, plain-text and full-HTML overrides plus an active flag. `notification_send()` applies active overrides to email and substitutes placeholders: `{{name}}`, `{{email}}`, `{{tracking}}`, `{{status}}`, `{{amount}}`, `{{reference}}`, `{{link}}`, `{{claim_ref}}`, `{{note}}`, `{{business}}`, `{{code}}`. Empty HTML falls back to the branded system layout; system templates cannot be deleted (edit/disable instead). Custom templates are stored for future/manual triggers.

## Generated journey template content

All 22 customer-journey templates are populated in `email_templates` with subject, plain-text and HTML content and can be viewed/edited in Admin → Communications. Run `php _server/db/seed-email-template-content.php` to (re)generate the default content; it is idempotent and only updates content, not custom templates. At send time `notification_send()` loads the DB template, applies the active subject/text/HTML overrides, substitutes placeholders (`{{name}}`, `{{email}}`, `{{tracking}}`, `{{status}}`, `{{amount}}`, `{{fee}}`, `{{reference}}`, `{{code}}`, `{{link}}`, `{{track_url}}`, `{{customer_url}}`, `{{claims_url}}`, `{{billing_url}}`, `{{login_url}}`, `{{password}}`, `{{invoice}}`, etc.), and wraps HTML fragments in the branded layout. Empty overrides fall back to the built-in layout for that template. Editing a template does not affect already-sent messages.


## Security, deliverability & recovery operations

### Customer email and notification preferences
Customers can add/change an email in the customer portal and choose email categories (parcel updates, payment receipts, claim updates, offers). `user_notification_preferences` stores the categories. Account/security email is always allowed; parcel/payment/claim email is skipped when disabled or when `email_enabled=0`, and the skip is recorded in the notification log. Adding an email issues the standard verification link.

### 2FA recovery codes
`user_2fa_recovery_codes` stores SHA-256 hashes only. Eight one-time codes are generated when 2FA is enabled and can be regenerated from Settings with the current password. The login verification field accepts either a 6-digit TOTP code or a recovery code; used codes are marked consumed and remaining count is shown in Settings. Disabling 2FA deletes all recovery codes.

### Bounce/complaint handling
`POST /api/email-events.php` accepts `{provider?, event|type, email|recipient, reason?, bounce_type?}` with header:
`X-Mzigo-Email-Signature: sha256=<HMAC-SHA256(raw body, app config key)>`.
Hard bounces, complaints, spam/abuse and unsubscribe suppress the address in `email_suppressions`; soft bounces only log to `email_events`. The mailer skips suppressed recipients. Admin → Communications → Email deliverability lists suppressions/events and allows an address to be unsuppressed. Configure the sending provider to call this endpoint with the same HMAC secret.

### Webhook retries & secret encryption
Webhook signing secrets are AES-256-GCM encrypted at rest and decrypted only when signing. Failed deliveries are queued in `webhook_deliveries` with `attempts`, `next_retry_at` and exponential backoff (1m, 5m, 15m, 1h, 2h; max 5 attempts). `php api/cron.php` retries due deliveries. Secrets are shown only once at creation and are not returned by the webhook list endpoint.

### Attachment malware scanning
Claim evidence uploads are scanned before storage. If ClamAV is available (`CLAMSCAN_PATH` or on PATH) it is used; otherwise a heuristic fallback rejects EICAR signatures, executable/script filenames, invalid images and PDFs with active/embedded content. Blocked files are recorded in `claim_attachment_scans`; clean files carry `scan_status`, `scan_engine` and `scan_note`.

### Encryption-key rotation
Run only from CLI with a backup of `_server/config-key.php`:
```
php _server/db/rotate-encryption-key.php --new-key="<new 32+ char secret>" --apply
php _server/db/rotate-encryption-key.php --new-key="<new 32+ char secret>" --apply --update-key
```
Without `--apply` it is a dry run. It re-encrypts `communication_channels.config` and `webhooks.secret`. `--update-key` rewrites `_server/config-key.php` atomically and backs up the old key; it is refused when `APP_CONFIG_KEY` is set (update the environment secret manually). Use `--code=email` for a single-channel test rotation (webhooks are skipped when `--code` is supplied). `--encrypt-plaintext` can backfill legacy plaintext values.

## Platform-wide API usage

`api/_api_usage.php` aggregates `api_usage_daily` across every workspace for the platform admin. Admin → Analytics → **Platform API usage** shows total requests in the last 30 days, last 7 days and today, active workspace/key counts, a 30-day daily bar sparkline, top workspaces and top API keys. The card is driven by the admin payload (`data.api_usage`) so it refreshes with the rest of the admin console. Per-tenant API usage remains visible on the merchant Settings page.


## Security production checklist

See `docs/SECURITY.md` for the complete review. Required before launch:

- `APP_ENV=production`, `DEMO_MODE=false`, `APP_BASE_URL=https://…`, strong `APP_CONFIG_KEY`
- Rotate/remove demo accounts and the default admin password; enforce 2FA for all platform admins
- TLS termination, HTTPS redirect and HSTS (`Strict-Transport-Security`) at the vhost/reverse proxy
- `HEALTH_CHECK_KEY` for detailed `/api/health.php` metrics; the public response is liveness-only
- Apache `ServerTokens Prod`, `ServerSignature Off`; keep `X-Powered-By` removed
- Set `CLAMSCAN_PATH` to enable ClamAV signature scanning for claim evidence
- Run `php api/cron.php` for billing, claim SLA escalation and webhook retries; alert if it stops
- Least-privilege DB user, off-host encrypted backups, secret manager for DB/SMTP/app keys
- Edge WAF/rate limits, audit-log alerting and a professional penetration test
- Configure the mail provider to sign bounce/complaint callbacks with the app HMAC key; add replay protection if the provider supports timestamps
- Webhook destinations are restricted to public internet hosts in production; local/staging can still use localhost for integration tests


## Partner scan points & mobile scanner

Admin → **Scan points** manages partner points (drop-off, handover, arrival, collection or multi), either platform-wide or scoped to one workspace. The scan API rejects scans for parcels outside the point's workspace and carrier. Each point gets a one-time `mzp_…` access token; only its SHA-256 hash is stored, and it can be regenerated.

Point staff open `https://<host>/scan` on a phone and connect with the token. The scanner is a mobile-first installable PWA (manifest + service worker, 192/512 icons) with manual tracking entry and QR scanning where the browser supports `BarcodeDetector`.

Scan actions are enforced server-side by point type and parcel state:

- **drop-off:** `CREATED → AT_ORIGIN_POINT`
- **handover:** `AT_ORIGIN_POINT → HANDED_TO_TRANSPORTER`
- **arrival:** `HANDED_TO_TRANSPORTER`/`IN_TRANSIT → ARRIVED_DEST_POINT → READY_FOR_COLLECTION` (two scans)
- **collection:** requires `READY_FOR_COLLECTION`, paid delivery fee and matching 6-character collection code → `COLLECTED_PAID`

Every POST requires an idempotency key; retries return the original result without duplicating scans. Scans are stored with `source=partner_point`, `point_id` and idempotency key; customer emails and `parcel.status_updated` webhooks fire after a successful scan. Point tokens must be treated like passwords: issue one per device/point, regenerate on loss, and never share across points.


## Claim payout & recovery rails

Admin → **Settings → Claim payout & recovery rails** configures:

- **Payout mode/provider:** `demo`/`sandbox`/`live` and `demo`/`mpesa` (Daraja B2C)
- M-Pesa consumer key/secret, shortcode, passkey, initiator name, security credential and result callback URL
- **Recovery mode/provider:** `demo`/`sandbox`/`live` and `demo`/`webhook` (generic insurer/carrier integration)
- Recovery endpoint, bearer/API key, signing secret and callback URL
- Callback signature secret (generated on first save or regenerated; shown once)

Provider credentials and callback secrets are encrypted at rest. Demo mode settles immediately; M-Pesa sandbox/live submits a B2C payment request and moves the claim to `payout_processing` until the signed callback completes or fails it. Recovery requests can produce a signed webhook to an insurer/carrier and callbacks update the recovery record and claim fields.

Callback endpoint: `POST /api/claim-rail-callback.php` with `X-Mzigo-Signature: sha256=HMAC(raw body, callback secret)` or `?token=<callback secret>` for providers that cannot add HMAC headers (use only over HTTPS). Payout/recovery records are available in claim detail. Manual payout and manual recovery remain available on the admin Claims page as reconciliation fallbacks.


## Per-tenant branding & carrier rate cards

Workspace owners/admins can set branding in Settings: logo (PNG/JPG/WebP, max 2 MB), primary/secondary/accent colours, support email/phone and invoice footer. Branding applies to the authenticated tenant menu/dashboard and invoice documents; the public Mzigo.online homepage keeps the platform brand. Public-safe branding is available at `GET /api/tenant-branding.php?slug=<workspace-slug>` (rate-limited).

Rate cards override platform quote components per tenant by carrier, route and/or package size. The most specific active match wins; unset components fall back to the platform quote. Parcel creation uses `tenant_rate_quote()` so overrides apply immediately. Manage cards under Workspace Settings → Carrier rate cards.


## Webhook delivery history & idempotency

Every outbound event gets a UUID `event_id` (also sent as `X-Mzigo-Event-Id`) and an optional `idempotency_key` (`X-Mzigo-Idempotency-Key`). Consumers should deduplicate on `event_id`. Repeated dispatches with the same `(webhook_id, idempotency_key)` are suppressed via a unique constraint.

Merchant Settings → Webhook deliveries shows the latest attempts with event ID, event, status, response code, attempt count, next retry and creation time. Owners/admins can click **Retry now**; retries reuse the original stored payload, event ID and signing secret. Automatic backoff retries remain scheduled by `api/cron.php`.


## Email provider adapters & delivery events

The email channel supports these providers in Admin → Communications → Manage Email:

- **SMTP** — existing mailbox/relay configuration
- **SendGrid** — API key plus Event Webhook public key (PEM)
- **Mailgun** — API key, verified sending domain, optional EU region, and webhook signing key
- **Demo log** — records only

Bounce/complaint webhook URLs:

- `POST /api/email-events.php?provider=sendgrid` — verifies `X-Twilio-Email-Event-Webhook-Signature` ECDSA over timestamp+body; accepts a JSON array of events
- `POST /api/email-events.php?provider=mailgun` — verifies Mailgun's `timestamp` + `token` HMAC with the configured webhook signing key
- Generic `POST /api/email-events.php` — existing `X-Mzigo-Email-Signature` HMAC of the raw body

Hard bounces, spam complaints, unsubscribe and dropped events suppress the recipient; soft bounces are logged only. Live SendGrid/Mailgun delivery requires valid provider credentials and a verified sending domain.


## Automated security regression suite

Start Apache/MariaDB, seed the demo workspace, then run:

```powershell
& 'C:\xampp\php\php.exe' tests/security/run.php
```

The suite exits non-zero on failure and covers:

- security headers
- public health and tracking redaction
- unauthenticated/merchant/platform-admin authorization
- cross-tenant invoice and order isolation
- CSRF on workspace/admin mutations
- login rate limiting
- attachment scanner markers/executable filenames/active PDFs
- webhook SSRF guard for production destinations
- unsigned email event webhook rejection
- unauthenticated claim upload rejection

See `tests/security/README.md` for environment variables and fixtures.


## Public API v1

Client documentation: [API_V1.md](API_V1.md)  
OpenAPI 3.1 spec: [openapi/v1.yaml](openapi/v1.yaml)

The v1 API is server-to-server, authenticated with a workspace API key in `X-API-Key`, and scoped to that workspace. Endpoints:

- `POST /api/v1/parcels.php` — create a parcel
- `GET /api/v1/parcels.php` — list recent parcels / fetch one with `?tracking=`
- `GET /api/v1/track.php?q=` — track a parcel or errand

Keys have `read`/`write` scopes and are rate-limited per key. Tenant carrier/rate-card overrides apply to API-created parcels. See the docs for request/response examples, error envelopes, retry guidance and outbound webhook signatures.


## Runner OTP, matching and incentives

- Runner delivery now requires the receiver's 6-digit code when the receiver has a deliverable email channel; codes are hashed, expire in 30 minutes and can be resent. Without a deliverable channel, the existing note/photo proof remains.
- Runner profiles' maximum distance and preferred parcel sizes are enforced in the available-job feed and new-job notifications. Errand bookings optionally capture item size and approximate distance.
- Admin → Settings → M-Pesa payouts configures rating/volume incentive bonuses; bonuses are added to runner payouts and shown in runner earnings history.

## SEO monitoring

- Partner filter landing pages: `/partners/{slug}` (for example `/partners/chania`), with route links, canonical/meta/schema and sitemap entries.
- Admin → SEO → Search & Core Web Vitals stores Google/Bing verification tokens and shows real-user p75 LCP/INP/CLS/FCP/TTFB.
- Full setup guide: [SEO_MONITORING.md](SEO_MONITORING.md).


## Workspace order visibility

Workspace Orders, dashboard parcels/errands and saved senders are strictly tenant-scoped: a record appears only when `tenant_id` matches the active workspace. The platform does not infer workspace ownership from receiver phone/email.

- Public tracking remains available for every parcel.
- Receiver notifications do not create workspace orders.
- Parcels with `tenant_id = NULL` are visible in **Admin -> Unassigned parcels** as a read-only queue.
- Platform admins have no Orders workspace by default; platform-wide oversight lives in the Admin console.


## Scheduled referral campaigns

Run `php api/cron.php` daily for platform lifecycle work and `php _server/cron/referral_campaigns.php` every 15 minutes for scheduled referral campaigns. The central cron also processes due campaigns, and campaign claims are atomic so overlapping runners do not double-send.


## Newsletter and alert prompt

Admin -> Website -> Newsletter & alerts prompt controls the public opt-in prompt. It is double opt-in, stores consent version and supports email, SMS and WhatsApp once those channels are active in Communications. The current demo pilot is 10% across `/`, `/send-mzigo` and `/send-errand`. Prompt artwork is uploaded in the same card and is used in the prompt and confirmation email. The form is email-first, uses affirmative button consent with a lead-magnet option, and leaves channel/interests choices out of the first interaction. See `docs/NEWSLETTER_PROMPTS.md`.


## Marketing campaigns

Admin -> Campaigns sends non-transactional marketing messages to confirmed newsletter/alert subscribers only. Campaigns support a multi-rule trigger builder with time, event, condition, multiple rules and ALL/ANY matching. Drafts/scheduled sends are stored in `marketing_campaigns`; delivery is tracked per subscriber and every message includes a signed unsubscribe link. The central `api/cron.php` and `_server/cron/marketing_campaigns.php` process time/event/condition campaigns; schedule the dedicated runner every 15 minutes. Transactional order, delivery, claim and onboarding messages remain in Communications templates. See `docs/CAMPAIGNS.md`.


## Email template header and footer

All branded HTML communications use a shared white header with the Mzigo wordmark, support email and a gold "Track a parcel" button, and a dark footer with Help centre, Terms, Privacy, support contact, location and the relevant preference link. Marketing campaigns show Unsubscribe in the footer; transactional messages show Manage notifications instead.


## Workspace document branding

Print documents generated from a workspace use that workspace's name and logo, with the exact tagline `Powered By Mzigo.online`. This covers shipping labels, payment receipts, workspace invoices/credit notes and settlement statements. Platform-admin documents retain Mzigo.online branding. If a workspace has not uploaded a logo, the document falls back to the workspace name without inventing or substituting another logo.


## Workspace logo updates during approval

Platform admins can upload or remove a workspace logo from the merchant KYC approval card. The update is stored in the same workspace branding record used by merchant Settings -> Business profile, so the approved logo immediately appears on workspace-generated labels, receipts, invoices and settlement statements.
