# Mzigo.online security regression suite

Run against the local seeded demo instance:

```powershell
& 'C:\xampp\php\php.exe' tests/security/run.php
```

Or with another base URL:

```powershell
$env:MZIGO_TEST_BASE_URL='http://localhost:8090'
& 'C:\xampp\php\php.exe' tests/security/run.php
```

The suite exits `0` when every check passes and `1` on any failure. It expects the seeded demo workspace and demo accounts:

- platform admin: `admin@mzigo.online` / `Admin123!`
- merchant owner: `amina@example.com` / `Demo1234!`
- second merchant owner: `sokoni@example.com` / `Demo1234!`

## Coverage

- Security headers on API responses
- Public health payload redaction
- Unauthenticated workspace rejection
- Merchant-to-platform-admin authorization denial
- Platform admin access
- Cross-tenant invoice isolation
- Tenant order-list isolation
- CSRF enforcement on workspace and admin mutations
- Login rate limiting (`429`)
- Public tracking phone/private-field redaction
- Attachment scanner markers, executable filenames and active PDF content
- Webhook SSRF guard for loopback, private, link-local and non-HTTP destinations in production mode
- Unsigned email event webhook rejection
- Unauthenticated claim upload rejection

## Notes

- The suite creates only temporary cookie jars, temporary scanner files and a unique rate-limit bucket; it cleans them up.
- Successful and failed test logins use a dedicated `Mzigo-Security-Suite/1.0` user agent so test sessions can be removed on exit.
- On Windows hosts with antivirus EICAR protection, the full EICAR string is blocked at the filesystem layer; the suite uses the scanner's EICAR marker path instead.
- The suite is read-only against demo data except for transient login/rate-limit/session records.
- For CI, start Apache/MariaDB with seeded fixtures, then run the command above from the project root.
