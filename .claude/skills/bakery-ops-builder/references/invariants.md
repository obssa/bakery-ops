# Cross-cutting invariants and the mechanism that enforces each

An invariant you only remember is an invariant you will eventually break at 02:00 before a pilot.
Each rule below has a mechanical check that runs in every CI run (`api-arch` and `invariants` jobs,
plus `scripts/check_invariants.py` which also runs on the host without PHP).

| Invariant | Mechanism | Test / script |
|---|---|---|
| Append-only: `stock_movements`, `audit_log`, `order_events`, locked `stock_counts` | PG trigger `forbid_update_delete()` created in migrations; app DB role has no UPDATE/DELETE on those tables; locked `stock_counts` rows reject UPDATE except `superseded_by_id` | `tests/Invariants/AppendOnlyTest.php` issues raw UPDATE/DELETE and expects an exception; arch scan: no `update( / delete( / forceDelete(` on those models in `app/` |
| Only `Ledger` writes movements | Pest arch: `expect('App')->not->toUse(StockMovement::class)` except `App\Modules\Inventory\Ledger\*` and tests | `tests/Arch/LedgerIsSoleWriterTest.php` |
| NUMERIC, never float | `migration-lint` forbids `float / double / unsignedFloat / real`; requires `decimal(12,2)` for `*price*, *amount*, *total*, *cost*` and `decimal(14,4)` for `qty, *_qty`; schema test on `information_schema.columns` finds no `real` / `double precision`; model casts are `decimal:N` or `Quantity` / `Money`; token scan forbids `(float)` in `app/Modules` and `app/Shared`; TS client types money and qty as `string`; ESLint rule bans `parseFloat` on fields named like money/qty | `tests/Invariants/NumericColumnsTest.php`, `scripts/check_invariants.py --numeric` |
| `branch_id` on every tenant table | schema test: every table not in the allowlist (`migrations, jobs, failed_jobs, cache*, sessions, personal_access_tokens, permissions, roles, model_has_*, role_has_permissions, branches, units, idempotency_keys`) has `branch_id NOT NULL` with FK + index; arch: every Model under `App\Modules` uses `BranchScoped` or is allowlisted; per-module `branchIsolation()` helper asserts cross-branch reads are 404 | `tests/Invariants/BranchIdTest.php`, `tests/Arch/BranchScopedTest.php`, `check_invariants.py --branch-id` |
| Idempotency on every write | route coverage test: every `POST / PUT / PATCH / DELETE` under `/api/v1` carries the `idempotency` middleware unless in the public allowlist (`login`); `tests/Support/write_endpoints.php` registry drives a table test `assertIdempotent()` (same key twice → same body, one row); a write route missing from the registry fails; unique `(branch_id, client_uuid)` on orders, order_lines, payments, stock_counts, production_batches, transfers, purchases; Redis `SET NX` lock per key so concurrent duplicates cannot both pass | `tests/Invariants/IdempotencyCoverageTest.php`, `check_invariants.py --idempotency` (route list via `artisan route:list --json`) |
| Bilingual fields | schema test: any `*_en` column has an `*_am` sibling and vice versa; configured user-facing tables (items, categories, payment_channels, modifiers, recipe_steps, variance_reasons, stations) have `name_en / name_am`; `scripts/i18n-check.mjs`: `en` and `am` dictionaries have identical key sets (`__TODO__` values allowed in `am` until Phase 5, forbidden after); API errors return `message_key` present in both | `tests/Invariants/BilingualColumnsTest.php`, `i18n-check` job, `check_invariants.py --bilingual` |
| Permission on every endpoint | `RoutePermissionCoverageTest`: every `/api/v1` route has `auth:sanctum` and a `permission:` / `can:` middleware or policy, or is in the public allowlist; `tests/Support/route_permissions.php` (route → permission → default roles) generates a test hitting every route as every role expecting 403 or not; unregistered route = failure | `tests/Feature/RoutePermissionMatrixTest.php` (runs in every module job) |
| Actor stamp | schema test: every table in the "operational writes" list has `created_by_user_id`, `acting_user_id?`, `shift_instance_id?`, `station_id?`, `device_id?`; arch: every write use case extends `AuditedAction`; `assertAudited()` helper cross-checked against the write-endpoint registry | `tests/Invariants/ActorStampTest.php`, `tests/Invariants/AuditCoverageTest.php` |
| Soft deletes only | arch: mutable tenant models use `SoftDeletes`; schema: `deleted_at` present on them and absent on append-only tables | `tests/Arch/SoftDeletesTest.php` |
| State machines complete | every `*Status` enum exposes `transitions()`; a generated test enumerates all `from × to` pairs: allowed succeed, others throw `IllegalTransition` | `tests/Unit/StateMachineExhaustiveTest.php` |
| Module boundaries | Pest arch allowlist matrix from `dependency-graph.md` | `tests/Arch/ModuleBoundariesTest.php` |
| Octane safety | arch: no mutable `static` properties in `App\Modules` / `App\Shared`; `ActorContext`, `CurrentBranch`, `CurrentShift` bound with `scoped()`; CI e2e runs `OCTANE_WORKERS=1` so leaks show up as cross-user bleed in `p1-order-flow` | `tests/Arch/OctaneSafetyTest.php` |
| OpenAPI and ERD in sync | CI regenerates the spec and diffs with `docs/openapi.json`; `scripts/erd-check.php` compares `information_schema` tables to `docs/erd.dbml` | `openapi-check`, `erd-check` jobs |
| Phase discipline | `gate_check.py` modes `entry`, `module`, `done` (see `ci-cd.md`) | `gate-p<N>` jobs, `make gate PHASE=N` |

## Temporary exceptions

`apps/api/.invariants-allow` lists tables allowed without `branch_id` for now, one per line with a reason.
Write a `TODO(p<N>)` marker in the reason: the phase gate refuses to close while the marker exists, so an
exception cannot quietly become permanent.

## Adding an invariant

1. Write the failing check first against a planted violation (a `float` column in a throwaway migration).
2. Put the mechanism in the smallest layer that can catch it (lint → arch → schema test → feature test).
3. Bump `invariants_version` in `STATUS.json` and note it in the phase report so later readers know when
   the rule started applying.
