# Server runbook — https://bakery-ops.orcamind.dev

How the live site is provisioned and operated. No secrets or server names live here (the repository is
public); they are in the server `.env`, GitHub secrets, and the owner's records. Pattern and scripts are
shared with the owner's other site on the same account (ADR 0006).

## Facts

| Item | Value |
|---|---|
| URL | https://bakery-ops.orcamind.dev (Cloudflare DNS + proxy, LiteSpeed origin) |
| Host | shared cPanel account with CloudLinux (host name only in the GitHub secret `DEPLOY_HOST`) |
| Site folder | `~/bakery-ops.orcamind.dev` = the Laravel app (`apps/api` of the repo); `.env` lives here, mode 600 |
| Document root | `~/bakery-ops.orcamind.dev/public` (set with `uapi SubDomain changedocroot`) |
| PHP (web) | CloudLinux `alt-php83` for this domain only (MultiPHP) |
| PHP (CLI, cron, deploy) | `/opt/alt/php83/usr/bin/php` (GitHub variable `DEPLOY_PHP_BIN`) |
| Database | MySQL database and user `<account>_bakeryops`, all privileges; password only in the server `.env` |
| Drivers | database cache, sessions and queue (cron-drained); `BROADCAST_CONNECTION=log` (polling) until a WebSocket host exists |
| Web app | `DEPLOY_WEB_MODE=static` once step 0.7 (the export-safe web shell) is on `main`: the static export is served by LiteSpeed from `public/app/` at https://bakery-ops.orcamind.dev/app/ (`/` redirects there), same origin as `/api/v1`, so the server `.env` needs no `CORS_ALLOWED_ORIGINS` |
| Live-e2e account | admin of the isolated branch "E2E Test Branch" (see "E2E account" below); its phone and password only in the GitHub secrets `E2E_ADMIN_PHONE` / `E2E_ADMIN_PASSWORD` |
| Deploy key | ed25519 key used by this repository's GitHub Actions and by the owner's PC for provisioning; authorised with the `restrict` option (commands and rsync only, no terminal, no forwarding) |
| Host key | pinned in the GitHub secret `DEPLOY_KNOWN_HOSTS` |
| Rollback snapshot | `~/releases/bakery-ops/prev.tar.gz` (code only, written before every deploy) |

