# Phase {{N}} gate report — {{PHASE_NAME}}

Commit: `{{SHA}}` · CI run: {{RUN_URL}} · Date: {{DATE}}

## Verdict

PASS / FAIL — one sentence.

## Gate criteria (from the manifest `phases.{{N}}.gate.criteria`)

| # | Criterion | Evidence (test name, CI job, numbers) | Result |
|---|---|---|---|

## Dependencies proven

| Contract | Cases | Status |
|---|---|---|

## Modules declared stable in this phase

| Module | module:X | contract:*->X | arch boundary | route coverage |
|---|---|---|---|---|

## Earlier gates re-run

`gate-p0` … `gate-p{{N-1}}`: all green at this commit (link the jobs).

## Invariants

`check_invariants.py --all` output summary; `invariants_version`.

## Artifacts produced

ADRs, OpenAPI diff summary (new endpoints, breaking changes: none expected), ERD tables added, seeds.

## Deviations from the plan and assumptions taken

## What Ramin needs to do

- [ ] add `gate-p{{N+1}}` to required status checks on `main`
- [ ] review and approve `docs/phases/phase-{{N+1}}-plan.md` when it exists
- [ ] anything else (device enrolment, secrets, decisions owed)
