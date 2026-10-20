# Phase 0 gate report — Foundations and ledger

Commit: the `done_commit` recorded in `docs/phases/STATUS.json` (the commit that adds this report) · CI evidence:
latest green `main` run [39342542904](https://github.com/obssa/bakery-ops/actions/runs/39342542904) at `1f8697b`
(steps 0.1-0.8) and the `phase-0/gate` pull request's own run · Date: 2026-10-20

## Verdict

PASS: every Phase 0 gate criterion is green in CI on the production engine (MariaDB 10.11) and on the live site, all
five Phase 0 modules are stable, and `gate_check.py --mode done --phase 0` passes locally with the tests run.

## Gate criteria (from the manifest `phases.0.gate.criteria`)

| # | Criterion | Evidence (test name, CI job, numbers) | Result |
|---|---|---|---|
| 1 | `LedgerPropertiesTest`: 200 random movement sequences over 5 items x 3 locations keep sum(movements) == stock_balances == item_stock_view | `apps/api/tests/Gate/LedgerPropertiesTest.php` (groups `gate:p0`, `property`, `module:inventory`): 200 seeded sequences (seed `20261019 + i x 7919`, at most 50 operations each: purchases, waste, transfers, refused overdraws); after every sequence the model, `stock_balances` and `item_stock_view` agree, and `Stock::balanceAt` agrees for every (item, location). Runs in `mod-inventory` (475 tests, 59 863 assertions, green in run 39342542904) | PASS |
| 2 | `ReservationPropertyTest`: random reserve/release/consume never produce available < 0; over-reserve returns `Shortfall{ingredient, qty_short, station}` | `apps/api/tests/Gate/ReservationPropertyTest.php` (`gate:p0`, `property`): 100 seeded sequences of at most 40 operations over two recipe products (one with `waste_pct`) and their ingredients; `available` is checked before every operation, every refusal names the short ingredient, the quantity short and the station, and balances == view == movements at the end. `mod-inventory`, green | PASS |
| 3 | `ReservationLockTest` (group `concurrency`): a second connection with `FOR UPDATE NOWAIT` fails while a reservation transaction holds the row | `apps/api/tests/Gate/ReservationLockTest.php` (`gate:p0`, `concurrency`, committed data through `tests/Support/CommitsForConcurrency.php`): NOWAIT on the held `stock_balances` row fails on MariaDB; a second reservation of the last cake waits for the first and then finds it gone; locking outside a transaction is refused. `mod-inventory`, green | PASS |
| 4 | `AdminCreatesBothKindsTest`: admin creates an ingredient and a product through the API in en and am | `apps/api/tests/Gate/AdminCreatesBothKindsTest.php` (`gate:p0`, `module:catalog`): `POST /api/v1/items` for "Wheat flour / የስንዴ ዱቄት" and "Birthday cake / የልደት ኬክ", read back one by one, listed, filtered by kind. `mod-catalog` (226 tests, 1 534 assertions), green | PASS |
| 5 | Playwright `p0-admin-smoke` green against the built web app | `apps/web/e2e/p0/admin-smoke.spec.ts`, CI job `e2e-p0` on the compose stack (image from `docker-build`, built Next app from `web-build`): 8 passed (8.9 s), including "admin creates an ingredient and a product and sees both in English and Amharic", "admin adds a person who then appears in People" and the demo bakery admin's check. Also green in the local gate run below | PASS |
| 6 | arch, invariants, migration-lint and route-permission coverage all green | `api-arch`: migration lint (`check_invariants.py --numeric --branch-id --bilingual --append-only`, 0 violations) and Pest `arch,invariants,unit,module:shared,module:audit` 533 passed (2 781 assertions), including `tests/Arch/ModuleBoundariesTest.php`, `LedgerIsSoleWriterTest`, `BranchScopedTest`, `SoftDeletesTest`, `OctaneSafetyTest` and `tests/Invariants/RoutePermissionCoverageTest.php` (every `/api/v1` route is public or in `tests/Support/route_permissions.php`; `tests/Feature/RoutePermissionMatrixTest.php` calls each one as every role). `invariants`: `check_invariants.py --all` 0 violations | PASS |

Also required by `gate_check --mode done`: `openapi-check` ("openapi in sync"; re-exported for this report inside Docker
against the migrated MariaDB, byte-identical to `docs/openapi.json`), `erd-check` (0 missing), the ADR files, no phase-0 TODO
markers, no `->skip(` in Phase 0 module tests. `gate-p0` in run 39342542904 was green with two warnings
(this report missing; the ERD lacked `item_stock_view` and the manifest's Phase 2 Inventory tables); both are fixed here.

## Dependencies proven

| Contract | Cases | Status |
|---|---|---|
| `catalog->identity` (`apps/api/tests/Contract/Catalog/IdentityContractTest.php`) | `catalog.manage` denied to all seven non-admin roles through `Access::can`; every catalog write method answers `403 auth.forbidden` for every non-admin role before the handler runs; admin passes `can('catalog.manage')` and every guarded write with branch and actor resolved; every role reads (`catalog.view`); a per-user grant is honoured; an inactive admin and an anonymous caller are refused; Idempotency-Key required even for admin | 50 passed (289 assertions) in `mod-identity`; committed in `26b80a2`, before any Catalog code (`c28a5b7`) |
| `inventory->catalog` (`apps/api/tests/Contract/Inventory/CatalogContractTest.php`) | `Recipes::activeFor` lines in base units; `Units::toBase` round-trips to 4 dp for every seeded unit; a bought-in product has an empty recipe, not an exception; Catalog tells Inventory the kind and fulfilment that availability branches on | 4 passed (240 assertions) in `mod-catalog`; committed in `d33cfe1`, before any Inventory code (`e3d78e8`) |

## Modules declared stable in this phase

| Module | module:X | contract:*->X | arch boundary | route coverage |
|---|---|---|---|---|
| shared (`app/Shared`) | `module:shared` green in `api-arch` (with arch, invariants, unit: 533 passed) | none in the manifest (leaf) | `ModuleBoundariesTest` green | no routes of its own (`GET /health` is public) |
| audit | `module:audit` green in `api-arch` | none in the manifest | green | no routes |
| identity | `module:identity` 209 passed (1 182 assertions), `mod-identity` | `catalog->identity` 50 passed; orders, production, notifications, shifts -> identity are written in their phases | green | 12 routes in the registry, matrix test green |
| catalog | `module:catalog` 226 passed (1 534 assertions), `mod-catalog` | `inventory->catalog` 4 passed; orders, production -> catalog in their phases | green | 18 routes, green |
| inventory | `module:inventory,property,concurrency,seeds` 475 passed (59 863 assertions), `mod-inventory` | orders, production, shifts -> inventory in their phases (allowed empty until then) | green, plus `LedgerIsSoleWriterTest` | 6 routes, green |

Stable since (STATUS.json): shared 2026-10-19T09:57Z, audit 10:51Z, identity 15:17Z, catalog 16:55Z, inventory 22:10Z,
each after `gate_check.py --mode module`. Steps web (0.7), seeds (0.8) and docs (0.9) are done.

## Earlier gates re-run

None: Phase 0 is the first gate. `gate-p0` keeps running on every pull request and push to `main`, next to `gate-p1`.

## Invariants

`check_invariants.py --all --routes <artisan route:list --json>`: 0 violations across numeric, branch-id, bilingual,
append-only, idempotency and i18n (CI job `invariants` in run 39342542904, and locally). `invariants_version`: 1.

## Artifacts produced

- ADRs, all `Status: accepted`: 0001 modular monolith and module boundaries, 0002 `stock_balances` table vs
  materialized view, 0003 idempotency and client UUID, 0004 NUMERIC as string end to end, 0005 phase gates and CI, and
  two written during the phase: 0006 shared cPanel hosting, deploys from GitHub, tests in CI and on the live URL; 0007
  bearer tokens, not SPA cookies. ADR 0002's first note now names MariaDB 10.11 instead of MySQL 8.
- OpenAPI `docs/openapi.json`: 26 paths, 39 operations under `/api/v1` (health, login, logout, me, short-code verify,
  users, stations, devices, units, categories, items with prices, recipe, stations and modifiers, locations, stock,
  stock movements, ledger). First version, so no breaking changes.
- ERD `docs/erd.dbml`: all 33 migrated tables plus the `item_stock_view` view; the seven Phase 2 Inventory tables
  (suppliers, purchases, purchase_lines, item_costs, transfers, reorder_rules, stock_alert_states) are listed as
  planned, not migrated. `scripts/erd_check.py` now also requires every view a migration creates.
- Seeds: `Phase0Seeder`, the demo bakery in its own branch ("Demo Bakery"): 6 stations, 7 locations, 14 categories,
  43 ingredients and 28 products with ETB prices, 25 v1 recipes, opening stock through the Ledger, 9 people with
  roles, stations and short codes. `DemoSeeder` composes the phases up to `SEED_PHASE`; `php artisan bakery:seed-demo`.
- Live site https://bakery-ops.orcamind.dev: deploy run
  [39343252773](https://github.com/obssa/bakery-ops/actions/runs/39343252773) shipped `1f8697b` (build, rsync,
  `release.sh`, "smoke OK"), then "Playwright @live against the site" passed 6 of 6 `@live` tests (14.3 s): API
  health, the shell in English and Amharic, the start page's language choice, a later-phase screen, admin creates an
  ingredient and a product in both languages (in the "E2E Test Branch"), wrong password refused. Checked again on
  2026-10-20: `GET /api/v1/health` answers `{"status":"ok","database":"ok"}`, `/` redirects to `/app/`, `/app/en/` 200.

## Deviations from the plan and assumptions taken

Each one is an "As built" note in `docs/phases/phase-0-plan.md`:

- Engine MariaDB 10.11 (the live host's), not MySQL 8; CI and the local stack run the same image; JSON, not JSONB;
  CHECK constraints for enums; triggers with `SIGNAL 45000` and `log_bin_trust_function_creators=1`; no materialized
  views, so `item_stock_view` is a plain view (ADR 0002, 0006).
- Recipes v1 (with bilingual steps) and modifiers are built in Phase 0, as the phase reference requires.
- Shared lives in `app/Shared`; Shared and Audit run their module groups in `api-arch` (no `mod-*` job).
- `stock_balances` is the lock target and read path, with CHECK `on_hand >= 0` and `reserved >= 0`; rows are locked in
  ascending (item, location) order; reservations settle exactly once through derived client UUIDs (ADR 0002 note).
- `items.fulfilment` (to_order, to_stock, bought_in) and `items.production_station_id` were added (manifest, step 0.5).
- Negative stock is refused: a batch that would take `on_hand` or `reserved` below zero is refused whole (409).
  `available` can still dip below zero when held stock is wasted afterwards; Availability then answers zero.
- Middleware order: authentication, actor, permission, idempotency, then route-model binding (ADR 0003 note).
- Endpoints differ in detail: station assignments under `PUT /users/{id}/stations`, devices, logout, modifiers and
  location writes added; units are read and create only.
- The web shell is a static export under `/app` on the API's origin; the live host serves it from `public/app/`.
- Playwright signs in as the admin of an isolated "E2E Test Branch" (`bakery:create-admin`); the demo bakery is a
  branch of its own; neither touches shop data.
- The gate tests are four files in `tests/Gate` instead of one `Phase0GateTest.php`.
- CI after Phase 0 (this pull request): with `current_phase` 1 the phase-1 jobs run. `e2e.yml` lets a Playwright
  project pass with no specs (`--pass-with-no-tests`) only while its phase is `todo`, `planning` or `plan_approved`;
  once the phase is `in_progress` or later, each of its projects must list at least one test or the job fails.
  `scripts/task.py e2e` applies the same rule locally. The `full` compose profile that `e2e-p1` and later start
  (worker, Reverb, Caddy) no longer includes MinIO: the `minio/minio` image cannot be pulled any more and no phase
  before 4 stores files, so MinIO has its own `s3` profile and the choice of file store is left to Phase 4.

## What Ramin needs to do

- [ ] Decide on negative stock (it changes what the ledger means). Today the Ledger refuses any movement that would take
  a location's `on_hand` below zero, so a sale, waste or transfer the system cannot cover is blocked until a purchase,
  a production output or a count is recorded first. The alternative is to let `on_hand` go negative and flag it for an
  explanation at the station's shift count (Phase 3). Until you decide, the strict rule stays.
- [ ] Optional: to show the demo bakery on the live site, put a long random `BAKERY_DEMO_PASSWORD` in the server `.env`
  and run `php artisan bakery:seed-demo --force` (`docs/runbook/server.md`, "Demo bakery"). It needs your server
  access; nothing else depends on it.

Required checks need nothing from you: once this is on `main`, `gate-p1` is added to the required checks with `gh api`.

## Local gate run

`python scripts/gate_check.py --mode done --phase 0` on 2026-10-20, before `claim-done`: **PASS (0 failed, 0 warnings,
14 checks)**.

| Check | Result |
|---|---|
| `gate:p0` | 303 passed (57 695 assertions) |
| `arch,invariants` | 363 passed (1 792 assertions) |
| Playwright `p0-admin-smoke` | 8 passed (15.4 s) |
| ADRs 0001-0005 present, gate report present, `openapi.json` present, ERD covers the manifest's tables | PASS |
| no phase-0 TODO markers, no `->skip(` in Phase 0 module tests | PASS (0 found) |
| phase claimed done | SKIP before `claim-done`; the bookkeeping checks (`done_commit` in history, no app code after it, all steps done, all modules stable) run in `gate-p0` from the next commit on |

How it ran: Pest in the dev image against MariaDB 10.11 (`--runner "docker compose -f compose.yml -f compose.dev.yml
run --rm --no-deps -T app vendor/bin/pest"`); Playwright on the host against a local copy of the `e2e-p0` stack (the
`ci` image, `compose.yml` + `compose.ci.yml`, `DemoSeeder` with `SEED_PHASE=0`, `bakery:create-admin` for the E2E Test
Branch). The first local attempt against the bind-mounted dev server failed four API-backed Playwright tests on time
(about 6 s per request on the Windows bind mount, over the 10 s expectation for a sign-in), and its Playwright output
could not be decoded on the Windows code page; `gate_check.py` now reads that output as UTF-8. CI's `e2e-p0` remains
the authority for criterion 5.
