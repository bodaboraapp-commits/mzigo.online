# Moving the Mzigo database to cPanel production

**Target:** Namecheap cPanel, database on `localhost:3306`, app deployed under `/home/tokeqvlx/public_html`.

A production dump has been prepared locally:

```text
_server/backups/mzigo_online_prod_20261026.sql   (~1.1 MB)
_server/backups/mzigo_online_prod_20261026.zip   (~127 KB)
```

The dump contains 83 tables, structure and data from the local `mzigo_online` database. It was created without a UTF-8 BOM.

---

## 1. Create the production database

In cPanel → **MySQL® Databases**:

1. Create a database, for example:
   ```text
   tokeqvlx_mzigo
   ```
2. Create a database user, for example:
   ```text
   tokeqvlx_mzigo
   ```
3. Use a strong password.
4. Add the user to the database with **All Privileges**.

cPanel may prefix the names with your account username. Use the exact names cPanel shows.

---

## 2. Import the dump

### Option A — phpMyAdmin (simplest)

1. Upload `mzigo_online_prod_20261026.zip` or `.sql` via cPanel **File Manager** to your home directory — **not** `public_html`.
2. Open **phpMyAdmin**.
3. Select the production database on the left.
4. Open **Import**.
5. Choose the `.sql` file (or unzip it first) and import.
6. Confirm 83 tables were created.

The file is about 1.1 MB, well below typical phpMyAdmin upload limits.

### Option B — cPanel Terminal/SSH (if available)

```bash
mysql -u tokeqvlx_mzigo -p tokeqvlx_mzigo < /home/tokeqvlx/mzigo_online_prod_20261026.sql
```

Delete the dump from the server after import.

---

## 3. Configure the app

Create `/home/tokeqvlx/public_html/.env` with the production values:

```dotenv
APP_ENV=production
DEMO_MODE=0
APP_BASE_URL=https://mzigo.online
PLATFORM_ADMIN_EMAIL=admin@mzigo.online
SUPPORT_EMAIL=hello@mzigo.online
APP_CONFIG_KEY=<copy from _server/config-key.php or generate a new key>

DB_HOST=localhost
DB_PORT=3306
DB_NAME=tokeqvlx_mzigo
DB_USER=tokeqvlx_mzigo
DB_PASS=<database password>

PAYMENT_PROVIDER=demo
MAIL_DRIVER=smtp
MAIL_FROM=no-reply@mzigo.online
SMTP_HOST=
SMTP_PORT=587
SMTP_USER=
SMTP_PASS=
SMTP_ENCRYPTION=tls
SMTP_FROM=no-reply@mzigo.online
```

Important:

- The app now auto-loads `.env`; real server environment variables take precedence.
- `.htaccess` denies direct access to `.env`. Verify `https://mzigo.online/.env` returns 403.
- **If you import encrypted channel/webhook configs from local**, copy the local `_server/config-key.php` to the same location on the server, or set the same `APP_CONFIG_KEY`. Without it, encrypted SMTP/webhook credentials cannot decrypt.
- Never commit `.env` or `_server/config-key.php`.

---

## 4. Post-import cleanup

The local database contains demo/test data. Before public launch:

- Rotate/remove the demo admin, merchant, customer and runner accounts.
- Delete demo workspaces, parcels, claims and WhatsApp test conversations.
- Re-check SMTP/channel credentials, payment mode and production domain settings.
- Set `DEMO_MODE=0` and confirm demo codes are no longer returned.
- If starting truly fresh, import structure only and re-enter settings/content instead of copying demo records.

---

## 5. Database migrations

Because this dump includes the current schema and `schema_migrations` tracking table, migrations do not need to be replayed after a full import.

For a fresh empty database instead:

```bash
php /home/tokeqvlx/public_html/_server/db/install.php
php /home/tokeqvlx/public_html/_server/db/migrate.php
```

or import migrations through phpMyAdmin in version order.

---

## 6. Verify

1. Open `https://mzigo.online/api/health.php`.
2. Sign in at `https://mzigo.online/login` with a production admin account.
3. Confirm dashboard, workspaces, parcels and settings load.
4. Create a test parcel and track it.
5. Check Admin → Communications shows channels/settings.
6. Verify cron:
   - daily: `php /home/tokeqvlx/public_html/api/cron.php`
   - every minute: `php /home/tokeqvlx/public_html/_server/cron/whatsapp_queue.php` if WhatsApp is enabled.
7. Delete the imported `.sql`/`.zip` from the server.

---

## 7. Rollback

- Before import, take a cPanel database backup or export the empty production database.
- Keep the local dump and a cPanel backup.
- If the import fails, drop/recreate the empty database and import the backup.
- Do not expose the dump in `public_html` at any point.
