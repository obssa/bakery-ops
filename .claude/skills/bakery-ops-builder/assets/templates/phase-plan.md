# Phase {{N}} plan — {{PHASE_NAME}}

Status: draft → Ramin sets `plan_approved: true` in `docs/phases/STATUS.json` in his own commit.
Nothing in this phase is coded before that flag flips; `gate_check.py --mode entry` enforces it.

## 1. Entry check

Output of `python scripts/gate_check.py --mode entry --phase {{N}}` (paste the summary lines).
List every upstream module this phase consumes and its `stable` status.

## 2. Contract tests to write first (green before any consumer code)

| Contract | File | Cases |
|---|---|---|
| `{{consumer}}->{{provider}}` | `apps/api/tests/Contract/...` | one line per case from the manifest, plus any this plan adds |

## 3. Migrations (in order)

For each: table, columns with types (NUMERIC scale stated), FKs, indexes, triggers, `branch_id`,
actor-stamp columns, soft delete yes/no, append-only yes/no.

## 4. Endpoints

| Method + path | Permission | Idempotent | Request shape | Response shape | Errors |
|---|---|---|---|---|---|

State-transition endpoints are actions (`POST /orders/{id}/send`), not PATCHes of a status field.

## 5. Domain events and listeners

| Event | Payload | Listeners (module) | Queued? |
|---|---|---|---|

## 6. Module build order inside the phase

Topological order from the manifest; for each step: what is built, what it consumes, which Pest groups
it adds, which Playwright project it enables.

## 7. Test list

- Unit (state machines, value objects): …
- Feature (one per endpoint + 403 per role + idempotent replay): …
- Property / concurrency: …
- Playwright projects: …
- Gate test file: `apps/api/tests/Gate/Phase{{N}}GateTest.php`

## 8. Seeds

What `Phase{{N}}Seeder` adds; what earlier seeders must change (and why that cannot break earlier gates).

## 9. ADRs this phase will write

## 10. Assumptions and open questions

Only questions that change the data model block; everything else gets the safer default, stated here.

## 11. Risks from `references/traps.md` that apply
