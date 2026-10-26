# Deploying with cPanel Git Version Control (Namecheap)

**Status:** `.cpanel.yml` is prepared in the repo root. The workspace is **not yet a Git repository**, so the remaining steps require a remote Git repo and local Git.

## How it works

cPanel Git Version Control clones a remote repository (GitHub/Bitbucket/GitLab), then runs the tasks in `.cpanel.yml` to copy the deployable files into `public_html`.

The prepared `.cpanel.yml`:

- copies `api/`, `assets/`, `_server/` and `.htaccess`
- copies root `.html`, `.php`, `.js`, `.txt`, `.xml` and `.ico` files
- does **not** copy `_raw/`, `docs/`, `tests/`, `deploy/`, `.git/`, `.env`, or local config keys
- deploys to `$HOME/public_html/`

Dry-run verified locally: 52 root HTML files plus the API/assets/server directories are selected, with no `_raw` or docs.

## 1. Install Git locally

On Windows:

```powershell
winget install --id Git.Git -e
```

Then reopen the terminal and check:

```powershell
git --version
```

## 2. Create a private GitHub repository

Create an empty private repository, for example `mzigo-online`. Do not add a README from GitHub yet.

From the project folder:

```powershell
git init
git add .
git commit -m "Initial Mzigo production deploy"
git branch -M main
git remote add origin https://github.com/<YOUR-USER>/mzigo-online.git
git push -u origin main
```

Before pushing, confirm `.env`, `_server/config-key.php`, logs and `_raw/` are not staged. `.gitignore` already excludes them.

## 3. Create the cPanel repository

In cPanel:

1. Open **Git™ Version Control**.
2. Click **Create**.
3. Choose **Clone a Repository**.
4. Clone URL: `https://github.com/bodaboraapp-commits/mzigo.online.git`
5. Repository Path:
   ```
   /home/tokeqvlx/mzigo.online
   ```
6. Repository Name: `mzigo.online`.
7. Create.

The deployment target in `.cpanel.yml` is currently `$HOME/public_html/`. Confirm the domain's Document Root in cPanel → **Domains**:

- If it is `/home/tokeqvlx/public_html`, the current file is correct.
- If the domain root is `/home/tokeqvlx/mzigo.online`, change the first task in `.cpanel.yml` to:
  ```
  - export DEPLOYPATH=/home/tokeqvlx/mzigo.online/
  ```
  Do not clone the Git repository directly into the live document root.

### Private repo access

For a private GitHub repo, use one of:

- **HTTPS + GitHub personal access token**: clone URL like
  `https://<github-user>:<token>@github.com/<user>/mzigo-online.git`
  — never commit the token.
- **SSH deploy key**: generate an SSH key via cPanel Git Version Control’s private-repository access page, add the public key as a **read-only deploy key** on GitHub, then use the SSH clone URL.

## 4. Deploy

In cPanel → Git Version Control → Manage the repository:

1. **Pull or Deploy** tab.
2. Click **Update from Remote**.
3. Click **Deploy HEAD Commit**.
4. Check the deployment output for errors.

After each future push to GitHub, repeat Update from Remote → Deploy HEAD Commit. With a cPanel-hosted repository, push deployment can run automatically via the post-receive hook; with GitHub, deployment is manual unless you add a webhook/cron.

## 5. First production setup (not handled by `.cpanel.yml`)

Git only copies code. The following are separate:

1. **Database**
   - Create the database/user in cPanel → MySQL Databases.
   - Import the migrations through phpMyAdmin, in order.
   - If PHP CLI/Terminal is available, run `php _server/db/migrate.php`.
2. **Environment/config**
   - `.env` is not read automatically by this PHP app outside Docker.
   - Set `APP_ENV=production`, `DEMO_MODE=0`, `APP_BASE_URL=https://mzigo.online`, DB credentials and `APP_CONFIG_KEY` through the server’s environment/config mechanism.
   - Do not commit secrets or `_server/config-key.php`.
3. **Cron**
   - `php /home/<user>/public_html/api/cron.php` daily.
   - `php /home/<user>/public_html/_server/cron/whatsapp_queue.php` every minute (if WhatsApp is enabled).
4. **HTTPS + document root**
   - Confirm the domain’s document root is the `public_html` directory that receives the deploy.
   - Enable AutoSSL.
5. **Smoke test**
   - `/api/health.php`
   - `/`
   - `/track-mzigo`
   - `/login`
   - `/admin`

## 6. Rollback

cPanel Git Version Control deploys the checked-out HEAD. To roll back:

- deploy a previous commit from the cPanel Git interface if available, or
- use cPanel Terminal to `git checkout <previous-commit>` then **Deploy HEAD Commit**, or
- restore the previous `public_html` backup.

Take a `public_html` backup before the first deploy.

## Important limitations

- `.cpanel.yml` uses `cp`, so deleted/renamed files remain in `public_html` on later deploys. Clean up stale files manually or deploy to a fresh release directory.
- It does not run database migrations, set environment variables, configure cron or install SSL.
- `_server/` contains sensitive code and is denied by `.htaccess`; verify direct web access returns 403 after deploy.
- Git Version Control availability does **not** mean SSH/Terminal is available. It is sufficient for Git-based code deployment.
