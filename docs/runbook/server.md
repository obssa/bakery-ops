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
   Laravel's rules), storage skeleton, `migrate --force`, `optimize`, `storage:link`, `queue:restart`, `receipts:fonts` (step 4.3e, never fatal),
   permissions, up.
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
**Phase 3 (shifts).** `bakery:seed-demo` now ends with `Phase3Seeder` (`--phase=3`, or no `--phase`). What it does depends on the branch, and it says which in its output:

- *A fresh demo branch* (it does not exist yet, or holds no stock movement and no shift): the clock is moved to 05:30 local three days ago before Phase 0 runs, Phases 0 to 2 are seeded
  there, and `Phase3Seeder` plays two days forward through the code the screens call: the anchor day's carried shifts are closed and locked; D-2 and D-1 each get a Day shift per
  station (counted from the previous locked closing), waste, a transfer from the store and three cash orders paid in the till's shift, closings, and the bakery's Night shift
  (planned with the bread baker, started 22:05 on D-2, closed 05:55 on D-1). Planned differences, decided by the manager as the second person: 500 g of flour in the kitchen (D-2), 3 loaves
  over at the bakery (D-1), 20.00 short in the till's drawer (D-1). The bar's closing of D-1 (250 ml of milk short) is left **pending approval** for the admin's first demo. Today every
  station has an open shift opened from yesterday's locked closing (the bar's started without counting, because of the pending closing; the till's drawer is declared), a handover note
  from the chef is on the kitchen, and there are the Day and Night templates, the roster and the five variance reasons. About a minute on a developer PC. The real clock is put back
  afterwards; nothing is dated in the future.
