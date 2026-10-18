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
        │  e2e-p2 ◄ [mod-production, mod-notifications, …]     e2e-p3 ◄ [mod-shifts, …]
        │  e2e-p4 + perf-dashboards ◄ [mod-analytics, …]        e2e-p5 ◄ [e2e-p4]
        └─ gate-p0 ◄ [api-arch, mod-inventory, mod-catalog, e2e-p0, openapi-check, erd-check, invariants]
           gate-p1 ◄ [gate-p0, mod-orders, mod-payments, e2e-p1]
           gate-p2 ◄ [gate-p1, mod-production, mod-notifications, e2e-p2]
           gate-p3 ◄ [gate-p2, mod-shifts, e2e-p3]
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
| `e2e-p<N>` | download artifacts; `docker load`; `docker compose -f compose.yml -f compose.ci.yml up -d --wait`; `artisan migrate:fresh --seed --seeder=DemoSeeder -- --phase=N`; setup-node 24 + Playwright browser cache; `npx playwright test --project=p<N>-*`; upload `test-results` on failure |
| `perf-dashboards` | k6 against compose with the 90-day seed, thresholds `p(95)<1000` |
| `gate-p<N>` | `python scripts/gate_check.py --mode done --phase N --ci` |

Reverb inside compose: the app container reaches `ws:8080`; the browser reaches `localhost:8080`.
These are two env vars (`REVERB_HOST` vs `NEXT_PUBLIC_REVERB_HOST`), never one.

## Caching

composer (`~/.composer/cache`, key `composer.lock`), PHPStan (`apps/api/tmp/phpstan`), pnpm store via
`setup-node`, `.next/cache`, `~/.cache/ms-playwright` (key `pnpm-lock.yaml`), Docker layers via gha.

## Branch protection and required checks

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
| `--mode entry --phase N` | `phases[N-1].status == done` and `done_commit` is an ancestor of HEAD; working tree clean; `phase-N-plan.md` exists and `plan_approved: true`; every `contracts_required` pair has its file and `pest --group=contract:<pair>` is green **while `app/Modules/<Consumer>` is unmodified relative to `done_commit`**; `Phase<N>Seeder` exists; every dependency module is `stable`; locally, `pest --group=gate:p{N-1}` green |
| `--mode module --module X` | `module:X` + all `contract:*->X` + the arch boundary test for X green → eligible for `stable` |
| `--mode done --phase N` | all groups incl. `gate:pN` green; the phase's Playwright projects green (CI: by `needs`; local: run them); regenerated OpenAPI equals committed; ERD covers every table; at least one new ADR (or an explicit waiver); `phase-N-report.md` exists; migration-lint clean; route-permission coverage 100 %; no `TODO(p<N>)` markers; no `->skip(` in tests tagged `module:` for this phase's modules; `STATUS.json` `done_commit == HEAD` |

`--ci` switches test checks from "run them now" to "trust the `needs` chain" and makes output GitHub-annotation friendly.

## Deploy later (not now)

When Ramin has a server: add `deploy-staging.yml` (on push to `main` after `gate-p<current>` green:
build images, push to GHCR, `ssh` + `docker compose pull && up -d` + `artisan migrate --force`) and
`promote-production.yml` (`workflow_dispatch` with the staging image digest, GitHub environment with
required reviewer). Secrets: `STAGING_HOST`, `STAGING_SSH_KEY`, `GHCR_TOKEN`. Nothing in the CI design
above needs to change.