Status: **provisioned 2026-10-19** (steps 1-10 done from the owner's PC over SSH with the deploy key; `DEPLOY_ENABLED=true`). The host's CloudLinux Node.js selector offers Node 10-22, so a server-rendered web app is possible later.

## One-time provisioning checklist (cPanel Terminal or SSH as the account user)

| # | Step | Command / where |
|---|---|---|
| 1 | Create the subdomain `bakery-ops` in cPanel (Domains) if it does not exist; Cloudflare DNS record proxied | cPanel → Domains; Cloudflare dashboard |
| 2 | Document root to the Laravel public folder | `mkdir -p ~/bakery-ops.orcamind.dev/public && uapi SubDomain changedocroot domain=bakery-ops.orcamind.dev docroot=bakery-ops.orcamind.dev/public` |
| 3 | PHP 8.3 for this domain only | `uapi LangPHP php_set_vhost_versions vhost=bakery-ops.orcamind.dev version=alt-php83` |
| 4 | Check extensions (web + CLI) | `/opt/alt/php83/usr/bin/php -m \| grep -E 'pdo_mysql\|intl\|bcmath\|gd\|zip\|mbstring\|sodium'` |
| 5 | Database + user | cPanel → MySQL Databases: create `<account>_bakeryops` database and user with all privileges (or `uapi Mysql create_database/create_user/set_privileges_on_database`). The migrations create append-only triggers (`audit_log`, later `stock_movements`, `order_events`) as this user; if the server has binary logging on, it must also have `log_bin_trust_function_creators=1` (check with `SHOW VARIABLES LIKE 'log_bin%'`; error 1419 on `migrate` means it does not, ask the host to set it) |
| 6 | Server `.env` | copy `apps/api/.env.example` to `~/bakery-ops.orcamind.dev/.env`, set `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://bakery-ops.orcamind.dev`, `DB_CONNECTION=mysql`, DB name/user/password, `CACHE_STORE=database`, `SESSION_DRIVER=database`, `QUEUE_CONNECTION=database`, `BROADCAST_CONNECTION=log`, `FILESYSTEM_DISK=local`; generate `APP_KEY` with `/opt/alt/php83/usr/bin/php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"`; `chmod 600 .env` |
| 7 | Authorise the deploy key | append the public key printed in the owner's records (`~/.ssh/bakery-ops-deploy/id_ed25519.pub` on the PC) to `~/.ssh/authorized_keys` as one line prefixed with `restrict ` (back up the file first; `chmod 600`) |
| 8 | Confirm the host key | `ssh-keyscan -t ed25519 <host>` must match the GitHub secret `DEPLOY_KNOWN_HOSTS` (pre-filled with the account's known host; replace if the host differs) |
| 9 | GitHub secrets / variables | secrets `DEPLOY_HOST`, `DEPLOY_USER` (cPanel user), `DEPLOY_PORT`, `DEPLOY_SSH_KEY`, `DEPLOY_KNOWN_HOSTS`; variables `DEPLOY_PATH` (`$HOME/bakery-ops.orcamind.dev` is expanded on the server), `DEPLOY_PHP_BIN`, `DEPLOY_URL`, `DEPLOY_WEB_MODE`, then `DEPLOY_ENABLED=true` |
| 10 | Cron (append; other sites' lines untouched) | see below |
| 11 | First deploy | GitHub → Actions → deploy → Run workflow; then `https://bakery-ops.orcamind.dev/api/v1/health` must answer `{"status":"ok","database":"ok"}` |

### Cron entries

```
* * * * * sleep $((RANDOM \% 60)) && cd $HOME/bakery-ops.orcamind.dev && /opt/alt/php83/usr/bin/php artisan schedule:run >> /dev/null 2>&1
* * * * * cd $HOME/bakery-ops.orcamind.dev && /opt/alt/php83/usr/bin/php artisan queue:work --stop-when-empty --max-time=50 >> /dev/null 2>&1
```

## How a release works

1. A push to `main` runs the `ci` workflow (lint, static analysis, module tests, e2e on the compose stack, gates).
2. On green, `deploy` builds the release on the runner (`composer install --no-dev`, optional static web
   export), records the git revision in `REVISION`, and ships Laravel's `public/.htaccess` as
   `scripts/deploy/public.htaccess`.
3. `scripts/deploy/pre-release.sh` on the server: maintenance mode, snapshot of the current code.
4. `rsync --delete` copies the release; `scripts/deploy/rsync-excludes.txt` protects `.env*`, `storage/`,
   `public/storage`, `bootstrap/cache/`, `public/.htaccess` and files cPanel creates.
5. `scripts/deploy/release.sh` on the server: rebuilds `public/.htaccess` (cPanel's PHP handler block +
   Laravel's rules), storage skeleton, `migrate --force`, `optimize`, `storage:link`, `queue:restart`, permissions, up.
6. `scripts/deploy/smoke.sh` checks `/api/v1/health`, `/up`, JSON 404s, the `/` redirect, and with the web deployed `/app/`,
   `/app/en/` and `/app/am/` (200 with the right `<html lang>`); the not-found fallback and the immutable `_next/static`
   cache header are reported as warnings.
   A failure runs `scripts/deploy/rollback.sh` (code only) and fails the job.
7. `live-e2e` runs the Playwright tests tagged `@live` against the live URL.

## Web shell under /app (public/.htaccess)

`deploy.yml` builds the Next.js app with `NEXT_OUTPUT=export` and copies `out/` to `public/app/`. Laravel's
`public/.htaccess` (shipped as `scripts/deploy/public.htaccess`, behind cPanel's handler block) serves it without PHP:

- an existing file or folder under `/app` is served as it is (`DirectoryIndex index.html index.php`: a page folder
  opens its `index.html`, the site root still opens `index.php`);
- an unknown page under `/app/en/` or `/app/am/` gets that language's "page not found" page (`/app/<locale>/not-found/`);
- any other `/app` path without a language gets the start page `/app/index.html`, which picks the language (the
  signed-in user's, else the browser's) and keeps the rest of the path; a missing `/app/_next/` asset is a plain 404;
- `/app/_next/static/*` is `Cache-Control: public, max-age=31536000, immutable`, every other `/app` file `no-cache`;
- everything else goes to Laravel's front controller as before. A rollback restores the previous release's rules too.

## E2E account (live Playwright tests)

The `@live` Playwright tests sign in as the admin of a branch of their own, so nothing they create touches shop data:

```
cd ~/bakery-ops.orcamind.dev && /opt/alt/php83/usr/bin/php artisan bakery:create-admin <phone> --name="E2E Admin" --branch="E2E Test Branch"
```

The command creates the branch when it does not exist, refuses a phone that is already in it, and prints the generated
password once. Store the phone and the password as the repository secrets `E2E_ADMIN_PHONE` and `E2E_ADMIN_PASSWORD`;
without them `live-e2e` skips the admin flow (and says so). The same command creates the first real admin of a fresh
install (`--branch="<shop name>"`).

## Manual operations

- Deploy without a new commit: GitHub → Actions → deploy → Run workflow.
- Roll back by hand (code only; migrations are not reversed, so they must be backwards compatible):
  `APP_DIR=$HOME/bakery-ops.orcamind.dev PHP_BIN=/opt/alt/php83/usr/bin/php bash $HOME/bakery-ops.orcamind.dev/scripts/deploy/rollback.sh`
- Logs: `~/bakery-ops.orcamind.dev/storage/logs/laravel-*.log`.

## Known behaviour (from the sibling site on the same account)

- Many SSH logins from one address in a few minutes can make the host's firewall drop that address for a
  while. Deploys come from GitHub's runners and are not affected.
- Changing the domain's PHP version in cPanel rewrites the handler block in `public/.htaccess`; the next
  deploy keeps whatever cPanel wrote.
