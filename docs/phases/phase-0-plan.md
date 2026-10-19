# Phase 0 plan — Foundations and ledger

Status: **draft, awaiting approval**. Ramin approves by setting `phases.0.plan_approved: true` in
`docs/phases/STATUS.json` in his own commit. Step 0.1 (repo, compose, CI, scaffolds) is already in
place as the skeleton this plan ships with; steps 0.2 onward wait for the flag.

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

## 5. Domain events

`StockChanged(item, location, on_hand, reserved)` dispatched by `Ledger::append` (listeners arrive in Phase 2).

## 6. Build order

0.2 shared → 0.3 audit → 0.4 identity → 0.5 catalog (contract `catalog->identity` first) → 0.6 inventory
(contract `inventory->catalog` first) → 0.7 web shell → 0.8 seeds → 0.9 docs. Each step ends with
`gate_check.py --mode module` and `phase_status.py module-stable`.

## 7. Test list

- Unit: `Quantity`/`Money` arithmetic and rounding; `StateMachine` trait; `MovementReason` delta map; `Units::toBase`.
- Feature: every endpoint above (happy path, validation, 403 per role, idempotent replay); short-code lockout; acting-as expiry; price history boundaries.
- Property: `LedgerPropertiesTest` (sum == balances == view), `ReservationPropertyTest` (available never < 0, shortfall shape).
- Concurrency: `ReservationLockTest` with a second connection (`DatabaseTruncation`).
- Arch: `ModuleBoundariesTest`, `LedgerIsSoleWriterTest`, `BranchScopedTest`, `SoftDeletesTest`, `OctaneSafetyTest`.
- Invariants: `AppendOnlyTest`, `NumericColumnsTest`, `BranchIdTest`, `BilingualColumnsTest`, `IdempotencyCoverageTest`, `RoutePermissionCoverageTest`, `ActorStampTest`.
- Playwright: `p0-admin-smoke` (login, create an ingredient and a product, see both in en and am).
- Gate: `tests/Gate/Phase0GateTest.php` (`gate:p0`).

## 8. Seeds

`Phase0Seeder`: 1 branch, 6 stations, 7 locations, units (kg g L ml pcs slice tray), 8 ingredient and
product categories, ~30 items, prices, recipes for 5 products (bread, cake, sandwich, latte, pizza), one
user per role with short codes, opening balances via `purchase_in` movements.

## 9. ADRs

0001 modular monolith · 0002 stock_balances vs matview · 0003 idempotency · 0004 NUMERIC as string ·
0005 phase gates and CI (all drafted with the skeleton; accepted when the phase closes).

## 10. Assumptions

- Database engine is MySQL 8 (live host, ADR 0006); migrations stay portable to PostgreSQL. NUMERIC(p,s) in this plan means DECIMAL(p,s).

- Laravel 13 instead of the document's Laravel 11: Laravel 11 is end-of-life and Composer blocks it on security advisories. Same skeleton layout; nothing in the design changes.

- Bearer tokens (Sanctum personal access tokens), not SPA cookies, from day one (ADR 0007 in Phase 1 formalises).
- `php artisan serve` in the dev/CI image until Octane arrives with Reverb in Phase 1.
- Audit trail of record is our own `audit_log`; activitylog only for model diffs.
- Recipes v1 schema lands here although the roadmap lists recipes under Phase 2 (reason in the phase reference).

## 11. Risks

Matview locking (ADR 0002) · `RefreshDatabase` vs second connection · triggers in migrations · CRLF ·
host PHP 8.0 · spatie permission cache in tests.
