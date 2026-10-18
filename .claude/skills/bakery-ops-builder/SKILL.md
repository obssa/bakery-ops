---
name: bakery-ops-builder
description: Phase-gated build procedure for the Bakery Operations Platform (bakery-ops repo - Laravel 13 API + Next.js 15 PWA, POS, kitchen/bar display screens, bakery production, ledger inventory with recipes, per-station shift handover with locked counts, payments by channel, Amharic/English). Use this skill for ANY work in this repo - building or continuing a phase or module, planning the next phase, adding a feature, writing or running tests, setting up or fixing CI, checking "what phase are we on" or "can we start phase N", reviewing a PR, writing an ADR, or deciding where a feature belongs - even when the user does not say "skill" or "phase". It carries the module dependency graph, the contract-test-first rule, the gate checks and the CI layout; skipping it means building on unproven dependencies.
---

# bakery-ops-builder

You are building a system where money and stock are tracked in an append-only ledger and every shift's
count is locked and hash-chained. The owner's requirement is simple to state and easy to violate: nothing
is built on top of something that has not been proven to work, every dependency is tested from the
consumer's side before the consumer exists, and no phase passes while anything it relies on is red.
This skill makes that mechanical. Follow it in order; the scripts refuse to let you skip.

## Files you will use

| Need | Read |
|---|---|
| The product, stack, the nine rules and their reasons | `references/product-and-rules.md` |
| Tables, state machines, which module owns what | `references/domain-model.md` |
| Workflows, screens, permission matrix (what tests must pin down) | `references/workflows-and-screens.md` |
| Who depends on whom, the named capabilities, cycle breaks | `references/dependency-graph.md` |
| What this phase builds, in what order, with which gate | `references/phases/phase-<N>-*.md` |
| Test layers, contract-first rule, "module stable" definition | `references/testing-strategy.md` |
| CI job graph, STATUS.json, gate_check modes | `references/ci-cd.md` |
| Cross-cutting rules and the check that enforces each | `references/invariants.md` |
| Things that pass locally and fail in Docker/CI/the shop | `references/traps.md` |
| Machine-readable truth for all of the above | `assets/dependency-manifest.json` |
| Templates: phase plan, gate report, ADR, contract test | `assets/templates/` |
| Canonical CI files | `assets/ci/` |
| Scripts (copied into the repo's `scripts/`) | `scripts/gate_check.py`, `scripts/phase_status.py`, `scripts/check_invariants.py` |

The long-form architecture is `docs/architecture.md` in the repo (sections 1–13). The skill references
are the condensed, build-oriented version; when they are silent, the doc decides.

## The protocol (every invocation)

### 1. Orient (always, takes one minute)

```
python scripts/phase_status.py show
```

Read `docs/phases/STATUS.json`, the manifest, and the current phase reference. Then tell the user, in
two or three sentences: which phase is current, which module is next in the phase's build order, and
why its upstream dependencies count as proven (their `stable` status and green contracts). If the
request does not fit the current phase (e.g. "add receipt photos" while Phase 1 is open), say which
phase it belongs to and offer to record it as a `TODO(p<N>)` marker or a line in that phase's plan
instead of building it now. Scope creep is how gates stop meaning anything.

### 2. Entry preflight before touching a phase or a module

```
python scripts/gate_check.py --mode entry --phase <N>
```

It refuses unless: the previous phase is `done` and its `done_commit` is an ancestor of HEAD; the working
tree is clean; `docs/phases/phase-<N>-plan.md` exists and STATUS says `plan_approved: true` (set only
by Ramin's own commit, which is how "wait for my OK" survives when nobody is watching); every contract
in `contracts_required` has its test file, contains no `->todo()`, and is green **while the consumer
module's directory is still unchanged**; every dependency module is `stable`. When it refuses, do what
it names and nothing else. Do not edit STATUS.json to make it pass. If the only failures are "plan missing" or
"plan not approved", the right move is step 3 (write the plan, commit, stop); if a dependency is unstable or a
contract is missing, the right move is to say so and offer the upstream work instead.

### 3. Plan the phase, then stop

Copy `assets/templates/phase-plan.md` to `docs/phases/phase-<N>-plan.md` and fill it from the phase
reference and the manifest: contract tests first, migrations with NUMERIC scales, endpoints with
request/response shapes and permissions, events and listeners, intra-phase build order, the test list,
seeds, ADRs, assumptions. Set the phase status to `planning` via `phase_status.py`, commit, and end your
turn telling Ramin the plan is ready for approval. Resume only when `plan_approved` is true.

### 4. Build one module at a time, in the manifest's order

For each step in `phases.<N>.steps`:

a. **Contract tests first.** From `assets/templates/ContractTest.php.stub`, write the consumer-side
   tests listed in the manifest for every provider this module consumes. Run them. They must be green
   before the consumer directory changes. If a provider cannot satisfy a case, the provider gets fixed
   (and its own module tests extended), not the expectation weakened.
b. **The module's own tests** next: exhaustive state-machine tables, one feature test per endpoint with
   403 per role and idempotent replay, property and concurrency tests wherever the ledger is touched.
c. **Implement** inside `app/Modules/<Name>` (or `app/Shared`), exposing the capabilities the manifest
   lists under `provides` as classes in `Contracts/`. Every write goes through `AuditedAction`, carries
   `ActorContext`, uses `Quantity`/`Money`, and registers in `tests/Support/write_endpoints.php` and
   `route_permissions.php`.
d. **`make ci`** locally (inside Docker). Fix until green. It mirrors GitHub Actions job for job.
e. Branch `phase-<N>/<module>`, PR with the template filled (tests added, assumptions, gate impact),
   CI green, merge. Small PRs: one module or sub-module each.
f. `python scripts/gate_check.py --mode module --module <name>` then
   `python scripts/phase_status.py module-stable <name>`. Only `stable` modules may be depended on.

### 5. Close the phase at its gate

```
python scripts/gate_check.py --mode done --phase <N>
```

It runs the `gate:p<N>` Pest group and the phase's Playwright projects, re-checks invariants,
OpenAPI/ERD sync, ADR presence, forbids `TODO(p<N>)` markers and `->skip(` in this phase's module tests,
and requires `docs/phases/phase-<N>-report.md` (from `assets/templates/gate-report.md`). Then
`phase_status.py claim-done <N>` records `done_commit`, commit, push, wait for `gate-p<N>` green on
`main`, record the run URL, tag `phase-<N>-complete`.

### 6. Stop and show

End the turn with the gate report summary and the list of things only Ramin can do (add `gate-p<N+1>`
to required checks, approve the next plan). Never begin the next phase in the same turn.

## Answering common requests

- **"What phase are we on?" / "where are we?"** → step 1 only. Include the next module and any red gate.
- **"Can we start phase N?"** → run step 2 and relay its output verbatim with a one-line verdict.
- **"Add / build module X"** → find X in the manifest. If its phase is not current, say so and stop. If
  it is, run step 2 for the phase, then step 4 for X only.
- **"Set up CI" / "CI is red"** → `references/ci-cd.md`; install or diff against `assets/ci/`; the job
  graph must keep mirroring `ci.module_jobs` / `gate_jobs` in the manifest. For a red run, name the
  lowest failing job in the dependency chain first; everything downstream is noise.
- **"Write the plan for phase N"** → step 3.
- **"Review this PR"** → check: contract tests exist and were committed before consumer code (git
  history), every new endpoint has feature test + registry entries, NUMERIC scales, `branch_id`, actor
  stamp, ADR if a decision was made, no `TODO(p<current>)`, `make ci` evidence.
- **A question that changes the data model** → ask it, one at a time. Anything else: take the safer
  default from `product-and-rules.md`, write the assumption in the plan or PR, continue.

## Rules of the road

- PHP runs only in Docker (`scripts/php`, `scripts/artisan`, `scripts/composer`, `scripts/pest`). The
  host PHP is 8.0 and will corrupt `composer.lock` if used.
- Never weaken a contract test to make a consumer pass; never mark a module stable by editing STATUS
  by hand; never set `plan_approved` yourself.
- Money and quantities are decimal strings end to end. If you type `(float)` or `parseFloat`, stop.
- Every table gets `branch_id`; every write gets an idempotency key and an actor stamp; every user-facing
  string has `_en` and `_am`. `check_invariants.py --all` will tell you when you forgot.
- Keep ADRs honest: when you deviate from `docs/architecture.md`, write the ADR in the same PR.
- Read `references/traps.md` before touching: Reverb, queues, Octane, Playwright, hash chains, time
  zones, MinIO, seeds, idempotency.
- Prefer small, verifiable increments. A phase is weeks of work; a PR is hours.

## First-time setup in a fresh clone

```
cp .env.example .env
make up
make doctor          # PHP 8.3 in container, Node 24, LF endings, docker, gh
python scripts/phase_status.py show
```

If `scripts/` lacks `gate_check.py`, `phase_status.py` or `check_invariants.py`, copy them from this
skill's `scripts/` directory; they are the same files.
