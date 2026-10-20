# Phase 0 plan — Foundations and ledger

Status: **built; Phase 0 closed at step 0.9** (gate report: `docs/phases/phase-0-report.md`). Approved on
2026-10-19 under Ramin's standing approval (`plan_approved_by` in `docs/phases/STATUS.json`). The sections below are
the plan as approved; an "As built" note follows a section only where the build deviated from it.

## 1. Entry check

```
gate_check.py --mode entry --phase 0
```
No previous phase. Working tree committed. Contracts internal to the phase (`inventory->catalog`,
`catalog->identity`) are written at steps 0.5/0.6 before their consumer code.

## 2. Contract tests to write first

| Contract | File | Cases |
|---|---|---|
| `inventory->catalog` | `apps/api/tests/Contract/Inventory/CatalogContractTest.php` | `Recipes::activeFor` returns lines in base units; `Units::toBase` round-trips to 4 dp for every seeded unit; bought-in product returns an empty recipe, not an exception |
| `catalog->identity` | `apps/api/tests/Contract/Catalog/IdentityContractTest.php` | every catalog write is 403 for non-admin roles; admin passes `can('catalog.manage')` |

## 3. Migrations (in order)

1. `branches` (id, name, timezone default `Africa/Addis_Ababa`, currency `ETB`, settings jsonb, timestamps, soft delete)
2. `stations` (branch_id, code enum till|kitchen|bar|cake_room|bakery|store, name_en, name_am, requires_overseer bool, sort)
3. `idempotency_keys` (branch_id, user_id, key, request_hash, response_status, response_body jsonb, expires_at; unique (branch_id, user_id, key))
4. `audit_log` (branch_id, actor_id, acting_user_id?, action, subject_type, subject_id, before jsonb, after jsonb, shift_instance_id?, station_id?, device_id?, ip, at) + `forbid_update_delete()` trigger function and trigger
5. `users` (branch_id, name, phone unique per branch, email?, password, short_code_hash, short_code_attempts, pin_locked_until, locale, is_active, photo_url, soft delete); spatie permission tables; `user_station_assignments` (user_id, station_id, can_count, is_overseer); `devices` (branch_id, name, fingerprint, enrolled_by, enrolled_at, revoked_at); `acting_sessions`
6. `units` (code, base_unit_id?, factor NUMERIC(14,4)); `categories` (branch_id, kind, name_en, name_am, parent_id, sort); `items` (branch_id, kind, name_en, name_am, category_id, unit_id, sku, is_active, is_new_until, default_batch_qty NUMERIC(14,4), soft delete); `item_prices` (item_id, price NUMERIC(12,2), currency, effective_from, effective_to); `item_station_assignments`; `recipes` (product_item_id, yield_qty NUMERIC(14,4), yield_unit_id, version, is_active); `recipe_lines` (recipe_id, ingredient_item_id, qty NUMERIC(14,4), unit_id, is_optional, waste_pct NUMERIC(5,2)); `recipe_steps`; `modifiers`
7. `locations` (branch_id, name, station_id?, type enum); `stock_movements` (append-only, trigger; branch_id, item_id, from_location_id?, to_location_id?, qty NUMERIC(14,4) signed, reason enum, ref_type, ref_id, reservation_ref?, shift_instance_id? (no FK yet), user_id, acting_user_id?, station_id?, device_id?, client_uuid?, created_at); `stock_balances` (branch_id, item_id, location_id, on_hand, reserved DECIMAL(14,4), unique (item_id, location_id)); `item_stock_view` as a plain view (SUM over movements) for reconciliation

