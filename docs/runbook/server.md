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
| Drivers | database cache, sessions and queue (cron-drained); `BROADCAST_CONNECTION=null` (polling, ADR 0009: nothing is broadcast; the queue carries Phase 2's stock alerts, automatic production requests and notifications, see "Queued work") until a WebSocket host exists; a server `.env` that still says `log` behaves the same (the code treats `log` as no transport), switch it to `null` at the next edit |
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
| 6 | Server `.env` | copy `apps/api/.env.example` to `~/bakery-ops.orcamind.dev/.env`, set `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://bakery-ops.orcamind.dev`, `DB_CONNECTION=mysql`, DB name/user/password, `CACHE_STORE=database`, `SESSION_DRIVER=database`, `QUEUE_CONNECTION=database`, `BROADCAST_CONNECTION=null`, `FILESYSTEM_DISK=local`; generate `APP_KEY` with `/opt/alt/php83/usr/bin/php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"`; `chmod 600 .env` |
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

## Queued work (Phase 2)

From Phase 2 the database queue carries real work: after a stock change a job evaluates the balance (`EvaluateBalance`), a stock-out
queues the automatic production request, and every inbox line and every push or Telegram delivery is a job. All of it runs on the
cron `queue:work --stop-when-empty --max-time=50` entry above (see "Cron entries"); nothing else needs to be started.

- **Expect up to about a minute** between a sale that sells the last of a product and the request appearing on the baker's board
  (and the bell lines), never more than one cron tick plus the seconds the chain takes: every job queues the next one on the same
  queue, so one worker run normally finishes the whole chain. The pad greys the product out at once, because availability is read
  from the balance, not from the queue. The board and the bell poll (5 s and 15 s), so add a few seconds after the chain.
- **Nothing is lost if the worker is late or a job fails.** Jobs are idempotent (derived client UUIDs, one open episode per key, one
  inbox line per key), so a retry or an overlapping run writes nothing twice. A failed job lands in `failed_jobs`; the hourly
  `inventory:sweep-alerts` (in the scheduler) re-evaluates the open episodes and every active rule, so a lost evaluation heals within
  the hour. Look at `php artisan queue:failed` after a cron outage.
- **A release restarts the worker** (`queue:restart` is in the release script), so the next cron run loads the new code.
- **The Playwright `@live` flows wait for this.** `p2-stockout-production` polls the API for the request for up to 150 s (30 s in
  CI) and says which link was missing (no alert row, no request, no inbox line); a failure there on the live host with a green CI
  run points at the cron entry or the queue, not at the code.
- **Seeding the demo runs the listeners itself.** `bakery:seed-demo` sets the queue to `sync` for the length of the Phase 2 seed
  and puts the setting back, so the demo bakery has its inbox lines without waiting for cron.

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

## Demo bakery (its own branch)

The demo bakery (README, "Demo bakery") can be loaded on the live site to show the system with realistic data. It goes
into a branch of its own, "Demo Bakery" by default, created on the first run in Africa/Addis_Ababa and ETB and marked as
a demo branch; a branch of that name that is not a demo branch is refused, so shop data and the "E2E Test Branch" are
never touched. Production needs `--force` and a password of its own for the demo accounts; nothing is written without
them.

1. Add a long random `BAKERY_DEMO_PASSWORD` to `~/bakery-ops.orcamind.dev/.env` (8 characters at least; the documented
   local default is refused here). Keep it in the owner's records; it is every demo account's password and the seed of
   their short codes. Optional: `BAKERY_DEMO_BRANCH="<name>"` for another branch name.
2. The release caches the configuration (`optimize`), so refresh it after editing `.env`:
   `cd ~/bakery-ops.orcamind.dev && /opt/alt/php83/usr/bin/php artisan config:cache`
3. Load it:
   ```
   cd ~/bakery-ops.orcamind.dev && /opt/alt/php83/usr/bin/php artisan bakery:seed-demo --force
   ```
   It prints what the branch holds (items by kind, recipes, users, locations, movements) and, for the accounts it
   created, their short codes, once. Sign in at https://bakery-ops.orcamind.dev/app/ with a demo phone (README) and
   `BAKERY_DEMO_PASSWORD`.

Running it again (for example after a later phase adds its seeder: `--phase=<n>` stops at a phase) only adds what is
missing; existing rows, passwords and short codes stay as they are.

**Phase 1 (ordering).** After the release that ships Phase 1, run the same command again on the branch that already
holds Phase 0 (`php artisan bakery:seed-demo --force`; `--phase=1` is the same while Phase 1 is the last seeder). It
adds only what Phase 1 brings and changes nothing of Phase 0 (a test seeds Phase 0, then Phase 1, and compares every
catalog and stock table before and after):
- the 12 dining tables (T1-T10 and Terrace 1-2, in both languages, with seats), unless a table with that code exists;
- the eight payment channels, only those the branch lacks (the release's migration has already given them to existing
  branches);
- the shared pad: the device "Front pad" enrolled and one new account, "Pad 1" (a waiter-role account the tablet stays
  signed in with; the two waiters of Phase 0 switch on it by short code). Like every demo account it signs in with its
  phone and `BAKERY_DEMO_PASSWORD`, so the same variable must be set (production refuses the command without it); it has
  no short code, and the command lists it with a dash in the code column;
- today's sample orders (7, local date of the branch): one open on T3, two sent (kitchen and bar lines queued), one in
  progress, one ready, one served and unpaid (the till queue) and one paid in cash and closed, with a croissant sold from
  the display case. They are taken at the pad, sent and moved along by the same code the screens call, so the ledger,
  the audit log and the timelines hold what a real shift would; they draw a few units of demo stock. A second run on the
  same day writes nothing; a run on a later day adds that day's seven orders (and only those), so run it once when you
  want to show the demo, not on a schedule.
**Phase 2 (inventory and production).** After the release that ships Phase 2, run the same command once more on the branch that
holds Phases 0 and 1 (`php artisan bakery:seed-demo --force`, or `--phase=2`). It adds only what Phase 2 brings and rewrites nothing of
Phases 0 and 1 (a test seeds Phase 1, then Phase 2, and compares the dining room, the orders, the people, the stations, locations and
categories before and after). All of it goes through the code the screens call, so the audit log, the ledger and the inbox hold what
people doing it would have left; the queued listeners run synchronously for the length of the seed, so no worker is involved:
- three suppliers (made-up names and numbers), and an opening cost for every ingredient and bought-in product, so recipe costs are
  complete and the Catalog shows margins;
- two confirmed purchases received into the store (flour at a price above its opening cost, so the moving average visibly moves; dairy)
  and one draft purchase (packaging) to confirm by hand;
- eight reorder rules (flour, sugar, butter, milk, eggs and cream at the store, cream at the cake room, and the gate product);
- the gate product "Honey cake (slice)" with a recipe and a price of 150.00, **one slice on hand at the display case** (it is below its
  reorder minimum, so the first poll shows one low-stock line) and "Bread roll" version 2 beside version 1;
- three transfers out of the store: one confirmed (butter to the bakery), one requested by the chef and left for the store to act on
  (eggs to the kitchen), one rejected with a reason (cream to the cake room);
- production: an 80-baguette request due 06:00 tomorrow (priority high), a bread-roll request accepted by the bread baker, a batch of
  bread rolls waiting for the overseer's sign-off (nothing is written until someone confirms it), and one chocolate-cake batch
  confirmed today with its ledger rows.
A second run writes nothing, not even an audit row or an inbox line (everything it creates has a fixed key); what you have since
changed or cancelled stays. The gate product is the one to show the main flow with: sell the slice at the pad and watch the request
reach the cake baker's board (on the live host after the cron tick, see "Queued work"). It draws only on stock the earlier phases left,
and nothing in it holds a secret: the same `BAKERY_DEMO_PASSWORD` as before is all the command needs.
Nothing secret is printed or stored: the password is never shown, and the sample orders carry made-up tables and
products only. Never run it with `--branch` pointing at the "E2E Test Branch" or at shop data (a branch that is not a
demo branch is refused anyway). `--branch="<name>"` loads a second, separate demo
branch. To retire the demo, deactivate its users under People (soft deletes only; nothing is ever removed from the
ledger).

## Turning on web push and Telegram

Every notification is in the in-app inbox (the bell) whatever happens here: the inbox needs no setting and cannot be switched
off. Web push and Telegram are extra ways to reach people outside the app, and both ship **off**. Nothing below goes in
GitHub, the repository or a chat: the values live only in the server `.env` (`~/bakery-ops.orcamind.dev/.env`, mode 600, which
a deploy never overwrites). A driver is on only when its flag is `true` **and** every setting it needs is present;
`php artisan notifications:status` prints which drivers are on and, for those that are not, which settings are missing (names
only, never a value). SMS has an interface slot and no driver: no gateway has been chosen.

Run the commands from the site folder: `cd ~/bakery-ops.orcamind.dev` and use `/opt/alt/php83/usr/bin/php artisan ...`.

### Web push (VAPID)

1. Make a key pair: `php artisan notifications:vapid-keys`. It prints two lines **once** and stores nothing; if the pair is
   lost, make another and have people switch push on again.
2. Add to the server `.env` (the contact address is yours, any `https:` or `mailto:` address that reaches you):

   ```
   NOTIFY_PUSH_ENABLED=true
   VAPID_SUBJECT=<https address of the site, or mailto: your address>
   VAPID_PUBLIC_KEY=<the public line printed by the command>
   VAPID_PRIVATE_KEY=<the private line printed by the command>
   ```

3. Rebuild the cached configuration, because a release caches it and an `.env` edit is not read until then:
   `php artisan config:cache`. Then `php artisan notifications:status` must say `push` is `on`.
4. Each person switches push on from the bell (the browser asks for permission). The public key reaches the browser from
   `GET /api/v1/notifications/channels`, so the web build needs no variable.

### Telegram

1. In Telegram, talk to @BotFather: `/newbot`, choose a name, and keep the token it gives you secret.
2. Make a long random string for the webhook secret (for example `openssl rand -hex 32`) and add to the server `.env`:

   ```
   NOTIFY_TELEGRAM_ENABLED=true
   TELEGRAM_BOT_TOKEN=<the token from BotFather>
   TELEGRAM_BOT_USERNAME=<the bot name, without the @>
   TELEGRAM_WEBHOOK_SECRET=<the long random string>
   ```

3. `php artisan config:cache`, then `php artisan notifications:telegram-webhook`: it tells Telegram to send the bot's messages
   to the site's `/api/v1/telegram/webhook/{secret}`, with the secret also as the header token, and prints what Telegram
   answered (never the token or the secret). Run it again if the secret or the address of the site ever changes.
   `php artisan notifications:status` must say `telegram` is `on`.
4. Each person links their chat from their profile: the app shows a one-time code (valid 10 minutes) and a link that opens the
   bot, and the bot answers that the chat is linked. A person can remove the link from the same place.

### Turning one off, and what to expect

- Set the flag to `false` (or remove it), run `php artisan config:cache`: nothing more is planned or sent for that driver, the
  controls disappear from the app, and a delivery that was already planned ends as failed with `driver_disabled`. People keep
  every inbox line.
- The queue is drained once a minute, so a push or Telegram message can arrive up to about a minute after the event.
  A failed send is tried three times (after 30 s, then 120 s) and then marked `failed` on its delivery row; a subscription or
  a chat that no longer exists is marked `gone` and removed from the plans.
- **Not verified until the keys exist:** that the shared host lets PHP reach `api.telegram.org` and the browsers' push services
  over HTTPS, and that `openssl`, `curl` and `mbstring` are present for `alt-php83` for the push library (check:
  `/opt/alt/php83/usr/bin/php -m | grep -E 'openssl|curl|mbstring'`). If either fails the inbox still works; the deliveries show the error.
- CI never sends anything: its `.env.ci` switches on only the `array` driver, which records what it was asked to send.

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
