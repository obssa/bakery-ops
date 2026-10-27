# ADR 0006: Run the system at https://bakery-ops.orcamind.dev (shared cPanel), deploy from GitHub, test in CI and on the live URL

- Status: accepted
- Date: 2026-10-19
- Phase: 0

## Context

Ramin provided the address where the system runs: https://bakery-ops.orcamind.dev. The domain is on
Cloudflare in front of the same shared cPanel account (CloudLinux, LiteSpeed, PHP 8.3 via `alt-php83`,
MySQL, cron, SSH) that already hosts another of his sites deployed from GitHub Actions. He asked for
full CI/CD from the repository to that URL, to remain the sole author of every commit, and for tests to
run on the live URL and in CI rather than on his PC.

The architecture document's recommended stack (Docker Compose on a VPS with PostgreSQL 16, Redis,
Reverb WebSockets, Horizon) cannot run on shared cPanel: no Docker, no Redis, no long-running processes,
no PostgreSQL (unless the host enables it), no Node server unless the host's Node.js selector is enabled.
The document itself allows MySQL 8 "if hosting dictates it" and lists cloud-only hosting with
offline-tolerant clients as deployment option (a).

## Decision

1. **Deploy pipeline** (`.github/workflows/deploy.yml`): runs after the `ci` workflow succeeds on `main`
   (so `gate-p0` and everything it needs are green). It builds the release on the runner (`composer
   install --no-dev`, optional static web export), `rsync`s it over SSH into the site folder (never
   `.env`, `storage/`, `bootstrap/cache/`, `public/.htaccess`), runs `scripts/deploy/release.sh` on the
   server (maintenance window, migrate, optimize, queue restart), smoke-tests the live URL, and restores
   the previous release on failure. It never creates commits; the deploy key lives only in GitHub secrets
   and the account's `authorized_keys`. Deploys are off until the repository variable `DEPLOY_ENABLED`
   is `true` (set after one-time provisioning, `docs/runbook/server.md`).
2. **Authorship**: every commit is authored and committed by the repository owner. No `Co-Authored-By`
   or tool-attribution trailers; `.githooks/commit-msg` rejects them; history was rewritten once on
   2026-10-19 to remove the trailers that existed.
3. **Where tests run**: unit, feature, property, concurrency, arch and invariant tests run in GitHub
   Actions on every push and pull request; end-to-end tests run in CI against the compose stack
   (`e2e-p<N>` jobs) and, after each deploy, the Playwright tests tagged `@live` run against
   https://bakery-ops.orcamind.dev (`PLAYWRIGHT_BASE_URL`). The developer PC is not a test target;
   local Docker remains available for debugging only.
4. **Infrastructure dependencies are drivers chosen in `.env`, never hard-coded**:

   | Concern | On cPanel (now) | VPS / later |
   |---|---|---|
   | Database | MariaDB 10.11 on the host (`DB_CONNECTION=mariadb`; CI and the local stack run the same image) unless the host provides PostgreSQL; all schema and tests must stay portable (DECIMAL, JSON, triggers, `FOR UPDATE`, no materialized views, no partial indexes) | PostgreSQL 16 |
   | Cache / session / queue | `database` drivers; queue drained by cron `queue:work --stop-when-empty --max-time=50` every minute | Redis + Horizon |
   | Live updates (KDS, pads) | polling of `GET /stations/{id}/tickets?since=<cursor>` and `/menu` availability every few seconds; Reverb channels stay in the code behind `BROADCAST_CONNECTION` and are enabled when a WebSocket host exists (hosted Pusher-compatible service or a VPS) | Reverb |
   | Files (receipt photos) | local `private` disk on the account, signed URLs through Laravel | S3-compatible |
   | Web app | static export of the Next.js app served from `public/app/` by LiteSpeed (`DEPLOY_WEB_MODE=static`) once the shell is made export-safe; or the host's Node.js selector if available | Node server behind Caddy |
   | Notifications | Telegram bot + in-app; Web Push works from a static PWA | same |

5. **Phase impact**: Phase 0's `stock_balances` table (ADR 0002) already carries the row-lock design, so
   dropping the materialized view costs nothing: `item_stock_view` becomes a reconciliation query/report,
   not a view. The CI module jobs run against the production engine (MariaDB 10.11, found on the host on 2026-10-19; service) so tests prove what
   production runs; a PostgreSQL job can be added later if a VPS move is planned.

Note (step 0.7, 2026-10-20): the web shell is export-safe. It is served under `/app/` on the API's own origin
(`basePath: '/app'`, `trailingSlash`, `/app/en/...` and `/app/am/...` prerendered, no middleware, record pages by
`?id=`); `GET /` redirects to `/app/`; `public/.htaccess` serves `/app` without PHP (rules in `docs/runbook/server.md`).
Being same-origin, production needs no CORS origin at all (ADR 0007's allowlist is `http://localhost:3000` locally and
in CI only). The live `@live` tests sign in as the admin of the isolated "E2E Test Branch".

Note (Phase 4, step 4.6b, 2026-10-28): the Files row of the table is concrete (built in steps 4.2a and 4.2b; ADR 0015). Receipt photos
live on the `files` disk, `storage/app/private/files` on the account (outside `public/`, protected from the deploy's `rsync --delete` with
the rest of `storage/`), chosen by `FILES_DISK`; the S3-compatible `files_s3` disk is one variable and a key-for-key copy away, proved in
CI only by the `files-s3` job against MinIO. Every read is a five-minute signed URL served by PHP (`private, no-store`). The host's PHP
needs `gd` (JPEG, PNG; WebP optional), `fileinfo`, `mbstring`, `zip`, `dom` and the XML extensions; `exif` is optional (a pure-PHP
orientation reader stands in); `imagick` is not used. `php artisan files:doctor` (the CLI PHP) and `GET /api/v1/files/doctor` (the web
PHP, which can differ on cPanel) check them, the limits, the free space and the folder's size, with a round trip of a generated photo.
The photos count against the account's quota and must be in its nightly backup together with the database (`docs/runbook/server.md`,
"Files"). Phase 4 also kept every heavy job to one local day of one branch on the cron-drained queue (ADR 0014), so nothing needs a
process that lives longer than the cron's 50 seconds, and it measures the dashboards in process (`php artisan analytics:bench`) instead
of loading the shared host from outside (k6 runs only against the CI compose stack).

## Consequences

- Positive: one URL, automatic deploys, honest tests in the environment the shop will use, no local
  setup required on the developer PC, the owner is the only author on GitHub.
- Negative: no WebSockets on day one (polling, a few seconds of latency on the KDS), no Octane, cron-bound
  queue latency (up to a minute), shared-host CPU limits; the pilot must confirm these are acceptable or
  trigger the VPS move described in the architecture document (section 10, option b/c).

## Enforcement

`deploy.yml` (workflow_run gated on `ci` success and `main`), `scripts/deploy/*`, `.githooks/commit-msg`,
`live-e2e` job with `--grep @live`, `docs/runbook/server.md` provisioning checklist, CI services switched to
the production database engine.

## Alternatives considered

- Small VPS with Docker Compose (the document's recommendation): best fit for the stack, but the owner
  chose his existing hosting. Kept as the documented upgrade path; nothing in the code prevents it.
- Managed PaaS (Fly.io / Render) for the API with the web on Cloudflare Pages: more moving parts and
  accounts than the owner asked for.