As built: the engine is MariaDB 10.11, not MySQL 8 (found on the live host on 2026-10-19; CI and the local stack run the
same image through Laravel's `mariadb` driver, ADR 0006). So `jsonb` is plain JSON, NUMERIC is DECIMAL, enums are
VARCHAR plus a named CHECK constraint, the append-only triggers `SIGNAL SQLSTATE '45000'` (the PostgreSQL
`forbid_update_delete()` variant stays in the migrations), and creating them needs `log_bin_trust_function_creators=1`.
`items` also carries `fulfilment` (`to_order | to_stock | bought_in`, ingredients always `bought_in`), which decides how
availability is computed, and `production_station_id`, the station that makes a display-case product that has no
ticket station; items and prices carry the actor stamp (rule 4). `units` are global reference data (no `branch_id`)
inserted by their own migration. Recipes v1 (`recipes`, `recipe_lines`, bilingual `recipe_steps`) and `modifiers`
(`price_delta`, `recipe_delta`) are built here, not in Phase 2 (the phase reference explains why). `stock_balances`
also has `branch_id`, `updated_at` and CHECK `on_hand >= 0`, `reserved >= 0`; `locations` have bilingual names and the
types `store, kitchen, bar, cake_room, bakery, display_case, cash_drawer`. Sanctum's `personal_access_tokens` table
was added (ADR 0007). `docs/erd.dbml` documents every migrated table and the view.

## 4. Endpoints (all under `/api/v1`, bearer auth unless public, `Idempotency-Key` on writes)

| Method + path | Permission | Notes |
|---|---|---|
| `POST /login` | public | phone + password → token, user, permissions, locale |
| `GET /me` | auth | |
| `POST /short-code/verify` | device + branch | starts a 10-min acting-as session; 5 tries then 15-min lockout |
| `GET/POST/PATCH/DELETE /items`, `/categories`, `/units`, `/items/{id}/prices`, `/items/{id}/recipe` | `catalog.manage` (reads: `catalog.view`) | bilingual validation; price creates a new history row; recipe v1 |
| `GET/POST /users`, `POST /users/{id}/short-code/reset`, `GET/POST /stations`, `/user-station-assignments` | `people.manage` | |
| `GET /locations`, `GET /stock?location=` | `inventory.view` | reads `stock_balances` |
| `POST /stock/movements` | `inventory.adjust` (admin) | manual `purchase_in` / `waste` / `return` for Phase 0 demos; goes through `Ledger` |
| `GET /ledger?item=&location=&from=&to=` | `inventory.view` | |
| `GET /health` | public | used by compose health checks and the Phase 0 smoke test |

As built (26 paths, 39 operations in `docs/openapi.json`): units are `GET`/`POST` only; station assignments are
`PUT /users/{id}/stations` (people) and `PUT /items/{item}/stations` (routing) instead of `/user-station-assignments`;
added `POST /logout`, `PATCH /users/{id}`, `GET/POST /devices`, `POST /devices/{id}/revoke`,
`GET/POST /items/{item}/modifiers`, `POST /locations` and `PATCH /locations/{location}` (`inventory.manage`).
Middleware order is authentication, actor, permission, idempotency, and only then route-model binding (ADR 0003 note):
a replayed `DELETE` returns its stored `200` instead of `404`, and a role without the permission gets `403` before any
lookup. Negative stock is refused: any Ledger batch, `POST /stock/movements` included, that would take `on_hand` or
`reserved` below zero is refused whole with `409` (`inventory.stock.insufficient` / `inventory.stock.reserved_below_zero`),
and CHECK constraints back it up (ADR 0002 note; an open question for Ramin in the gate report). Every error carries a
bilingual `message_key`. `GET /` redirects to the web shell at `/app/` (section 6).

## 5. Domain events

`StockChanged(item, location, on_hand, reserved)` dispatched by `Ledger::append` (listeners arrive in Phase 2).

As built: `StockChanged` is in `App\Shared\Events` (array payload, dispatched after commit), so the Phase 2 listeners
never import Inventory.

## 6. Build order

0.2 shared → 0.3 audit → 0.4 identity → 0.5 catalog (contract `catalog->identity` first) → 0.6 inventory
(contract `inventory->catalog` first) → 0.7 web shell → 0.8 seeds → 0.9 docs. Each step ends with
`gate_check.py --mode module` and `phase_status.py module-stable`.

As built: Shared is `app/Shared` (namespace `App\Shared`), not a folder under `app/Modules`. Shared and Audit have no
`mod-*` job of their own: their `module:shared` and `module:audit` groups run in `api-arch` with the arch, invariant
and unit tests, before `mod-identity` starts. Web (0.7) and seeds (0.8) are steps, not manifest modules, so they have
no `--mode module` check; their evidence is `e2e-p0` and the `seeds` group in `mod-inventory`. The web shell is a static
export served under `/app` on the API's own origin (`/app/en/...` and `/app/am/...` prerendered, no Next middleware,
record pages by `?id=`); on the live host LiteSpeed serves it from `public/app/` (ADR 0006 note), so production needs
no CORS origin.

## 7. Test list

