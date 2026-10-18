# ADR 0005: Phase gates, contract-first dependencies and a CI graph that mirrors the module graph

- Status: accepted
- Date: 2026-10-18
- Phase: 0

## Context

The owner asked for a build that proceeds phase by phase in the roadmap's order (architecture.md
section 12), where every dependency between phases and modules is explicit, every module is tested
against what depends on it before that dependant is built, nothing passes a gate while an upstream
module is red, and CI exists from the first commit. Discipline alone does not survive a 16-week build
with an AI agent doing much of the typing; the rules have to be checked by scripts and by the pipeline.

## Decision

- **Single source of truth**: `.claude/skills/bakery-ops-builder/assets/dependency-manifest.json`
  lists modules, `depends_on`, the named capabilities each consumer needs, the per-phase build order,
  the contract tests required before each phase, and the gate (Pest group, Playwright projects, CI job).
- **State**: `docs/phases/STATUS.json` records the current phase, per-phase status, `plan_approved`,
  `done_commit`, per-module `stable`. `plan_approved` is only ever set in a commit authored by Ramin.
- **Contract-first**: a consumer's contract tests (`tests/Contract/<Consumer>/<Provider>ContractTest.php`,
  group `contract:<consumer>-><provider>`) must be green while the consumer directory is still unchanged
  relative to the previous gate's commit. `scripts/gate_check.py --mode entry` refuses otherwise.
- **Module stable**: `module:<x>` + all `contract:*-><x>` + arch boundary green
  (`gate_check.py --mode module`). Only stable modules may be depended on.
- **Gate**: `gate:p<N>` Pest group + Playwright projects + invariants + OpenAPI/ERD sync + ADRs + report
  (`gate_check.py --mode done`). All earlier gates re-run in every CI run.
- **CI**: GitHub Actions job `needs:` chains copy the module graph (`mod-identity → mod-catalog →
  mod-inventory → …`); each module job also runs its consumers' contract tests; `gate-p<N>` jobs are the
  required status checks on `main`; jobs for phases above `current_phase` are skipped by a `status` job.
  Fast tests run natively with service containers; e2e runs on the compose stack with the same image
  as production. CI only for now; deploy workflows are added when a server exists.
- **Local parity**: `python scripts/task.py ci` (or `make ci`) runs the same steps in the same order,
  with all PHP inside Docker because the host has PHP 8.0.

## Consequences

- Positive: a red dependency stops downstream work automatically; "done" has one meaning; an agent
  cannot skip the owner's approval; the first failing CI job names the lowest broken module.
- Negative: more process than a solo hack; ten module jobs cost runner minutes (collapse to one
  fail-fast job if runs exceed ~12 minutes).

## Enforcement

`scripts/gate_check.py`, `scripts/phase_status.py`, `scripts/check_invariants.py`,
`.github/workflows/ci.yml`, branch protection requiring `gate-p0..gate-p<current>`.

## Alternatives considered

- Trunk-based with feature flags and no gates: fine for a web app, wrong for a ledger that a pilot
  will trust after Phase 3. Rejected.
- One mega test job: loses the "which module broke" signal. Kept as a fallback for runtime.
