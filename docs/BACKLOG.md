# Pending tasks & development backlog

Snapshot: 2026-10-28  
Environment: local/demo (`http://localhost:8090`) with live MariaDB and the SMTP email channel active.

There are **no queued Team tasks, scheduled reminders or active goals**. The only background job is the Apache preview server.

**Open items moved:** all open devlist/backlog items are now tracked in the canonical **V1 production readiness register**:

`docs/ENGINEERING_V1_READINESS.md`

This file now keeps shipped history only. Open MVP and launch blockers are tracked in `docs/ENGINEERING_V1_READINESS.md` and the current status/verification checklist in `docs/MVP_STATUS.md`.

## Recently completed (for roadmap accuracy)

- Scanner PWA task-first flow, offline queue, torch, jsQR fallback, settings recent scans, admin-defined action order/wording and per-point manual-entry control
- Workspace enforcement for handover/arrival/collection scans (drop-off stays open)
- Product/package photo capture on order creation; admin photo links; public confirmation gallery removed
- Admin insurance charge mode (percentage/fixed) and platform holding/platform fee configuration
- Email OTP login, merchant multi-role access, deleted-user restore and production DB updater fix
- M-Pesa default payment option; customer + creator payment receipts over SMS/email

- SMS integrations (Infobip, then Twilio) for parcel notifications and OTP seams; live delivery limits/credentials remain pending

- WhatsApp Phase 1 (FAQ, tracking, handoff, admin inbox) and Phase 2 (approved outbound parcel templates, consent/opt-in, lifecycle hooks)

- Marketing Campaigns admin menu with consent-based audiences, drafts, scheduling, recipient tracking and unsubscribe links

- Newsletter and alert opt-in prompt with idle/engagement/abandoned-task triggers, double opt-in, configurable channels and subscriber export

- Partner QR poster downloads, referral A/B copy testing, automated scheduled campaigns and expanded attribution/K-factor dashboard

- Strict workspace-order visibility (parcels and errands), platform-admin workspace-only Orders state, and read-only unassigned-parcels report

- Runner receiver OTP delivery proof, configurable rating/volume incentives, and distance/size matching filters
- SEO partner filter landing pages (`/partners/{slug}`), Search Console/Bing verification fields and real-user Core Web Vitals monitoring

- **Phase 7:** public API v1 documentation, OpenAPI 3.1 spec, tenant rate cards on API-created parcels, symmetric page gutters

- **Phase 6:** automated security regression suite covering authz/tenant isolation, CSRF, rate limits, upload scanning, health/tracking redaction, webhook SSRF and email webhook signatures

- **Phase 5:** SendGrid/Mailgun email transports, provider-specific bounce/complaint webhook verification and parsing, live credential readiness

- **Phase 4:** webhook event IDs, idempotency-key duplicate suppression, merchant delivery history and manual Retry now action

- **Phase 1:** partner scan points, idempotent scan ingestion API, admin CRUD and installable mobile scanner PWA (`/scan`)
- **Phase 2:** claim payout/recovery rails with demo/sandbox/live config, M-Pesa B2C initiation, insurer/carrier recovery webhook, signed callbacks, payout/recovery records, manual fallback
- **Phase 3:** per-tenant branding (logo/colours/support/document footer) and carrier/route/size rate cards with quote overrides
- Stage A–E SaaS foundation, multi-tenancy, plans, billing, claims phases 1–4
- Real SMTP delivery + HTML journey emails and editable DB templates
- Admin Communications two-column layout and journey template catalogue
- Customer email capture + notification preferences
- 2FA recovery codes
- Email bounce/complaint handling + admin deliverability panel
- Webhook retries + encrypted webhook secrets
- ClamAV-ready attachment scanning + heuristic fallback
- Encryption-key rotation CLI
- Platform-wide API usage aggregate
- Toll-free header, SMTP configuration, security review and hardening

Full security report: `docs/SECURITY.md`. Production checklist: `docs/PRODUCTION.md`.