- Unit: `Quantity`/`Money` arithmetic and rounding; `StateMachine` trait; `MovementReason` delta map; `Units::toBase`.
- Feature: every endpoint above (happy path, validation, 403 per role, idempotent replay); short-code lockout; acting-as expiry; price history boundaries.
- Property: `LedgerPropertiesTest` (sum == balances == view), `ReservationPropertyTest` (available never < 0, shortfall shape).
- Concurrency: `ReservationLockTest` with a second connection (`DatabaseTruncation`).
- Arch: `ModuleBoundariesTest`, `LedgerIsSoleWriterTest`, `BranchScopedTest`, `SoftDeletesTest`, `OctaneSafetyTest`.
- Invariants: `AppendOnlyTest`, `NumericColumnsTest`, `BranchIdTest`, `BilingualColumnsTest`, `IdempotencyCoverageTest`, `RoutePermissionCoverageTest`, `ActorStampTest`.
- Playwright: `p0-admin-smoke` (login, create an ingredient and a product, see both in en and am).
- Gate: `tests/Gate/Phase0GateTest.php` (`gate:p0`).

As built: the gate is four files in `tests/Gate`, all in group `gate:p0`: `LedgerPropertiesTest` (200 seeded sequences),
`ReservationPropertyTest` (100 seeded sequences), `ReservationLockTest` and `AdminCreatesBothKindsTest`; there is no
`Phase0GateTest.php`. Added: `tests/Feature/RoutePermissionMatrixTest.php` (every route as every role),
`tests/Feature/WebShellHostingTest.php`, `tests/Invariants/DeployableCachesTest.php` and `tests/Seeders` (group
`seeds`). Playwright `p0-admin-smoke` signs in as the admin of its own "E2E Test Branch", created by
`php artisan bakery:create-admin` (in CI before Playwright runs; on the live site once, with the credentials kept as
GitHub secrets), so the tests never write to the demo bakery or a real branch. Six of its eight tests are tagged
`@live` and run against https://bakery-ops.orcamind.dev after every deploy.

## 8. Seeds

`Phase0Seeder`: 1 branch, 6 stations, 7 locations, units (kg g L ml pcs slice tray), 8 ingredient and
product categories, ~30 items, prices, recipes for 5 products (bread, cake, sandwich, latte, pizza), one
user per role with short codes, opening balances via `purchase_in` movements.

As built (step 0.8): the demo branch "Demo Bakery" (`bakery.demo.branch`, marked `settings.demo`), 6 stations, 7 locations
linked to their stations, 8 ingredient + 6 product categories, 43 ingredients and 28 products (15 to order, 10 to stock,
3 bought in) with ETB prices, routing, production stations and batch sizes, an active v1 recipe for every made product
(25), opening stock through the Ledger (purchase_in into the store, an opening count where the bakery and the cake room
made their goods, transfers to where stock is used or sold), and 9 people (one per role plus a second waiter, an
overseer flag, station assignments, short codes). Credentials from `BAKERY_DEMO_PASSWORD` (documented default only in
local/testing). Idempotent, branch-isolated, every write audited. `DemoSeeder` composes the phase seeders up to
`SEED_PHASE`; `php artisan bakery:seed-demo {--branch=} {--phase=} {--force}` loads them on a server. Tests:
`tests/Seeders` (group `seeds`, run by `mod-inventory`).

## 9. ADRs

0001 modular monolith · 0002 stock_balances vs matview · 0003 idempotency · 0004 NUMERIC as string ·
0005 phase gates and CI (all drafted with the skeleton; accepted when the phase closes).

As built: 0001-0005 are accepted, and two more were written during the phase: 0006 (shared cPanel hosting at
https://bakery-ops.orcamind.dev, deploys from GitHub, tests in CI and on the live URL) and 0007 (bearer tokens).

## 10. Assumptions

- Database engine is MySQL 8 (live host, ADR 0006); migrations stay portable to PostgreSQL. NUMERIC(p,s) in this plan means DECIMAL(p,s).
  As built: MariaDB 10.11, the engine the live host actually runs (section 3 note).

- Laravel 13 instead of the document's Laravel 11: Laravel 11 is end-of-life and Composer blocks it on security advisories. Same skeleton layout; nothing in the design changes.

- Bearer tokens (Sanctum personal access tokens), not SPA cookies, from day one (ADR 0007 in Phase 1 formalises).
  As built: ADR 0007 was written in Phase 0 (step 0.4).
- `php artisan serve` in the dev/CI image until Octane arrives with Reverb in Phase 1.
- Audit trail of record is our own `audit_log`; activitylog only for model diffs.
- Recipes v1 schema lands here although the roadmap lists recipes under Phase 2 (reason in the phase reference).

## 11. Risks

Matview locking (ADR 0002) · `RefreshDatabase` vs second connection · triggers in migrations · CRLF ·
host PHP 8.0 · spatie permission cache in tests.
