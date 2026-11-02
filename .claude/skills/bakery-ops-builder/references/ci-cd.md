# CI from commit one, and how the pipeline mirrors the dependency graph

Canonical files: `assets/ci/ci.yml`, `assets/ci/setup-api-action.yml`, `assets/ci/compose.ci.yml`.
Phase 0 installs them as `.github/workflows/ci.yml`, `.github/actions/setup-api/action.yml` and
`compose.ci.yml`. Deploy workflows are deliberately absent for now (CI only); the last section says how
they bolt on later.

## Principles

1. **Job dependencies = module dependencies.** `mod-inventory` needs `mod-catalog` needs `mod-identity`
   needs `api-arch`. A broken Catalog never lets Inventory tests run, so the failing job in the PR
   checks list names the lowest broken module.
2. **A module job runs its own tests and its consumers' contract tests against it.** `mod-inventory`
   runs `module:inventory, property, concurrency` and then
   `contract:orders->inventory, contract:production->inventory, contract:shifts->inventory`.
3. **Gate jobs are fan-in required checks.** `gate-p<N>` needs everything the phase depends on plus
   `gate-p<N-1>`, and runs `gate_check.py --mode done --phase N --ci`. All gates ≤ `current_phase`
   always run, so Phase 3 work cannot silently break the Phase 1 gate.
4. **Future-phase jobs exist but skip.** A `status` job reads `docs/phases/STATUS.json` and every
   phase-scoped job has `if: fromJSON(needs.status.outputs.phase) >= N`. Adding a phase is a STATUS
   change, not a YAML rewrite.
5. **Fast tests run natively, e2e runs on the real stack.** Unit/feature/property on `ubuntu-latest`
   with `shivammathur/setup-php` 8.3 (pinned to the Docker image's version) and `postgres:16` +
   `redis:7` service containers. E2E brings up `compose.yml` + `compose.ci.yml` (same images as
   production) and runs Playwright natively against the built Next app.
6. **Local parity.** `make ci` runs the same steps in the same order inside Docker. If local and CI
   disagree, fix the Makefile.
7. **Every job states `timeout-minutes`** (since 5.5d): 20 unless a measured run needs more (`mod-inventory` 30,
   `perf-dashboards` 40, `gate-p4` 45, `backup-drill` and `gate-p5` 25, `perf-load` 30), so a hung step cannot hold a runner
   for six hours. A job that calls a reusable workflow cannot set its own: the reusable workflow's job does (`e2e.yml` 30,
   `module-tests.yml` its `timeout_minutes` input).

## Job graph

```
status ─┬─ api-lint → api-arch ─┬→ mod-identity → mod-catalog → mod-inventory ─┬→ mod-orders → mod-payments ─┐
        │                       │                                               ├→ mod-production ──────────────┤
        │                       │                                               └→ mod-notifications ──────────┤
        │                       │                 mod-shifts ◄ [mod-orders, mod-inventory, mod-payments]         │
        │                       │                 mod-analytics ◄ [mod-shifts, mod-production, mod-notifications]│
        │                       ├→ openapi-check   ├→ erd-check   └→ invariants                                  │
        ├─ web-lint → web-build  ─ i18n-check                                                                    │
        ├─ docker-build (api image → artifact)                                                                   │
        ├─ e2e-p0 ◄ [mod-catalog, web-build, docker-build]      e2e-p1 ◄ [mod-payments, web-build, docker-build]
        │  rules-off-gates ◄ [mod-payments, mod-production, mod-notifications]   (gate:p0..p2 with the three shift rules off)
        │  e2e-p2 ◄ [mod-production, mod-notifications, …]     e2e-p3 ◄ [mod-shifts, …]
        │  e2e-p4 + perf-dashboards ◄ [mod-analytics, …]        e2e-p5 ◄ [mod-analytics, web-build, docker-build] (beside e2e-p4)
        │  web-errors ◄ [web-build]    backup-drill ◄ [docker-build, mod-inventory]    perf-load ◄ [docker-build, mod-analytics]
        │  print-bridge ◄ [status] (Node only, no Docker)
        └─ gate-p0 ◄ [api-arch, mod-inventory, mod-catalog, e2e-p0, openapi-check, erd-check, invariants]
           gate-p1 ◄ [gate-p0, mod-orders, mod-payments, e2e-p1]
           gate-p2 ◄ [gate-p1, mod-production, mod-notifications, e2e-p2]
           gate-p3 ◄ [gate-p2, mod-shifts, rules-off-gates, e2e-p3]
           gate-p4 ◄ [gate-p3, mod-analytics, e2e-p4, perf-dashboards] (+ files-s3 ◄ [mod-payments, docker-build], the S3 proof); seeds 90 days, runs gate:p4
           gate-p5 ◄ [gate-p4, e2e-p5, i18n-check (strict), backup-drill, perf-load, print-bridge]; runs gate:p5, then the go-live checklist

uptime.yml (5.5c) is a workflow of its own on a schedule, outside this graph.
```

