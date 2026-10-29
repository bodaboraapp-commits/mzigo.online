# Web database update (no data deletion)

**URL after admin login:**

```text
https://mzigo.online/api/db-update.php
```

This page lets a platform admin apply only migrations that are not yet recorded in `schema_migrations`. It does not drop existing tables or delete existing rows.

## What it does

- Shows:
  - number of applied migrations
  - pending migration files
- **Apply pending migrations** — runs only pending SQL files and records each version/checksum
- **Download SQL** — downloads the pending migration SQL for manual import through phpMyAdmin
- Strips hardcoded `USE <database>;` statements before applying/downloading, so the same migration works against the hosting database name (for example `tokeqvlx_mzigo_online`) instead of a local `mzigo_online` database
- Requires a logged-in platform admin session
- POST is protected by CSRF

## Safety rules

- The page refuses to auto-apply if the app tables exist but `schema_migrations` is empty. Replaying all migrations on an existing schema would cause duplicate-column/table errors.
- Automatic apply is blocked if any pending migration contains:
  - `DELETE FROM`
  - `TRUNCATE`
  - `DROP TABLE`
  - `DROP COLUMN`
  - `DROP INDEX`
- In that case, download the SQL, review it, and run it manually.
- Take a cPanel database backup before clicking Apply.
- After the update, remove or restrict the endpoint if you do not want it permanently available.

## Deploy

The endpoint is part of the repository:

```text
api/db-update.php
```

Deploy the latest commit in cPanel:

```text
Git Version Control → Update from Remote → Deploy HEAD Commit
```

Then log in as `platform_admin` and open:

```text
https://mzigo.online/api/db-update.php
```

Pending MVP migrations:

```text
039_multi_role_access.sql   -> user_roles
040_scan_manual_entry.sql   -> partner_points.manual_entry_enabled
041_parcel_photos.sql       -> parcel_photos
042_mpesa_payments.sql      -> mpesa_payments (Daraja STK)
```

Verify all three before testing multi-role login, scan manual entry or order photos.

## What is not changed

- No existing table is dropped.
- No parcel, user, workspace, invoice, claim or settings rows are deleted.
- No full database dump is imported by this page; only pending migration files are run.
- It cannot repair a database with a missing/empty `schema_migrations` table; use the cPanel backup/full dump workflow in that case.
