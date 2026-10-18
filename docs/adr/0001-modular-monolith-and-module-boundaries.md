# ADR 0001: Modular monolith with enforced module boundaries

- Status: accepted
- Date: 2026-10-18
- Phase: 0

## Context

The architecture document (section 5) prescribes one Laravel application split into bounded modules:
Identity, Catalog, Inventory, Orders, Payments, Production, Shifts, Notifications, Analytics, Audit.
The owner's requirement is that no module is built on another that has not been proven to work. That
needs a dependency direction that can be checked by a machine, not a convention in a README. Five
modules reference `stations` and every table references `branches`; without a neutral home for those,
import cycles appear in the first week.

## Decision

- One Laravel 13 app under `apps/api`. Modules live in `App\Modules\<Name>`; an eleventh, `App\Shared`,
  is the leaf of the graph and owns `branches`, `stations`, `BranchScoped`, `Bilingual`, `Quantity`,
  `Money`, `StateMachine`, `Idempotency`, `ActorContext`, broadcast channel names and `Files`.
- Allowed `use` directions are the layered graph in
  `.claude/skills/bakery-ops-builder/references/dependency-graph.md`. Anything else is a build failure.
- Cross-module calls go through classes in `App\Modules\<Provider>\Contracts\`. Consumers write
  contract tests (`tests/Contract/<Consumer>/<Provider>ContractTest.php`) before any consumer code.
- Cross-module reactions go through domain events with array payloads; interfaces needed before their
  implementing module exists (`StationPresenceResolver`, `ShiftAttacher`) live in Identity and are
  rebound when Shifts arrives.
- Audit is written by the application into its own `audit_log` table via `AuditedAction` (before/after
  JSONB, actor and acting-as, shift, station, device). `spatie/laravel-activitylog` is kept only as a
  helper for model diffs; its `activity_log` table is not the audit trail of record.

## Consequences

- Positive: the first red CI job names the lowest broken module; a provider change that breaks a
  consumer fails in the provider's job; a second branch or a NestJS rewrite of one module is possible.
- Negative: more files and some ceremony (Contracts classes, registries) than a flat Laravel app.
- Later phases: Analytics (Phase 4) reads only provider-owned `Facts` classes, so provider schema
  changes fail the provider's tests first.

## Enforcement

`apps/api/tests/Arch/ModuleBoundariesTest.php` (Pest arch allowlist matrix), `module-tests.yml` job
chain in `.github/workflows/ci.yml`, `scripts/gate_check.py --mode entry` (contract-first check).

## Alternatives considered

- Flat Laravel app: fastest start, no mechanical way to prove dependency order. Rejected.
- Separate services per module: operational cost far above a single bakery's needs; offline and
  transactional stock gating become distributed problems. Rejected.