The exact `needs:` lists are in the manifest (`ci.module_jobs`, `ci.e2e_jobs`, `ci.gate_jobs`).
Trade-off: ten module jobs each boot Postgres (~40 s). If a run exceeds ~12 minutes, collapse `mod-*`
into one fail-fast `api-modules` job that runs the same groups in the same order; dependency semantics
survive, only parallelism is lost.

## Per-job essentials

| Job | Does |
|---|---|
| `status` | `jq -r .current_phase docs/phases/STATUS.json` → output `phase` |
| `api-lint` | composer install (cached), `pint --test`, `phpstan analyse` (result cache) |
| `api-arch` | `php scripts/migration-lint.php`, `pest --group=arch,invariants` |
| `mod-<x>` | (the reusable `module-tests.yml`, timeout `timeout_minutes`, 20 by default; `mod-inventory` 30: it measured 18 to 19 minutes in Phase 4) services postgres + redis; `artisan migrate --force --env=testing`; `pest --group=module:<x>[,property,concurrency]`; then `pest --group=<consumers' contracts against x>` (skipped when the group has no tests yet) |
| `invariants` | `python scripts/check_invariants.py --all` (uses `artisan route:list --json` for routes) |
| `openapi-check` | `artisan scramble:export` → diff against `docs/openapi.json` |
| `erd-check` | `php scripts/erd-check.php` (tables in `information_schema` vs `docs/erd.dbml`) |
| `web-lint` | pnpm install (cached), eslint (with the local `no-literal-text` rule of step 5.4a and its `node --test` cases), `tsc --noEmit` |
| `web-build` | `next build` (standalone) → artifact |
| `i18n-check` | `python3 scripts/check_invariants.py --i18n` (`--strict-i18n` when phase ≥ 5: no `__TODO__` left); from step 5.4a also the review sheet tests (`python3 -m unittest discover -s scripts/tests`) and `scripts/i18n_report.py` (a report, never a failure) |
| `docker-build` | `docker/build-push-action` with `cache-from/to: type=gha`, `outputs: type=docker,dest=api.tar` → artifact |
| `e2e-p<N>` | download artifacts; `docker load`; `docker compose -f compose.yml -f compose.ci.yml up -d --wait`; `artisan migrate:fresh --seed --seeder=DemoSeeder -- --phase=N`; setup-node 24 + Playwright browser cache; `npx playwright test --project=p<N>-*`, with `--pass-with-no-tests` only while phase N is `todo`/`planning`/`plan_approved` (once it is `in_progress` or later, each of its projects must list at least one test); upload `test-results` on failure |
| `perf-dashboards` | after `mod-analytics`, beside `e2e-p4`: the compose stack with the 90-day seed (`DEMO_HISTORY_DAYS` unset, about 12 minutes; timeout 40), the demo admin signed in by `curl`, every read warmed once, then k6 in its pinned image (`grafana/k6:1.3.0@sha256:...`, `scripts/perf/dashboards.k6.js`): two admins read every `/analytics/*` dashboard of the 90 days compared with the 90 before, every answer 200, `p(95)<1000` per read and in all; the per-read figures go to the step summary. Never against the live host; the timed in-process substitute is `DashboardBudgetGateTest` (in `gate:p4`) and `php artisan analytics:bench` (any host) |
| `gate-p<N>` | `python scripts/gate_check.py --mode done --phase N --ci` |
| `gate-p4` | first seeds the demo bakery once on the compose stack (`SEED_PHASE=4`, `DEMO_HISTORY_DAYS=$GATE_SEED_DAYS`, 90), then runs `pest --group=gate:p4` in the app container in one process with `GATE_SEED_DATABASE=bakery` and `GATE_SEED_DAYS`: the seeded tests (`Tests\Support\SeededGate`) point their own connection at the seeded database (phpunit.xml pins `bakery_test` with a `<server>` entry) and fail, never skip, when fewer days are built; the race tests of the group keep `bakery_test` (DatabaseTruncation). An empty `.env` is put in the container first (the image has none, and PHPUnit reports phpdotenv's suppressed read of the missing file as a warning on every Laravel test); a skipped or warning test then fails the step. Then `gate_check.py --mode done --phase 4 --ci`. Timeout 45 |
| `web-errors` (5.5a) | needs `web-build`, 20 min: `p5-errors-off` on web-build's artifact (no Sentry SDK in `.next/static`, no request but the page's and the API's), then a build with a made-up DSN in which the same grep must find the SDK (so the negative check proves something) and `p5-errors-on` (a `page.route` sink gets the error, scrubbed) |
| `e2e-p5` (5.1b) | through `e2e.yml` (its job timeout, 30), needs `status`, `mod-analytics`, `web-build`, `docker-build` (beside `e2e-p4`, not after it); profile `full` (polling, no Reverb), `history_days: "2"`, projects `p5-device p5-offline p5-amharic`; `compose.ci.yml` turns `IDENTITY_REQUIRE_DEVICE` on for `app` and `worker` in every e2e job, and global setup enrols each role's device |
| `backup-drill` (5.5b) | needs `status`, `docker-build`, `mod-inventory`, 25 min: the compose stack; `pest --group=ops` (the real `mysqldump`/`mysql` clients: the image installs `mariadb-client`); `SEED_PHASE=4` with 14 days; `backup:run`, `backup:drill --keep` into `bakery_drill`; then one Pest process with `--group=gate:p0 --group=gate:p5:restored` and `GATE_SEED_DATABASE=bakery_drill`, so the ledger oracles and chains also run on the restored server |
| `perf-load` (5.5d) | needs `status`, `docker-build`, `mod-analytics`, 30 min (measured 14 to 14.5): `compose.perf.yml` (PHP's built-in server, `PHP_CLI_SERVER_WORKERS=8`, OPcache on), `SEED_PHASE=5`, 7 days, `LOAD_DEVICES=60`, `php artisan optimize`; k6 `scripts/perf/load.k6.js` in the pinned image: 60 devices polling at the web's cadences (`apps/api/tests/Fixtures/perf/poll-profile.json`, kept equal to the web constants by `scripts/tests/test_poll_profile.py`) and 300 orders in 10 minutes; thresholds polls p95 < 300 ms, writes p95 < 800 ms, errors < 0.5 %, `orders_paid == 300`, `dropped_iterations == 0`; then `optimize:clear` and, once the queue drained, `pest --group=perf:load` (exactly 300 orders closed, the ledger oracle `ok`, no `failed_jobs`); figures to the step summary |
| `print-bridge` (5.3b) | needs `status`, 20 min, no Docker: Node 22; at most one dependency and no native module (checked); `node --test` in `tools/print-bridge` (fake API, fake printer), failing on a skipped, cancelled or todo test; `install-service.ps1` parsed by PowerShell and kept ASCII (Windows PowerShell 5.1 reads a file without a byte-order mark as ANSI) |
| `gate-p5` (5.6b) | needs `status`, `gate-p4`, `e2e-p5`, `i18n-check`, `backup-drill`, `perf-load`, `print-bridge`, 25 min, `permissions: { contents: read, actions: read }`, checkout with `fetch-depth: 0`. Loads the image and starts `app` on the compose stack, puts an empty `.env` in it, copies `docs/` and `scripts/` into the container (`/tmp/repo`, named in `GATE_REPO_ROOT`: the image holds `apps/api` only), and runs `pest --group=gate:p5` with `IDENTITY_REQUIRE_DEVICE=false`, failing when it ran nothing or on a skip or a warning, as `gate-p4`. Then `gate_check.py --mode done --phase 5 --ci` with `GH_TOKEN: ${{ github.token }}` for the checklist's workflow rows (`live-ops`, `uptime-monitor`) and `branch-protection`; the checklist rows go to the step summary. While the phase is open the run is informational: a checklist FAIL becomes `WARN` (`FAIL:` kept in its detail) and a sign-off row stays `WAIT` without failing the job; once STATUS claims the phase done, both fail it |
| `uptime` (workflow `uptime.yml`, 5.5c) | not part of `ci`: schedule `*/15 * * * *` and `workflow_dispatch`, `contents: read`, 5 min, skipped while the repository variable `DEPLOY_URL` is unset (the URL is never written in the file); one `GET /api/v1/health` (a query string per read so no cache answers), a second 60 s later when it is not ok, and the job fails only when both are not ok: GitHub's email about the failed run is the alert. No checkout, no secret, no write |

Live updates in e2e (ADR 0009): `e2e-p1` and later run the polling transport, as the live host does
(`BROADCAST_CONNECTION=null`, `NEXT_PUBLIC_LIVE_TRANSPORT=poll`), so no `ws` service is needed. Reverb is its own
compose profile, `ws` (not part of `full`); `e2e.yml` sets `BROADCAST_CONNECTION=reverb` and
`NEXT_PUBLIC_LIVE_TRANSPORT=reverb` only when the job's `profiles` include `ws`. `e2e-p1` to `e2e-p5` run `full` (worker, no
Reverb); the Phase 5 plan settled `e2e-p5` on polling too (step 5.1b; it listed `full,ws` before). When a WebSocket transport is switched on, Reverb inside compose: the app container reaches `ws:8080`;
the browser reaches `localhost:8080`. These are two env vars (`REVERB_HOST` vs `NEXT_PUBLIC_REVERB_HOST`), never one.

## Caching

composer (`~/.composer/cache`, key `composer.lock`), PHPStan (`apps/api/tmp/phpstan`), pnpm store via
`setup-node`, `.next/cache`, `~/.cache/ms-playwright` (key `pnpm-lock.yaml`), Docker layers via gha.

## Branch protection and required checks

GitHub constraints met on 2026-10-18: Actions minutes on a **private** repo need working billing (the first run
failed with "recent account payments have failed or your spending limit needs to be increased"), and branch
protection on a private repo needs GitHub Pro. Either make the repo public or fix billing / upgrade; until then
CI does not run and gate jobs cannot be required checks. Runners are pinned to `ubuntu-24.04`.

`main` requires `gate-p0 … gate-p<current>`, linear history, no force push. When a phase is declared
done, a human adds `gate-p<N+1>` to the required list; the skill reminds, it cannot do it. Set the
first one with:

```
gh api -X PUT repos/obssa/bakery-ops/branches/main/protection \
  -F required_status_checks.strict=true \
  -F 'required_status_checks.contexts[]=gate-p0' \
  -F enforce_admins=false -F required_pull_request_reviews=null -F restrictions=null
```

## Merging parallel PRs (learned in Phase 5)

Phase 5 had several step branches open at once. A branch's green checks prove it on the base it was cut from; once
another PR has merged, they prove nothing about the combination. So for every PR, before `main` moves:

1. Merge `origin/main` into the branch: an ordinary merge commit, never a rebase or a force-push (the branch's commits and
   their checks stay as they were, and Ramin stays author and committer).
2. Never hand-merge the generated docs. On a conflict in `docs/openapi.json` take either side and re-export it the way the
   `openapi-check` job prints (`scripts/artisan scramble:export --path=../../docs/openapi.json`, against a migrated database);
   in `docs/erd.dbml` add every table `erd-check` (`scripts/erd_check.py`) lists as missing. Commit the result with the merge.
3. Push and let the full CI run on the merged head; only that run counts.
4. Fast-forward `main` to that head (`git merge --ff-only`), so main gets the very commit that was green.

The next PR in line then merges the new `main` the same way.

## `docs/phases/STATUS.json`

Schema: `assets/status.schema.json`. Shape:

```json
{
  "schema_version": 1,
  "current_phase": 0,
  "invariants_version": 1,
  "phases": {
    "0": {
      "status": "in_progress",
      "plan": "docs/phases/phase-0-plan.md",
      "plan_approved": false,
      "report": null,
      "gate_job": "gate-p0",
      "gate_run_url": null,
      "done_commit": null,
      "done_at": null,
      "adrs": [],
      "contracts_required": ["inventory->catalog", "catalog->identity"],
      "modules": {"shared": "todo", "audit": "todo", "identity": "todo", "catalog": "todo", "inventory": "todo", "web": "todo", "seeds": "todo", "docs": "todo"},
      "playwright_projects": ["p0-admin-smoke"]
    }
  },
  "modules": {"shared": {"phase": 0, "status": "todo"}, "...": {}}
}
```

Phase `status`: `todo | planning | plan_approved | in_progress | gate_pending | done`.
Module `status`: `todo | in_progress | stable`. `plan_approved` flips to `true` only in a commit authored
by Ramin; that is how "wait for my OK" survives an unattended agent.

## `scripts/gate_check.py` (copied from the skill into the repo's `scripts/`)

| Mode | Verifies |
|---|---|
| `--mode entry --phase N` | (contracts whose provider is itself built in phase N, e.g. `payments->orders` in Phase 1, are skipped here and required at the consumer's step instead) `phases[N-1].status == done` and `done_commit` is an ancestor of HEAD; working tree clean; `phase-N-plan.md` exists and `plan_approved: true`; every `contracts_required` pair has its file and `pest --group=contract:<pair>` is green **while `app/Modules/<Consumer>` is unmodified relative to `done_commit`**; `Phase<N>Seeder` exists; every dependency module is `stable`; locally, `pest --group=gate:p{N-1}` green |
| `--mode module --module X` | `module:X` + all `contract:*->X` + the arch boundary test for X green → eligible for `stable` |
| `--mode done --phase N` | all groups incl. `gate:pN` green; the phase's Playwright projects green (CI: by `needs`; local: run them); regenerated OpenAPI equals committed; ERD covers every table; at least one new ADR (or an explicit waiver); `phase-N-report.md` exists; migration-lint clean; route-permission coverage 100 %; no `TODO(p<N>)` markers; no `->skip(` in tests tagged `module:` for this phase's modules; `STATUS.json` `done_commit == HEAD` (no app code after `done_commit` until phase N+1 is `in_progress`; from then on the gate proves phase N by its tests) |

`--ci` switches test checks from "run them now" to "trust the `needs` chain" and makes output GitHub-annotation friendly.

**The go-live checklist (from Phase 5, step 5.6a).** A phase whose manifest gate names a `checklist`
(`docs/go-live-checklist.json`) gets one `checklist <id>` row per item in `--mode done`: PASS, FAIL, SKIP (a `ci_job` row run
locally, trusted to CI) or WAIT (a sign-off not made yet). What the build taught:

- A `ci_job` verifier with `jobs` counts a job the gate job needs directly **or only through the manifest's need graph**
  (`ci.module_jobs`, `e2e_jobs`, `gate_jobs`: a job runs only after every need succeeded), and the job must also be defined in
  `ci.yml`. It proves the job exists and succeeded, not that it does the real work: the placeholder `backup-drill` passed as a
  need until 5.5b; the phase-marker row caught it. `self` passes only while the gate job's block runs the gate's Pest group.
- A `ci_job` verifier with `workflow` reads the Actions API, so the gate job needs `actions: read` and `GH_TOKEN`; a run whose
  job was skipped is passed over for an older one.
- WAIT fails a local run and a claimed phase like FAIL; in the informational CI run (phase open) checklist FAILs are WARN and
  WAIT does not fail the job. `phase_status.py claim-done N` runs `gate_check --mode done --no-tests --no-e2e` and refuses
  until it passes, so a phase with sign-offs still waiting cannot be claimed.
- Sign-offs exist only as files committed through GitHub's web editor (committer `GitHub <noreply@github.com>`); reading who
  committed them needs full history (`fetch-depth: 0`, and `check_invariants.py --signoffs` fails a shallow clone). Agents
  never write in `docs/go-live/signoffs/`, and D16's fallback (sign from the PC) is not built: nothing in the repository could
  tell it apart from an agent's commit.
- The repository's `scripts/gate_check.py` and the skill's copy are kept identical; `check_invariants.py`'s two copies already
  differ (the Phase 4 tables and routes), so a change to it goes to the repository copy.

## Deploy (live since 2026-10-19 decision, ADR 0006)

`.github/workflows/deploy.yml` runs on `workflow_run` after `ci` succeeds on `main`: build release on the runner
(`composer install --no-dev`, optional static web export when `DEPLOY_WEB_MODE=static`), rsync over SSH to the
cPanel site folder (`scripts/deploy/rsync-excludes.txt` protects `.env`, `storage/`, `public/.htaccess`),
`scripts/deploy/release.sh` on the server, `scripts/deploy/smoke.sh` against https://bakery-ops.orcamind.dev,
rollback on failure, then the `live-e2e` job runs Playwright `--grep @live` with `PLAYWRIGHT_BASE_URL`.
Secrets `DEPLOY_HOST/USER/PORT/SSH_KEY/KNOWN_HOSTS`; variables `DEPLOY_ENABLED/PATH/PHP_BIN/URL/WEB_MODE`.
Provisioning steps: `docs/runbook/server.md`. Tag a Playwright test `@live` only when it can run against the
deployed data (no local seeds assumed).

## Deploy later (VPS option)

When Ramin has a server: add `deploy-staging.yml` (on push to `main` after `gate-p<current>` green:
build images, push to GHCR, `ssh` + `docker compose pull && up -d` + `artisan migrate --force`) and
`promote-production.yml` (`workflow_dispatch` with the staging image digest, GitHub environment with
required reviewer). Secrets: `STAGING_HOST`, `STAGING_SSH_KEY`, `GHCR_TOKEN`. Nothing in the CI design
above needs to change.
