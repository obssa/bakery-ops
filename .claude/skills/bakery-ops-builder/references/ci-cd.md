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
        │  e2e-p4 + perf-dashboards ◄ [mod-analytics, …]        e2e-p5 ◄ [e2e-p4]
        └─ gate-p0 ◄ [api-arch, mod-inventory, mod-catalog, e2e-p0, openapi-check, erd-check, invariants]
           gate-p1 ◄ [gate-p0, mod-orders, mod-payments, e2e-p1]
           gate-p2 ◄ [gate-p1, mod-production, mod-notifications, e2e-p2]
           gate-p3 ◄ [gate-p2, mod-shifts, rules-off-gates, e2e-p3]
           gate-p4 ◄ [gate-p3, mod-analytics, e2e-p4, perf-dashboards]
           gate-p5 ◄ [gate-p4, e2e-p5, i18n-check (strict), backup-drill]
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
| `mod-<x>` | services postgres + redis; `artisan migrate --force --env=testing`; `pest --group=module:<x>[,property,concurrency]`; then `pest --group=<consumers' contracts against x>` (skipped when the group has no tests yet) |
| `invariants` | `python scripts/check_invariants.py --all` (uses `artisan route:list --json` for routes) |
| `openapi-check` | `artisan scramble:export` → diff against `docs/openapi.json` |
| `erd-check` | `php scripts/erd-check.php` (tables in `information_schema` vs `docs/erd.dbml`) |
| `web-lint` | pnpm install (cached), eslint, `tsc --noEmit` |
| `web-build` | `next build` (standalone) → artifact |
| `i18n-check` | `node scripts/i18n-check.mjs` (`--strict` when phase ≥ 5) |
| `docker-build` | `docker/build-push-action` with `cache-from/to: type=gha`, `outputs: type=docker,dest=api.tar` → artifact |
| `e2e-p<N>` | download artifacts; `docker load`; `docker compose -f compose.yml -f compose.ci.yml up -d --wait`; `artisan migrate:fresh --seed --seeder=DemoSeeder -- --phase=N`; setup-node 24 + Playwright browser cache; `npx playwright test --project=p<N>-*`, with `--pass-with-no-tests` only while phase N is `todo`/`planning`/`plan_approved` (once it is `in_progress` or later, each of its projects must list at least one test); upload `test-results` on failure |
| `perf-dashboards` | k6 against the CI compose stack with the 90-day seed, thresholds `p(95)<1000` (never against the live host; the timed Pest substitute `DashboardBudgetGateTest` is part of `gate:p4`) |
| `gate-p<N>` | `python scripts/gate_check.py --mode done --phase N --ci` |

Live updates in e2e (ADR 0009): `e2e-p1` and later run the polling transport, as the live host does
(`BROADCAST_CONNECTION=null`, `NEXT_PUBLIC_LIVE_TRANSPORT=poll`), so no `ws` service is needed. Reverb is its own
compose profile, `ws` (not part of `full`); `e2e.yml` sets `BROADCAST_CONNECTION=reverb` and
`NEXT_PUBLIC_LIVE_TRANSPORT=reverb` only when the job's `profiles` include `ws`. `e2e-p1` to `e2e-p4` run `full` (worker, no
Reverb); `e2e-p5` lists `full,ws`, the stack it had before step 1.2, until its phase plan settles the
transport. When a WebSocket transport is switched on, Reverb inside compose: the app container reaches `ws:8080`;
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
