# Mzigo.online security review & hardening

Review date: 2026-10-26  
Scope: PHP API, static frontend, MySQL schema, Apache configuration, authentication, sessions, multi-tenant access, uploads, webhooks, email delivery and platform administration.

> No internet-facing system can be proven “hack-proof”. The goal is defense in depth: reduce attack surface, make exploitation expensive, detect abuse and limit blast radius. This review combined source inspection, configuration review and safe HTTP probes; no destructive penetration testing was performed.

## Executive summary

The application already has a strong security baseline for an MVP/SaaS:

- PDO prepared statements with emulated prepares disabled
- CSRF tokens on cookie-authenticated writes
- Role and tenant checks on merchant/admin APIs
- DB-backed sessions with regeneration, idle/absolute expiry and revocation
- bcrypt password hashes; hashed reset/verification tokens and API keys
- API-key scopes and per-key rate limits
- Encrypted communication-channel and webhook secrets (AES-256-GCM)
- Upload validation + malware scanning
- Security headers (`X-Content-Type-Options`, `X-Frame-Options`, Referrer-Policy, Permissions-Policy, COOP)
- Server internals (`_server`, `_raw`) denied over HTTP

Critical issues found during this review were fixed in place. Remaining items require deployment/process decisions and are listed below.

## Findings and status

| # | Severity | Finding | Status |
|---|---|---|---|
| 1 | **Critical** | Public `/api/health.php` returned customer counts, MRR/revenue, DB/PHP versions and recent parcel routes/tracking numbers | **Fixed** — public response is now `{ok,status,time}`; detailed metrics require `X-Health-Key: <HEALTH_CHECK_KEY>` |
| 2 | **Critical** | Public `/api/track.php` returned full sender/receiver phone numbers, item description, declared value, payment reference and the **collection code** for any known tracking number | **Fixed** — public tracking now masks phone numbers and removes collection code, payment reference, item description and declared value; response rate-limited |
| 3 | **High** | `demo_mode` was hardcoded `true` in `_server/config.php`, so a production deployment would keep demo OTP `1234`, demo PINs and demo sign-in conveniences | **Fixed** — `APP_ENV=production` defaults demo mode off; `DEMO_MODE=true/false` can override explicitly. Set `DEMO_MODE=false` in production |
| 4 | **High** | Login/2FA used an unvalidated `next` query parameter, enabling open redirects or `javascript:` navigation | **Fixed** — client-side `safeNext()` only allows same-origin paths beginning with a single `/` |
| 5 | **High** | Tenant owners could register webhooks to `localhost`, private IPs, link-local or internal hostnames (blind SSRF) | **Mitigated** — production webhook URLs must resolve only to public internet IPs; local/development remains permissive for integration tests |
| 6 | **High** | Known demo/admin credentials are documented and shipped with the demo database; admin 2FA is optional | **Open — deployment action required**: delete/rotate demo users, use a unique admin password, enforce 2FA for `platform_admin`, restrict admin access by IP/VPN and disable public staff login where possible |
| 7 | **Medium** | Anonymous parcel/errand creation, signup and failed API-key attempts had no or weak abuse throttles | **Fixed** — IP-based limits added for anonymous parcel/errand intake, signup, public tracking and failed API-key auth; claim uploads limited per user/IP |
| 8 | **Medium** | No Content-Security-Policy; `X-Powered-By` exposed PHP; directory indexes were not explicitly disabled; HSTS intentionally absent locally | **Partially fixed** — CSP (`frame-ancestors`, `base-uri`, `object-src`), `X-Powered-By` removed, `-Indexes` set. Production must enable HSTS after TLS, set `ServerTokens Prod`/`ServerSignature Off`, and consider a stricter CSP after removing inline scripts/styles |
| 9 | **Medium** | Email event webhook HMAC has no timestamp/replay window; provider-specific event formats not normalized | **Open** — add timestamp header + replay window and per-provider adapters |
| 10 | **Medium** | ClamAV is not installed in this environment; malware scanning uses heuristic fallback only | **Partial** — install ClamAV (`CLAMSCAN_PATH`) for signature-based scanning; keep heuristic validation |
| 11 | **Medium** | No central SIEM/alerting for repeated auth failures, privilege changes, bulk exports, suppression changes or admin actions | **Recommendation** — ship audit logs to a log platform, alert on high-risk actions and 4xx/5xx spikes |
| 12 | **Low/Medium** | Apache/PHP version banners visible; local config runs HTTP; SMTP/DB secrets live on the same host as the app | **Recommendation** — reverse proxy/WAF (Cloudflare), TLS-only vhost, HSTS, secrets manager, least-privilege DB user, separate mail credentials |
| 13 | **Scope** | Payments remain demo/simulated; a production payment integration needs its own threat model (callback forgery, replay, amount tampering) | **Deployment decision** |

## Verified controls