- *A branch that already holds movements* (the live demo bakery, loaded on Phases 0 to 2 on 2026-10-21/22): **no history is written**. The ledger is append-only and rows backdated under rows it
  has already shown would rewrite what it showed, so the command does only the "today" part: the templates, the five reasons, the roster, today's shifts for a station with none running
  (counted from the current ledger, the till's drawer declared) and the kitchen's note, and prints "history not written". Earlier phases' seeders run first as they always do:
  `DemoShifts` opens a carried shift at any tracked station without one, and Phase 1 adds the new day's seven sample orders. No row is dated before the newest movement that was there.
  For the full history load a **new** demo branch: `php artisan bakery:seed-demo --force --branch="Demo Bakery 2"`.
- A second run writes nothing either way. Running it on the live demo branch is the owner's decision (the report lists it).

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

## Shift rules and the kill switch (Phase 3, on since step 3.7e)

Three rules are on by default in the code (`config/ledger.php`, `config/shifts.php`, `config/payments.php`), in `.env.example` and `.env.ci`. The live
`.env` sets none of them, so the code defaults apply; each has its own variable, so one rule can be switched off alone.

| Variable (server `.env`) | On (default) | Off (kill switch) |
|---|---|---|
| `LEDGER_REQUIRE_SHIFT_FOR_STATION_LOCATIONS` | The stock ledger refuses a movement that changes on-hand stock at a location of a station that tracks shifts unless a shift is open there (`409 inventory.shift.required`). Locations of no station (the E2E branch's display case, the store) and a count's own correction are exempt. | Stock moves at any station with no shift, as before Phase 3; rows carry the shift only when one runs. |
| `SHIFTS_OPEN_AD_HOC_ON_ACCEPT` | A baker who accepts a production request at a station with no running shift opens a call-in shift (`origin = call_in`, carried); the accept, the batch and its movements carry it. | Nothing is opened; the accept carries a shift only if one already runs (the baker joins it). |
| `PAYMENTS_REQUIRE_SHIFT_FOR_CASH` | A cash payment at the till needs the cashier to carry an open shift (`409 payments.shift.required`); the web till's Pay button waits for a running shift. Since step 4.3b the same holds for **every** channel (Telebirr, the banks): `Totals::byChannel(shift)` and the cashier's per-channel reconciliation are keyed on the payment's own shift. | Every channel is taken with or without a shift, as before; the till's cash count still works on whatever payments carry the shift. |

**Kill switch** (nothing is deployed and no data is lost; shifts already opened stay and are closed or cancelled from the screens). On the server,
as the account user, in the release's `.env` (`~/bakery-ops.orcamind.dev/.env`; copy it to `.env.bak` first):

```
cd ~/bakery-ops.orcamind.dev
cp .env .env.bak
printf '
LEDGER_REQUIRE_SHIFT_FOR_STATION_LOCATIONS=false
SHIFTS_OPEN_AD_HOC_ON_ACCEPT=false
PAYMENTS_REQUIRE_SHIFT_FOR_CASH=false
' >> .env
/opt/alt/php83/usr/bin/php artisan config:cache
```

(or edit the three lines in place if they are already there; the last value wins only when there is one line per variable, so remove duplicates).

**Every channel, one line (step 4.3b, phase-4 plan 10.1 R-A).** `config/payments.php` also reads `PAYMENTS_REQUIRE_SHIFT`, the rule for the digital channels.
The live `.env` does not set it, so it follows `PAYMENTS_REQUIRE_SHIFT_FOR_CASH`: the kill switch above switches the shift rule off for cash and for every
other channel at once, and it is still three lines. Set `PAYMENTS_REQUIRE_SHIFT` only to split the two rules: `false` keeps the rule for cash and drops it
for the other channels; `true` asks every channel, cash included, even with the cash line `false`. Leave it out otherwise.
The change takes effect with `config:cache`; verify with `php artisan tinker --execute="var_dump(config('ledger.require_shift_for_station_locations'), config('shifts.open_ad_hoc_on_accept'), config('payments.require_shift_for_cash'), config('payments.require_shift'));"`
(three `false`, then `NULL`: the every-channel rule follows the cash line). To switch a rule back on, delete its line (or set `true`) and run `config:cache` again; a deploy runs `optimize` and so keeps whatever the `.env` says.

The web build carries one matching switch, `NEXT_PUBLIC_REQUIRE_SHIFT_FOR_CASH=true` (set in `deploy.yml` for the static export): the till's Pay button
blocks when no shift runs. With the cash rule off on the server the button still blocks until the next release is built with it `false`; the API would accept the payment
anyway, so this is a convenience, not a safety. `CI` proves both sides: every job runs with the rules on, and the `rules-off-gates` job runs the Phase 0-2 gate tests with all three `false`.
`SHIFTS_START_GRACE_MINUTES` (default 60) is how long before its window starts a scheduled shift may be started.

**What staff see after the flip.** A station that tracks shifts must have a shift started from the shift bar (Start shift) before orders are taken, stock moves or
a baker confirms a batch there; the till needs its shift for cash. The demo bakery (branch 2) has no open shifts until someone starts them, or until `bakery:seed-demo`
is run again (it opens carried shifts at every tracked demo station and does only today's part of Phase 3, see "Demo bakery"); that decision is the owner's, not part of the flip.

## Nightly chain verification (Phase 3)

Every locked count is a link in the hash chain of its place (ADR 0012). The scheduler runs `php artisan shifts:verify-chain` every day at 03:00
Africa/Addis_Ababa (`routes/console.php`, `withoutOverlapping`; it needs only the `schedule:run` cron entry above). For every branch and every place it
checks the numbering, the previous hash, the hash and that the stored bytes are what the live rows produce today. It prints and logs one line per place
with the **head hash** (`storage/logs/laravel-*.log`, message `shifts:verify-chain`, context `branch_id, location_id, checked, head_hash, ok`), and for every
break it dispatches one `ChainBroken` notification (critical: the admins and every `shifts.replay` holder, in the app, by push and by Telegram where
those are on, deep link `/admin/shifts?location=<id>`). Exit code 0 is a clean run, 1 a break, 2 an unknown `--branch`.

- Run it by hand: `cd ~/bakery-ops.orcamind.dev && /opt/alt/php83/usr/bin/php artisan shifts:verify-chain` (add `--branch=<id>`).
- One place: `GET /api/v1/locations/{id}/chain/verify` (admin screen: Shifts, the place's chain card) answers `{ok, checked, head_hash, failures}`.
- **Keep the head hashes.** A chain cut at its newest end still verifies; only a head hash kept elsewhere tells. The nightly log lines are that record
  on the server; copy a week of them to the owner's records each Monday during the pilot (Phase 4 puts them in the weekly summary).

### When the chain reports a break

A break means a stored count, its lines or its cash declaration no longer match the hash that sealed them, or a link is missing. Do not "repair" it and do not
recompute a hash.

1. Read the notification or the command output: it names the place, the link (`chain_seq`), the count and the kind. `gap`: a link is missing from the
   numbering. `previous_mismatch`: the link does not point at the hash before it. `hash_mismatch`: the stored hash is not the hash of the stored bytes.
   `payload_mismatch`: the stored bytes are no longer what the live rows produce (a line, note, reason, figure or the cash declaration was changed after the lock).
   One tampered link names itself and the link after it, nothing more.
2. Look at the count on the admin Shifts screen (the locked count shows its hash and its decisions) and at the audit log around the count's `locked_at`.
   Ask who had database access. An application bug is as likely as a person: check the deploys since the last clean night (`gh run list`, the release log).
3. Compare the head hash with the last clean night's line in the log (and the copy in the owner's records). If the break is at the newest links only, the head
   differs; if it is in the middle, links after it are still intact.
4. **Fixing a wrong figure is an unlock, not an edit.** The admin unlocks the count with a reason (`POST /stock-counts/{count}/unlock`): the old link stays and
   keeps verifying, the revision is counted again, approved by a second person, and becomes the next link. A tampered link itself is not unlocked: restore the
   rows from the last backup taken before the break, verify again, and record what happened.
5. Until it is explained, treat the place's counts since the break as unconfirmed; stock itself is the ledger, which is append-only and has its own triggers, so
   shifts and sales carry on. Record the break, the cause and the fix in the pilot log (`docs/phases/phase-3-pilot-feedback-template.md`).

## Pilot day-one checklist (Phase 3)

Before the first shift, on https://bakery-ops.orcamind.dev/app/ as the admin:

- [ ] Every person has an account with the right role and a short code, and is assigned to the stations they work at with **can count** on (the cake baker is also
      flagged **overseer** at the bakery, which requires a second person for its counts; the cake room requires one too, co-signed by the admin or manager).
- [ ] The store tracks no shifts (`select code, tracks_shifts from stations` over SSH: the store is `false`, every other station `true`).
- [ ] Templates exist under Shifts, Templates: Day 06:00-14:00 at the till, kitchen, bar, cake room and bakery, Night 22:00-06:00 at the bakery (the demo seeds these;
      on the shop branch create them). A station with no template simply starts ad hoc, dated by the local day.
- [ ] The till has a **cash drawer** location (Locations) and the denominations in `config/shifts.php` match the notes and coins in use (ETB 200, 100, 50, 10, 5, 1
      and 0.50, 0.25, 0.10, 0.05).
- [ ] Variance reasons are the five (spoilage, breakage, miscount, theft suspected, other + note); edit the Amharic wording with the staff.
- [ ] Stock is on the ledger at every station (the first opening count compares against it); a first opening of a drawer has no expected figure and is taken as counted.
- [ ] The cron entries are running (`schedule:run` and `queue:work`); `storage/logs` shows last night's `shifts:verify-chain` lines; the kill switch has been rehearsed once on
      a staging copy or read through (above).
- [ ] The tablet at each station is signed in as its station account and the pad idles to its lock screen after 60 s.

On the day:

- [ ] Each station starts its shift (Start shift on the shift bar) and makes the opening count: "Nothing changed" where it is true, a reason where it is not. The cashier declares the drawer.
- [ ] A late baker who accepts a request at a closed station gets a call-in shift: check it on Shifts and close it at the end of the day.
- [ ] At the end: Close shift at every station, closing counts, the cashier's cash declaration with the float that stays; the manager or admin approves what differs (never the person who counted); locked counts show their hash.
- [ ] Next morning: the nightly run was clean; the pending approvals queue is empty; the next opening counts are prefilled from the locked closings.
- [ ] Write the day's notes in `docs/phases/phase-3-pilot-feedback-template.md`.

Decisions that stay with the owner and are asked of the pilot: whether stock may go negative on a count approval (refused by default), whether a sole admin may
confirm their own count (refused by default), and the push and Telegram keys (the notifications that tell approvers about a count work in the app without them).

## Phase 4: receipt photos and analytics (host checks)

Phase 4 stores receipt photos on the account's private disk and builds summary tables behind the dashboards. The live host has no MinIO,
no S3 and no Docker; the code talks to a `files` disk (`FILES_DISK`, default the local private folder `storage/app/private/files`), so
nothing here needs a service that is not installed. The detailed design is in `docs/phases/phase-4-plan.md` (sections 6, 10.1 and 10.9).

### PHP extensions the photo feature needs (check on every host move)

Checked read-only on 2026-10-24: `alt-php83` on this host has `gd` (JPEG, PNG and WebP), `exif`, `fileinfo`, `mbstring`, `zip`, `dom`,
`xmlwriter`, `xmlreader`, `libxml` and `intl`; it has no `imagick`, and the code does not need it. `gd` is required (the feature is off
without it); `exif` is optional (a pure PHP fallback reads the orientation). Re-check after any PHP version change or host move, with the
CLI PHP that the cron and the deploy use:

```
/opt/alt/php83/usr/bin/php -m | grep -E 'gd|exif|fileinfo|mbstring|zip|dom|xmlwriter|xmlreader|libxml|intl'
/opt/alt/php83/usr/bin/php ~/bakery-ops.orcamind.dev/artisan files:doctor
```

`files:doctor` (built in step 4.2a) prints one row per extension and per `gd` capability (JPEG, PNG, WebP, FreeType), the PHP limits
(`memory_limit`, `upload_max_filesize`, `post_max_size`), free disk space, the size of the files folder, whether the folders are writable,
and a round trip that re-encodes a generated 3000x2000 JPEG with a planted GPS block and reports whether the metadata is gone. The web
PHP and the CLI PHP can differ on cPanel (MultiPHP INI Editor, Select PHP Version), so the admin also gets `GET /files/doctor` (step 4.2b):
compare the two tables after a host change.

`files:doctor` is built (step 4.2a). It prints a table of `check`, `status` (`ok`, `warn` or `missing`) and `detail`, ends with the round trip line
(`exif stripped: yes, longest edge: 1600, bytes: ..., ms: ...`) and exits 1 when any row is `missing`. `warn` rows are not blockers: no `exif`
(the pure PHP reader is used), no WebP in `gd` (WebP uploads answer `files.unsupported_type`), a `memory_limit` under 256M (a 24 MP photo may not
decode), a missing `storage/fonts`. It writes one small probe object to the files disk and deletes it; nothing else. Run it as shown above, once
after a deploy that touches PHP and once before the photo feature is switched on. To move to a bucket later: `FILES_DISK=files_s3` plus the `AWS_*`
variables, then copy `storage/app/private/files` into the bucket under the same keys (`stored_files.disk` records where each object lives).

### Disk space

Photos are about 180 KB each at 1600 px: 200 digital payments a day is about 1 GB a month. The account's home directory used 961 MB on
2026-10-24 and `quota -s` reports no quota on this host: read the figure in cPanel (Disk Usage) before the photos grow, and move to an
S3-compatible disk (`FILES_DISK=files_s3`, Cloudflare R2) before the account passes 70% of its quota or the nightly backup gets slow.
Unattached uploads are purged after 24 hours (`files:purge-orphans`).

### Files (step 4.2b): where they live, quota, backup, the move to S3

- **Where.** The default disk is `files`: `storage/app/private/files/receipts/{branch_id}/{yyyy}/{mm}/{uuid}.jpg` under the app folder,
  outside `public/`, so the web server never serves it; `rsync --delete` in the deploy protects `storage/`. No original file name is kept.
  `stored_files` (the database) knows each object's disk, size, sha256 and what it is attached to. The only read path is a signed URL
  (`GET /api/v1/files/{id}?expires&signature`, five minutes, `Cache-Control: private, no-store`); a plain GET answers 403.
- **Who.** `files.upload` (admin, manager, cashier) uploads and deletes one's own unattached photo; `payments.view` (same roles) asks for a fresh
  signed URL; a manager (`payments.void`) may delete any unattached photo; `GET /api/v1/files/doctor` is the admin's. The migration
  `grant_phase_4_permissions` puts `files.upload` and `payments.view` in the live database at the next deploy, before any screen uses them.
- **Orphans.** A photo nobody attached within `files.orphan_hours` (24) is removed, object and row, by `files:purge-orphans`, hourly from the
  existing `schedule:run` cron line. It prints how many it removed and never touches an attached photo.
- **Logs.** A signed URL is a bearer credential while it lives. The application scrubs `signature=` and `expires=` (and the S3 `X-Amz-*`
  parameters) from anything it logs and never logs an object key. The web server's own access log cannot be changed on shared hosting; the
  five-minute life is the mitigation.
- **Quota.** About 180 KB a photo, about 1 GB a month at 200 digital payments a day. Read the figure in cPanel (Disk Usage); the home folder
  was 961 MB on 2026-10-24 and `quota -s` reports no quota here. `files:doctor` prints the size of the files folder and the free space.
  Act before 70% of the account's quota.
- **Backup.** The nightly backup must include `storage/app/private/files` as well as the database: a database restored without the folder has
  rows whose objects are gone (the signed URL answers 404, nothing else breaks). Restore both from the same night. A copy of the folder is
  plain files, so `rsync -a storage/app/private/files/ <backup>/files/` is enough.
- **Moving to S3 or R2.** (1) Create the bucket and an access key; set `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`,
  `AWS_BUCKET` and `AWS_ENDPOINT` (the bucket's own endpoint, the host the URLs are signed for: it must be reachable from the browser;
  `AWS_USE_PATH_STYLE_ENDPOINT=true` for MinIO) in `.env`. (2) Copy the folder key for key:
  `rclone copy storage/app/private/files :s3:<bucket>` (or `aws s3 sync`), so `receipts/...` is the same key in the bucket. (3) Set
  `FILES_DISK=files_s3` and `php artisan config:cache`. New photos go to the bucket; old rows keep `disk = files` and still resolve while
  the local folder exists, so a half-moved bucket works. (4) When `select disk, count(*) from stored_files where deleted_at is null group by disk` shows
  no row on `files`, retire the folder. The CI job `files-s3` proves put, presigned URL over HTTP and delete against MinIO
  (it never runs against the live host). Two hosts (traps.md): inside a compose stack the app signs for `minio:9000` and a browser would use
  `localhost:9000`; a signature is bound to the host, so on a real deployment use one public endpoint for both.

### Receipts (step 4.3e): the font folder, memory, `receipts:fonts`

- **What.** `GET /api/v1/orders/{order}/receipt.pdf` (payment readers) and the signed customer link of `POST /api/v1/orders/{order}/receipt-link`
  (`GET /api/v1/receipts/{order}?branch&expires&signature`, 72 hours by default, at most 168) answer a PDF made by dompdf in the request: no queue,
  no external service, no network fetch (dompdf runs with remote files, PHP and JavaScript off). A receipt is at most two A4 pages, a few kB.
  It prints no person's name and only the last 4 characters of a payment reference; the PDF is `Cache-Control: private, no-store`.
- **The font folder.** Latin text prints in Helvetica (a PDF core font, nothing embedded); Amharic in Noto Sans Ethiopic (SIL Open Font
  License 1.1), bundled in the release at `resources/fonts/noto-sans-ethiopic/` with its `OFL.txt`, embedded in each PDF as a subset. dompdf
  reads fonts from one writable folder, `storage/fonts` (`rsync --delete` protects `storage/`, so it survives deploys): the first receipt, or
  `php artisan receipts:fonts`, copies the TTF there with its metrics (`.ufm`) and adds it to dompdf's `installed-fonts.json`, under the
  cache lock `receipts:fonts` (the database cache's lock here), every file written under a temporary name and renamed into place. dompdf also
  writes small `*.ufm.json` metric caches there. Nothing else writes the folder; deleting it is safe (the next receipt rebuilds it).
- **`receipts:fonts`.** `release.sh` runs it after `queue:restart` on every deploy, so the first receipt of the day does not pay for it. It writes
  nothing when the font is installed and prints "already installed"; it never fails a deploy (`|| echo ...`). By hand:
  `/opt/alt/php83/usr/bin/php ~/bakery-ops.orcamind.dev/artisan receipts:fonts`. Exit 1 with "could not be registered" means `storage/fonts`
  is not writable by the account (fix the permission: `chmod 755 storage/fonts`) or the bundled file is missing (a broken release: redeploy).
  `files:doctor` also reports a missing or read-only `storage/fonts` as `warn`.
- **Memory and time.** About 40 MB of PHP memory and well under a second per receipt (measured in the Docker image; subsetting DejaVu Sans
  instead of using Helvetica took 3 to 5 seconds, which is why Latin text uses the core font). A `memory_limit` of 128M is enough; 256M is what
  `files:doctor` asks for the photos anyway. A name with a letter Helvetica lacks (a Polish L) falls back to DejaVu Sans and that receipt takes
  a few seconds longer.
- **Exports.** `GET /api/v1/payments/export?format=csv|xlsx` (admin, manager) streams at most 20 000 payments (`payments.export_max_rows`);
  the xlsx is written by OpenSpout to a temporary file under the system temp folder and removed once sent, a few MB of memory whatever the
  rows. Each export is an audit row `payment.export`. No photo URL or object key is ever in a file.

### Dashboards and the cron

`analytics:rebuild`, `analytics:weekly-summary` and `files:purge-orphans` run from the existing `schedule:run` line (section "Cron entries").
No extra cron entry is needed. k6 is never run against this host; to time the dashboards on the live demo branch use
`php artisan analytics:bench` (step 4.4d), which makes the same timed calls in process and exits non-zero over budget.

How the summary tables stay current (step 4.4b, ADR 0014). Every rebuild is one local day of one branch, a `RebuildDay` job on the
database queue, run by the `queue:work` cron line:

- **Today, during service.** A payment (taken, verified, disputed, voided) or an order paid or voided asks for today's rebuild 60 s later.
  The job is unique per branch and day, so a busy minute gives one rebuild. Expect the dashboards' today to be at most about three
  minutes behind the till (the listener's cron tick, the 60 s, the job's cron tick).
- **A locked shift** rebuilds every local day from its date to the day it locked: a Night shift that locks in the morning rebuilds both.
- **00:05 Addis Ababa**: `analytics:rebuild --nightly` queues yesterday and the day before for every branch. **05:30**:
  `analytics:rebuild --since-last` queues the days of every shift that locked after they were last built (the last 7 days).
- **By hand**, from the admin's screen or the API: `POST /api/v1/analytics/rebuild {from, to, sync}` (admin only). `sync: true` rebuilds
  up to 7 days at once in the request; otherwise up to 31 days go on the queue. From a shell, for any range up to 400 days:
  `/opt/alt/php83/usr/bin/php ~/bakery-ops.orcamind.dev/artisan analytics:rebuild --from=2026-11-01 --to=2026-11-30` (queued) or with
  `--sync` (here and now, one day at a time; prints each day's version and row counts).
- **Is it current?** `GET /api/v1/analytics/freshness?from=&to=` lists each built day's version, `rebuilt_at` and trigger, the days not
  built yet, and `running` (a rebuild was asked for and has not run). A day stays `running` for at most 15 minutes when its job is lost;
  `php artisan queue:failed` shows a job that failed (`analytics.rebuild.failed` in the log names the branch, the day and the error's class).
- **Nothing to clean up.** The summary tables are derived: a rebuild replaces the day, and rebuilding a day twice gives the same rows.
- **The dashboards** (step 4.4c: `GET /api/v1/analytics/overview`, `/sales`, `/sales/hourly`, `/channels`, `/items`, `/items/{id}`,
  `/stations`, `/staff`, `/shifts`) read these tables only and answer `304` until a day of their range is rebuilt; a day not built is listed
  in `stale_days`, never shown as zero. **Once after the 4.4c deploy**, rebuild the days built before it, so their mean prep times count
  (`prep_samples` and `prep_seconds_sum` are new and zero on older rows): `analytics:rebuild --from=<the first day in analytics_days>
  --to=<today>` (queued, at most 400 days; the dashboards show the old rows meanwhile, with fewer prep samples).

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