- `/_server`, `_raw`, config/key files, uploads and underscore PHP internals return **403**
- Session cookie is `HttpOnly`, `SameSite=Lax`, `Secure` when HTTPS is detected; session IDs regenerate on login
- All reviewed state-changing cookie endpoints call `verify_csrf()` (public contact form includes CSRF too)
- Admin APIs require `platform_admin`; merchant writes require owner/admin; tenant resources are scoped by `tenant_id`
- Claim evidence download checks claim visibility; invoices are tenant-scoped; platform claims export is admin-only
- Webhook delivery allows at most 5 retries with HMAC signing; secrets are encrypted at rest
- Email suppression skips hard-bounced/complained addresses; communication-channel credentials are encrypted and masked in admin responses

## Recommended hardening roadmap

### Before production (must)
1. Set `APP_ENV=production`, `DEMO_MODE=false`, `APP_BASE_URL=https://…`, `APP_CONFIG_KEY` (and back it up separately).
2. Rotate/delete all demo accounts and the default `admin@mzigo.online` password; enforce strong unique admin credentials.
3. Enforce 2FA for every `platform_admin`; restrict `/admin` by VPN/IP allowlist or Cloudflare Access.
4. Terminate TLS with a valid certificate; enable HSTS (`Strict-Transport-Security: max-age=31536000; includeSubDomains`), HTTPS-only redirects and secure cookies.
5. Set Apache `ServerTokens Prod`, `ServerSignature Off`; disable `X-Powered-By` at the PHP/Apache layer.
6. Move `APP_CONFIG_KEY`/DB/SMTP secrets into a secret manager or server environment; never commit them. Keep `_server/config-key.php` backed up and 0600.
7. Set `HEALTH_CHECK_KEY` and configure monitoring to send `X-Health-Key`; keep `/api/health.php` minimal publicly.
8. Schedule `php api/cron.php` (billing, claim SLA, webhook retries); alert if it stops.
9. Install ClamAV and set `CLAMSCAN_PATH`; keep upload size/type limits.
10. Use a least-privilege MySQL user (no `DROP`/`ALTER`/`FILE`), separate backups and tested restore.

### Near term
- Add WAF/rate limiting at the edge (Cloudflare) for `/api/*`, login, signup and tracking; block known bad ASNs/bots.
- Add strict production CSP with nonces/hashes after moving inline scripts/styles; add `upgrade-insecure-requests`.
- Add timestamp/replay protection and provider-specific parsing to `/api/email-events.php`.
- Add per-tenant and per-IP API quotas/alerts; currently API keys are limited per key, but public endpoints still deserve edge limits.
- Alert on: repeated failed logins, 2FA failures, failed API keys, admin credential resets, role/plan changes, large claims exports, suppression changes, webhook failures.
- Add dependency/container scanning, PHP/Apache patch cadence, secrets scanning in CI.
- Add a professional penetration test and a bug bounty/private disclosure channel before scaling.

### Nice to have
- Admin IP allowlist + hardware-key/passkey support.
- Email DMARC/SPF/DKIM monitoring and a bounce/complaint provider integration.
- Webhook destination allowlist and outbound egress proxy.
- Per-user login notifications and impossible-travel detection.
- Encrypted database backups and key rotation drills.
- Game-day incident response runbook and customer notification process.

## Security verification commands

```powershell
# Confirm internals are blocked
curl.exe -s -o NUL -w "%{http_code}`n" http://localhost:8090/_server/config-key.php
curl.exe -s -o NUL -w "%{http_code}`n" http://localhost:8090/_raw/

# Public health must not expose metrics
curl.exe -s http://localhost:8090/api/health.php

# Public tracking must not expose codes/full phones
curl.exe -s "http://localhost:8090/api/track.php?q=TRK-4WEWFH1L"

# Security headers
curl.exe -s -D - -o NUL http://localhost:8090/ | Select-String "Content-Security|X-Frame|X-Powered|Referrer"
```

## Closing note

The fixes above materially reduce the highest-risk exposure (public data leakage, open redirect, demo-mode deployment risk, SSRF and abuse throttling). The remaining “must” items are operational: unique admin credentials, enforced 2FA, TLS/HSTS, secrets management, WAF and monitoring. Those controls, not code alone, are what make the system resistant to real malicious attacks.


## Automated security regression tests

`tests/security/run.php` is the executable security regression suite. It runs against the local Apache instance and seeded demo database and fails on any regression in:

- security headers and public health redaction
- tracking phone/private-field redaction
- unauthenticated, merchant and platform-admin authorization boundaries
- cross-tenant invoice and order isolation
- CSRF on workspace and admin mutations
- login rate limiting
- attachment scanner detections
- webhook SSRF production guard
- unsigned email event rejection
- unauthenticated claim upload rejection

Run it from the project root after starting the stack:

```powershell
& 'C:\xampp\php\php.exe' tests/security/run.php
```

The suite uses temporary cookie jars/files and its own rate-limit bucket, and cleans up its sessions on exit. Add it to CI after the demo fixtures are seeded.
